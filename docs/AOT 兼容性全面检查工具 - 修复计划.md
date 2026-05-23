# hWnd 所有权重构与 Application 迁移计划

## Context

当前 `BaseRenderer` 和 `Application` 类各自持有 `hWnd` 窗口句柄，造成职责不清。根据单一职责原则，hWnd 作为渲染上下文的固有属性，应该由 `GdiRenderContext` 统一持有。同时，`Application` 作为通用应用控制器，应移入 `framework` 目录，并增加反模式检查。

---

## 当前架构问题

```
Application ($hWnd)
  └─ BaseRenderer ($hWnd) → RenderContext → GdiRenderContext
       └─ ctx.beginFrame($hWnd), ctx.endFrame($hWnd, $hdc)
```

问题:
- `Application` 和 `BaseRenderer` 重复持有 hWnd
- hWnd 作为临时参数传递，而非内部状态
- Application 职责混杂（窗口管理 + 组件协调）

---

## 目标架构

```
main.php
  ├─ vue_window_create() → $hWnd
  ├─ new GdiRenderContext($hWnd)  ← hWnd 转移至此
  └─ new Application($root, $ctx)
       └─ BaseRenderer($component, $ctx)  ← 无 hWnd
            └─ $ctx->beginFrame(), $ctx->endFrame()  ← 内部使用 hWnd
```

---

## 变更范围

### 1. RenderContext 基类 (framework/rendering/RenderContext.php)

```php
abstract class RenderContext
{
    abstract public function beginFrame(): int;  // 移除 hWnd 参数
    abstract public function endFrame(int $hdc): void;  // 移除 hWnd 参数
    // ... 其余方法不变
}
```

### 2. GdiRenderContext 实现 (framework/rendering/GdiRenderContext.php)

```php
class GdiRenderContext extends RenderContext
{
    private int $hWnd;  // 新增

    public function __construct(int $hWnd)
    {
        $this->hWnd = $hWnd;
    }

    public function beginFrame(): int  // 无参数
    {
        return vue_begin_paint($this->hWnd);
    }

    public function endFrame(int $hdc): void  // 无 hWnd 参数
    {
        vue_end_paint($this->hWnd, $hdc);
    }
    // ... 其余方法不变
}
```

### 3. BaseRenderer (framework/BaseRenderer.php)

```php
class BaseRenderer
{
    // private int $hWnd;  // 删除
    private ReactiveComponent $component;
    private RenderContext $ctx;

    public function __construct(ReactiveComponent $component, RenderContext $ctx)
    {
        // $this->hWnd = $hWnd;  // 删除
        $this->component = $component;
        $this->ctx = $ctx;
    }

    public function render(array $layout): void
    {
        $dirtyInfo = $this->component->consumeDirty();
        $hdc = $this->ctx->beginFrame();  // 无参数
        // ...
        $this->ctx->endFrame($hdc);  // 无 hWnd 参数
    }
}
```

### 4. Application 迁移 (apps/calculator → framework)

**移动**: `apps/calculator/Application.php` → `framework/Application.php`

变更:
- 删除 `$this->hWnd` 属性
- `initWindow()` 返回 `int $hWnd`（由调用方使用）
- `initWindow()` 内部创建 `BaseRenderer($component, $ctx)` 不传 hWnd

### 5. main.php 调整 (apps/calculator/main.php)

```php
function main(): int
{
    // 1. 创建根组件
    $root = new AppComponent('App');
    $root->initShared(10240);

    // 2. 初始化窗口，获取 hWnd
    $hWnd = vue_window_create('VueCalc', WINDOW_WIDTH, WINDOW_HEIGHT);
    if ($hWnd == 0) {
        return 1;
    }
    vue_window_show($hWnd, SW_SHOW);

    // 3. 创建渲染上下文（持有 hWnd）
    $ctx = new GdiRenderContext($hWnd);

    // 4. 创建应用控制器（无 hWnd）
    $app = new Application($root, $ctx);

    // 5. 启动事件循环
    $app->run();

    return 0;
}
```

### 6. AntiPatternChecker (framework/AntiPatternChecker.php) — 新增

检查项:

| 检查项 ID | 说明 | 严重度 |
|-----------|------|--------|
| `hwnd_in_app` | Application 不应持有 hWnd 属性 | ERROR |
| `hwnd_in_renderer` | BaseRenderer 不应持有 hWnd 属性 | ERROR |
| `hwnd_not_in_render_ctx` | GdiRenderContext 必须持有 hWnd | ERROR |
| `hwnd_param_in_methods` | beginFrame/endFrame 不应接收 hWnd 参数 | WARN |
| `direct_cpp_call` | 业务代码（除 GdiRenderContext）不应直接调用 vue_* 函数 | ERROR |

---

## 文件变更清单

| 操作 | 文件路径 | 说明 |
|------|----------|------|
| 修改 | `framework/rendering/RenderContext.php` | 移除 beginFrame/endFrame 的 hWnd 参数 |
| 修改 | `framework/rendering/GdiRenderContext.php` | 新增构造函数和 $hWnd 属性 |
| 修改 | `framework/BaseRenderer.php` | 删除 hWnd 属性和参数 |
| 移动+修改 | `apps/calculator/Application.php` → `framework/Application.php` | 删除 hWnd，initWindow() 返回 hWnd |
| 修改 | `apps/calculator/main.php` | 调整初始化顺序 |
| 新增 | `framework/AntiPatternChecker.php` | 反模式检查工具 |

---

## 实施步骤

### Step 1: 修改 RenderContext 基类
- `beginFrame(): int` - 移除 `(int $hWnd)` 参数
- `endFrame(int $hdc): void` - 移除 `int $hWnd` 参数

### Step 2: 修改 GdiRenderContext 实现
- 新增 `private int $hWnd` 属性
- 新增 `__construct(int $hWnd)` 构造函数
- 修改 `beginFrame()` 和 `endFrame()` 方法体（使用 `$this->hWnd`）

### Step 3: 修改 BaseRenderer
- 删除 `private int $hWnd` 属性
- `__construct()` 移除 `int $hWnd` 参数
- `render()` 内部调用 `$this->ctx->beginFrame()` 无参数，`$this->ctx->endFrame($hdc)` 无 hWnd

### Step 4: 迁移 Application 到 framework
- 复制 `apps/calculator/Application.php` → `framework/Application.php`
- 删除 `$this->hWnd` 属性
- `initWindow()` 返回 `int $hWnd`（调用方负责）
- `initWindow()` 创建 `BaseRenderer` 时不传 hWnd
- 删除原文件 `apps/calculator/Application.php`

### Step 5: 修改 main.php
- `vue_window_create()` 后获取 $hWnd
- 创建 `GdiRenderContext($hWnd)`
- `Application` 构造函数不变（但 ctx 已持有 hWnd）

### Step 6: 新增 AntiPatternChecker
- 实现 5 项检查规则
- 可作为独立工具: `php framework/AntiPatternChecker.php framework/`

---

## 验证方式

1. **语法检查**: `php -l framework/*.php`
2. **构建验证**: `build.bat calculator` 生成 .exe 无错误
3. **运行验证**: 执行 `apps/calculator/bin/calculator.exe`，窗口正常显示，交互正常
4. **反模式检查**: `php framework/AntiPatternChecker.php framework/` 无 ERROR 输出