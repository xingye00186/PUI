<?php

/**
 * Application — 通用 SFC 应用控制器 (v6 M1)
 * 
 * 负责窗口初始化、事件循环、点击分发、脏标记驱动的渲染调度。
 * 支持分段布局: 初始化时 attach 所有组件布局。
 * 支持渲染抽象: 通过 RenderContext 参数化渲染后端。
 */
class Application
{
    private ReactiveComponent $component;
    private int $hWnd;
    private BaseRenderer $renderer;
    private RenderContext $ctx;

    public function __construct(ReactiveComponent $component, RenderContext $ctx)
    {
        $this->component = $component;
        $this->ctx = $ctx;
        $this->hWnd = 0;
    }

    /** 初始化窗口 */
    public function initWindow(): bool
    {
        $this->hWnd = vue_window_create(
            'VueCalc - SFC Data-Driven App',
            WINDOW_WIDTH,
            WINDOW_HEIGHT
        );

        if ($this->hWnd == 0) {
            echo "Error: window creation failed!\n";
            return false;
        }

        vue_window_show($this->hWnd, SW_SHOW);

        // v6 M1: 创建渲染器并 attach 所有分段布局
        $this->renderer = new BaseRenderer($this->hWnd, $this->component, $this->ctx);
        // v6 M1: 动态注册所有编译器生成的布局段
        $segNames = getLayoutSegmentNames();

        foreach ($segNames as $i => $segName) {
            $this->renderer->attachLayout($segName);
        }
   

        echo "Window created (SFC Data-Driven Mode)\n";
        return true;
    }

    /** 主事件循环 */
    public function run(): void
    {
        $running = true;
        $this->renderer->render(); // 首帧
        echo "App started!\n";

        while ($running) {
            while (true) {
                $msg = vue_peek_message();
                if (count($msg) == 0) {
                    break;
                }

                $msgType = $msg[1] ?? 0;

                if ($msgType == WM_LBUTTONDOWN) {
                    $lParam = $msg[3] ?? 0;
                    $mx = $lParam & 0xFFFF;
                    $my = ($lParam >> 16) & 0xFFFF;
                    try {
                        $this->handleClick($mx, $my);
                    } catch (\Throwable $e) {
                        echo "ERROR in handleClick: " . $e->getMessage() . "\n";
                        echo $e->getTraceAsString() . "\n";
                    }
                }

                if ($msgType == WM_QUIT) {
                    $running = false;
                    break;
                }
            }

            if (vue_quit_requested()) {
                $running = false;
            }
            if (!$running) {
                break;
            }

            // 数据驱动渲染: 仅在组件状态变更后重绘
            if ($this->component->dirty) {
                try {
                    $this->renderer->render();
                } catch (\Throwable $e) {
                    echo "RENDER ERROR: " . $e->getMessage() . "\n";
                }
                $this->component->dirty = false;
            }

            usleep(16000); // ~60 FPS
        }

        echo "App closed\n";
    }

    /** 处理鼠标点击: 分层命中测试 (v6 M1: 使用 activeLayouts) */
    private function handleClick(int $x, int $y): void
    {
        $layout = $this->renderer->getActiveLayout();
        $buttons = (array) $layout['buttons'];

        // Phase 1: 确定最高活跃层
        $maxLayer = 0;
        foreach ($buttons as $btn) {
            // AOT 安全检查
            if (!is_array($btn)) continue;
            if (isset($btn['condition']) && !$this->component->evalCondition($btn['condition'])) continue;
            $layer = $btn['layer'] ?? 0;
            if ($layer > $maxLayer) $maxLayer = $layer;
        }

        // Phase 2: 从最高层向下逆序命中测试
        for ($l = $maxLayer; $l >= 0; $l--) {
            for ($i = count($buttons) - 1; $i >= 0; $i--) {
                $btn = $buttons[$i];
                // AOT 安全检查
                if (!is_array($btn)) continue;
                $btnLayer = $btn['layer'] ?? 0;
                if ($btnLayer !== $l) continue;
                if ($btnLayer < $maxLayer && isset($btn['condition'])) continue;
                if (isset($btn['condition']) && !$this->component->evalCondition($btn['condition'])) continue;

                // 安全访问坐标
                $btnX = $btn['x'] ?? 0;
                $btnY = $btn['y'] ?? 0;
                $btnW = $btn['w'] ?? 0;
                $btnH = $btn['h'] ?? 0;

                if ($x >= $btnX && $x < $btnX + $btnW &&
                    $y >= $btnY && $y < $btnY + $btnH) {
                    $this->dispatchClick($btn);
                    return;
                }
            }
        }
    }

    /** 分发按钮点击到组件方法 */
    private function dispatchClick(array $btn): void
    {
        $this->component->dispatchClick($btn);
    }
}