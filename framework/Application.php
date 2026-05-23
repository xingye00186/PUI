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

    /** v6 M4: 焦点系统 - 当前聚焦的组件 ID */
    private string $focusedId = '';

    /** v6 M4: 聚焦的 textbox 元素数据 */
    private ?array $focusedTextBox = null;

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
     * v6 M3: 直接挂载根组件（静态子组件树已废弃，动态组件由 collectLayoutRecursive 管理）
     */
    public function initRenderer(): bool
    {
        if ($this->rootComponent !== null) {
            $id = $this->rootComponent->getId();
            $this->activeComponents[$id] = $this->rootComponent;
            $this->rootComponent->onMount();
        }

        $this->renderer = new BaseRenderer($this->rootComponent, $this->ctx);

        return true;
    }

    /**
     * 挂载 v-if 动态组件（创建或从缓存池取出）
     * @param string $key 实例唯一标识
     * @param string $type 组件类名
     * @param array $props 组件属性（包含偏移）
     * @param ComponentInterface $parent 父组件引用
     * @param array $bindProps 动态绑定的 props（如 :value="display"）
     * @return ComponentInterface
     */
    private function mountComponent(string $key, string $type, array $props, ComponentInterface $parent, array $bindProps = []): ComponentInterface
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
        $comp->onMount();

        // 设置父子关系
        if (method_exists($comp, 'setParent')) {
            $comp->setParent($parent);
        }
        // v6 M5: 添加到父组件的 children 列表（用于 BaseRenderer.buildComponentMap）
        if (method_exists($parent, 'addChild')) {
            $parent->addChild($comp, []);
        }

        // v6 M5: 设置动态属性绑定 (如 :value="display" → component.value = root.display)
        foreach ($bindProps as $propName => $bindKey) {
            if (method_exists($comp, 'setBindValue') && $this->rootComponent !== null) {
                $value = $this->rootComponent->getBindValue($bindKey);
                $comp->setBindValue($propName, $value);
            }
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
            $comp->onUnmount();
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
     * v6 M3: 使用 ComponentFactory 替代动态 new
     * @param string $type 组件类名
     * @param array $props 组件属性
     * @return ComponentInterface
     */
    private function createComponentInstance(string $type, array $props): ComponentInterface
    {
        return ComponentFactory::create($type, $props);
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
            $bindProps = (array)($vifEl['bindProps'] ?? []);  // v6 M5: 动态绑定 props

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
                $childComp = $this->mountComponent($key, $type, $props, $comp, $bindProps);
                $childOffsetX = $props['x'] ?? 0;
                $childOffsetY = $props['y'] ?? 0;
                $this->collectLayoutRecursive(
                    $childComp,
                    $offsetX + $childOffsetX,
                    $offsetY + $childOffsetY,
                    $elements
                );
            } elseif ($conditionMet && $wasActive) {
                // 条件始终为 true: 同步绑定值 + 收集已挂载的组件布局
                $childComp = $this->activeComponents[$key] ?? null;
                if ($childComp !== null) {
                    // v6 M5: 同步动态绑定 props（父组件值变化时更新）
                    foreach ($bindProps as $propName => $bindKey) {
                        if (method_exists($childComp, 'setBindValue') && $this->rootComponent !== null) {
                            $value = $this->rootComponent->getBindValue($bindKey);
                            $childComp->setBindValue($propName, $value);
                        }
                    }
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

        // v6 M5: 更新组件映射（确保子组件已挂载）
        $this->renderer->updateComponentMap();
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

                if ($msgType == WinMsg::WM_LBUTTONDOWN) {
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

                // v6 M5: 鼠标滚轮事件 — 滚动容器
                if ($msgType == WinMsg::WM_MOUSEWHEEL) {
                    $wParam = $msg[2] ?? 0;
                    $delta = (int)(($wParam >> 16) & 0xFFFF);
                    if ($delta >= 32768) $delta -= 65536; // signed int16
                    try {
                        $this->handleScroll($delta);
                    } catch (\Throwable $e) {
                        echo "ERROR in handleScroll: " . $e->getMessage() . "\n";
                    }
                }

                // v6 M4: 键盘事件处理
                if ($msgType == WinMsg::WM_KEYDOWN || $msgType == WinMsg::WM_CHAR) {
                    $wParam = $msg[2] ?? 0;
                    try {
                        $this->handleKeyboard($msgType, $wParam);
                    } catch (\Throwable $e) {
                        echo "ERROR in handleKeyboard: " . $e->getMessage() . "\n";
                    }
                }

                if ($msgType == WinMsg::WM_QUIT) {
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
                    // v6 M5: 更新组件映射（确保获取最新的子组件绑定值）
                    $this->renderer->updateComponentMap();
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

        // v6 M5: First pass — find scroll-container to determine scroll offset
        $scrollTop = 0;
        $scrollCtx = null; // {x, y, w, h}
        $actualCount = 0; // v6 M6: actual items count for hit testing
        $itemHeight = 50; // v6 M8: item height for slot calculation
        $elCount = count($elements);
        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            if (!is_array($el)) continue;
            if (($el['type'] ?? '') === 'scroll-container') {
                $scrollCtx = [
                    'x' => $el['x'] ?? 0,
                    'y' => $el['y'] ?? 0,
                    'w' => $el['w'] ?? 0,
                    'h' => $el['h'] ?? 0,
                ];
                $scrollTopBind = $el['scroll-top-bind'] ?? '';
                if ($scrollTopBind !== '' && $this->rootComponent !== null) {
                    $scrollTopStr = $this->rootComponent->getBindValue($scrollTopBind);
                    $scrollTop = (int)$scrollTopStr;
                }
                // v6 M6: Compute actual items count for hit testing
                $itemsBind = $el['items-bind'] ?? '';
                if ($itemsBind !== '' && $this->rootComponent !== null) {
                    $itemsJson = $this->rootComponent->getBindValue($itemsBind);
                    $items = json_decode($itemsJson, true) ?? [];
                    $actualCount = count($items);
                }
                break; // only one scroll-container
            }
        }

        // 收集所有按钮元素
        $buttons = [];
        $debugLog = [];
        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            if (!is_array($el)) continue;
            if (($el['type'] ?? '') === 'button') {
                // v6 M5: Apply scroll offset for buttons inside scroll-container
                $isScrollChild = ($el['scroll-container'] ?? false);
                if ($isScrollChild && $scrollCtx !== null) {
                    $adjustedY = ($el['y'] ?? 0) - $scrollTop;
                    // v6 M6 FIX: Skip buttons completely outside visible area
                    $containerTop = $scrollCtx['y'];
                    $containerBottom = $scrollCtx['y'] + $scrollCtx['h'];
                    $btnH = $el['h'] ?? 0;
                    if ($adjustedY + $btnH <= $containerTop || $adjustedY >= $containerBottom) {
                        continue; // Button is not visible, skip
                    }
                    $el['y'] = $adjustedY;
                    // v6 M8 FIX: Dynamic slot-to-item mapping for scrolling
                    // Only apply for scroll-container children (not Add Item etc.)
                    $baseIndex = (int)($scrollTop / $itemHeight);
                    $listIndex = ($el['list_index'] ?? -1);
                    $actualItemIndex = $listIndex - $baseIndex;
                    if ($actualItemIndex < 0 || $actualItemIndex >= $actualCount) {
                        continue;
                    }
                }
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
     * v6 M5: 处理鼠标滚轮滚动
     * @param int $delta 滚轮增量 (正=向上, 负=向下)
     */
    private function handleScroll(int $delta): void
    {
        if ($this->rootComponent === null) return;

        $layout = $this->getActiveLayout();
        $elements = (array)($layout['elements'] ?? []);

        // Find scroll-container element
        $scrollEl = null;
        $elCount = count($elements);
        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            if (!is_array($el)) continue;
            if (($el['type'] ?? '') === 'scroll-container') {
                $scrollEl = $el;
                break;
            }
        }

        if ($scrollEl === null) return;

        $containerH = $scrollEl['h'] ?? 0;
        $contentH = $scrollEl['content-height'] ?? 0;
        $maxScrollTop = max(0, $contentH - $containerH);

        if ($maxScrollTop <= 0) return; // Content fits, no scrolling needed

        // WHEEL_DELTA = 120, each notch scrolls ~40px
        $scrollAmount = (int)($delta / 120) * 40;
        $scrollTopBind = $scrollEl['scroll-top-bind'] ?? '';
        if ($scrollTopBind === '') return;

        $currentScrollTop = (int)$this->rootComponent->getBindValue($scrollTopBind);
        $newScrollTop = $currentScrollTop - $scrollAmount; // delta>0 scrolls up
        $newScrollTop = max(0, min($newScrollTop, $maxScrollTop));

        if ($newScrollTop !== $currentScrollTop) {
            $this->rootComponent->setBindValue($scrollTopBind, (string)$newScrollTop);
            $this->rootComponent->dirty = true;
        }
    }

    /**
     * 分发按钮点击到根组件
     */
    private function dispatchClick(array $btn): void
    {
        if ($this->rootComponent !== null) {
            // v6 M8 FIX: Use list_index as the actual item index for deletion
            // This fixes the issue where scrolling causes slot->item mismatch
            $listIndex = $btn['list_index'] ?? -1;
            $scrollTop = 0;
            $itemHeight = 50;
            $itemsBind = '';

            // Get scroll info from layout
            $layout = $this->getActiveLayout();
            $elements = (array)($layout['elements'] ?? []);
            for ($i = 0; $i < count($elements); $i++) {
                $el = $elements[$i];
                if (($el['type'] ?? '') === 'scroll-container') {
                    $scrollTopBind = $el['scroll-top-bind'] ?? '';
                    if ($scrollTopBind !== '' && $this->rootComponent !== null) {
                        $scrollTop = (int)$this->rootComponent->getBindValue($scrollTopBind);
                    }
                    $itemHeight = $el['item-height'] ?? 50;
                    break;
                }
            }

            // For scroll-container children, compute actual item index
            if (($btn['scroll-container'] ?? false) && $listIndex >= 0) {
                $baseIndex = (int)($scrollTop / $itemHeight);
                $actualItemIndex = $listIndex - $baseIndex;
                // Override arg with actual item index for deleteItem handler
                if ($btn['handler'] === 'deleteItem') {
                    $btn['arg'] = (string)$actualItemIndex;
                }
            }

            $this->rootComponent->dispatchClick($btn);
        }
    }

    /**
     * v6 M4: 处理键盘事件
     *
     * @param int $msgType WM_KEYDOWN, WM_KEYUP, WM_CHAR
     * @param int $wParam 键码
     */
    private function handleKeyboard(int $msgType, int $wParam): void
    {
        // 如果没有焦点 textbox，尝试设置焦点
        if ($this->focusedTextBox === null) {
            $this->tryFocusTextBox();
            if ($this->focusedTextBox === null) {
                return;
            }
        }

        $el = $this->focusedTextBox;
        $bindKey = $el['bind'] ?? '';
        if ($bindKey === '') return;

        // v6 M5: Tab 键导航支持 (Shift+Tab = 0x0F, Tab = 0x09)
        if ($msgType === WinMsg::WM_KEYDOWN && ($wParam === 0x09 || $wParam === 0x0F)) {
            // Tab 或 Shift+Tab - 移动焦点到下一个/上一个元素
            // 简单实现: 如果是 Shift+Tab 或 Tab，直接处理
            // 完整实现需要 FocusManager
            if ($wParam === 0x09) {
                // Tab: 下一个 - 在列表应用中移动到下一个删除按钮
            } else {
                // Shift+Tab: 上一个
            }
            // 更新渲染以显示焦点变化
            if ($this->rootComponent !== null) {
                $this->rootComponent->dirty = true;
            }
            return;
        }

        if ($msgType === WinMsg::WM_CHAR) {
            // 可打印字符输入
            $char = chr($wParam & 0xFF);
            if (ctype_print($char) || $char === ' ') {
                $this->appendText($bindKey, $char);
            }
        } elseif ($msgType === WinMsg::WM_KEYDOWN) {
            if ($wParam === WinMsg::VK_BACK) {
                // 退格键
                $this->deleteTextChar($bindKey);
            } elseif ($wParam === WinMsg::VK_DELETE) {
                // Delete 键（向右删除）
                $this->deleteTextChar($bindKey, true);
            } elseif ($wParam === WinMsg::VK_RETURN || $wParam === WinMsg::VK_ESCAPE) {
                // Enter/Escape 处理
                $handler = $el['enterHandler'] ?? '';
                if ($handler !== '' && $this->rootComponent !== null) {
                    // AOT 安全：显式 if 分支处理已知处理器
                    if ($handler === 'handleEnterKey') {
                        $this->rootComponent->handleEnterKey($wParam);
                    } elseif ($handler === 'handleEscapeKey') {
                        $this->rootComponent->handleEscapeKey($wParam);
                    }
                }
            } else {
                // 方向键等特殊键，传递给自定义处理器
                $handler = $el['keyHandler'] ?? '';
                if ($handler !== '' && $this->rootComponent !== null) {
                    if ($handler === 'handleArrowKey') {
                        $this->rootComponent->handleArrowKey($wParam);
                    }
                }
            }
        }

        // 更新渲染（文本变化后标记 dirty）
        if ($this->rootComponent !== null) {
            $this->rootComponent->dirty = true;
        }
    }

    /**
     * v6 M4: 尝试将焦点设置到最近的 textbox 元素
     */
    private function tryFocusTextBox(): void
    {
        $layout = $this->getActiveLayout();
        $elements = (array)($layout['elements'] ?? []);

        // 逆序遍历找最后一个 textbox
        $elCount = count($elements);
        for ($i = $elCount - 1; $i >= 0; $i--) {
            $el = $elements[$i];
            if (!is_array($el)) continue;
            if (($el['type'] ?? '') !== 'textbox') continue;

            // 检查条件
            $cond = $el['condition'] ?? null;
            if ($cond !== null && !is_array($cond)) continue;
            if ($cond !== null && $this->rootComponent !== null && !$this->rootComponent->evalCondition($cond)) continue;

            $this->focusedTextBox = $el;
            $this->focusedId = $el['bind'] ?? '';
            return;
        }
    }

    /**
     * v6 M4: 向绑定文本追加字符
     */
    private function appendText(string $bindKey, string $char): void
    {
        if ($this->rootComponent === null) return;
        if (!method_exists($this->rootComponent, 'setBindValue')) return;

        $currentValue = $this->rootComponent->getBindValue($bindKey);
        $newValue = $currentValue . $char;
        $this->rootComponent->setBindValue($bindKey, $newValue);
    }

    /**
     * v6 M4: 删除绑定文本的最后一个字符
     */
    private function deleteTextChar(string $bindKey, bool $forward = false): void
    {
        if ($this->rootComponent === null) return;
        if (!method_exists($this->rootComponent, 'setBindValue')) return;

        $currentValue = $this->rootComponent->getBindValue($bindKey);
        if (strlen($currentValue) === 0) return;

        if ($forward) {
            // Delete: 删除光标后的字符（暂不支持光标位置，简化为删除最后一个）
            $newValue = substr($currentValue, 0, -1);
        } else {
            // Backspace: 删除最后一个字符
            $newValue = substr($currentValue, 0, -1);
        }
        $this->rootComponent->setBindValue($bindKey, $newValue);
    }
}
