# VueCalc 框架演进文档 (v6 M2)

## Context

当前 VueCalc 框架采用"布局函数"模式：
- `BaseRenderer` 管理 `$activeLayouts` 数组，通过 `callLayoutSegment(name)` 调用生成的布局函数
- SFC 编译器生成 `App.gen.php` (组件类) + `AppLayout_gen.php` (布局函数集)
- `Application` 在初始化时通过 `attachLayout()` 挂载所有布局段

这种模式存在以下问题：
1. **职责耦合**: `BaseRenderer` 承担了组件管理和渲染调度的双重职责
2. **组件实例缺失**: 生成的布局函数无法保留组件实例的引用关系
3. **偏移/绑定硬编码**: 子组件的坐标偏移和 props 传递在编译时内联，无法运行时动态调整
4. **扩展性受限**: 无法支持条件挂载/卸载、条件 props 等动态特性

本次演进目标是：将框架从"布局函数"模式升级为"组件实例"模式，建立真正的组件树结构，为未来的虚拟 DOM 打下基础。

---

## 架构设计

### 新架构组件关系

```
┌─────────────────────────────────────────────────────────────┐
│                        Application                          │
│  - $activeComponents: array (id => Component)             │
│  - $rootComponent: AppComponent                           │
│  - registerRootComponent()                               │
│  - attachComponents() ← 从根组件获取初始组件树             │
│  - detachComponent(id)                                    │
│  - getActiveLayout(): 遍历组件树，收集布局+应用偏移        │
└──────────────────────────┬──────────────────────────────────┘
                           │ 创建时注入
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                       BaseRenderer                          │
│  - 仅保留渲染调度逻辑                                       │
│  - render(): 接收预处理后的布局数据进行两阶段分层渲染      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│              AppComponent (根组件)                          │
│  - $children: array (静态注册的子组件)                      │
│  - getLayout(): array                                      │
│  - getBaseComponents(): array (返回初始组件树)              │
└─────────────────────────────────────────────────────────────┘
```

### 关键接口设计

#### ComponentInterface

```php
interface ComponentInterface
{
    public function getId(): string;
    public function getLayout(): array;
    public function getChildren(): array;
    public function getParent(): ?ComponentInterface;
    public function getProps(): array;
    public function onAttach(): void;
    public function onDetach(): void;
}
```

#### 组件基类 BaseComponent

```php
abstract class BaseComponent implements ComponentInterface
{
    protected string $id;
    protected ?ComponentInterface $parent = null;
    protected array $children = [];
    protected array $props = [];
    protected bool $attached = false;

    // 子类必须实现
    abstract public function getLayout(): array;
    abstract public function onAttach(): void;
    abstract public function onDetach(): void;

    // 已实现方法
    public function getId(): string;
    public function getParent(): ?ComponentInterface;
    public function getChildren(): array;
    public function getProps(): array;

    public function addChild(ComponentInterface $child, array $props = []): void;
    public function removeChild(string $childId): void;
    public function setParent(ComponentInterface $parent): void;
    public function setProps(array $props): void;
}
```

---

## 实现步骤

### Phase 1: 核心接口与基类 [新建 2 个文件]

| 步骤 | 文件 | 操作 |
|------|------|------|
| 1.1 | `framework/interfaces/ComponentInterface.php` | 新建 |
| 1.2 | `framework/BaseComponent.php` | 新建 |

### Phase 2: ReactiveComponent 改造 [修改 1 个文件]

| 步骤 | 文件 | 操作 |
|------|------|------|
| 2.1 | `framework/ReactiveComponent.php` | 修改 |

- `extends BaseComponent` 替代原有的独立基类
- 保留响应式逻辑 (dirty, dirtyGroups 等)
- 生命周期方法签名从 `onAttach(string $layoutName)` 改为 `onAttach()`

### Phase 3: BaseRenderer 职责精简 [修改 1 个文件]

| 步骤 | 文件 | 操作 |
|------|------|------|
| 3.1 | `framework/BaseRenderer.php` | 修改 |

**移除内容**:
- 删除 `attachLayout()` / `detachLayout()`
- 删除 `getActiveLayout()`
- 删除 `$activeLayouts` 属性

**新构造函数**:
```php
public function __construct(int $hWnd, ReactiveComponent $component, RenderContext $ctx)
```

**render() 方法变更**:
```php
public function render(array $layout): void  // 接收外部注入的布局数据
```

### Phase 4: Application 重构 [修改 1 个文件]

| 步骤 | 文件 | 操作 |
|------|------|------|
| 4.1 | `apps/calculator/Application.php` | 重构 |

**新增属性**:
```php
private array $activeComponents = [];  // id => ComponentInterface
private ?ReactiveComponent $rootComponent = null;
```

**新增方法**:
- `registerRootComponent(ReactiveComponent $root): void`
- `attachComponents(array $components): void` - 批量挂载组件
- `detachComponent(string $id): void`
- `getActiveLayout(): array` - 布局收集逻辑

**initWindow() 核心逻辑**:
```php
public function initWindow(): bool
{
    $this->hWnd = vue_window_create(...);
    vue_window_show($this->hWnd, SW_SHOW);

    // 从根组件获取初始组件树并挂载
    $this->attachComponents($this->rootComponent->getBaseComponents());

    // 创建渲染器
    $this->renderer = new BaseRenderer($this->hWnd, $this->rootComponent, $this->ctx);
    return true;
}

/**
 * 批量挂载组件到活跃列表
 */
private function attachComponents(array $components): void
{
    foreach ($components as $comp) {
        if ($comp instanceof ComponentInterface) {
            $this->activeComponents[$comp->getId()] = $comp;
            $comp->onAttach();
        }
    }
}
```

### Phase 5: SFC 编译器改造 [修改 2 个文件]

| 步骤 | 文件 | 操作 |
|------|------|------|
| 5.1 | `framework/sfc-compiler.php` | 重构 |
| 5.2 | `framework/compiler/component-resolver.php` | 修改 |

**AppComponent.php 生成内容**:
```php
class AppComponent extends ReactiveComponent
{
    public string $display = '0';
    public bool $showDialog = false;

    public function __construct(?string $id = null)
    {
        parent::__construct($id ?? 'App');
        $this->registerChildren();
    }

    /**
     * 初始化时注册子组件
     * 注意: 使用 addChild($component, $props) 直接传入，避免变量类型问题
     */
    private function registerChildren(): void
    {
        $this->addChild(new DisplayPanelComponent('display-panel'), ['x' => 4, 'y' => 4]);
        $this->addChild(new NumPadComponent('num-pad'), ['x' => 0, 'y' => 80]);
        $this->addChild(new AboutDialogComponent('about-dialog'), ['x' => 0, 'y' => 0]);
    }

    /**
     * 获取初始组件树（用于 Application 初始化挂载）
     */
    public function getBaseComponents(): array
    {
        $result = [$this];
        $this->collectDescendantsRecursive($this, $result);
        return $result;
    }

    private function collectDescendantsRecursive(ComponentInterface $comp, array &$result): void
    {
        $children = $comp->getChildren();
        $childIds = array_keys($children);
        $count = count($childIds);
        for ($i = 0; $i < $count; $i++) {
            $child = $children[$childIds[$i]];
            $result[] = $child;
            $this->collectDescendantsRecursive($child, $result);
        }
    }

    public function getLayout(): array
    {
        return [
            'elements' => [...],
            'buttons' => [...],
        ];
    }

    public function onAttach(): void {}
    public function onDetach(): void {}
}
```

**移除内容**:
- 不再生成 `AppLayout_gen.php`
- 移除 `callLayoutSegment()` 函数

### Phase 6: 应用适配 [修改 2 个文件]

| 步骤 | 文件 | 操作 |
|------|------|------|
| 6.1 | `apps/calculator/main.php` | 修改 |
| 6.2 | `apps/calculator/project.yml` | 修改 |

**main.php**:
```php
function main(): int
{
    // 仅创建根组件，子组件由根组件内部注册
    $root = new AppComponent('App');
    $root->initShared(10240);

    $ctx = new GdiRenderContext();
    $app = new Application($root, $ctx);
    $app->run();
}
```

**project.yml** (新增 ComponentInterface.php):
```yaml
sources:
  - ../../framework/interfaces/ComponentInterface.php  # 新增
  - ../../framework/BaseComponent.php
  - ../../framework/ReactiveComponent.php
  # ...
```

---

## 关键文件清单

### 新建文件 (2 个)
- `framework/interfaces/ComponentInterface.php`
- `framework/BaseComponent.php`

### 修改文件 (6 个)
- `framework/ReactiveComponent.php`
- `framework/BaseRenderer.php`
- `apps/calculator/Application.php`
- `framework/sfc-compiler.php`
- `framework/compiler/component-resolver.php`
- `apps/calculator/main.php`
- `apps/calculator/project.yml`

### 删除文件 (2 个)
- `apps/calculator/gen/App.gen.php`
- `apps/calculator/gen/AppLayout_gen.php`

### 生成的文件 (5 个)
- `apps/calculator/gen/AppComponent.php` - 根组件
- `apps/calculator/gen/DisplayPanelComponent.php` - 子组件
- `apps/calculator/gen/NumPadComponent.php` - 子组件
- `apps/calculator/gen/AboutDialogComponent.php` - 子组件
- `apps/calculator/gen/constants.php` - 窗口常量

---

## 改进前后对比

| 维度 | 改进前 | 改进后 |
|------|--------|--------|
| **组件管理** | BaseRenderer 管理布局名称字符串 | Application 管理组件实例对象 |
| **组件引用** | 无父子组件引用关系 | 完整的 parent/children 引用链 |
| **布局来源** | 布局函数 callLayoutSegment() | 组件实例 getLayout() 方法 |
| **偏移处理** | 编译时内联到布局数据 | 运行时由 Application::collectLayoutRecursive() 动态应用 |
| **组件初始化** | Application attach 所有布局段 | Application 调用根组件 getBaseComponents() 获取初始组件树 |
| **职责划分** | BaseRenderer: 组件管理 + 渲染调度 | BaseRenderer: 仅渲染调度 |
| **可扩展性** | 静态布局，无法动态增删 | 支持运行时 attach/detach 组件 |
| **编译产物** | App.gen.php + AppLayout_gen.php | *Component.php (每个组件独立文件) |

---

## AOT 兼容性要点

### 1. 关联数组遍历
```php
// ❌ AOT 不安全 (foreach 遍历关联数组 key 类型推断错误)
foreach ($this->activeLayouts as $name => $val) { }

// ✅ AOT 安全
$childIds = array_keys($children);
$count = count($childIds);
for ($i = 0; $i < $count; $i++) {
    $child = $children[$childIds[$i]];
}
```

### 2. 嵌套数组类型保留
```php
// ❌ AOT 可能丢失子数组类型
foreach ($seg['elements'] as $el) { }

// ✅ AOT 安全
foreach ((array)$seg['elements'] as $el) { }
```

### 3. 条件字段类型检查
```php
// ❌ AOT 可能将 condition 推断为 int
if ($cond !== null && !$this->component->evalCondition($cond)) continue;

// ✅ AOT 安全
$cond = $btn['condition'] ?? null;
if ($cond !== null && !is_array($cond)) continue;
if ($cond !== null && !$this->component->evalCondition($cond)) continue;
```

### 4. 避免变量函数调用
```php
// ❌ AOT 不支持
$method = 'getLayout';
return $comp->$method();

// ✅ AOT 安全
if ($comp instanceof AppComponent) {
    return $comp->getLayout();
}
```

### 5. 子组件变量命名规范 (重要!)
AOT 编译器严格要求变量类型一致性，同一变量不能赋值不同类型对象。

```php
// ❌ AOT 编译失败: Cannot re-assign typed object
$child = new DisplayPanelComponent('display-panel');
$child = new NumPadComponent('num-pad');  // 错误! $child 已被类型化为 DisplayPanelComponent

// ✅ AOT 安全: 使用 addChild() 直接传入
$this->addChild(new DisplayPanelComponent('display-panel'), ['x' => 4, 'y' => 4]);
$this->addChild(new NumPadComponent('num-pad'), ['x' => 0, 'y' => 80]);
$this->addChild(new AboutDialogComponent('about-dialog'), ['x' => 0, 'y' => 0]);
```

---

## 编程工程原则审视

### 已遵循原则

| 原则 | 体现 |
|------|------|
| **单一职责原则 (SRP)** | BaseRenderer 仅负责渲染调度，组件管理移至 Application |
| **依赖倒置原则 (DIP)** | RenderContext 抽象后端，BaseRenderer 面向接口编程 |
| **开闭原则 (OCP)** | 新增组件类型只需实现 ComponentInterface，不修改渲染器 |
| **里氏替换原则 (LSP)** | BaseComponent 提供了稳定的抽象基类 |
| **接口隔离原则 (ISP)** | ComponentInterface 方法精简，各方法各司其职 |

### 待完善之处

| 问题 | 说明 | 优先级 |
|------|------|--------|
| **1. 缺乏统一生命周期** | onAttach/onDetach 无标准化参数，子组件挂载顺序不确定 | P2 |
| **2. 无依赖注入容器** | 子组件创建硬编码在 registerChildren() 中，无法注入依赖 | P2 |
| **3. 无组件通信机制** | 父子组件通过 parent 引用直接通信，缺乏标准化事件总线 | P3 |
| **4. 布局数据无版本控制** | getActiveLayout() 每次都遍历组件树重建，无缓存/diff | P3 |
| **5. 缺乏单元测试基础设施** | 无 mock 框架，Application.getActiveLayout 难以单元测试 | P3 |
| **6. 无错误边界机制** | 单个组件渲染失败会导致整个应用崩溃 | P3 |
| **7. 组件类型系统不完整** | 无 slots、fragments、teleport 等高级概念 | P4 |

---

## 后续框架演进影响

### 短期 (v6 M3 ~ M4)
1. **组件通信机制**: 引入 EventBus 或 Props Drilling 替代方案
2. **生命周期标准化**: 定义完整的 mount/update/unmount 钩子
3. **布局缓存优化**: 引入 dirtyComponent + diff 机制减少重复遍历

### 中期 (v7 ~ v8)
1. **虚拟 DOM 树**: 基于组件树结构，实现 diff + patch 管线
2. **条件渲染优化**: layer 机制升级为 VisibilityTier，支持动态 z-order
3. **组件库基础设施**: Tier 1~4 Widget 系统基于 ComponentInterface 构建

### 长期 (v9+)
1. **跨平台渲染后端**: SkiaRenderContext / WebCanvasRenderContext
2. **状态管理框架**: Pinia/Vuex 风格的状态管理
3. **开发者工具**: 组件树可视化、热重载、状态快照

---

## 验证方案

### 1. SFC 编译验证
```bash
php framework/sfc-compiler.php apps/calculator/App.vue
```
- 检查生成的 `*Component.php` 包含 `getBaseComponents()` 方法
- 检查 `registerChildren()` 使用 `addChild()` 直接传入

### 2. PHP 语法验证
```bash
php -l framework/BaseComponent.php
php -l framework/ReactiveComponent.php
php -l apps/calculator/gen/AppComponent.php
```

### 3. AOT 编译验证
```bash
swoole_compiler.exe apps/calculator/project.yml -f
```

### 4. 功能验证
- 运行 `calculator.exe`
- 测试按钮点击、显示更新
- 测试 About 弹窗显示/隐藏

### 5. 回归验证
- 对比解释器版本和 AOT 版本的功能一致性