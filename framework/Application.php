<?php

use native_types;

/**
 * Application — VNode-driven application controller (v7)
 *
 * 负责窗口初始化、VNode 事件循环、点击分发、脏标记驱动的渲染调度。
 * v7 变更:
 *   - 使用 VNode 树替代 flat element 数组
 *   - LayoutResolver 计算位置, VNodeRenderer 渲染
 *   - dispatchClick 使用 match 表达式 (PHP 8.4)
 *   - 支持 v-if 动态组件管理
 *
 * AOT 兼容性:
 *   - 使用 array_keys() + for 循环遍历关联数组
 */
class Application
{
    /** 根组件 */
    private ?ReactiveComponent $rootComponent = null;

    /** 活跃组件列表 (id => ComponentInterface) */
    private array $activeComponents = [];

    /** 组件实例缓存池 (key => ComponentInterface) - v-if 复用 */
    private array $componentPool = [];

    /** v-if 实例状态追踪 */
    private array $vifStates = [];

    /** 焦点系统 */
    private string $focusedId = '';

    /** 聚焦的 input 元素 VNode */
    private ?VNode $focusedInput = null;

    /** 渲染器 */
    private VNodeRenderer $renderer;

    /** 布局解析器 */
    private LayoutResolver $layoutResolver;

    /** 渲染上下文 */
    private RenderContext $ctx;

    /** CSS class styles (从 <style> 解析) */
    private array $classStyles = [];

    /** 当前活动的 VNode 树根 */
    private ?VNode $activeVNodeTree = null;

    /** 当前滚动容器列表 (从 LayoutResolver) */
    private array $scrollContainers = [];

    public function __construct(ReactiveComponent $root, RenderContext $ctx)
    {
        $this->rootComponent = $root;
        $this->ctx = $ctx;
        $this->initRenderer();
    }

    /**
     * 设置 CSS class styles (从 SFC 编译期传入)
     */
    public function setClassStyles(array $classStyles): void
    {
        $this->classStyles = $classStyles;
        $this->layoutResolver = new LayoutResolver($classStyles);
    }

    /**
     * 初始化渲染器
     */
    public function initRenderer(): bool
    {
        if ($this->rootComponent !== null) {
            $id = $this->rootComponent->getId();
            $this->activeComponents[$id] = $this->rootComponent;
            $this->rootComponent->onMount();
        }

        // Load CSS class styles from generated component if available
        if ($this->rootComponent !== null && method_exists($this->rootComponent, 'getClassStyles')) {
            $this->classStyles = $this->rootComponent->getClassStyles();
        }

        $this->layoutResolver = new LayoutResolver($this->classStyles);
        $this->renderer = new VNodeRenderer($this->rootComponent, $this->ctx);

        return true;
    }

    // ============================================================
    // v-if 动态组件管理 (保留)
    // ============================================================

    private function mountComponent(string $key, string $type, array $props, ComponentInterface $parent, array $bindProps = []): ComponentInterface
    {
        if (isset($this->componentPool[$key])) {
            $comp = $this->componentPool[$key];
            unset($this->componentPool[$key]);
        } else {
            $comp = ComponentFactory::create($type, $props);
        }

        $id = $comp->getId();
        $this->activeComponents[$id] = $comp;
        $comp->onMount();

        if (method_exists($comp, 'setParent')) {
            $comp->setParent($parent);
        }
        if (method_exists($parent, 'addChild')) {
            $parent->addChild($comp, []);
        }

        foreach ($bindProps as $propName => $bindKey) {
            if (method_exists($comp, 'setBindValue') && $this->rootComponent !== null) {
                $value = $this->rootComponent->setBindValue($bindKey, '');
                if (method_exists($this->rootComponent, 'getBindValue')) {
                    $value = $this->rootComponent->getBindValue($bindKey);
                    $comp->setBindValue($propName, $value);
                }
            }
        }

        return $comp;
    }

    private function unmountComponent(ComponentInterface $comp): void
    {
        $id = $comp->getId();
        $key = $comp->getProps()['_poolKey'] ?? $id;

        if (isset($this->activeComponents[$id])) {
            $comp->onUnmount();
            unset($this->activeComponents[$id]);
        }

        $poolSize = count($this->componentPool);
        if ($poolSize >= 10) {
            $keys = array_keys($this->componentPool);
            unset($this->componentPool[$keys[0]]);
        }
        $this->componentPool[$key] = $comp;
    }

    /**
     * Trigger a VNode tree rebuild and re-render.
     */
    public function rebuildVNodeTree(): VNode
    {
        // Collect VNode trees from root and active components
        $rootVNode = null;

        if ($this->rootComponent !== null) {
            $rootVNode = $this->rootComponent->render();
        }

        if ($rootVNode === null) {
            $rootVNode = VNode::h('#root', ['style' => 'width:400px;height:500px'], []);
        }

        // Merge in active child components
        $this->mergeChildVNodes($rootVNode);

        // Resolve layout positions
        $result = $this->layoutResolver->resolve($rootVNode);
        $this->scrollContainers = $result['scrollContainers'] ?? [];

        $this->activeVNodeTree = $rootVNode;
        return $rootVNode;
    }

    /**
     * Merge child component VNode trees into the root.
     */
    private function mergeChildVNodes(VNode $root): void
    {
        foreach ($this->activeComponents as $id => $comp) {
            if ($comp === $this->rootComponent) continue;
            if (!($comp instanceof ReactiveComponent)) continue;

            try {
                $childVNode = $comp->render();
                if ($childVNode !== null) {
                    // Collect child VNode's children
                    $childChildren = [];
                    if ($childVNode->children instanceof VNode) {
                        $childVNode->children->groupId = $id;
                        $childChildren = [$childVNode->children];
                    } elseif (is_array($childVNode->children)) {
                        foreach ($childVNode->children as $gc) {
                            if ($gc instanceof VNode) {
                                $gc->groupId = $id;
                            }
                        }
                        $childChildren = $childVNode->children;
                    }
                    // Append to root's children
                    if (!empty($childChildren)) {
                        if ($root->children instanceof VNode) {
                            $root->children = [$root->children];
                        } elseif (!is_array($root->children)) {
                            $root->children = [];
                        }
                        $root->children = array_merge($root->children, $childChildren);
                    }
                }
            } catch (\Throwable $e) {
                echo "ERROR in child render ($id): " . $e->getMessage() . "\n";
            }
        }
    }

    // ============================================================
    // 主事件循环
    // ============================================================

    public function run(): void
    {
        // Initial render
        $rootVNode = $this->rebuildVNodeTree();
        $this->renderer->render($rootVNode);

        echo "App started!\n";

        $running = true;

        while ($running) {
            while (true) {
                $msg = vue_peek_message();
                if (count($msg) == 0) break;

                $msgType = $msg[1] ?? 0;

                if ($msgType == WinMsg::WM_LBUTTONDOWN) {
                    $lParam = $msg[3] ?? 0;
                    $mx = $lParam & 0xFFFF;
                    $my = ($lParam >> 16) & 0xFFFF;
                    try {
                        $this->handleClick($mx, $my);
                    } catch (\Throwable $e) {
                        echo "ERROR in handleClick: " . $e->getMessage() . "\n";
                    }
                }

                if ($msgType == WinMsg::WM_MOUSEWHEEL) {
                    $wParam = $msg[2] ?? 0;
                    $delta = (int)(($wParam >> 16) & 0xFFFF);
                    if ($delta >= 32768) $delta -= 65536;
                    try {
                        $this->handleScroll($delta);
                    } catch (\Throwable $e) {
                        echo "ERROR in handleScroll: " . $e->getMessage() . "\n";
                    }
                }

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
            if (!$running) break;

            // Dirty check: re-render if needed
            if ($this->rootComponent !== null && $this->rootComponent->dirty) {
                try {
                    $rootVNode = $this->rebuildVNodeTree();
                    $this->renderer->render($rootVNode);
                } catch (\Throwable $e) {
                    echo "RENDER ERROR: " . $e->getMessage() . "\n";
                }
                $this->rootComponent->dirty = false;
            }

            usleep(16000); // ~60 FPS
        }

        echo "App closed\n";
    }

    // ============================================================
    // 点击处理 — VNode 树遍历
    // ============================================================

    private function handleClick(int $x, int $y): void
    {
        if ($this->activeVNodeTree === null) return;

        // Check scroll bar hit first
        if ($this->handleScrollBarClick($x, $y)) return;

        // Find the topmost button VNode at click position
        $hit = $this->findHitButton($this->activeVNodeTree, $x, $y);
        if ($hit !== null) {
            $this->dispatchVNodeClick($hit);
        }
    }

    /**
     * Walk the VNode tree to find a button at the click position.
     *
     * Layer-aware two-phase algorithm:
     *   1. Collect ALL nodes (clickable + non-clickable) covering the click point
     *   2. Determine max active layer among all covering nodes
     *   3. Return the last (topmost in tree order) clickable node on that layer
     *
     * Higher-layer non-clickable elements (overlays) naturally block
     * lower-layer buttons — no need for manual @click on overlays.
     */
    private function findHitButton(VNode $node, int $x, int $y): ?VNode
    {
        // Phase 1: Collect all covering nodes with layer info
        // Parent layer cascades: children inherit max(child.layer, parentLayer)
        $candidates = [];
        $this->collectCoveringNodes($node, $x, $y, 0, $candidates);

        if (count($candidates) === 0) return null;

        // Phase 2: Find max layer among all covering nodes
        $maxLayer = 0;
        foreach ($candidates as $c) {
            if ($c['layer'] > $maxLayer) $maxLayer = $c['layer'];
        }

        // Phase 3: Find clickable node on maxLayer (last in tree order wins)
        $hit = null;
        foreach ($candidates as $c) {
            if ($c['clickable'] && $c['layer'] >= $maxLayer) {
                $hit = $c['node'];
            }
        }

        return $hit;
    }

    /**
     * Recursively collect all nodes covering the click point into candidates.
     *
     * @param int $parentLayer Accumulated parent layer — children inherit
     *                         effective layer = max(node->layer, parentLayer)
     *
     * Each candidate has: ['node' => VNode, 'layer' => int, 'clickable' => bool]
     */
    private function collectCoveringNodes(VNode $node, int $x, int $y, int $parentLayer, array &$candidates): void
    {
        // Check v-if condition: skip invisible subtrees
        $vif = ($node->props !== null) ? ($node->props['v-if'] ?? '') : '';
        if ($vif !== '' && $this->rootComponent !== null && method_exists($this->rootComponent, 'getBindValue')) {
            $cond = $this->rootComponent->getBindValue($vif);
            if (!$cond) return;
        }

        // Compute effective layer (own layer or inherited from parent)
        $effectiveLayer = max($node->layer, $parentLayer);

        // Recurse into children first (deeper nodes are on top)
        if ($node->children instanceof VNode) {
            $this->collectCoveringNodes($node->children, $x, $y, $effectiveLayer, $candidates);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->collectCoveringNodes($child, $x, $y, $effectiveLayer, $candidates);
                }
            }
        }

        // Check if current node covers the click point
        $nx = $node->x;
        $ny = $node->y;
        $nw = $node->w;
        $nh = $node->h;

        // Apply scroll container offset
        foreach ($this->scrollContainers as $sc) {
            if ($this->isInsideScrollContainer($node, $sc)) {
                $ny -= $sc->scrollTop;
            }
        }

        if ($nw > 0 && $nh > 0 &&
            $x >= $nx && $x < $nx + $nw &&
            $y >= $ny && $y < $ny + $nh) {

            $isClickable = ($node->type === 'button' || ($node->type === 'div' && isset($node->props['@click'])));

            $candidates[] = [
                'node'      => $node,
                'layer'     => $effectiveLayer,
                'clickable' => $isClickable,
            ];
        }
    }

    /**
     * Check if a VNode is a child of a scroll container.
     */
    private function isInsideScrollContainer(VNode $node, VNode $container): bool
    {
        if ($node === $container) return false;
        if ($node->x >= $container->x && $node->x < $container->x + $container->w &&
            $node->y >= $container->y - $container->scrollTop &&
            $node->y + $node->h <= $container->y + $container->h - $container->scrollTop) {
            return true;
        }
        return false;
    }

    /**
     * Dispatch click from a VNode button.
     */
    private function dispatchVNodeClick(VNode $btn): void
    {
        if ($this->rootComponent === null) return;

        $handler = $btn->props['@click'] ?? '';
        $arg = $btn->props['click-arg'] ?? null;

        if ($handler === '') return;

        $this->rootComponent->dispatchClick($handler, $arg);
    }

    // ============================================================
    // 滚动处理
    // ============================================================

    private function handleScrollBarClick(int $x, int $y): bool
    {
        if ($this->rootComponent === null) return false;

        foreach ($this->scrollContainers as $sc) {
            $sx = $sc->x;
            $sy = $sc->y;
            $sw = $sc->w;
            $sh = $sc->h;

            // Check if click is in scrollbar area (right 12px of container)
            $thumbW = 8;
            $thumbX = $sx + $sw - $thumbW;

            if ($x < $thumbX || $x > $thumbX + $thumbW) continue;
            if ($y < $sy || $y > $sy + $sh) continue;

            $scrollTopBind = $sc->props[':scroll-top'] ?? '';
            if ($scrollTopBind === '') continue;

            // Calculate new scroll position
            $contentH = max($sc->contentHeight, 1);
            $maxScrollTop = max(0, $contentH - $sh);
            if ($maxScrollTop <= 0) return false;

            $ratio = ($y - $sy) / max($sh, 1);
            $newScrollTop = (int)($ratio * $maxScrollTop);
            $newScrollTop = max(0, min($newScrollTop, $maxScrollTop));

            if ($newScrollTop !== $sc->scrollTop) {
                if (method_exists($this->rootComponent, 'setBindValue')) {
                    $this->rootComponent->setBindValue($scrollTopBind, (string)$newScrollTop);
                }
                $this->rootComponent->dirty = true;
            }
            return true;
        }

        return false;
    }

    private function handleScroll(int $delta): void
    {
        if ($this->rootComponent === null) return;

        if (count($this->scrollContainers) === 0) return;

        $sc = $this->scrollContainers[0];
        $scrollTopBind = $sc->props[':scroll-top'] ?? '';
        if ($scrollTopBind === '') return;

        $contentH = max($sc->contentHeight, 1);
        $maxScrollTop = max(0, $contentH - $sc->h);
        if ($maxScrollTop <= 0) return;

        // Each notch = ~40px
        $scrollAmount = (int)(abs($delta) / 120) * 40;
        if ($delta > 0) $scrollAmount = -$scrollAmount;

        $newScrollTop = $sc->scrollTop + $scrollAmount;
        $newScrollTop = max(0, min($newScrollTop, $maxScrollTop));

        if ($newScrollTop !== $sc->scrollTop) {
            if (method_exists($this->rootComponent, 'setBindValue')) {
                $this->rootComponent->setBindValue($scrollTopBind, (string)$newScrollTop);
            }
            $this->rootComponent->dirty = true;
        }
    }

    // ============================================================
    // 键盘处理
    // ============================================================

    private function handleKeyboard(int $msgType, int $wParam): void
    {
        // Handle keyboard navigation (Up/Down arrows for scrolling)
        if ($msgType === WinMsg::WM_KEYDOWN && $this->focusedInput === null) {
            $this->handleKeyboardNavigation($wParam);
            return;
        }

        // Try to focus an input if none focused
        if ($this->focusedInput === null) {
            $this->tryFocusInput();
            if ($this->focusedInput === null) return;
        }

        $el = $this->focusedInput;
        $bindKey = $el->props['v-model'] ?? '';
        if ($bindKey === '') return;

        if ($msgType === WinMsg::WM_CHAR) {
            $char = chr($wParam & 0xFF);
            if (ctype_print($char) || $char === ' ') {
                $this->appendText($bindKey, $char);
            }
        } elseif ($msgType === WinMsg::WM_KEYDOWN) {
            if ($wParam === WinMsg::VK_BACK) {
                $this->deleteTextChar($bindKey);
            } elseif ($wParam === WinMsg::VK_DELETE) {
                $this->deleteTextChar($bindKey, true);
            } elseif ($wParam === WinMsg::VK_RETURN || $wParam === WinMsg::VK_ESCAPE) {
                $handler = $el->props['@enter'] ?? '';
                if ($handler !== '' && $this->rootComponent !== null) {
                    $this->rootComponent->dispatchClick($handler, null);
                }
            } else {
                // @keydown for other keys (pass key code as argument)
                $handler = $el->props['@keydown'] ?? '';
                if ($handler !== '' && $this->rootComponent !== null) {
                    $this->rootComponent->dispatchClick($handler, (string)$wParam);
                }
            }
        }

        if ($this->rootComponent !== null) {
            $this->rootComponent->dirty = true;
        }
    }

    private function handleKeyboardNavigation(int $wParam): void
    {
        if ($this->rootComponent === null) return;
        if (count($this->scrollContainers) === 0) return;

        $sc = $this->scrollContainers[0];
        $scrollTopBind = $sc->props[':scroll-top'] ?? '';
        if ($scrollTopBind === '') return;

        $contentH = max($sc->contentHeight, 1);
        $maxScrollTop = max(0, $contentH - $sc->h);
        if ($maxScrollTop <= 0) return;

        $itemHeight = 50; // default
        $scrollAmount = $itemHeight;

        $newScrollTop = $sc->scrollTop;

        switch ($wParam) {
            case 0x26: // VK_UP
                $newScrollTop -= $scrollAmount;
                break;
            case 0x28: // VK_DOWN
                $newScrollTop += $scrollAmount;
                break;
            case 0x21: // VK_PRIOR
                $newScrollTop -= $sc->h;
                break;
            case 0x22: // VK_NEXT
                $newScrollTop += $sc->h;
                break;
            case 0x24: // VK_HOME
                $newScrollTop = 0;
                break;
            case 0x23: // VK_END
                $newScrollTop = $maxScrollTop;
                break;
            default:
                return;
        }

        $newScrollTop = max(0, min($newScrollTop, $maxScrollTop));

        if ($newScrollTop !== $sc->scrollTop) {
            if (method_exists($this->rootComponent, 'setBindValue')) {
                $this->rootComponent->setBindValue($scrollTopBind, (string)$newScrollTop);
            }
            $this->rootComponent->dirty = true;
        }
    }

    private function tryFocusInput(): void
    {
        if ($this->activeVNodeTree === null) return;
        $this->focusedInput = $this->findFirstInput($this->activeVNodeTree);
        if ($this->focusedInput !== null) {
            $this->focusedId = $this->focusedInput->props['v-model'] ?? '';
        }
    }

    private function findFirstInput(VNode $node): ?VNode
    {
        if ($node->type === 'input') return $node;

        if ($node->children instanceof VNode) {
            $found = $this->findFirstInput($node->children);
            if ($found !== null) return $found;
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $found = $this->findFirstInput($child);
                    if ($found !== null) return $found;
                }
            }
        }
        return null;
    }

    private function appendText(string $bindKey, string $char): void
    {
        if ($this->rootComponent === null) return;
        if (!method_exists($this->rootComponent, 'setBindValue')) return;
        if (!method_exists($this->rootComponent, 'getBindValue')) return;

        $currentValue = $this->rootComponent->getBindValue($bindKey);
        $newValue = $currentValue . $char;
        $this->rootComponent->setBindValue($bindKey, $newValue);
    }

    private function deleteTextChar(string $bindKey, bool $forward = false): void
    {
        if ($this->rootComponent === null) return;
        if (!method_exists($this->rootComponent, 'setBindValue')) return;
        if (!method_exists($this->rootComponent, 'getBindValue')) return;

        $currentValue = $this->rootComponent->getBindValue($bindKey);
        if (strlen($currentValue) === 0) return;

        $newValue = substr($currentValue, 0, -1);
        $this->rootComponent->setBindValue($bindKey, $newValue);
    }
}
