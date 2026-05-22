<?php
/**
 * SFC Compiler v6 — Vue-like Single File Component compiler for AOT desktop apps
 *
 * Usage: php framework/sfc-compiler.php apps/calculator/App.vue [--dump-ast]
 *
 * Architecture (v6 M2):
 *   1. Block Extraction:     template / script / style from .vue
 *   2. Style Parsing:        CSS class → GDI properties (via CssMappings)
 *   3. Template Parsing:     recursive descent → AST  (via TemplateParser + ComponentRegistry)
 *   4. Component Resolution: resolve <child-comp> refs, generate child registration code
 *   5. AST → Layout Arrays:  compile-time coordinate calculation + bindKeys
 *   6. AOT Validation:       check generated code before write (via AotValidator)
 *   7. Code Generation:      *Component.php output (no longer generates *Layout_gen.php)
 *
 * v6 M2 变更:
 *   - 输出文件名从 *.gen.php 改为 *Component.php
 *   - 生成 getLayout() 方法替代布局函数
 *   - 主组件生成 registerChildren() 方法注册子组件
 *   - 主组件生成 onMount/onUnmount 生命周期方法
 *   - 不再生成 AppLayout_gen.php
 *
 * This tool runs OUTSIDE the AOT pipeline (standard PHP CLI).
 * Generated *Component.php files are consumed by the AOT compiler.
 */

// ---- Load compiler modules ----
$compilerDir = __DIR__ . '/compiler';
require_once $compilerDir . '/ast-nodes.php';
require_once $compilerDir . '/css-mappings.php';
require_once $compilerDir . '/template-parser.php';
require_once $compilerDir . '/aot-validator.php';
require_once $compilerDir . '/script-analyzer.php';
require_once $compilerDir . '/component-registry.php';
require_once $compilerDir . '/component-resolver.php';
require_once $compilerDir . '/flex-layout.php';

// ============================================================
// v6 M3: Helper — build component registry from components/ directory
// Automatically scans app/components/*.vue and maps tagName → file path
// ============================================================
function loadComponentRegistry(string $vueFile): ComponentRegistry
{
    $registry = new ComponentRegistry();
    $appDir = dirname(realpath($vueFile));
    $componentsDir = $appDir . DIRECTORY_SEPARATOR . 'components';

    if (!is_dir($componentsDir)) {
        return $registry;
    }

    // Scan all .vue files in components/ directory
    $files = glob($componentsDir . DIRECTORY_SEPARATOR . '*.vue');
    $config = [];
    foreach ($files as $file) {
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        // Convert filename to tag-name: AboutDialog → about-dialog
        $tagName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
        $tagName = strtolower($tagName);
        if ($tagName === '') continue;
        $relativePath = 'components' . DIRECTORY_SEPARATOR . basename($file);
        $config[$tagName] = $relativePath;
    }

    if (count($config) > 0) {
        $warnings = $registry->load($config, $appDir);
        foreach ($warnings as $w) {
            echo "  [WARN] ComponentRegistry: $w\n";
        }
    }

    return $registry;
}

// ============================================================
// v6 M3: PHASE 1 — Pre-compile all child components to gen/
// This runs BEFORE the root component compilation so that gen/
// is populated. Phase 2 will scan gen/ to build ComponentFactory.
// ============================================================
function compileChildComponents(ComponentRegistry $registry, string $outDir): void
{
    $allComponents = $registry->all();
    if (count($allComponents) === 0) {
        return;
    }

    echo "\n--- Phase 1: Compiling child components ---\n";

    foreach ($allComponents as $tagName => $vuePath) {
        if (!file_exists($vuePath)) {
            echo "  [SKIP] $tagName: source file not found\n";
            continue;
        }

        $baseName = pathinfo($vuePath, PATHINFO_FILENAME);
        $className = componentTagToComponentName($tagName);

        echo "  Compiling: $vuePath\n";

        $source = file_get_contents($vuePath);

        // Extract blocks
        $template = '';
        $script = '';
        $styles = '';
        if (preg_match('#<template[^>]*>(.*?)</template>#s', $source, $m)) {
            $template = $m[1];
        }
        if (preg_match('#<style[^>]*>(.*?)</style>#s', $source, $m)) {
            $styles = $m[1];
        }

        // Parse styles
        $styleWarnings = [];
        $classStyles = CssMappings::parseStyleBlock($styles, $styleWarnings);

        // Initialize ReactiveComponent shared state (AOT requires ChangeQueue)
        $analyzer = new ScriptAnalyzer();
        $analyzer->injectDirty('');

        // Parse template (pass registry so ComponentRefNodes are recognized)
        $parser = new TemplateParser($registry);
        $app = $parser->parse($template);
        $layout = $parser->lowerToLayout($app, $classStyles);

        $elements = $layout['elements'];
        $buttons = $layout['buttons'] ?? [];
        $bindKeys = $layout['bindKeys'] ?? [];
        $handlerMap = $layout['handlerMap'] ?? [];
        $condProps = $layout['condProps'] ?? [];

        // v6 M5: 收集动态绑定属性（来自子组件模板的 bind 属性）
        // 子组件的 bind 属性（如 DisplayPanel.vue 的 TextNode bind="value"）
        // 需要从父组件传入值，所以声明为动态属性
        $dynamicPropsDeclaration = '';
        $dynamicBindKeys = [];
        foreach ($elements as $el) {
            if (isset($el['bind']) && $el['bind'] !== '') {
                $key = $el['bind'];
                if (!in_array($key, $dynamicBindKeys)) {
                    $dynamicBindKeys[] = $key;
                }
            }
        }
        if (count($dynamicBindKeys) > 0) {
            foreach ($dynamicBindKeys as $key) {
                $dynamicPropsDeclaration .= "    public string \$$key = '';\n";
            }
        }

        // Merge buttons into elements
        $mergedElements = array_map(function($btn) {
            $btn['type'] = 'button';
            return $btn;
        }, $buttons);
        $allElements = array_merge($elements, $mergedElements);

        $elementsExport = varExportShort($allElements);

        $classBody = '';
        $getBindValue = '';
        if (count($bindKeys) > 0) {
            foreach ($bindKeys as $key) {
                $getBindValue .= "        if (\$bindKey === '$key') {\n";
                $getBindValue .= "            return \$this->$key;\n";
                $getBindValue .= "        }\n";
            }
            $getBindValue .= "        return '';";
        } else {
            $getBindValue = "        return '';";
        }

        // v6 M5: 生成 setBindValue 方法体 (支持动态绑定属性)
        $setBindValueImpl = "    public function setBindValue(string \$bindKey, string \$value): void\n    {\n        // No dynamic bind props defined\n    }";
        if (count($dynamicBindKeys) > 0) {
            $setBindValueBody = "    public function setBindValue(string \$bindKey, string \$value): void\n    {\n";
            foreach ($dynamicBindKeys as $key) {
                $setBindValueBody .= "        if (\$bindKey === '$key') {\n";
                $setBindValueBody .= "            \$this->$key = \$value;\n";
                $setBindValueBody .= "            \$this->dirty = true;\n";
                $setBindValueBody .= "        }\n";
            }
            $setBindValueBody .= "    }";
            $setBindValueImpl = $setBindValueBody;
        }

        $dispatchClick = '';
        if (count($handlerMap) > 0) {
            $first = true;
            foreach ($handlerMap as $handler => $hasArg) {
                $prefix = $first ? 'if' : 'elseif';
                $first = false;
                if ($hasArg) {
                    $dispatchClick .= "        {$prefix} (\$handler === '$handler') {\n";
                    $dispatchClick .= "            \$this->$handler(\$btn['arg']);\n";
                    $dispatchClick .= "        }\n";
                } else {
                    $dispatchClick .= "        {$prefix} (\$handler === '$handler') {\n";
                    $dispatchClick .= "            \$this->$handler();\n";
                    $dispatchClick .= "        }\n";
                }
            }
        } else {
            $dispatchClick = "        // No handlers defined";
        }

        // evalCondition — v6 M3 fix: use explicit if/else per property
        // AOT 不支持 $this->$prop 变量属性访问，改用显式 if 分支路由
        $evalCondition = "        return true;";
        if (count($condProps) > 0) {
            $condBranches = [];
            foreach ($condProps as $prop) {
                $condBranches[] = "if (\$cond['prop'] === '$prop') { " .
                    "\$v = \$this->$prop; " .
                    "\$op = \$cond['op'] ?? '==='; " .
                    "if (\$op === '===' || \$op === '==') return \$v === \$cond['value']; " .
                    "if (\$op === '!=') return \$v !== \$cond['value']; }";
            }
            $evalCondition = "        " . implode(" else ", $condBranches) . " else return false;";
        }

        $classContent = <<<PHP
<?php

/**
 * AUTO-GENERATED by SFC Compiler v6 — DO NOT EDIT
 * Source: $baseName.vue
 *
 * v6 M5: Child component with dynamic props binding
 */

use native_types;

class {$className} extends ReactiveComponent
{
$classBody
    /** v6 M5: 动态绑定属性声明 (从父组件传入) */
$dynamicPropsDeclaration

    public function getLayout(): array
    {
        return [
            'elements' => {$elementsExport},
        ];
    }

    public function onMount(): void
    {
    }

    public function onUnmount(): void
    {
    }

    public function getBindValue(string \$bindKey): string
    {
        {$getBindValue}
    }

    /** v6 M5: setBindValue 支持动态绑定属性 (从父组件传入) */
$setBindValueImpl

    public function dispatchClick(array \$btn): void
    {
        \$handler = \$btn['handler'] ?? '';
{$dispatchClick}
    }

    public function evalCondition(array \$cond): bool
    {
{$evalCondition}
    }

    public function __construct(?string \$componentId = null)
    {
        parent::__construct(\$componentId ?? '{$baseName}');
    }
}
PHP;

        $classPath = $outDir . DIRECTORY_SEPARATOR . $className . '.php';
        $validator = new AotValidator();
        $classOk = $validator->validate($classContent, $classPath);

        if ($classOk) {
            file_put_contents($classPath, $classContent);
            echo "  Generated:  $classPath (" . strlen($classContent) . " bytes)\n";
        } else {
            echo "  [ERROR] $className: AOT validation failed\n";
        }
    }

    echo "--- Phase 1 complete ---\n\n";
}

// ============================================================
// v6 M2: Helper — resolve component references
// Returns resolved children info for generating registerChildren() code
// ============================================================
function resolveComponentRefsV6(AppNode $app, array &$classStyles, int $depth = 0): array
{
    $warnings = [];
    $resolvedChildren = [];
    $childComponents = [];  // v6 M2: collect child component info
    $nextOverlayLayer = 1;

    foreach ($app->children as $child) {
        if ($child instanceof ComponentRefNode) {
            if ($depth >= 1) {
                $warnings[] = "Line {$child->line}: Nested component <{$child->tagName}> exceeds maximum depth (1 level). Skipping.";
                continue;
            }

            $childSource = @file_get_contents($child->componentFile);
            if ($childSource === false) {
                $warnings[] = "Line {$child->line}: Cannot read component file: {$child->componentFile}";
                continue;
            }

            $childTemplate = '';
            $childStyles = '';
            if (preg_match('#<template[^>]*>(.*?)</template>#s', $childSource, $m)) {
                $childTemplate = $m[1];
            }
            if (preg_match('#<style[^>]*>(.*?)</style>#s', $childSource, $m)) {
                $childStyles = $m[1];
            }
            if (preg_match('#<script[^>]*lang=["\']php["\'][^>]*>(.*?)</script>#s', $childSource, $m)) {
                // 子组件如果有 script 块，也需要解析
            }

            if ($childTemplate === '') {
                $warnings[] = "Line {$child->line}: Component <{$child->tagName}> has no <template> block";
                continue;
            }

            $childStyleWarnings = [];
            $childClassStyles = CssMappings::parseStyleBlock($childStyles, $childStyleWarnings);
            foreach ($childClassStyles as $cls => $style) {
                if (!isset($classStyles[$cls])) {
                    $classStyles[$cls] = $style;
                }
            }
            foreach ($childStyleWarnings as $w) {
                $warnings[] = "Component <{$child->tagName}> CSS: $w";
            }

            $childParser = new TemplateParser();
            $childAst = $childParser->parse($childTemplate);

            foreach ($childAst->children as $grandchild) {
                if ($grandchild instanceof ComponentRefNode) {
                    $warnings[] = "Line {$child->line}: Component <{$child->tagName}> contains nested component <{$grandchild->tagName}>. v6 only supports 1 level of nesting.";
                }
            }

            // v6 M2: 收集子组件信息（用于生成 registerChildren 代码）
            $childComponentName = componentTagToComponentName($child->tagName);
            $childProps = $child->props;
            $offsetX = (int)($childProps['x'] ?? 0);
            $offsetY = (int)($childProps['y'] ?? 0);

            // v6 M5: 提取动态绑定 props (如 :value="display" → bindProps['value'] = 'display')
            $bindProps = [];
            foreach ($childProps as $key => $value) {
                if (strlen($key) > 0 && $key[0] === ':') {
                    $propName = substr($key, 1);  // 去掉 ':' 前缀
                    $bindProps[$propName] = $value;
                }
            }

            $childComponents[] = [
                'tagName' => $child->tagName,
                'componentClass' => $childComponentName,
                'offsetX' => $offsetX,
                'offsetY' => $offsetY,
                'isOverlay' => $child->isOverlay,
                'vIf' => $child->vIf,
                'bindProps' => $bindProps,  // v6 M5: 动态绑定 props
            ];

            // 应用坐标偏移
            $offsetX = (int)($child->props['x'] ?? 0);
            $offsetY = (int)($child->props['y'] ?? 0);

            foreach ($childAst->children as $childNode) {
                applyOffset($childNode, $offsetX, $offsetY);
                applyPropBindings($childNode, $child->props);
                if ($child->vIf !== '' && $childNode->vIf === '') {
                    $childNode->vIf = $child->vIf;
                }
                if ($child->isOverlay) {
                    $childNode->layer = $nextOverlayLayer;
                }
                $childNode->groupId = $child->tagName;
                $resolvedChildren[] = $childNode;
            }
            if ($child->isOverlay) {
                $nextOverlayLayer++;
            }
        } else {
            $resolvedChildren[] = $child;
        }
    }

    $app->children = $resolvedChildren;
    return ['warnings' => $warnings, 'children' => $childComponents];
}

// v6 M2: 将 component tag 转换为类名
function componentTagToComponentName(string $tag): string
{
    // Strip "Component" suffix if present (caller passes filename like "AboutDialog")
    $tag = preg_replace('/Component$/i', '', $tag);
    $parts = explode('-', $tag);
    $result = '';
    foreach ($parts as $i => $part) {
        $result .= ucfirst($part);
    }
    return $result . 'Component';
}

// ============================================================
// v6 M1: Helper — group_id to camelCase function name suffix
// ============================================================
function varExportShort(array $data): string
{
    $export = var_export($data, true);
    $export = preg_replace('/array\s*\(/', '[', $export);
    $export = preg_replace('/\)(,?)$/m', ']$1', $export);
    return $export;
}

function groupIdToCamel(string $gid): string
{
    $parts = explode('-', $gid);
    $result = $parts[0];
    for ($i = 1; $i < count($parts); $i++) {
        $result .= ucfirst($parts[$i]);
    }
    return $result;
}

// ---- CLI ----
if ($argc < 2) {
    echo "Usage: php framework/sfc-compiler.php <path/to/component.vue> [--dump-ast]\n";
    exit(1);
}

$vueFile = $argv[1];
$dumpAst = in_array('--dump-ast', $argv, true);

if (!file_exists($vueFile)) {
    echo "Error: File not found: $vueFile\n";
    exit(1);
}

$source = file_get_contents($vueFile);

// v5 M2: Load component registry from app's project.yml
$componentRegistry = loadComponentRegistry($vueFile);
$componentNames = array_keys($componentRegistry->all());
$appDir = dirname(realpath($vueFile));
$outDir = $appDir . DIRECTORY_SEPARATOR . 'gen';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$baseName = pathinfo($vueFile, PATHINFO_FILENAME);
$isRootComponent = (strtolower($baseName) === 'app' || strtolower($baseName) === 'appcomponent');

if (count($componentNames) > 0) {
    echo "SFC Compiler v6: $vueFile (components: " . implode(', ', $componentNames) . ")\n";
} else {
    echo "SFC Compiler v6: $vueFile\n";
}

// ============================================================
// v6 M3: PHASE 1 — Pre-compile all child components to gen/
// ============================================================
if ($isRootComponent) {
    compileChildComponents($componentRegistry, $outDir);
}

// ============================================================
// Step 1: Extract blocks (template / script / style)
// ============================================================
$template = '';
$script   = '';
$styles   = '';
$blockErrors = [];

if (preg_match('#<template[^>]*>(.*?)</template>#s', $source, $m)) {
    $template = $m[1];
} else {
    $blockErrors[] = "No <template> block found in $vueFile";
}

if (preg_match('#<script[^>]*lang=["\']php["\'][^>]*>(.*?)</script>#s', $source, $m)) {
    $script = trim($m[1]);
} else {
    $blockErrors[] = "No <script lang=\"php\"> block found in $vueFile";
}

if (preg_match('#<style[^>]*>(.*?)</style>#s', $source, $m)) {
    $styles = $m[1];
}

if (count($blockErrors) > 0) {
    foreach ($blockErrors as $err) {
        echo "Error: $err\n";
    }
    exit(1);
}

echo "  Template: " . strlen($template) . " bytes\n";
echo "  Script:   " . strlen($script) . " bytes\n";
echo "  Style:    " . strlen($styles) . " bytes\n";

// ============================================================
// Step 2: Parse styles → class map (via CssMappings)
// ============================================================
$styleWarnings = [];
$classStyles = CssMappings::parseStyleBlock($styles, $styleWarnings);
echo "  Classes:  " . count($classStyles) . " parsed\n";

foreach ($styleWarnings as $w) {
    echo "  [WARN] CSS: $w\n";
}

// ============================================================
// Step 3: Parse template → AST (via TemplateParser + ComponentRegistry)
// ============================================================
$parser = new TemplateParser($componentRegistry);
$app = $parser->parse($template);
$parseErrors = $parser->getErrors();

if (count($parseErrors) > 0) {
    echo "\n=== Template Parse Errors (" . count($parseErrors) . ") ===\n";
    foreach ($parseErrors as $err) {
        echo "  $err\n";
    }
    echo "========================================\n\n";
}

if ($dumpAst) {
    echo "\n=== AST Dump ===\n";
    echo $parser->dumpAst($app);
    echo "\n=== End AST ===\n\n";
}

// ============================================================
// v6 M2: Step 4 — Resolve component references
// ============================================================
$resolveResult = resolveComponentRefsV6($app, $classStyles);
$componentWarnings = $resolveResult['warnings'];
$childComponentInfo = $resolveResult['children'];

if (count($componentWarnings) > 0) {
    echo "\n=== Component Resolution Warnings (" . count($componentWarnings) . ") ===\n";
    foreach ($componentWarnings as $w) {
        echo "  [WARN] $w\n";
    }
    echo "===================================================\n\n";
}

// ============================================================
// Step 4: AST → Layout Arrays (compiler-time coordinate calculation)
// ============================================================
$layout = $parser->lowerToLayout($app, $classStyles);
$elements = $layout['elements'];
$buttons  = $layout['buttons'];
$bindKeys = $layout['bindKeys'] ?? [];
$handlerMap = $layout['handlerMap'] ?? [];
$condProps = $layout['condProps'] ?? [];

echo "  Elements: " . count($elements) . " (rects + texts)\n";
echo "  Buttons:  " . count($buttons) . "\n";
echo "  BindKeys: " . count($bindKeys) . "\n";
echo "  Handlers: " . count($handlerMap) . "\n";
echo "  CondProps: " . count($condProps) . "\n";

// ============================================================
// Step 5: Generate Component class
// ============================================================

// v6 M2: 组件类名
$componentClassName = $baseName . 'Component';

// v4: Auto-inject $this->dirty = true into methods that modify reactive properties
$analyzer = new ScriptAnalyzer();
$classBody = $analyzer->injectDirty($script);

// v4 M2.2: Generate getBindValue() body from collected bindKeys
// v6 M5: Handle item_text_* keys specially (not actual properties, return empty string)
// v6 M5 FIX: Also include titleText and statusText for <text :bind="..."> elements
$getBindValueBody = '';
// Build complete set of bind keys (from layout + text bind values)
$allBindKeys = array_merge($bindKeys, $layout['textBindKeys'] ?? []);
$allBindKeys = array_unique($allBindKeys);
if (count($allBindKeys) > 0) {
    foreach ($allBindKeys as $key) {
        $getBindValueBody .= "        if (\$bindKey === '$key') {\n";
        // v6 M5: For item_text_* pattern, return empty (handled by BaseRenderer at runtime)
        if (strpos($key, 'item_text_') === 0) {
            $getBindValueBody .= "            return '';  // v6 M5: runtime item binding\n";
        } else {
            $getBindValueBody .= "            return \$this->$key;\n";
        }
        $getBindValueBody .= "        }\n";
    }
    $getBindValueBody .= "        return '';";
} else {
    $getBindValueBody = "        return '';";
}

// v4 M2.3: Generate dispatchClick() body from collected handlerMap
$dispatchClickBody = '';
if (count($handlerMap) > 0) {
    $dispatchClickBody .= "        \$handler = \$btn['handler'];\n";
    $first = true;
    foreach ($handlerMap as $handler => $hasArg) {
        $prefix = $first ? 'if' : 'elseif';
        $first = false;
        if ($hasArg) {
            $dispatchClickBody .= "        {$prefix} (\$handler === '$handler') {\n";
            $dispatchClickBody .= "            \$this->$handler(\$btn['arg']);\n";
            $dispatchClickBody .= "        }\n";
        } else {
            $dispatchClickBody .= "        {$prefix} (\$handler === '$handler') {\n";
            $dispatchClickBody .= "            \$this->$handler();\n";
            $dispatchClickBody .= "        }\n";
        }
    }
} else {
    $dispatchClickBody = "        // No handlers defined";
}

// v4 M2.5: Generate evalCondition() body from collected condProps
$evalConditionBody = '';
if (count($condProps) > 0) {
    $evalConditionBody .= "        \$prop = \$cond['prop'];\n";
    $evalConditionBody .= "        \$op = \$cond['op'];\n";
    $evalConditionBody .= "        if (\$op === 'truthy') {\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop ? true : false;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    $evalConditionBody .= "        if (\$op === 'falsy') {\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop ? false : true;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    $evalConditionBody .= "        if (\$op === '==') {\n";
    $evalConditionBody .= "            \$value = \$cond['value'];\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop === \$value;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    $evalConditionBody .= "        if (\$op === '!=') {\n";
    $evalConditionBody .= "            \$value = \$cond['value'];\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop !== \$value;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    $evalConditionBody .= "        return false;";
} else {
    $evalConditionBody = "        return true; // no conditions defined";
}

// v6 M3: Generate getLayout() body (统一 elements + components)
$elementsExport = varExportShort($elements);
// 合并 buttons 到 elements，添加 type: 'button' 标记
$mergedElements = varExportShort(array_merge($elements, array_map(function($btn) {
    $btn['type'] = 'button';
    return $btn;
}, $buttons)));
// v6 M3: 生成 components 声明（不在 elements 中内联子组件）
$componentsExport = '[]';
if (count($childComponentInfo) > 0) {
    $componentItems = [];
    foreach ($childComponentInfo as $child) {
        $className = $child['componentClass'];
        $key = $child['tagName'];
        $propsX = $child['offsetX'];
        $propsY = $child['offsetY'];
        $vIf = $child['vIf'];
        $bindProps = $child['bindProps'] ?? [];

        if ($vIf !== '') {
            // v6 M3: 结构化 v-if 条件 ['prop' => 'xxx', 'op' => 'truthy']
            $item = [
                'type' => $className,
                'key' => $key,
                'props' => ['x' => $propsX, 'y' => $propsY],
                'vIf' => ['prop' => $vIf, 'op' => 'truthy'],
            ];
        } else {
            $item = [
                'type' => $className,
                'key' => $key,
                'props' => ['x' => $propsX, 'y' => $propsY],
            ];
        }

        // v6 M5: 添加动态绑定 props (如 :value="display")
        if (count($bindProps) > 0) {
            $item['bindProps'] = $bindProps;
        }

        $componentItems[] = $item;
    }
    $componentsExport = varExportShort($componentItems);
}
$getLayoutBody = <<<PHP
    {
        return [
            'elements' => {$mergedElements},
            'components' => {$componentsExport},
        ];
    }
PHP;

// v6 M3: registerChildren 已废弃，子组件通过 components 声明管理
$registerChildrenBody = '';

// v6 M3: Generate onMount/onUnmount
$lifecycleMethods = <<<PHP

    public function onMount(): void
    {
    }

    public function onUnmount(): void
    {
    }
PHP;

// v6 M3: 构造函数（registerChildren 已废弃）
$constructBody = "        parent::__construct(\$componentId ?? '{$baseName}');\n";

// Assemble class content using string concatenation for proper indentation control
$classContent = "<?php\n";
$classContent .= "/**\n";
$classContent .= " * AUTO-GENERATED by SFC Compiler v6 — DO NOT EDIT\n";
$classContent .= " * Source: $baseName.vue\n";
$classContent .= " *\n";
$classContent .= " * v6 M3: Component-based architecture with v-if support\n";
$classContent .= " */\n";
$classContent .= "\n";
$classContent .= "use native_types;\n";
$classContent .= "\n";
$classContent .= "class {$componentClassName} extends ReactiveComponent\n";
$classContent .= "{\n";
$classContent .= $classBody . "\n";
$classContent .= "\n";
$classContent .= "    /**\n";
$classContent .= "     * 获取组件布局数据\n";
$classContent .= "     * v6 M3: 返回 elements 和 components（子组件通过 v-if 声明）\n";
$classContent .= "     */\n";
$classContent .= "    public function getLayout(): array\n";
// v6 M3: getLayout 返回 elements + components，不再生成 registerChildren
$classContent .= $getLayoutBody . "\n";

$classContent .= "\n";
$classContent .= "    public function onMount(): void\n";
$classContent .= "    {\n";
$classContent .= "    }\n";
$classContent .= "\n";
$classContent .= "    public function onUnmount(): void\n";
$classContent .= "    {\n";
$classContent .= "    }\n";
$classContent .= "\n";
$classContent .= "    public function getBindValue(string \$bindKey): string\n";
$classContent .= "    {\n";
$classContent .= $getBindValueBody . "\n";
$classContent .= "    }\n";
$classContent .= "\n";
$classContent .= "    public function dispatchClick(array \$btn): void\n";
$classContent .= "    {\n";
$classContent .= $dispatchClickBody . "\n";
$classContent .= "    }\n";
$classContent .= "\n";
$classContent .= "    public function evalCondition(array \$cond): bool\n";
$classContent .= "    {\n";
$classContent .= $evalConditionBody . "\n";
$classContent .= "    }\n";
$classContent .= "\n";
$classContent .= "    public function __construct(?string \$componentId = null)\n";
$classContent .= "    {\n";
$classContent .= $constructBody . "\n";
$classContent .= "    }\n";
$classContent .= "}\n";

// ============================================================
// Step 6: AOT Validation
// ============================================================
$validator = new AotValidator();

$classPath = $outDir . DIRECTORY_SEPARATOR . $componentClassName . '.php';
$classOk = $validator->validate($classContent, $classPath);

echo "\n" . $validator->report();

if (!$classOk) {
    echo "\nAOT validation FAILED. Generated file NOT written.\n";
    echo "Fix the issues above and re-run the compiler.\n";
    exit(1);
}

// ---- Passed validation → write files ----
file_put_contents($classPath, $classContent);
echo "  Generated:  $classPath (" . strlen($classContent) . " bytes)\n";

// v6 M3: 子组件已在 Phase 1 (compileChildComponents) 中生成到 gen/
// 此处仅保留 childComponentInfo 用于根组件的 components 声明

// v6 M2: 如果是根组件，还需要确保 gen/ 目录有入口文件声明常量
$windowWidth = $app->width;
$windowHeight = $app->height;
$constantsPath = $outDir . DIRECTORY_SEPARATOR . 'constants.php';
$constantsContent = <<<PHP
<?php
/**
 * AUTO-GENERATED by SFC Compiler v6 — DO NOT EDIT
 * Window constants for AOT compilation
 */

const WINDOW_WIDTH  = {$windowWidth};
const WINDOW_HEIGHT = {$windowHeight};
PHP;

if ($isRootComponent) {
    file_put_contents($constantsPath, $constantsContent);
    echo "  Generated:  $constantsPath (" . strlen($constantsContent) . " bytes)\n";
}

// v6 M3: 扫描 gen/ 目录下所有组件类（Phase 1 已预编译所有子组件）
$genDir = $appDir . DIRECTORY_SEPARATOR . 'gen';
$allComponentClasses = [];

// 根组件（当前正在编译）
$allComponentClasses[$componentClassName] = true;

// 扫描 gen/ 目录下所有 *Component.php 文件
if (is_dir($genDir)) {
    $files = glob($genDir . '/*Component.php');
    foreach ($files as $file) {
        $fileName = basename($file, '.php');
        // 避免重复添加根组件自身
        if ($fileName !== $componentClassName) {
            $allComponentClasses[$fileName] = true;
        }
    }
}

// 生成工厂类
$factoryContent = <<<PHP
<?php

/**
 * ComponentFactory - 组件工厂类 (v6 M3)
 *
 * 由 SFC 编译器自动生成，使用 switch-case 创建组件实例（AOT 安全）。
 * 已扫描 gen/ 目录下所有组件类。
 */
class ComponentFactory
{
    /**
     * 通过类名创建组件实例 (AOT 安全：switch-case 替代动态 new)
     *
     * @param string \$className 组件类名
     * @param array \$props 组件属性
     * @return ComponentInterface
     */
    public static function create(string \$className, array \$props = []): ComponentInterface
    {
        switch (\$className) {
PHP;

// 添加所有组件的 case（使用 any() 丢弃类型推断）
foreach (array_keys($allComponentClasses) as $className) {
    $factoryContent .= "            case '$className':\n";
    $factoryContent .= "                \$comp = any(new $className());\n";
    $factoryContent .= "                if (method_exists(\$comp, 'setProps')) { \$comp->setProps(\$props); }\n";
    $factoryContent .= "                break;\n";
}

$factoryContent .= <<<PHP
            default:
                throw new \RuntimeException("Component not found: \$className");
        }

        return \$comp;
    }
}
PHP;

$factoryPath = $outDir . DIRECTORY_SEPARATOR . 'ComponentFactory.php';
file_put_contents($factoryPath, $factoryContent);
echo "  Generated:  $factoryPath (" . strlen($factoryContent) . " bytes)\n";

echo "\nDone.\n";