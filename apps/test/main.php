<?php

use native_types;

/**
 * VueCalc v6 M4 — Test Application Entry Point
 *
 * 功能测试:
 *   - Flex 布局引擎
 *   - TextBox 组件 + 键盘事件
 *   - v-model 双向绑定
 *
 * AOT 编译由此文件开始。project.yml sources 引用此文件。
 */

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    // Windows ShowWindow command
    $showCmd = 1;

    echo "========================================\n";
    echo "  VueCalc v6 M4 — Flex/TextBox Test App\n";
    echo "  Pipeline: .vue → SFC Compiler → AOT → .exe\n";
    echo "========================================\n\n";

    // 1. 创建根组件 AppComponent
    $root = new AppComponent('App');
    $root->initShared(10240);

    // 2. 初始化窗口，获取 hWnd
    $hWnd = vue_window_create(
        'VueCalc v6 M4 Test',
        WINDOW_WIDTH,
        WINDOW_HEIGHT
    );

    if ($hWnd == 0) {
        echo "Error: window creation failed!\n";
        return 1;
    }

    echo "Window initialized (Flex/TextBox/v-model Test)\n";

    vue_window_show($hWnd, $showCmd);

    // 3. 创建渲染上下文（持有 hWnd）
    $ctx = new GdiRenderContext($hWnd);

    // 4. 创建应用控制器
    $app = new Application($root, $ctx);

    // 5. 启动事件循环
    $app->run();

    echo "\nApplication closed.\n";
    return 0;
}