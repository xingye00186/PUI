<?php

use native_types;

/**
 * Application — 通用 SFC 应用控制器 (v6 M3)
 *
 * 负责窗口初始化、事件循环、点击分发、脏标记驱动的渲染调度。
 * v6 M3: 支持 v-if 动态组件（条件挂载/卸载）和组件缓存池。
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

    /** 组件实例缓存池 (key => ComponentInterface) - 用于 v-if 复用 */
    private array $componentPool = [];

    /** v-if 实例状态追踪 (key => bool) - 记录上次渲染时的条件值 */
    private array $vifStates = [];

    /** 渲染器 */
    private BaseRenderer $renderer;

    /** 渲染上下文 */
    private RenderContext $ctx;

    public function __construct(ReactiveComponent $root, RenderContext $ctx)
    {
        $this->rootComponent = $root;
        $this->ctx = $ctx;
        $this->initRenderer();
    }

    /**
     * 初始化渲染器
     * v6 M2: 从根组件获取初始组件树并挂载
     */
    public function initRenderer(): bool
    {
        // v6 M2: 从根组件获取初始组件树并挂载
        if ($this->rootComponent !== null) {
            $this->attachComponents($this->rootComponent->getBaseComponents());
        }

        // v6 M2: 创建渲染器（hWnd 由 ctx 持有）
        $this->renderer = new BaseRenderer($this->rootComponent, $this->ctx);

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
     * 挂载 v-if 动态组件（创建或从缓存池取出）
     * @param string $key 实例唯一标识
     * @param string $type 组件类名
     * @param array $props 组件属性（包含偏移）
     * @param ComponentInterface $parent 父组件引用
     * @return ComponentInterface
     */
    private function mountComponent(string $key, string $type, array $props, ComponentInterface $parent): ComponentInterface
    {
        // 优先从缓存池取出
        if (isset($this->componentPool[$key])) {
            $comp = $this->componentPool[$key];
            unset($this->componentPool[$key]);
        } else {
            // 创建新实例
            $comp = $this->createComponentInstance($type, $props);
        }

        $id = $comp->getId();
        $this->activeComponents[$id] = $comp;
        $comp->onAttach();

        // 设置父子关系
        if (method_exists($comp, 'setParent')) {
            $comp->setParent($parent);
        }

        return $comp;
    }

    /**
     * 卸载 v-if 动态组件（放入缓存池）
     * @param ComponentInterface $comp 组件实例
     */
    private function unmountComponent(ComponentInterface $comp): void
    {
        $id = $comp->getId();
        $key = $comp->getProps()['_poolKey'] ?? $id;

        if (isset($this->activeComponents[$id])) {
            $comp->onDetach();
            unset($this->activeComponents[$id]);
        }

        // 放入缓存池（最多缓存 10 个实例）
        $poolSize = count($this->componentPool);
        if ($poolSize >= 10) {
            // 移除最旧的
            $keys = array_keys($this->componentPool);
            unset($this->componentPool[$keys[0]]);
        }
        $this->componentPool[$key] = $comp;
    }

    /**
     * 创建组件实例
     * @param string $type 组件类名
     * @param array $props 组件属性
     * @return ComponentInterface
     */
    private function createComponentInstance(string $type, array $props): ComponentInterface
    {
        // 支持带命名空间的类名
        if (strpos($type, '\\') === false) {
            $type = 'components\\' . $type;
        }

        $comp = new $type();

        // 注入 props（保留 _poolKey 用于缓存池标识）
        if (method_exists($comp, 'setProps')) {
            $comp->setProps($props);
        }

        return $comp;
    }

    /**
     * 收集所有活跃组件的布局数据并应用偏移
     * v6 M2 核心逻辑: 遍历组件树，动态应用 offset/props
     *
     * @return array ['elements' => [...]] (统一 elements 数组，包含 rect/text/button)
     */
    public function getActiveLayout(): array
    {
        if ($this->rootComponent === null) {
            return ['elements' => []];
        }

        $allElements = [];

        $this->collectLayoutRecursive($this->rootComponent, 0, 0, $allElements);

        return ['elements' => $allElements];
    }

    /**
     * 递归收集组件树布局数据
     * v6 M3: 支持 v-if 动态组件（条件挂载/卸载）和 components 声明
     *
     * @param ComponentInterface $comp 当前组件
     * @param int $offsetX 累积 X 偏移
     * @param int $offsetY 累积 Y 偏移
     * @param array &$elements 收集的元素（包含 rect/text/button）
     */
    private function collectLayoutRecursive(
        ComponentInterface $comp,
        int $offsetX,
        int $offsetY,
        array &$elements
    ): void {
        $layout = $comp->getLayout();

        // 统一处理 elements (AOT 安全)
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

        // ====== v-if 动态组件处理 ======
        $vifComponents = (array)($layout['components'] ?? []);
        $vifCount = count($vifComponents);
        for ($i = 0; $i < $vifCount; $i++) {
            $vifEl = $vifComponents[$i];
            if (!is_array($vifEl)) continue;

            $type = $vifEl['type'] ?? '';
            $key = $vifEl['key'] ?? '';
            $props = (array)($vifEl['props'] ?? []);
            $vIf = $vifEl['vIf'] ?? null;

            if ($type === '' || $key === '') continue;

            // 计算当前条件值（使用结构化条件数组）
            $conditionMet = true;
            if ($vIf !== null && is_array($vIf) && $this->rootComponent !== null) {
                $conditionMet = $this->rootComponent->evalCondition($vIf);
            }

            $wasActive = $this->vifStates[$key] ?? false;

            if ($conditionMet && !$wasActive) {
                // 条件从 false 变为 true: 挂载组件
                $props['_poolKey'] = $key;
                $childComp = $this->mountComponent($key, $type, $props, $comp);
                $childOffsetX = $props['x'] ?? 0;
                $childOffsetY = $props['y'] ?? 0;
                $this->collectLayoutRecursive(
                    $childComp,
                    $offsetX + $childOffsetX,
                    $offsetY + $childOffsetY,
                    $elements
                );
            } elseif ($conditionMet && $wasActive) {
                // 条件始终为 true: 收集已挂载的组件布局
                $childComp = $this->activeComponents[$key] ?? null;
                if ($childComp !== null) {
                    $childOffsetX = $props['x'] ?? 0;
                    $childOffsetY = $props['y'] ?? 0;
                    $this->collectLayoutRecursive(
                        $childComp,
                        $offsetX + $childOffsetX,
                        $offsetY + $childOffsetY,
                        $elements
                    );
                }
            } else {
                // 条件为 false: 卸载组件（如果之前是活跃的）
                if ($wasActive && isset($this->activeComponents[$key])) {
                    $this->unmountComponent($this->activeComponents[$key]);
                }
            }

            // 更新状态追踪
            $this->vifStates[$key] = $conditionMet;
        }
        // v6 M3: 静态子组件已废弃，所有子组件通过 components 声明管理
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
     * v6 M2: 从 elements 中筛选 type='button' 的元素
     */
    private function handleClick(int $x, int $y): void
    {
        $layout = $this->getActiveLayout();
        $elements = (array)($layout['elements'] ?? []);

        // 收集所有按钮元素
        $buttons = [];
        $elCount = count($elements);
        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            if (!is_array($el)) continue;
            if (($el['type'] ?? '') === 'button') {
                $buttons[] = $el;
            }
        }

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