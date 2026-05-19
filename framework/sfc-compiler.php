<?php
/**
 * SFC Compiler v5 — Vue-like Single File Component compiler for AOT desktop apps
 * 
 * Usage: php framework/sfc-compiler.php apps/calculator/Calculator.vue [--dump-ast]
 * 
 * Architecture (v5 M2):
 *   1. Block Extraction:     template / script / style from .vue
 *   2. Style Parsing:        CSS class → GDI properties (via CssMappings)
 *   3. Template Parsing:     recursive descent → AST  (via TemplateParser + ComponentRegistry)
 *   4. Component Resolution: resolve <child-comp> refs, inline child layouts, apply offsets
 *   5. AST → Layout Arrays:  compile-time coordinate calculation + bindKeys
 *   6. AOT Validation:       check generated code before write (via AotValidator)
 *   7. Code Generation:      .gen.php ×2 output + auto dirty + auto getBindValue
 * 
 * This tool runs OUTSIDE the AOT pipeline (standard PHP CLI).
 * Generated .gen.php files are consumed by the AOT compiler.
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
    // Simple YAML parsing for the "components:" section
    // Looks for:
    //   components:
    //     tag-name: ./path/to/Component.vue
    if (preg_match('/^components:\s*$/m', $yml)) {
        // Extract indented lines after "components:"
        if (preg_match_all('/^  (\S+):\s*(.+)$/m', $yml, $matches, PREG_SET_ORDER)) {
            $inComponents = false;
            $config = [];
            foreach (explode("\n", $yml) as $line) {
                if (trim($line) === 'components:') {
                    $inComponents = true;
                    continue;
                }
                if ($inComponents) {
                    // Stop at non-indented or empty section
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
// v5 M2: Helper — resolve component references in AST (1 level)
// ============================================================
function resolveComponentRefs(AppNode $app, array &$classStyles, int $depth = 0): array
{
    $warnings = [];
    $resolvedChildren = [];
    $nextOverlayLayer = 1;  // v5 M3: overlay layer counter

    foreach ($app->children as $child) {
        if ($child instanceof ComponentRefNode) {
            if ($depth >= 1) {
                $warnings[] = "Line {$child->line}: Nested component <{$child->tagName}> exceeds maximum depth (1 level). Skipping.";
                continue;
            }

            // Read child .vue file
            $childSource = @file_get_contents($child->componentFile);
            if ($childSource === false) {
                $warnings[] = "Line {$child->line}: Cannot read component file: {$child->componentFile}";
                continue;
            }

            // Extract blocks from child .vue
            $childTemplate = '';
            $childStyles = '';
            if (preg_match('#<template[^>]*>(.*?)</template>#s', $childSource, $m)) {
                $childTemplate = $m[1];
            }
            if (preg_match('#<style[^>]*>(.*?)</style>#s', $childSource, $m)) {
                $childStyles = $m[1];
            }

            if ($childTemplate === '') {
                $warnings[] = "Line {$child->line}: Component <{$child->tagName}> has no <template> block";
                continue;
            }

            // Parse child styles → merge into parent classStyles
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

            // Parse child template (no component registry — nesting > 1 rejected)
            $childParser = new TemplateParser();
            $childAst = $childParser->parse($childTemplate);

            // Check for grandchild components (would be depth 2 — reject)
            foreach ($childAst->children as $grandchild) {
                if ($grandchild instanceof ComponentRefNode) {
                    $warnings[] = "Line {$child->line}: Component <{$child->tagName}> contains nested component <{$grandchild->tagName}>. v5 only supports 1 level of nesting.";
                }
            }

            // Apply coordinate offset and prop bindings to each child node
            $offsetX = (int)($child->props['x'] ?? 0);
            $offsetY = (int)($child->props['y'] ?? 0);

            foreach ($childAst->children as $childNode) {
                applyOffset($childNode, $offsetX, $offsetY);
                applyPropBindings($childNode, $child->props);
                // v5 M3: propagate v-if from component reference to inlined children
                if ($child->vIf !== '' && $childNode->vIf === '') {
                    $childNode->vIf = $child->vIf;
                }
                // v5 M3: 分配 overlay layer
                if ($child->isOverlay) {
                    $childNode->layer = $nextOverlayLayer;
                }
                // v5 M4: 标记组件边界 (group_id = 子组件 tagName)
                $childNode->groupId = $child->tagName;
                $resolvedChildren[] = $childNode;
            }
            // v5 M3: overlay component's children all processed, increment layer counter
            if ($child->isOverlay) {
                $nextOverlayLayer++;
            }
        } else {
            $resolvedChildren[] = $child;
        }
    }

    $app->children = $resolvedChildren;
    return $warnings;
}

// ============================================================
// v6 M1: Helper — group_id to camelCase function name suffix
// ============================================================
/** var_export 短数组语法版本: array() -> [] */
function varExportShort(array $data): string
{
    $export = var_export($data, true);
    $export = preg_replace('/array\s*\(/', '[', $export);
    $export = preg_replace('/\)(,?)$/m', ']$1', $export);
    return $export;
}

function groupIdToCamel(string $gid): string
{
    // num-pad -> numPad, display-panel -> displayPanel, about-dialog -> aboutDialog, app -> app
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
if (count($componentNames) > 0) {
    echo "SFC Compiler v5: $vueFile (components: " . implode(', ', $componentNames) . ")\n";
} else {
    echo "SFC Compiler v5: $vueFile\n";
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
// style block is optional — not an error if missing

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

// Report parse errors
if (count($parseErrors) > 0) {
    echo "\n=== Template Parse Errors (" . count($parseErrors) . ") ===\n";
    foreach ($parseErrors as $err) {
        echo "  $err\n";
    }
    echo "========================================\n\n";
}

// --dump-ast mode
if ($dumpAst) {
    echo "\n=== AST Dump ===\n";
    echo $parser->dumpAst($app);
    echo "\n=== End AST ===\n\n";
}

// ============================================================
// v5 M2: Step 4 — Resolve component references (inline child layouts)
// ============================================================
$componentWarnings = resolveComponentRefs($app, $classStyles);
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
$bindKeys = $layout['bindKeys'] ?? [];       // v4 M2.2
$handlerMap = $layout['handlerMap'] ?? [];  // v4 M2.3
$condProps = $layout['condProps'] ?? [];    // v4 M2.5

echo "  Elements: " . count($elements) . " (rects + texts)\n";
echo "  Buttons:  " . count($buttons) . "\n";
echo "  BindKeys: " . count($bindKeys) . "\n";
echo "  Handlers: " . count($handlerMap) . "\n";
echo "  CondProps: " . count($condProps) . "\n";

// ============================================================
// Step 6: Generate output files
// ============================================================

// v6 M1: 按 group_id 分组
$groupedElements = [];
$groupedButtons = [];
foreach ($elements as $el) {
    $gid = $el['group_id'] ?? 'app';
    $groupedElements[$gid][] = $el;
}
foreach ($buttons as $btn) {
    $gid = $btn['group_id'] ?? 'app';
    $groupedButtons[$gid][] = $btn;
}

// 收集所有 group 名称（保持顺序）
$allGroups = array_unique(array_merge(array_keys($groupedElements), array_keys($groupedButtons)));

// --- Generate Layout_gen.php ---
$appWidth  = $app->width;
$appHeight = $app->height;

// 生成分段布局函数
$segmentFunctions = '';
$segmentFnNames = [];
foreach ($allGroups as $gid) {
    $fnSuffix = groupIdToCamel($gid);
    $fnName = "getLayout_{$fnSuffix}";
    $segmentFnNames[] = $fnName;

    $segElements = $groupedElements[$gid] ?? [];
    $segButtons = $groupedButtons[$gid] ?? [];
    $segElementsExport = varExportShort($segElements);
    $segButtonsExport = varExportShort($segButtons);

    $segmentFunctions .= <<<PHP

function {$fnName}(): array
{
    return [
        'elements' => {$segElementsExport},
        'buttons'  => {$segButtonsExport},
    ];
}
PHP;
    $segmentFunctions .= "\n";
}

// 生成分发函数 (AOT 兼容: if/else 显式调用, 不使用变量函数)
// AOT 嵌套数组类型保留: 使用 isset() 替代 ?? 操作符,避免 C++ 编译时类型推断错误
$dispatchCases = '';
foreach ($allGroups as $gid) {
    $fnSuffix = groupIdToCamel($gid);
    $fnName = "getLayout_{$fnSuffix}";
    $dispatchCases .= "    if (\$name === '{$gid}') {\n";
    $dispatchCases .= "        return {$fnName}();\n";
    $dispatchCases .= "    }\n";
}
$callLayoutSegment = <<<PHP

function callLayoutSegment(string \$name): array
{
{$dispatchCases}    return ['elements' => [], 'buttons' => []];
}
PHP;

// 生成布局段名称列表函数
$segNamesExport = varExportShort(array_values($allGroups));
$getSegmentNames = <<<PHP

function getLayoutSegmentNames(): array
{
    return {$segNamesExport};
}
PHP;

$layoutContent = <<<PHP
<?php
/**
 * AUTO-GENERATED by SFC Compiler v6 — DO NOT EDIT
 * Source: {$baseName}.vue
 */

const WINDOW_WIDTH  = {$appWidth};
const WINDOW_HEIGHT = {$appHeight};
{$segmentFunctions}
{$callLayoutSegment}
{$getSegmentNames}
PHP;

// --- Generate Calculator.gen.php (class file) ---
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
    // truthy check for each prop
    $evalConditionBody .= "        if (\$op === 'truthy') {\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop ? true : false;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    // falsy check (negation) for each prop — v5 M3
    $evalConditionBody .= "        if (\$op === 'falsy') {\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop ? false : true;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    // == comparison for each prop
    $evalConditionBody .= "        if (\$op === '==') {\n";
    $evalConditionBody .= "            \$value = \$cond['value'];\n";
    foreach ($condProps as $prop) {
        $evalConditionBody .= "            if (\$prop === '$prop') return \$this->$prop === \$value;\n";
    }
    $evalConditionBody .= "            return false;\n";
    $evalConditionBody .= "        }\n";
    // != comparison
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

$classContent = <<<PHP
<?php
/**
 * AUTO-GENERATED by SFC Compiler v5 — DO NOT EDIT
 * Source: $baseName.vue
 */

use native_types;

class $baseName extends ReactiveComponent
{
$classBody

    public function getBindValue(string \$bindKey): string
    {
$getBindValueBody
    }

    public function dispatchClick(array \$btn): void
    {
$dispatchClickBody
    }

    public function evalCondition(array \$cond): bool
    {
$evalConditionBody
    }

    public function __construct(string \$componentId = '$baseName')
    {
        parent::__construct(\$componentId);
    }
}
PHP;

// ============================================================
// Step 7: AOT Validation (before writing to disk)
// ============================================================
$validator = new AotValidator();

$layoutPath = $outDir . DIRECTORY_SEPARATOR . $baseName . 'Layout_gen.php';
$classPath  = $outDir . DIRECTORY_SEPARATOR . $baseName . '.gen.php';

$layoutOk = $validator->validate($layoutContent, $layoutPath);
$classOk  = $validator->validate($classContent, $classPath);

echo "\n" . $validator->report();

if (!$layoutOk || !$classOk) {
    echo "\nAOT validation FAILED. Generated files NOT written.\n";
    echo "Fix the issues above and re-run the compiler.\n";
    exit(1);
}

// ---- Passed validation → write files ----
file_put_contents($layoutPath, $layoutContent);
echo "  Generated:  $layoutPath (" . strlen($layoutContent) . " bytes)\n";

file_put_contents($classPath, $classContent);
echo "  Generated:  $classPath (" . strlen($classContent) . " bytes)\n";

echo "\nDone.\n";
