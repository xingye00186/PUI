<?php

/**
 * VueCalc v6 M2 — Application Entry Point
 *
 * v6 M2 变更:
 *   - 使用 AppComponent 替代 App
 *   - 子组件由 v-if 动态声明，通过 components 管理
 *   - Application 构造时直接挂载根组件并调用 onMount()
 *
 * AOT 编译由此文件开始。project.yml sources 引用此文件。
 */

// Windows 消息常量
const SW_SHOW = 5;
const WM_LBUTTONDOWN = 0x0201;
const WM_QUIT = 0x0012;

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    echo "========================================\n";
    echo "  VueCalc v6 M2 — SFC Component Architecture\n";
    echo "  Pipeline: .vue → SFC Compiler → *Component.php → AOT → .exe\n";
    echo "========================================\n\n";

    // 1. 创建根组件 AppComponent
    //    子组件由 AppComponent 构造函数内部通过 registerChildren() 注册
    $root = new AppComponent('App');
    $root->initShared(10240);

    // 2. 初始化窗口，获取 hWnd
    $hWnd = vue_window_create(
        'SFC Data-Driven App',
        WINDOW_WIDTH,
        WINDOW_HEIGHT
    );

    if ($hWnd == 0) {
        echo "Error: window creation failed!\n";
        return 1;
    }

     echo "Window initialized (SFC Component Mode v6 M2)\n";

    vue_window_show($hWnd, SW_SHOW);

    // 3. 创建渲染上下文（持有 hWnd）
    $ctx = new GdiRenderContext($hWnd);

    // 4. 创建应用控制器（构造时直接挂载根组件）
    $app = new Application($root, $ctx);

    // 5. 启动事件循环
    $app->run();

    echo "\nApplication closed.\n";
    return 0;
}