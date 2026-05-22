# VueCalc 构建说明

> 最后更新：2026-05-22

本文档说明如何构建 VueCalc 项目。详细技术原理参见 `../swoole compiler AOT 文档/最佳实践.html` 及 `docs/构建编译流程参考.md`。

---

## 快速开始

### 方式 1：应用编排器（推荐）

双击框架根目录的 **`main_build.bat`**，自动扫描 `apps/` 下的应用，列出后选择构建。编译完成后回到选择菜单，可继续构建其他应用，输入 `q` 退出。

```
<框架目录>/                              ← 框架根 (可整体迁移)
├── main_build.bat              ← [编排器] 扫描 apps/, 选择构建目标应用
├── swoole_compiler/            ← 编译器 (通配符 swoole_compile* 自动发现)
│   ├── php.exe
│   ├── swoole_compiler.exe
│   ├── php8ts.dll
│   ├── phpx.dll
│   └── SDK/lib/php8embed.lib   ← AOT 链接所需 (自动复制到编译器根)
├── framework/
├── apps/
│   └── calculator/
│       └── bin/           ← [构建产物] 可分发包
└── ...
```

`main_build.bat` 使用 `swoole_compile*` 通配符，优先在框架根目录内查找编译器文件夹，其次在父目录查找。无需硬编码版本号，编译器版本更新后只需替换文件夹即可。

> **php8embed.lib**：AOT 编译需要此文件。脚本会自动从 `SDK/lib/`、`lib/` 等路径查找并复制到编译器根目录。

### 方式 2：非交互式构建（推荐用于自动化 / CI）

> **必须使用 PowerShell**：`build.bat` 内部调用 `chcp 65001`，在 cmd/bash 中输出为空，必须通过 PowerShell 调用才能捕获完整输出。

```powershell
powershell -Command "cd 'f:\work\pdv'; & '.\build.bat' calculator 2>&1"
```

退出码: 0=成功, 1=参数/前置检查, 2=SFC失败, 3=AOT失败, 4=打包失败, 5=运行崩溃

**构建成功标志**：
```
AOT Validation: PASSED (0 errors, 0 warnings)
Successfully compiled N files
Build successful: calculator.exe
[OK] Build completed successfully
```

**产物**：`f:/work/pdv/apps/calculator/bin/calculator.exe`

**末尾可忽略的非致命信息**：`clang-format not found`（格式化步骤，不影响 EXE）、exit code 非 0（PowerShell 因 clang-format 报错但 EXE 已正确生成）

### 方式 3：MSYS2 / Git Bash 手动构建

由于 MSYS2 的路径自动转换与 `cmd //c` 不兼容，需要在 bash 中手动执行：

```bash
# ---- 变量设置 ----
COMPILER_DIR=$(ls -d /d/AOT_sfc/swoole_compile* | tail -1)  # 通配符自动发现
FRAMEWORK_ROOT=/d/AOT_sfc/<框架目录>                           # 框架根

# ---- 第一步：设置 MSVC 编译器环境（每次新终端只需执行一次）----
export MSVC_HOME="/c/Program Files/Microsoft Visual Studio/18/Community/VC/Tools/MSVC/14.50.35717"
export WINKIT_HOME="/c/Program Files (x86)/Windows Kits/10"
export WINKIT_VER="10.0.26100.0"

export PATH="${MSVC_HOME}/bin/Hostx64/x64:$PATH"

# 注意：INCLUDE 和 LIB 必须用 Windows 反斜杠格式 (cl.exe 是 Windows 程序)
export INCLUDE="C:\\Program Files\\Microsoft Visual Studio\\18\\Community\\VC\\Tools\\MSVC\\14.50.35717\\include;C:\\Program Files (x86)\\Windows Kits\\10\\Include\\10.0.26100.0\\ucrt;C:\\Program Files (x86)\\Windows Kits\\10\\Include\\10.0.26100.0\\shared;C:\\Program Files (x86)\\Windows Kits\\10\\Include\\10.0.26100.0\\um;C:\\Program Files (x86)\\Windows Kits\\10\\Include\\10.0.26100.0\\cppwinrt;C:\\Program Files (x86)\\Windows Kits\\10\\Include\\10.0.26100.0\\winrt"

export LIB="C:\\Program Files\\Microsoft Visual Studio\\18\\Community\\VC\\Tools\\MSVC\\14.50.35717\\lib\\x64;C:\\Program Files (x86)\\Windows Kits\\10\\Lib\\10.0.26100.0\\ucrt\\x64;C:\\Program Files (x86)\\Windows Kits\\10\\Lib\\10.0.26100.0\\um\\x64"

# ---- 第二步：SFC 编译器 (Vue → .gen.php) ----
cd "$FRAMEWORK_ROOT"   # 即 main_build.bat 所在目录
"$COMPILER_DIR/php.exe" framework/sfc-compiler.php apps/calculator/Calculator.vue

# ---- 第三步：AOT 编译器 (PHP → exe) ----
cd "$FRAMEWORK_ROOT"
"$COMPILER_DIR/swoole_compiler.exe" apps/calculator/project.yml -f

# ---- 第四步：打包 (exe + DLL → apps/calculator/bin/) ----
mkdir -p apps/calculator/bin
cp "$FRAMEWORK_ROOT/calculator.exe" apps/calculator/bin/
cp "$COMPILER_DIR/php8ts.dll" apps/calculator/bin/
cp "$COMPILER_DIR/phpx.dll" apps/calculator/bin/
```

---

## 构建管道

```
App.vue ──→ [SFC Compiler v6] ──→ gen/*Component.php ──→ [AOT 编译器] ──→ calculator.exe ──→ [打包] ──→ apps/calculator/bin/
 (apps/calculator/)    (每个组件独立文件            (apps/calculator/gen/) (PHP→C++→MSVC→exe)              (exe + DLL)
                          getLayout + onMount/onUnmount)
```

### Step 1: SFC 编译器

| 项目 | 说明 |
|------|------|
| 命令 | `php framework/sfc-compiler.php apps/calculator/App.vue` |
| PHP | 使用 `swoole_compile*` 目录下的 `php.exe`（通配符自动发现） |
| 输入 | `apps/calculator/App.vue`（template + script + style 三块）+ 嵌套组件 |
| 组件注册 | SFC 编译器自动扫描 `apps/calculator/components/` 目录下的 `*.vue` 文件 |
| 输出 | `apps/calculator/gen/AppComponent.php`、`apps/calculator/gen/DisplayPanelComponent.php` 等（每个组件独立文件） |
| 生成函数 | `getLayout()` — 布局数据函数；`onMount()`/`onUnmount()` — 生命周期回调 |
| 特性 | 标准 PHP CLI 脚本，不经过 AOT；支持嵌套组件解析与布局内联 (v5 M2) |

### Step 2: AOT 编译器

| 项目 | 说明 |
|------|------|
| 命令 | `swoole_compiler.exe apps/calculator/project.yml -f` (从框架根执行) |
| 入口 | `apps/calculator/main.php` 中的 `main()` 函数 (project.yml 通过 sources 引入) |
| 输入 | project.yml 指定的所有 sources |
| 子步骤 | prepare → convert → compile (MSVC cl.exe) → link |
| 产物 | `calculator.exe`（~360KB，PE32+ x64 原生可执行文件，输出到框架根） |

### Step 3: 打包

参照 [Swoole Compiler AOT 最佳实践](../swoole%20compiler%20AOT%20文档/最佳实践.html) 第 2 节「如何打包分发」：

> AOT 编译器采用动态链接方式，生成的 exe 依赖运行时 DLL。
> 部署包应包含：二进制 exe + libphp.so(dll) + libphpx.so(dll)

| 文件 | 大小 | 说明 |
|------|------|------|
| `calculator.exe` | ~360KB | 计算器主程序 |
| `php8ts.dll` | ~11MB | PHP 8 Thread-Safe 运行时 (从 swoole_compile* 目录复制) |
| `phpx.dll` | ~2MB | PHPX 桥接库 (从 swoole_compile* 目录复制) |

**三个文件放在同一目录下即可独立分发运行**，无需安装 PHP 解释器。

---

## 环境要求

| 组件 | 路径 / 版本 | 用途 |
|------|------------|------|
| PHP CLI | `<父目录>/swoole_compile*/php.exe` (v8.4, 通配符自动发现) | 运行 SFC 编译器 |
| Swoole Compiler | `<父目录>/swoole_compile*/swoole_compiler.exe` (通配符自动发现) | AOT 编译 |
| Visual Studio 2026 | v18.5.2，C++ 桌面开发工作负载 | 提供 cl.exe 编译器 |
| Windows SDK | `C:\Program Files (x86)\Windows Kits\10\` | user32.lib, gdi32.lib 等 |

> **注意**：`php.exe` 必须使用 `swoole_compile*` 目录自带的 PHP 8.4，不能使用系统环境中的其他 PHP 版本。两者 AOT 兼容性不同。
>
> **提示**：swoole_compiler 版本更新后，只需将新版本文件夹（如 `swoole_compiler_vNNNN_windows_x86_64`）放到同一父目录下，`main_build.bat` 会通过 `swoole_compile*` 通配符自动识别，无需修改脚本。

---

## 常见问题

### Q: 双击 build.bat 闪退？

`build.bat` 需要参数（应用名称），双击会显示 Usage 后退出。请改用命令行或 PowerShell：

```powershell
powershell -Command "cd 'f:\work\pdv'; & '.\build.bat' calculator 2>&1"
```

如需交互式选择应用，请双击 `main_build.bat`。

常见构建失败原因：
- Visual Studio 2026 未安装或安装路径不是默认位置 → 修改 build.bat 中的 `VCVARSALL` 路径
- 构建后看不到输出 → 必须用 PowerShell，cmd/bash 中 `build.bat` 输出为空
- 未找到 swoole_compile* 目录 → 确保 swoole_compiler 文件夹与框架在同一父目录下
- vcvarsall.bat 执行失败 → 确认安装了 C++ 桌面开发工作负载

### Q: swoole_compiler 版本更新后怎么办？

只需将新版本的 swoole_compiler 文件夹（如 `swoole_compiler_v2000_windows_x86_64`）放到框架的同一父目录下即可。`main_build.bat` 使用 `swoole_compile*` 通配符自动发现编译器目录，无需修改任何脚本。如果存在多个匹配的目录，脚本会使用最后一个（通常是最新版本）。

### Q: 提示 `'cl' 不是内部或外部命令`？

MSVC 编译器环境未初始化。双击 build.bat 会自动调用 vcvarsall.bat。

如果在 MSYS2 中遇到此问题，请按上面"方式 2"的步骤先设置环境变量。

### Q: SFC 编译器报错？

检查以下项目：
- Calculator.vue 是否包含完整的三个块：`<template>` / `<script lang="php">` / `<style>`
- 嵌套组件 (`<display-panel>` 等) 对应的 `.vue` 文件是否存在于 `components/` 目录（SFC 编译器 v6 M3 自动扫描）

### Q: AOT 编译器报错？

常见错误及解决方案参见 `docs/构建编译流程参考.md` 第 7-8 节。最常见的几类：

| 错误 | 原因 | 解决 |
|------|------|------|
| `All execution code must be within a function` | require_once 写在顶层，或其他游离代码 | 删除 require 语句，内联工具函数 |
| `Cannot redeclare class X` | 重复声明 | 删除重复的 require/include |
| `Undefined variable $xxx` | 未初始化变量 | 显式赋初始值 |
| `error C3927` | 文件名含点号 | 改用下划线 |
| `找不到 php8embed.lib` | SDK/lib/ 路径未被搜索 | 脚本已自动处理；或手动复制到编译器根目录 |
| `Method accepts N arguments, N+1 given` | 事件处理器参数不匹配 | 使用可选参数 `?string $arg = null` |

### Q: MSYS2 / bash 中运行 build.bat 没反应？

这是因为 `build.bat` 内部调用 `chcp 65001` 切换代码页，在 bash/cmd/msys2 中会导致输出为空。**必须使用 PowerShell**：

```powershell
powershell -Command "cd 'f:\work\pdv'; & '.\build.bat' calculator 2>&1"
```

### Q: 根目录 main.php 去哪了？开发时如何运行？

根级 `main.php` 已移除。原因：AOT 兼容性要求所有代码必须在 function 内，`require_once` 在顶层属游离代码。

- **构建**：双击 `main_build.bat`（框架根）选择应用；或命令行 `build.bat calculator`
- **开发**：`php apps/calculator/main.php`

### Q: main_build.bat 是什么？

框架根的应用构建编排器。扫描 `apps/` 下所有含 `project.yml` 的应用，列出后供用户选择并启动构建。

所有路径基于 `%~dp0` 相对计算，编译器通过 `swoole_compile*` 通配符在父目录中自动发现，框架整体迁移后仍然有效。

### Q: 构建完成后产物在哪？

**`apps/<应用名>/bin/`** 目录下（每个应用拥有独立的可分发包）：

```
apps/calculator/bin/
├── calculator.exe ← 主程序
├── php8ts.dll     ← PHP 运行时
└── phpx.dll       ← PHPX 桥接库
```

直接运行 `apps\calculator\bin\calculator.exe` 即可，无需安装 PHP。

### Q: 为什么需要打包 DLL？

AOT 编译器采用动态链接（而非 Go 那样的纯静态编译），以减小 exe 体积。因此 exe 运行需要 PHP 运行时库。参照最佳实践，将三者打包在一起即可独立分发。

### Q: 中文显示乱码怎么办？

`.bat` 文件使用 UTF-8 编码，脚本在首个 `echo` 输出前自动执行 `chcp 65001` 切换到 UTF-8 代码页。如果仍有乱码，在运行前手动执行：

```cmd
chcp 65001
```

然后运行 target build.bat。Windows Terminal 默认支持 UTF-8，推荐使用。

---

## 文件说明

```
<父目录>/                           ← swoole_compiler 与框架的同级目录
├── swoole_compiler_vNNNN_x86_64/  ← [自动发现] 通配符 swoole_compile* 匹配
│   ├── php.exe                    ← PHP 8.4 CLI (SFC 编译 + AOT 运行时)
│   ├── swoole_compiler.exe        ← AOT 编译器
│   ├── php8ts.dll                 ← PHP 运行时 DLL
│   └── phpx.dll                   ← PHPX 桥接库 DLL
└── <框架目录>/                    ← 框架根 (可整体迁移)
    ├── main_build.bat             ← [编排器] 扫描 apps/, 选择构建目标应用
    ├── build.bat                 ← [非交互] build.bat <app> [--run]
    ├── project.yml                ← AOT 编译配置 (入口: apps/calculator/main.php)
    ├── framework/                 ← 可复用框架层 (v6 M1)
    │   ├── ReactiveComponent.php  ← 响应式组件基类
    │   ├── ChangeQueue.php        ← 变更队列 (环形缓冲)
    │   ├── BaseRenderer.php       ← 数据驱动渲染器 (v6 M1: activeLayouts + RenderContext)
    │   ├── rendering/              ← 渲染抽象层 (v6 M1)
    │   │   ├── RenderContext.php   ← 渲染抽象基类
    │   │   └── GdiRenderContext.php ← GDI 后端实现
    │   ├── sfc-compiler.php       ← SFC 编译器入口
    │   └── compiler/              ← 编译器模块
    │       ├── template-parser.php
    │       ├── script-analyzer.php
    │       ├── css-mappings.php
    │       ├── ast-nodes.php
    │       ├── aot-validator.php
    │       ├── component-registry.php
    │       └── component-resolver.php
    ├── apps/calculator/           ← 计算器应用层
    │   ├── main.php               ← [AOT 入口] 应用启动逻辑
    │   ├── Application.php        ← 窗口/事件循环控制器
    │   ├── App.vue                ← [源] SFC 单文件组件 (主入口)
    │   ├── project.yml            ← 应用级 AOT 配置
    │   ├── components/
    │   │   ├── DisplayPanel.vue   ← 可复用子组件 (v5 M2)
    │   │   ├── NumPad.vue         ← 可复用子组件 (v5 M2)
    │   │   └── AboutDialog.vue    ← 弹窗子组件 (v5 M3: overlay 分层)
    │   ├── gen/                   ← [自动生成] SFC 编译器输出
    │   │   ├── AppComponent.php     ← 根组件 (getLayout + onMount/onUnmount)
    │   │   ├── DisplayPanelComponent.php
    │   │   ├── NumPadComponent.php
    │   │   ├── AboutDialogComponent.php
    │   │   ├── ComponentFactory.php
    │   │   └── constants.php
    │   └── bin/                   ← [构建产物] 可分发包 (每个 app 独立)
    │       ├── calculator.exe     ← 计算器主程序 (~580KB)
    │       ├── php8ts.dll         ← PHP 8 运行时 (~11MB)
    │       └── phpx.dll           ← PHPX 桥接库 (~2MB)
    ├── stub/
    │   └── vue_calc.stub.php      ← C++ 函数 PHP 声明
    ├── cpp/
    │   └── vue_calc.cc            ← Win32 GDI 原生实现
    └── docs/
        ├── 构建编译流程参考.md     ← 详细技术文档
        └── BUILD.md               ← [本文件] 构建说明
```
