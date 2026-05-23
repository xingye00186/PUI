# AOT 兼容性全面检查工具 - 说明

## Context

用户提供了完整的 AOT 禁止规则，需要创建一个全面的静态检查工具来检测所有这些违规模式。

---

## AOT 禁止规则清单

| 规则 | 说明 | 检测模式 |
|------|------|----------|
| **禁止游离代码** | 所有代码必须在函数/方法内 | 检查顶层可执行语句 |
| **禁止 $$var** | 可变变量 | `'/\$\$\w+/'` |
| **禁止 extract()** | 变量注入 | `'/extract\s*\(/'` |
| **禁止 yield** | 生成器 | `'/yield\s+/'` |
| **禁止多层 break/continue** | 使用 goto 或 try/catch | 深度检测 |
| **禁止字符串包含 \0** | null 字节 | 检测 `"\0"` 或 `'\0'` |
| **禁止参数数量不匹配** | 必须严格匹配 | 函数签名检查（复杂） |
| **禁止 Property Hook** | 使用 getter/setter | `'/\{[^}]*get\s/{` |
| **禁止动态调用** | 变量函数 | `'/\$\w+\s*\(/'` |
| **禁止 eval/include** | 动态加载 | `'/\\b(eval\|include\|require)\s*\(/'` |
| **禁止未定义变量** | isset 前必须先定义 | 需上下文分析 |
| **禁止改变变量类型** | 类型不可变 | 需类型追踪（复杂） |
| **禁止 __get/__set** | 魔术方法 | `'/function\s+__(get\|set\|call)/'` |
| **禁止 any()** | 运行时不存在 | `'/\\bany\s*\(/'` |

---

## 任务 1：创建统一的 AOT 检查工具

### 新文件：`framework/aot-checker.php`

这是一个统一的 CLI 工具，整合 AOT Validator 和 AntiPatternChecker 的功能。

### 1.1 核心检测规则

```php
private array $rules = [
    // ========== 核心 AOT 禁止规则 ==========

    // 1. 可变变量 $$var
    'aot_dynamic_variable' => [
        'severity' => 'ERROR',
        'pattern' => '/\$\$\w+/',
        'message' => 'AOT: 可变变量 $$var（AOT 不支持）',
    ],

    // 2. 动态属性访问 ->$var (包括嵌套链)
    'aot_variable_property' => [
        'severity' => 'ERROR',
        'pattern' => '/->\$\w+/',
        'message' => 'AOT: 动态属性访问 ->$var（AOT 不支持）',
    ],

    // 3. 动态方法调用 ->$method()
    'aot_variable_method' => [
        'severity' => 'ERROR',
        'pattern' => '/->\$\w+\s*\(/',
        'message' => 'AOT: 动态方法调用 ->$method()（AOT 不支持）',
    ],

    // 4. 可变函数调用 $fn()
    'aot_variable_function' => [
        'severity' => 'ERROR',
        'pattern' => '/(?<![>\w])\$\w+\s*\(/',
        'message' => 'AOT: 可变函数调用 $fn()（AOT 不支持）',
    ],

    // 5. extract()
    'aot_extract' => [
        'severity' => 'ERROR',
        'pattern' => '/\bextract\s*\(/',
        'message' => 'AOT: extract() 动态变量注入（AOT 不支持）',
    ],

    // 6. yield 生成器
    'aot_yield' => [
        'severity' => 'ERROR',
        'pattern' => '/\byield\s+/',
        'message' => 'AOT: yield 生成器（AOT 不支持）',
    ],

    // 7. eval/include 动态加载
    'aot_eval_include' => [
        'severity' => 'ERROR',
        'pattern' => '/\b(eval|include|require|include_once|require_once)\s*\(/',
        'message' => 'AOT: eval/include 动态加载（AOT 不支持）',
    ],

    // 8. __get/__set 魔术方法
    'aot_magic_methods' => [
        'severity' => 'ERROR',
        'pattern' => '/function\s+__(get|set|call|__callStatic)\s*\(/',
        'message' => 'AOT: __get/__set 等魔术方法（AOT 中不可靠）',
    ],

    // 9. 注意: any() 是 AOT 内置函数，用于将变量类型标注为 php::Var，是可用的！
    // 不需要检测 any()，这是正确用法

    // 10. 字符串包含 \0
    'aot_null_byte' => [
        'severity' => 'ERROR',
        'pattern' => '/["\']\\[0]["\']]/',
        'message' => 'AOT: 字符串包含 \\0（AOT 不支持 null 字节）',
    ],

    // ========== 资源所有权规则 ==========

    'hwnd_in_app' => [
        'severity' => 'ERROR',
        'pattern' => '/class\s+Application\b[\s\S]*?\{[\s\S]*?private\s+int\s+\$hWnd/s',
        'message' => 'Application 不应持有 hWnd 属性',
    ],

    'hwnd_in_renderer' => [
        'severity' => 'ERROR',
        'pattern' => '/class\s+BaseRenderer\b[\s\S]*?\{[\s\S]*?private\s+int\s+\$hWnd/s',
        'message' => 'BaseRenderer 不应持有 hWnd 属性',
    ],

    'hdc_in_renderer' => [
        'severity' => 'ERROR',
        'pattern' => '/class\s+BaseRenderer\b[\s\S]*?\{[\s\S]*?private\s+int\s+\$hdc/s',
        'message' => 'BaseRenderer 不应持有 hdc 属性',
    ],

    'hdc_param_in_methods' => [
        'severity' => 'WARN',
        'pattern' => '/(fillRect|drawText|drawButton)\s*\(\s*[^)]*?\$hdc[^)]*?\)/',
        'message' => '绘制方法不应接收 hdc 参数',
    ],

    'direct_cpp_call' => [
        'severity' => 'ERROR',
        'pattern' => '/vue_(window_create|window_show|begin_paint|end_paint|fill_rect|draw_text|draw_button)/',
        'message' => '业务代码不应直接调用 vue_* C++ 函数',
    ],
];
```

### 1.2 CLI 使用方式

```bash
# 扫描指定目录
php framework/aot-checker.php framework/

# 扫描项目（基于 project.yml）
php framework/aot-checker.php --project apps/calculator

# 扫描所有项目
php framework/aot-checker.php --all

# 只显示错误
php framework/aot-checker.php --level error framework/

# 输出 JSON 格式（用于 CI）
php framework/aot-checker.php --json framework/
```

### 1.3 输出示例

```
========================================
  AOT Checker - AOT 兼容性检查工具
========================================

Scanning: framework/Application.php
Scanning: framework/ReactiveComponent.php
...

[ERROR] framework/Application.php:120
  Rule: aot_variable_method
  Code: $this->rootComponent->$handler($wParam)
  Message: AOT: 动态方法调用 ->$method()（AOT 不支持）

[WARN] framework/AntiPatternChecker.php:48
  Rule: hdc_param_in_methods
  Code: drawButton(..., $hdc, ...)
  Message: 绘制方法不应接收 hdc 参数

Found 2 errors, 1 warning
```

---

## 任务 2：project.yml 解析支持

### 2.1 parseProjectYml() 方法

```php
/**
 * 解析 project.yml 并返回所有源文件路径
 */
public function scanProject(string $projectDir): array
{
    $ymlPath = $projectDir . '/project.yml';
    if (!file_exists($ymlPath)) {
        throw new \RuntimeException("project.yml not found: $ymlPath");
    }

    $content = file_get_contents($ymlPath);
    // 简单 YAML 解析（支持 sources 列表）
    $sources = $this->extractSources($content);

    $files = [];
    foreach ($sources as $source) {
        $fullPath = $this->resolvePath($source, $projectDir);
        if (is_dir($fullPath)) {
            $files = array_merge($files, $this->collectPhpFiles($fullPath));
        } else {
            $files[] = $fullPath;
        }
    }

    return $files;
}

private function resolvePath(string $source, string $projectDir): string
{
    if (strpos($source, '../') === 0) {
        // 相对路径：../../framework/ → 相对于项目目录
        return $projectDir . '/' . $source;
    }
    if ($source === './gen') {
        // 相对于项目目录
        return $projectDir . '/gen';
    }
    // 其他路径
    return $source;
}
```

---

## 任务 3：集成到构建流程

### 修改的文件

| 文件 | 操作 | 说明 |
|------|------|------|
| `framework/aot-checker.php` | 新建 | 统一的 AOT 检查工具 |
| `main_build.bat` | 修改 | 在 SFC 编译前运行检查 |

### main_build.bat 集成位置

在 Step 0（确认 MSVC 编译器）之后、Step 1（SFC 编译）之前添加：

```bat
:: ====================================================================
:: Step 0.5: AOT 静态检查
:: ====================================================================
echo ========================================
echo   Step 0.5: AOT 静态检查
echo ========================================
echo.

cd /d "%FRAMEWORK_ROOT%"
"%PHP_CLI%" framework\aot-checker.php --project "%APP_DIR%"
set "CHECK_EXIT=!errorlevel!"
if !CHECK_EXIT! neq 0 (
    echo.
    echo [错误] AOT Checker 发现问题，中止构建
    echo   请修复上述错误后重新构建
    goto :choose
)
echo   [完成] AOT 静态检查通过
echo.
```

### 完整构建流程

```
Step 0: 确认 MSVC 编译器
Step 0.5: AOT 静态检查 (新增)
Step 1: SFC 编译 (Vue -> Component.php)
Step 2: AOT 编译 (PHP -> exe)
Step 3: 打包 (exe + DLLs -> bin/)
```

---

## 验证计划

1. **运行检查工具**：
   ```bash
   php framework/aot-checker.php framework/
   php framework/aot-checker.php --project apps/calculator
   ```

2. **检查输出**：
   - 应能检测到 `$this->rootComponent->$handler()` 等问题
   - 应能检测到 `__get/__set` 魔术方法
   - 应能检测到 `$$var` 可变变量
   - 应能解析 project.yml 并扫描所有源文件
   - **注意**：any() 是 AOT 内置函数，不应被检测为问题

3. **集成测试**：
   - 运行 `build.bat` 验证检查被正确调用

---

## 风险评估

| 风险 | 等级 | 说明 |
|------|------|------|
| 误报 | 低 | 正则针对明确的问题模式 |
| 性能 | 低 | 对少量文件影响有限 |
| 构建时间增加 | 低 | 检查通常在 1 秒内完成 |