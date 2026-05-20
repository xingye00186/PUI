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
 *   - 主组件生成 getBaseComponents() 方法返回初始组件树
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

// ============================================================
// v5 M2: Helper — load component registry from project.yml
// ============================================================
function loadComponentRegistry(string $vueFile): ComponentRegistry
{
    $registry = new ComponentRegistry();
    $appDir = dirname(realpath($vueFile));
    $ymlFile = $appDir . DIRECTORY_SEPARATOR . 'project.yml';

    if (!file_exists($ymlFile)) {
        return $registry;
    }

    $yml = file_get_contents($ymlFile);
    if (preg_match('/^components:\s*$/m', $yml)) {
        if (preg_match_all('/^  (\S+):\s*(.+)$/m', $yml, $matches, PREG_SET_ORDER)) {
            $inComponents = false;
            $config = [];
            foreach (explode("\n", $yml) as $line) {
                if (trim($line) === 'components:') {
                    $inComponents = true;
                    continue;
                }
                if ($inComponents) {
                    if ($line === '' || (strlen($line) > 0 && $line[0] !== ' ' && $line[0] !== "\t")) {
                        if (strlen(trim($line)) > 0 && strpos($line, ':') !== false && $line[0] !== ' ') {
                            $inComponents = false;
                            continue;
                        }
                        if (strlen(trim($line)) === 0) {
                            continue;
                        }
                        if ($line[0] !== ' ') {
                            $inComponents = false;
                            continue;
                        }
                    }
                    if (preg_match('/^\s+(\S+):\s*(.+)$/', $line, $m)) {
                        $config[$m[1]] = trim($m[2]);
                    }
                }
            }
            if (count($config) > 0) {
                $warnings = $registry->load($config, $appDir);
                foreach ($warnings as $w) {
                    echo "  [WARN] ComponentRegistry: $w\n";
                }
            }
        }
    }

    return $registry;
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
            $childComponents[] = [
                'tagName' => $child->tagName,
                'componentClass' => $childComponentName,
                'offsetX' => $offsetX,
                'offsetY' => $offsetY,
                'isOverlay' => $child->isOverlay,
                'vIf' => $child->vIf,
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
$baseName = pathinfo($vueFile, PATHINFO_FILENAME);

// Output to gen/ directory relative to the .vue file
$appDir = dirname(realpath($vueFile));
$outDir = $appDir . DIRECTORY_SEPARATOR . 'gen';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

// v5 M2: Load component registry from app's project.yml
$componentRegistry = loadComponentRegistry($vueFile);
$componentNames = array_keys($componentRegistry->all());
$isRootComponent = (strtolower($baseName) === 'app' || strtolower($baseName) === 'appcomponent');

if (count($componentNames) > 0) {
    echo "SFC Compiler v6: $vueFile (components: " . implode(', ', $componentNames) . ")\n";
} else {
    echo "SFC Compiler v6: $vueFile\n";
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
$getBindValueBody = '';
if (count($bindKeys) > 0) {
    foreach ($bindKeys as $key) {
        $getBindValueBody .= "        if (\$bindKey === '$key') {\n";
        $getBindValueBody .= "            return \$this->$key;\n";
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

// v6 M2: Generate getLayout() body
$elementsExport = varExportShort($elements);
$buttonsExport = varExportShort($buttons);
$getLayoutBody = <<<PHP
    {
        return [
            'elements' => {$elementsExport},
            'buttons'  => {$buttonsExport},
        ];
    }
PHP;

// v6 M2: Generate registerChildren() for root component
// 使用 addChild($component, $props) 直接传入 props，避免变量类型问题
$registerChildrenBody = '';
if ($isRootComponent && count($childComponentInfo) > 0) {
    $lines = [];
    foreach ($childComponentInfo as $child) {
        $className = $child['componentClass'];
        $id = $child['tagName'];
        $propsX = $child['offsetX'];
        $propsY = $child['offsetY'];
        $lines[] = "        \$this->addChild(new {$className}('{$id}'), ['x' => {$propsX}, 'y' => {$propsY}]);";
    }
    $registerChildrenBody = implode("\n", $lines);
}

// v6 M2: Generate getBaseComponents() for root component
$getBaseComponentsBody = '';
if ($isRootComponent) {
    $getBaseComponentsBody = <<<PHP

    public function getBaseComponents(): array
    {
        \$result = [\$this];
        \$this->collectDescendantsRecursive(\$this, \$result);
        return \$result;
    }

    private function collectDescendantsRecursive(ComponentInterface \$comp, array &\$result): void
    {
        \$children = \$comp->getChildren();
        \$childIds = array_keys(\$children);
        \$count = count(\$childIds);
        for (\$i = 0; \$i < \$count; \$i++) {
            \$child = \$children[\$childIds[\$i]];
            \$result[] = \$child;
            \$this->collectDescendantsRecursive(\$child, \$result);
        }
    }
PHP;
}

// v6 M2: Generate onAttach/onDetach
$lifecycleMethods = <<<PHP

    public function onAttach(): void
    {
    }

    public function onDetach(): void
    {
    }
PHP;

// v6 M2: 生成构造函数体
$constructBody = "        parent::__construct(\$componentId ?? '{$baseName}');\n";
if ($isRootComponent) {
    $constructBody .= "        \$this->registerChildren();";
}

// Assemble class content using string concatenation for proper indentation control
$classContent = "<?php\n";
$classContent .= "/**\n";
$classContent .= " * AUTO-GENERATED by SFC Compiler v6 — DO NOT EDIT\n";
$classContent .= " * Source: $baseName.vue\n";
$classContent .= " *\n";
$classContent .= " * v6 M2: Component-based architecture\n";
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
$classContent .= "     * v6 M2: 返回自身的 elements 和 buttons（不含子组件）\n";
$classContent .= "     */\n";
$classContent .= "    public function getLayout(): array\n";
$classContent .= $getLayoutBody . "\n";

// Add registerChildren method for root component
if ($isRootComponent && $registerChildrenBody !== '') {
    $classContent .= "\n";
    $classContent .= "    /**\n";
    $classContent .= "     * 初始化时注册子组件\n";
    $classContent .= "     * v6 M2: 由编译器生成\n";
    $classContent .= "     */\n";
    $classContent .= "    private function registerChildren(): void\n";
    $classContent .= "    {\n";
    $classContent .= $registerChildrenBody . "\n";
    $classContent .= "    }\n";
}

// Add getBaseComponents method for root component
if ($isRootComponent) {
    $classContent .= "\n";
    $classContent .= $getBaseComponentsBody . "\n";
}

$classContent .= "\n";
$classContent .= "    public function onAttach(): void\n";
$classContent .= "    {\n";
$classContent .= "    }\n";
$classContent .= "\n";
$classContent .= "    public function onDetach(): void\n";
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

// v6 M2: 如果是根组件，需要生成子组件文件
if ($isRootComponent && count($childComponentInfo) > 0) {
    echo "\n--- Generating child component files ---\n";

    foreach ($childComponentInfo as $child) {
        $childTag = $child['tagName'];
        $childFile = $componentRegistry->all()[$childTag] ?? '';

        if ($childFile === '' || !file_exists($childFile)) {
            echo "  [SKIP] $childTag: source file not found\n";
            continue;
        }

        $childSource = file_get_contents($childFile);
        $childBaseName = pathinfo($childFile, PATHINFO_FILENAME);
        $childClassName = $child['componentClass'];

        // 提取子组件的 template 和 style
        $childTemplate = '';
        $childScript = '';
        $childStyles = '';
        if (preg_match('#<template[^>]*>(.*?)</template>#s', $childSource, $m)) {
            $childTemplate = $m[1];
        }
        if (preg_match('#<script[^>]*lang=["\']php["\'][^>]*>(.*?)</script>#s', $childSource, $m)) {
            $childScript = trim($m[1]);
        }
        if (preg_match('#<style[^>]*>(.*?)</style>#s', $childSource, $m)) {
            $childStyles = $m[1];
        }

        // 解析子组件的样式
        $childStyleWarnings = [];
        $childClassStyles = CssMappings::parseStyleBlock($childStyles, $childStyleWarnings);

        // 解析子组件的模板
        $childParser = new TemplateParser(new ComponentRegistry());
        $childApp = $childParser->parse($childTemplate);

        // 转换为布局数据
        $childLayout = $childParser->lowerToLayout($childApp, $childClassStyles);
        $childElements = $childLayout['elements'];
        $childButtons = $childLayout['buttons'];
        $childBindKeys = $childLayout['bindKeys'] ?? [];
        $childHandlerMap = $childLayout['handlerMap'] ?? [];
        $childCondProps = $childLayout['condProps'] ?? [];

        // 分析子组件的 script
        $childAnalyzer = new ScriptAnalyzer();
        $childClassBody = $childAnalyzer->injectDirty($childScript);

        // 生成子组件的 getBindValue
        $childGetBindValue = '';
        if (count($childBindKeys) > 0) {
            foreach ($childBindKeys as $key) {
                $childGetBindValue .= "        if (\$bindKey === '$key') {\n";
                $childGetBindValue .= "            return \$this->$key;\n";
                $childGetBindValue .= "        }\n";
            }
            $childGetBindValue .= "        return '';";
        } else {
            $childGetBindValue = "        return '';";
        }

        // 生成子组件的 dispatchClick
        $childDispatchClick = '';
        if (count($childHandlerMap) > 0) {
            $childDispatchClick .= "        \$handler = \$btn['handler'];\n";
            $first = true;
            foreach ($childHandlerMap as $handler => $hasArg) {
                $prefix = $first ? 'if' : 'elseif';
                $first = false;
                if ($hasArg) {
                    $childDispatchClick .= "        {$prefix} (\$handler === '$handler') {\n";
                    $childDispatchClick .= "            \$this->$handler(\$btn['arg']);\n";
                    $childDispatchClick .= "        }\n";
                } else {
                    $childDispatchClick .= "        {$prefix} (\$handler === '$handler') {\n";
                    $childDispatchClick .= "            \$this->$handler();\n";
                    $childDispatchClick .= "        }\n";
                }
            }
        } else {
            $childDispatchClick = "        // No handlers defined";
        }

        // 生成子组件的 evalCondition
        $childEvalCondition = '';
        if (count($childCondProps) > 0) {
            $childEvalCondition .= "        \$prop = \$cond['prop'];\n";
            $childEvalCondition .= "        \$op = \$cond['op'];\n";
            $childEvalCondition .= "        if (\$op === 'truthy') {\n";
            foreach ($childCondProps as $prop) {
                $childEvalCondition .= "            if (\$prop === '$prop') return \$this->$prop ? true : false;\n";
            }
            $childEvalCondition .= "            return false;\n";
            $childEvalCondition .= "        }\n";
            $childEvalCondition .= "        if (\$op === 'falsy') {\n";
            foreach ($childCondProps as $prop) {
                $childEvalCondition .= "            if (\$prop === '$prop') return \$this->$prop ? false : true;\n";
            }
            $childEvalCondition .= "            return false;\n";
            $childEvalCondition .= "        }\n";
            $childEvalCondition .= "        if (\$op === '==') {\n";
            $childEvalCondition .= "            \$value = \$cond['value'];\n";
            foreach ($childCondProps as $prop) {
                $childEvalCondition .= "            if (\$prop === '$prop') return \$this->$prop === \$value;\n";
            }
            $childEvalCondition .= "            return false;\n";
            $childEvalCondition .= "        }\n";
            $childEvalCondition .= "        if (\$op === '!=') {\n";
            $childEvalCondition .= "            \$value = \$cond['value'];\n";
            foreach ($childCondProps as $prop) {
                $childEvalCondition .= "            if (\$prop === '$prop') return \$this->$prop !== \$value;\n";
            }
            $childEvalCondition .= "            return false;\n";
            $childEvalCondition .= "        }\n";
            $childEvalCondition .= "        return false;";
        } else {
            $childEvalCondition = "        return true; // no conditions defined";
        }

        // 生成子组件的 getLayout
        $childElementsExport = varExportShort($childElements);
        $childButtonsExport = varExportShort($childButtons);

        // 组装子组件类
        $childClassContent = <<<PHP
<?php
/**
 * AUTO-GENERATED by SFC Compiler v6 — DO NOT EDIT
 * Source: $childBaseName.vue
 *
 * v6 M2: Child component
 */

use native_types;

class {$childClassName} extends ReactiveComponent
{
$childClassBody

    /**
     * 获取组件布局数据
     */
    public function getLayout(): array
    {
        return [
            'elements' => {$childElementsExport},
            'buttons'  => {$childButtonsExport},
        ];
    }

    public function onAttach(): void
    {
    }

    public function onDetach(): void
    {
    }

    public function getBindValue(string \$bindKey): string
    {
$childGetBindValue
    }

    public function dispatchClick(array \$btn): void
    {
$childDispatchClick
    }

    public function evalCondition(array \$cond): bool
    {
$childEvalCondition
    }

    public function __construct(?string \$componentId = null)
    {
        parent::__construct(\$componentId ?? '{$childBaseName}');
    }
}
PHP;

        // 验证并写入子组件文件
        $childClassPath = $outDir . DIRECTORY_SEPARATOR . $childClassName . '.php';
        $childClassOk = $validator->validate($childClassContent, $childClassPath);

        if ($childClassOk) {
            file_put_contents($childClassPath, $childClassContent);
            echo "  Generated:  $childClassPath (" . strlen($childClassContent) . " bytes)\n";
        } else {
            echo "  [ERROR] $childClassName: AOT validation failed\n";
        }
    }
}

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

echo "\nDone.\n";