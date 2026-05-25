# PUI Framework

**PHP Universal Interface** — 基于 PHP + Vue 3 模板 DSL 的声明式原生桌面 UI 框架，通过 AOT（Ahead-of-Time）编译生成原生 Windows 可执行程序。

---

> ## ⚠️ 重要声明 / IMPORTANT NOTICE
>
> **本项目是一个 GUI 框架从零演进的实验性探索项目**，目的在于通过编写样例测试应用，主动暴露框架在架构设计、实现细节演进过程中存在的各种问题与能力缺失，借此引发对框架设计的深层思考，反哺架构与工程能力的提升，并落实到具体迭代改进中。
>
> - 请勿将其用于任何生产环境
> - 本项目仅适合作为**缺陷挖掘（bug hunting）**与**反模式识别（anti-pattern discovery）**的测试靶场
> - 它最大的价值或许是提供了一个**可运行的毛坯框架**，你可以此为基础继续探索、改造、演进
> - **作者已停止更新本项目**
>
> ---
>
> **This project is an experimental sandbox for exploring the from-scratch evolution of a GUI framework.** Its purpose is to surface architectural flaws, capability gaps, and design problems through sample test applications — sparking deeper thinking about framework design and driving iterative improvement in both architecture and engineering practice.
>
> - Do NOT use in any production environment
> - This project is intended solely as a testing ground for **bug hunting** and **anti-pattern discovery**
> - Its greatest value may be as a **runnable bare-bones framework** — a starting point for your own exploration, modification, and evolution
> - **The author has stopped maintaining this project**
>
> ---

## 核心理念

> 用 PHP 写业务逻辑，用 Vue 3 模板写视图，编译为原生机器码。

PUI 将 Vue 3 的单文件组件（SFC）开发体验带到 PHP 生态中，同时通过 Swoole Compiler 的 AOT 编译能力，生成零运行时开销的原生 Windows GUI 应用。开发者无需学习新的语言或框架范式——如果你会 Vue 3 和 PHP，你已经会使用 PUI。

---

## 架构总览

```
                         ┌──────────────────────┐
 App.vue                 │  SFC Compiler (PHP)   │
 ┌─────────────────┐     │  ┌─────────────────┐  │     gen/AppComponent.php
 │ <template>      │────▶│  │ Template Parser │──┼──▶  ┌──────────────────┐
 │   Vue 3 DSL     │     │  ├─────────────────┤  │     │ class AppComponent│
 │ </template>     │     │  │ Style Parser    │  │     │  render(): VNode  │
 │ <script>PHP</>  │     │  ├─────────────────┤  │     │  dispatchClick()  │
 │ <style>CSS</>   │     │  │ Code Generator  │  │     │  setBindValue()   │
 └─────────────────┘     │  └─────────────────┘  │     └────────┬─────────┘
                         │  AOT Validator        │              │
                         └──────────────────────┘              │
                                                                ▼
                         ┌──────────────────────┐     Swoole AOT Compiler
                         │   Native Windows EXE │◀──── PHP → C++ → x64 .exe
                         │  ┌─────────────────┐ │
                         │  │ Application     │ │     Runtime (Event Loop)
                         │  │  ├─ WinMsg Loop │ │     ┌──────────────────┐
                         │  │  ├─ Dirty Check │ │     │ 60 FPS           │
                         │  │  ├─ Layout Resolver│   │ Click/Key/Scroll │
                         │  │  └─ GDI Render  │ │     │ VNode → GDI绘制的   │
                         │  └─────────────────┘ │     └──────────────────┘
                         └──────────────────────┘
```

### 编译管道

```
.vue 文件 ──▶ SFC Compiler ──▶ .php 类文件 ──▶ AOT Compiler ──▶ Native .exe
              (PHP 8.4)         (VNode树生成)    (Swoole)          (Windows x64)
```

### 运行时渲染管线

```
用户交互 ──▶ Application Event Loop ──▶ dirty=true ──▶ render() ──▶ VNode树
                                                                    │
                                                                    ▼
                        GDI Draw ◀── VNodeRenderer ◀── LayoutResolver
                       (原生绘制)     (VNode→图元)       (CSS布局计算)
```

---

## 当前能力

### 模板 / DSL（Vue 3 子集）

| 能力 | 支持 | 说明 |
|------|:----:|------|
| `v-if` | ✅ | 条件渲染，支持嵌套短路语义 |
| `v-for` | ✅ | 列表渲染，支持 `(item, index) in items`，`:key` |
| `v-model` | ✅ | 双向绑定（input 元素） |
| `:bind` / `bind` | ✅ | 单向数据绑定，文本插值 `{{ expr }}` |
| `@click` | ✅ | 点击事件，支持 `click-arg` 传参 |
| `@keydown` / `@keyup` | ✅ | 键盘事件，携带键码参数 |
| `@enter` | ✅ | 回车/ESC 确认事件 |
| `class` | ✅ | CSS class 绑定 |
| `style` | ✅ | 内联样式（px 单位属性） |
| `<component>` 标签 | ✅ | 子组件引用与内联 |
| `v-if` 动态组件 | ✅ | 运行时条件挂载/卸载子组件 |
| 模板插值 `{{ }}` | ✅ | 混合文本与表达式 |
| 组件 Props 传递 | ✅ | 父→子数据流，v-if 传递 |

### 布局系统

| 模式 | 支持 | 说明 |
|------|:----:|------|
| **Block（块级）** | ✅ | 绝对定位，`left/top/width/height` |
| **Flexbox** | ✅ | `flex-direction`, `justify-content`, `align-items`, `gap`, `flex-wrap` |
| **CSS Grid** | ✅ | `grid-template-columns/rows`, `repeat()`, `gap`, 显式行列放置 |
| **Scroll Container** | ✅ | `overflow:auto`，鼠标滚轮+滚动条，`:scroll-top` 绑定 |
| **Auto-stack** | ✅ | 滚动容器内子元素自动垂直堆叠 |
| **z-index 层叠** | ✅ | 层级命中测试（Hit Test），覆盖层自然遮挡底层 |

### 样式系统

| 能力 | 支持 | 说明 |
|------|:----:|------|
| `background` | ✅ | 背景色（`#rrggbb` → BGR 映射） |
| `color` | ✅ | 前景色/文字颜色 |
| `font-size` | ✅ | 字体大小（px） |
| `font-weight` | ✅ | 粗体标记 |
| `text-align` | ✅ | 文字水平对齐 |
| CSS class | ✅ | `<style>` 块编译为 class→属性映射表 |
| 内联 style | ✅ | 直接覆盖 class 样式 |
| Vendor prefix | ✅ | `-webkit-` / `-moz-` 透传 |

### 响应式系统

| 机制 | 实现 |
|------|------|
| 脏标记驱动 | `$this->dirty = true` → 下一帧完整重建 VNode 树 |
| 自动注入 | ScriptAnalyzer 在属性写入后自动插入 `$this->dirty = true` |
| 实时重绘 | 60 FPS 事件循环，脏检测→重建→布局→绘制 |

### 组件系统

| 能力 | 支持 | 说明 |
|------|:----:|------|
| SFC 单文件组件 | ✅ | `.vue` 文件 = template + script + style |
| 父子组件嵌套 | ✅ | 父组件引用子组件，通过 ComponentRegistry 注册 |
| Props 传递 | ✅ | `:prop="value"` 编译时展开 |
| 子组件预编译 | ✅ | `components/` 目录下子组件在 Phase 1 独立编译 |
| 组件池复用 | ✅ | v-if 动态组件使用对象池（最多 10 实例） |
| 生命周期 | ✅ | `onMount()` / `onUnmount()` |

### 事件系统

| 事件类型 | 支持 | 派发方式 |
|----------|:----:|----------|
| 鼠标点击 | ✅ | 两层命中测试（先收集覆盖节点→取最高层可交互节点） |
| 鼠标滚轮 | ✅ | 滚动容器偏移更新 |
| 键盘输入 | ✅ | WM_CHAR → v-model 拼接；WM_KEYDOWN → @keydown/@enter |
| 滚动条拖拽 | ✅ | 点击位置映射到滚动偏移 |
| 键盘导航 | ✅ | 方向键控制滚动容器 |

---

## 与主流框架的对比分析

### 架构维度对比

| 维度 | PUI | Vue 3 | React Native | Flutter |
|------|-----|-------|-------------|---------|
| **语言** | PHP 8.4 | JavaScript/TypeScript | JavaScript/TypeScript | Dart |
| **UI 描述** | Vue 3 模板 DSL | Vue 3 模板 | JSX | Widget 组合 |
| **渲染引擎** | GDI（Windows 原生） | 浏览器 DOM | 平台原生控件 | Skia 自绘引擎 |
| **布局引擎** | 自研（Flex/Grid/Block） | CSS + 浏览器 | Yoga（Flexbox） | RenderObject 树 |
| **响应式** | 脏标记 + 全量重建 | Proxy 细粒度追踪 | Fiber + Virtual DOM | StatefulWidget setState |
| **编译方式** | AOT 编译为原生 exe | JIT 解释 | JIT + Hermes 引擎 | AOT 编译为原生代码 |
| **平台** | Windows | Web | iOS / Android / Web | iOS / Android / Web / Desktop |
| **包大小** | ~15MB（含 PHP 运行时） | 取决于打包工具 | ~5-20MB | ~5-20MB |
| **开发体验** | 无热重载 | 热重载 | 快速刷新 | 热重载 + 热重启 |

### 具体差异分析

#### vs Vue 3（Web 生态）

**Vue 3 优势：**
- 成熟的浏览器生态——完整 HTML/CSS 支持，丰富的第三方组件库，DevTools
- 细粒度响应式——Proxy 自动追踪依赖，无需手动标记 dirty
- Virtual DOM diffing——增量更新，仅变更差异节点
- 丰富的过渡/动画系统——`<Transition>`, `<TransitionGroup>`
- 完整的组件特性——slots、provide/inject、teleport、异步组件、Suspense

**PUI 的优势：**
- 零运行时 JS 虚拟机——AOT 编译原生机器码，启动快（冷启动 < 100ms）
- 无需浏览器——独立窗口原生应用，系统级集成
- 内存可控——无 GC 抖动（PHP 有 GC 但 AOT 下行为不同），适用于嵌入式/工控场景
- 全局类型安全——PHP 8.4 静态类型 + `use native_types` 严格模式

**PUI 的差距：**
- 无 DOM/CSSOM —— CSS 属性映射有限（约 20 个属性 vs 全量 CSS）
- 无 HTML 标准元素 —— 仅 div/span/button/input/p/h1-h6
- 无动画引擎 —— 帧间无插值/过渡能力
- 无 DevTools —— 调试依赖日志和静态分析
- 响应式粒度粗 —— full rebuild vs diffing，大列表性能差

---

#### vs React Native（跨平台移动端）

**React Native 优势：**
- JavaScript 生态 —— npm 海量包，Redux/MobX 等成熟状态管理
- 平台原生控件 —— iOS UIKit / Android View 真实组件，非模拟
- Yoga 布局引擎 —— 成熟的 Flexbox 实现（来自 Facebook）
- Metro bundler —— 快速刷新开发体验
- 社区生态 —— Expo、React Navigation、Native Modules

**PUI 的优势：**
- 单一语言全栈 —— 后端 PHP → 前端 PUI，无需 Node.js 中间层
- 离线 AOT 编译 —— 无 JS bundle 解析开销，启动速度碾压 Hermes
- 内存确定性 —— 无 JS 闭包内存泄漏风险
- Windows 原生 —— React Native Windows 社区薄弱，PUI 天生 Windows

**PUI 的差距：**
- 单平台 —— 仅 Windows，无法覆盖 iOS/Android
- 无 Flexbox 自动换行 —— 仅基础 flex-wrap 标记
- 无触摸手势系统 —— 仅 click/scroll，无 pan/pinch/swipe
- 无导航栈 —— 路由需手动管理
- 无 Animated API —— 动画需帧间手动更新
- 第三方生态为零

---

#### vs Flutter（跨平台自绘引擎）

**本项目的终极对标对象是 Flutter。** Flutter 的成功验证了"自绘引擎 + 声明式 UI + AOT 编译"范式的可行性。以下对比聚焦在架构决策层面：

| 架构层面 | Flutter | PUI（当前） | 差距评估 |
|----------|---------|------------|----------|
| **渲染后端抽象** | `dart:ui` → Canvas API | `RenderContext` 接口 | PUI 已有接口层，但仅 GDI 实现 |
| **平台通道** | Platform Channel（MethodChannel） | 无（直接嵌入 Window） | 差距大——需引入消息通道抽象 |
| **Widget 组合** | 不可变 Widget + Element/State 分离 | VNode 树 + 组件池 | VNode 相当于 Widget，但缺少 Element 中间层 |
| **布局管线** | `RenderObject` → `performLayout()` → `paint()` | `LayoutResolver` → `VNodeRenderer`（双 pass） | 双 pass 架构已类似，但缺少增量布局 |
| **图层合成** | Layer Tree → Engine 合成 | element layer 属性 | PUI 有基本 layer 概念，但无 GPU 合成 |
| **文本渲染** | LibTxt + HarfBuzz（文字排版） | GDI DrawText（简单） | 差距大——无复杂文本支持 |
| **图片/资源** | AssetBundle + ImageCache | 无 | 完全缺失 |
| **路由导航** | Navigator 2.0 | 手动 v-if | 需借鉴 Flutter 路由架构 |
| **状态管理** | Provider/Bloc/Riverpod | 脏标记 | 差距大——需引入状态管理方案 |
| **国际化 i18n** | intl + ARB | 无 | 完全缺失 |
| **测试框架** | Widget/Integration/Unit Test | 仅编译期单元测试 | 需补齐各层测试 |

**关键架构差异：**

1. **Widget = Element = State？**
   - Flutter: Widget（不可变配置）→ Element（可变实例）→ RenderObject（布局绘制）三层分离
   - PUI: VNode（配置+状态混合）→ 直接渲染。缺少中间层导致全量重建不可避免

2. **AOT 编译目标**
   - Flutter: Dart → ARM/x64 机器码（经 LLVM）
   - PUI: PHP → C++ → x64 机器码（经 Swoole Compiler）。路径更长但生成的 C++ 可移植

3. **自绘 vs 系统控件**
   - Flutter: 完全自绘（Skia Impeller），零系统依赖
   - PUI: 依赖 Windows GDI。这是跨平台的最大障碍

---

## 演进路线图

### 愿景

> 成为 **PHP 生态的 Flutter** —— 用 Vue 3 模板描述 UI，用 PHP 编写业务逻辑，一次编写，编译部署到 Windows / Linux / macOS / iOS / Android / Web 六大平台。

### Phase 1: 渲染后端抽象化（当前 → Q3 2026）

**目标：** 将渲染层从 GDI 彻底解耦，建立跨平台绘制基础。

```
                    ┌─────────────────────────┐
                    │   RenderContext 接口     │
                    │  ┌───────────────────┐  │
                    │  │ fillRect()        │  │
                    │  │ drawText()        │  │
                    │  │ drawButton()      │  │
                    │  │ measureText()     │  │
                    │  │ beginFrame()      │  │
                    │  │ endFrame()        │  │
                    │  └───────────────────┘  │
                    └───────┬───────┬─────────┘
                            │       │
                    ┌───────┘       └───────┐
                    ▼                       ▼
            ┌──────────────┐        ┌──────────────┐
            │ GdiContext   │        │ SkiaContext  │
            │ (Windows)    │        │ (跨平台)      │
            └──────────────┘        └──────────────┘
```

**具体任务：**
- [ ] 完善 `RenderContext` 接口——补充 `measureText()`、`drawImage()`、`drawPath()` 等方法
- [ ] 将 Application 中的 GDI 调用移到 `GdiContext` 实现
- [ ] 引入 Skia/CanvasKit 作为第二渲染后端
- [ ] 抽象 Window 创建（`PlatformWindow` 接口）：Win32 / GLFW / SDL2 可选
- [ ] 事件系统抽象化（`PlatformEvent` → 统一事件类型）

---

### Phase 2: SFC 编译器能力增强（Q3-Q4 2026）

**目标：** 缩小与 Vue 3 模板特性的差距，提升开发体验。

**具体任务：**
- [ ] **插槽（Slots）** —— 支持默认插槽和具名插槽，子组件内容分发
- [ ] **计算属性（Computed）** —— `computed:` 块，缓存依赖追踪结果
- [ ] **侦听器（Watchers）** —— `watch:` 块，属性变化时的副作用
- [ ] **CSS 选择器增强** —— 后代选择器 `.parent .child`，伪类 `:hover`/`:active`
- [ ] **过渡动画** —— 基于帧插值的简单 Transition 支持
- [ ] **模板 include/import** —— 模板片段复用机制
- [ ] **错误消息改进** —— 编译期精确定位模板错误行列
- [ ] **HMR 开发服务器** —— 文件变更→自动重编译→推送刷新

---

### Phase 3: 跨平台桌面（Q4 2026 - Q1 2027）

**目标：** 覆盖 Windows + Linux + macOS 三大桌面平台。

```
  App.vue
    │
    ▼
  SFC Compiler ──▶ gen/AppComponent.php
    │
    ▼
  Swoole AOT Compiler
    │
    ├──▶ Windows: GDI / Skia backend
    ├──▶ Linux:   X11 + Skia backend
    └──▶ macOS:   Cocoa + Skia backend
```

**具体任务：**
- [ ] **Linux 后端** —— X11 窗口创建 + Skia 渲染（或复用 GTK 壳）
- [ ] **macOS 后端** —— NSWindow + Metal/Skia 渲染（或通过 SDL2 统一）
- [ ] **平台通道（Platform Channel）** —— PHP ↔ 原生代码异步消息机制
  - 文件系统访问（已有 PHP 内建，需本地路径映射）
  - 系统通知
  - 剪贴板读写
- [ ] **窗口管理 API** —— 多窗口、托盘图标、菜单栏
- [ ] **打包工具链** —— `pui build --target windows|linux|macos`

---

### Phase 4: 移动端（Q2-Q4 2027）

**目标：** 扩展到 iOS 和 Android。

**技术路径选择：**

| 方案 | 渲染 | 优势 | 劣势 |
|------|------|------|------|
| A. PHP → C++ → Android NDK / iOS 原生 | Skia | 与桌面共享渲染后端 | 需解决 PHP 运行时在移动端的嵌入 |
| B. PHP → WebAssembly → WebView 壳 | Canvas/WebGL | 复用 web 技能 | 性能瓶颈（WebView） |
| C. SFC Compiler 输出 Flutter Widget | Flutter Engine | 借力 Flutter 生态 | 需 Dart 桥接层，复杂度高 |

**推荐路径 A（自绘 Skia）：**

```
  PHP 业务代码 ──▶ Swoole AOT ──▶ C++ 模块
                                       │
                    ┌──────────────────┼──────────────────┐
                    ▼                  ▼                  ▼
              Android NDK         iOS ARM64           Desktop
              (libphp.so)      (libphp.a)            (.exe)
                    │                  │
                    ▼                  ▼
              Skia (Vulkan)     Skia (Metal)
```

**具体任务：**
- [ ] 跨编译 PHP 运行时到 ARM64（Android NDK）
- [ ] 触摸事件系统——`@touchstart`、`@touchmove`、`@touchend`
- [ ] 手势识别器——pan、pinch、swipe、long-press
- [ ] 移动导航组件——`<navigation-bar>`、`<tab-bar>`、`<drawer>`
- [ ] 移动适配——SafeArea、屏幕密度、键盘避让
- [ ] iOS 沙盒适配——PHP 运行时静态链接

---

### Phase 5: Web 平台（2027+）

**目标：** 编译到 WebAssembly，运行在浏览器中。

**技术路径：**

```
  PHP 业务代码
       │
       ▼
  Swoole AOT (Emscripten target)
       │
       ▼
  WebAssembly (.wasm) + JS glue
       │
       ▼
  Canvas 2D / WebGL 渲染
```

**具体任务：**
- [ ] Emscripten 交叉编译 PHP 运行时到 Wasm
- [ ] Canvas 2D RenderContext 后端
- [ ] DOM 事件 ↔ PHP 事件桥接
- [ ] Service Worker 离线支持
- [ ] 渐进式 Web 应用（PWA）打包

---

### Phase 6: 生态建设（持续）

- [ ] **组件库** —— PUI Material / Cupertino 风格组件
- [ ] **状态管理** —— 类似 Pinia 的 PHP 状态管理方案
- [ ] **路由库** —— 声明式路由、深层链接
- [ ] **CLI 工具** —— `pui create`、`pui build`、`pui run`、`pui doctor`
- [ ] **插件市场** —— 社区贡献的组件和平台通道插件
- [ ] **文档站点** —— 教程、API 参考、示例画廊

---

### 总体演进路线图

```
2025 Q2  ████████████ 当前：PUI v7，Windows GDI，基础 Vue DSL
         │
2026 Q3  ████████████ Phase 1+2：渲染抽象化 + Compiler 增强
         │             Skia 后端，slots/computed/watch/HMR
         │
2027 Q1  ████████████ Phase 3：跨平台桌面（Win/Linux/macOS）
         │             Platform Channel，多窗口
         │
2027 Q4  ████████████ Phase 4：移动端（iOS/Android）
         │             Skia 自绘，触摸手势，移动导航
         │
2028     ████████████ Phase 5：Web（WebAssembly + Canvas）
         │             Emscripten，PWA
         │
2029+    ████████████ Phase 6：生态成熟
                      组件库、CLI、插件市场
```

---

## 技术债与已知限制

### 架构层面

| 问题 | 严重度 | 计划 |
|------|:------:|------|
| VNode 全量重建无 diff | 中 | Phase 2 引入增量更新机制 |
| 单线程事件循环 | 低 | 桌面场景足够，移动端需评估 |
| 无 GPU 加速 | 中 | Skia 后端引入可解决 |
| scrollbar 宽度硬编码 14px | 低 | 读取系统 DPI/主题 |
| Auto-stack 仅滚动容器 | 低 | 推广到普通 block 容器 |
| 无相对/固定定位 | 中 | Phase 3 布局增强 |

### 编译器层面

| 问题 | 严重度 | 计划 |
|------|:------:|------|
| switch/match 重复实现 | 中 | Phase 2 重构为统一生成器 |
| 属性变体未规范化（`:bind`/`bind`） | 低 | Parser 阶段统一化 |
| click-arg 启发式判断（`$v[0] === '$'`） | 低 | AST 引入 Expression 节点类型 |
| 模板解析无 source map | 中 | Phase 2 HMR 同时引入 |

### 生态层面

| 缺失 | 影响 |
|------|------|
| 无第三方组件 / 插件 | 所有 UI 需手写 |
| 无 CLI 脚手架 | 手动创建项目结构 |
| 无调试面板 | 状态追踪需 `echo` 日志 |
| 无测试框架（Widget Test） | 仅编译期单元测试 |

---

## 项目结构

```
d:/PUI/
├── framework/                    # 核心框架
│   ├── Application.php           # 主事件循环、窗口管理
│   ├── VNode.php                 # 虚拟 DOM 节点定义
│   ├── VNodeRenderer.php         # VNode → GDI 绘制元素
│   ├── LayoutResolver.php        # CSS 布局计算
│   ├── ReactiveComponent.php     # 响应式组件基类
│   ├── BaseComponent.php         # 组件树结构
│   ├── ChangeQueue.php           # 变更缓冲（遗留）
│   ├── ComponentInterface.php    # 组件协约
│   ├── rendering/
│   │   └── RenderContext.php     # 绘制抽象接口
│   └── compiler/
│       ├── template-parser.php   # 模板 → VNode 解析器
│       ├── sfc-compiler.php      # SFC 完整编译管线
│       ├── css-mappings.php      # CSS 属性映射
│       ├── aot-validator.php     # AOT 兼容性检查
│       ├── aot-checker.php       # 静态检查工具
│       ├── script-analyzer.php   # PHP 代码分析
│       └── component-registry.php # 组件注册表
├── apps/
│   ├── calculator/               # 计算器示例应用
│   │   ├── App.vue               # 根组件
│   │   ├── components/           # 子组件
│   │   │   ├── DisplayPanel.vue
│   │   │   ├── NumPad.vue
│   │   │   └── AboutDialog.vue
│   │   ├── gen/                  # 生成代码
│   │   ├── main.php              # 入口
│   │   └── project.yml           # 构建配置
│   ├── test/                     # 键盘事件测试应用
│   │   ├── App.vue
│   │   └── gen/
│   └── list-test/                # 列表渲染测试应用
├── tests/                        # 单元测试
│   ├── sfc-compiler-test.php     # 编译器测试（30 项）
│   └── parser-robustness-test.php # 解析器健壮性测试（36 项）
├── build.bat                     # 非交互式构建脚本
└── main_build.bat                # 全局构建入口
```

---

## 快速开始

### 前置要求

- Windows 10/11 x64
- Visual Studio 2022（含 C++ 桌面开发工作负载）
- Swoole Compiler（`swoole_compiler/` 目录）

### 构建示例应用

```bat
# 构建计算器
build.bat calculator

# 构建并运行
build.bat calculator --run
```

### 创建新应用

```bat
# 1. 创建目录
mkdir apps\myapp
mkdir apps\myapp\components

# 2. 创建 App.vue
```

```vue
<template>
  <div style="left:0px;top:0px;width:640px;height:480px" class="bg">
    <span style="left:10px;top:10px;font-size:16px" class="title">{{ message }}</span>
    <button style="left:10px;top:50px;width:100px;height:30px" @click="onClick">
      Click Me
    </button>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $message = 'Hello PUI!';

    public function onClick(): void
    {
        $this->message = 'Clicked!';
    }
}
</script>

<style>
.bg { background: #1e1e1e; }
.title { color: #ffffff; }
</style>
```

```bat
# 3. 创建 project.yml
echo name: myapp > apps\myapp\project.yml
echo window_width: 640 >> apps\myapp\project.yml
echo window_height: 480 >> apps\myapp\project.yml

# 4. 构建
build.bat myapp
```

---

## 许可证

Proprietary. All rights reserved.

---

## 参考资源

- [Vue 3 模板语法](https://vuejs.org/guide/essentials/template-syntax.html)
- [Flutter 架构概览](https://docs.flutter.dev/resources/architectural-overview)
- [React Native 架构](https://reactnative.dev/architecture/overview)
- [Skia 2D 图形库](https://skia.org/)
- [Swoole Compiler](https://www.swoole.com/)
