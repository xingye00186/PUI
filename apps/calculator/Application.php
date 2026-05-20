<?php

use native_types;

/**
 * Application — 通用 SFC 应用控制器 (v6 M2)
 *
 * 负责窗口初始化、事件循环、点击分发、脏标记驱动的渲染调度。
 * v6 M2: 组件管理移至 Application，支持组件树结构和动态偏移应用。
 *
 * AOT 兼容性:
 *   - 使用 array_keys() + for 循环遍历关联数组
 *   - 使用 (array) 类型转换保留数组引用
 */
class Application
{
    /** 根组件引用 */
    private ?ReactiveComponent $rootComponent = null;

    /** 活跃组件列表 (id => ComponentInterface) */
    private array $activeComponents = [];

    /** 当前窗口句柄 */
    private int $hWnd = 0;

    /** 渲染器 */
    private BaseRenderer $renderer;

    /** 渲染上下文 */
    private RenderContext $ctx;

    public function __construct(ReactiveComponent $root, RenderContext $ctx)
    {
        $this->rootComponent = $root;
        $this->ctx = $ctx;
    }

    /**
     * 注册根组件（v6 M2）
     * 由 main.php 在创建 Application 时调用
     */
    public function registerRootComponent(ReactiveComponent $root): void
    {
        $this->rootComponent = $root;
    }

    /**
     * 初始化窗口
     * v6 M2: 从根组件获取初始组件树并挂载
     */
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

        // v6 M2: 从根组件获取初始组件树并挂载
        if ($this->rootComponent !== null) {
            $this->attachComponents($this->rootComponent->getBaseComponents());
        }

        // v6 M2: 创建渲染器，注入预处理后的布局数据
        $this->renderer = new BaseRenderer($this->hWnd, $this->rootComponent, $this->ctx);

        echo "Window created (SFC Component Mode v6 M2)\n";
        return true;
    }

    /**
     * 批量挂载组件到活跃列表
     * @param array $components ComponentInterface[]
     */
    private function attachComponents(array $components): void
    {
        foreach ($components as $comp) {
            if ($comp instanceof ComponentInterface) {
                $id = $comp->getId();
                $this->activeComponents[$id] = $comp;
                $comp->onAttach();
            }
        }
    }

    /**
     * 从活跃列表卸载组件
     * @param string $id 组件标识
     */
    public function detachComponent(string $id): void
    {
        if (isset($this->activeComponents[$id])) {
            $this->activeComponents[$id]->onDetach();
            unset($this->activeComponents[$id]);
        }
    }

    /**
     * 获取活跃组件列表
     * @return array ComponentInterface[]
     */
    public function getActiveComponents(): array
    {
        return $this->activeComponents;
    }

    /**
     * 收集所有活跃组件的布局数据并应用偏移
     * v6 M2 核心逻辑: 遍历组件树，动态应用 offset/props
     *
     * @return array ['elements' => [], 'buttons' => []]
     */
    public function getActiveLayout(): array
    {
        if ($this->rootComponent === null) {
            return ['elements' => [], 'buttons' => []];
        }

        $allElements = [];
        $allButtons = [];

        $this->collectLayoutRecursive($this->rootComponent, 0, 0, $allElements, $allButtons);

        return ['elements' => $allElements, 'buttons' => $allButtons];
    }

    /**
     * 递归收集组件树布局数据
     *
     * @param ComponentInterface $comp 当前组件
     * @param int $offsetX 累积 X 偏移
     * @param int $offsetY 累积 Y 偏移
     * @param array &$elements 收集的元素
     * @param array &$buttons 收集的按钮
     */
    private function collectLayoutRecursive(
        ComponentInterface $comp,
        int $offsetX,
        int $offsetY,
        array &$elements,
        array &$buttons
    ): void {
        $layout = $comp->getLayout();

        // 应用偏移到 elements (AOT 安全)
        $layoutElements = (array)($layout['elements'] ?? []);
        $elCount = count($layoutElements);
        for ($i = 0; $i < $elCount; $i++) {
            $el = $layoutElements[$i];
            if (is_array($el)) {
                $el['x'] = ($el['x'] ?? 0) + $offsetX;
                $el['y'] = ($el['y'] ?? 0) + $offsetY;
                // container 坐标也需偏移
                if (isset($el['containerX'])) {
                    $el['containerX'] += $offsetX;
                }
                if (isset($el['containerY'])) {
                    $el['containerY'] += $offsetY;
                }
                $elements[] = $el;
            }
        }

        // 应用偏移到 buttons (AOT 安全)
        $layoutButtons = (array)($layout['buttons'] ?? []);
        $btnCount = count($layoutButtons);
        for ($i = 0; $i < $btnCount; $i++) {
            $btn = $layoutButtons[$i];
            if (is_array($btn)) {
                $btn['x'] = ($btn['x'] ?? 0) + $offsetX;
                $btn['y'] = ($btn['y'] ?? 0) + $offsetY;
                $buttons[] = $btn;
            }
        }

        // 递归处理子组件
        $children = $comp->getChildren();
        $childIds = array_keys($children);
        $childCount = count($childIds);
        for ($i = 0; $i < $childCount; $i++) {
            $child = $children[$childIds[$i]];
            $props = $child->getProps();
            $childOffsetX = $props['x'] ?? 0;
            $childOffsetY = $props['y'] ?? 0;
            $this->collectLayoutRecursive(
                $child,
                $offsetX + $childOffsetX,
                $offsetY + $childOffsetY,
                $elements,
                $buttons
            );
        }
    }

    /**
     * 主事件循环
     */
    public function run(): void
    {
        $running = true;

        // v6 M2: 传递预处理后的布局数据给渲染器
        $this->renderer->render($this->getActiveLayout());

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
            if ($this->rootComponent !== null && $this->rootComponent->dirty) {
                try {
                    // v6 M2: 传递预处理后的布局数据
                    $this->renderer->render($this->getActiveLayout());
                } catch (\Throwable $e) {
                    echo "RENDER ERROR: " . $e->getMessage() . "\n";
                }
                $this->rootComponent->dirty = false;
            }

            usleep(16000); // ~60 FPS
        }

        echo "App closed\n";
    }

    /**
     * 处理鼠标点击: 分层命中测试
     * v6 M2: 从 activeComponents 收集布局
     */
    private function handleClick(int $x, int $y): void
    {
        $layout = $this->getActiveLayout();
        $buttons = (array)($layout['buttons'] ?? []);
        $btnCount = count($buttons);

        // Phase 1: 确定最高活跃层 (AOT 安全)
        $maxLayer = 0;
        for ($i = 0; $i < $btnCount; $i++) {
            $btn = $buttons[$i];
            if (!is_array($btn)) continue;
            $cond = $btn['condition'] ?? null;
            if ($cond !== null && !is_array($cond)) continue;
            if ($cond !== null && $this->rootComponent !== null && !$this->rootComponent->evalCondition($cond)) continue;
            $layer = $btn['layer'] ?? 0;
            if ($layer > $maxLayer) $maxLayer = $layer;
        }

        // Phase 2: 从最高层向下逆序命中测试
        for ($l = $maxLayer; $l >= 0; $l--) {
            for ($i = $btnCount - 1; $i >= 0; $i--) {
                $btn = $buttons[$i];
                if (!is_array($btn)) continue;
                $btnLayer = $btn['layer'] ?? 0;
                if ($btnLayer !== $l) continue;
                $cond = $btn['condition'] ?? null;
                if ($btnLayer < $maxLayer && $cond !== null) continue;
                if ($cond !== null && !is_array($cond)) continue;
                if ($cond !== null && $this->rootComponent !== null && !$this->rootComponent->evalCondition($cond)) continue;

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

    /**
     * 分发按钮点击到根组件
     */
    private function dispatchClick(array $btn): void
    {
        if ($this->rootComponent !== null) {
            $this->rootComponent->dispatchClick($btn);
        }
    }
}