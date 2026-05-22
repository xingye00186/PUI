<?php

use native_types;

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    echo "========================================\n";
    echo "  VueCalc v6 M5 — v-for List Test App\n";
    echo "  Pipeline: .vue → SFC Compiler → AOT → .exe\n";
    echo "========================================\n\n";

    // 1. Create root component
    $root = new AppComponent('App');
    $root->initShared(10240);

    // 2. Initialize window
    $hWnd = vue_window_create('v-for List Test', WINDOW_WIDTH, WINDOW_HEIGHT);

    if ($hWnd == 0) {
        echo "Error: window creation failed!\n";
        return 1;
    }

    echo "Window initialized (v-for List Test)\n";
    vue_window_show($hWnd, WinMsg::SW_SHOW);

    // 3. Create render context
    $ctx = new GdiRenderContext($hWnd);

    // 4. Create application
    $app = new Application($root, $ctx);

    // 5. Run event loop
    $app->run();

    echo "\nApplication closed.\n";
    return 0;
}