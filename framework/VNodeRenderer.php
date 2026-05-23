<?php

use native_types;

/**
 * VNodeRenderer — VNode 树遍历渲染器
 *
 * 替代旧的 BaseRenderer，直接遍历 VNode 树生成 GDI 绘制调用。
 *
 * 两阶段渲染:
 *   1. Walk: 收集所有需要绘制的元素 (按 layer 分组)
 *   2. Draw: 按 layer 顺序调用 ctx->drawElement()
 *
 * PHP 8.4: 使用 match 表达式分发元素类型。
 */
class VNodeRenderer
{
    private ReactiveComponent $component;
    private RenderContext $ctx;

    /** @var array Scroll context for offsetting children */
    private array $scrollCtxStack = [];

    public function __construct(ReactiveComponent $component, RenderContext $ctx)
    {
        $this->component = $component;
        $this->ctx = $ctx;
    }

    /**
     * 渲染 VNode 树。
     *
     * @param VNode $root Root VNode (已经过 LayoutResolver 计算位置)
     */
    public function render(VNode $root): void
    {
        $this->ctx->beginFrame();

        // Phase 1: Collect drawable elements
        $elementsByLayer = [];
        $maxLayer = 0;

        $this->collectElements($root, $elementsByLayer, $maxLayer);

        // Phase 2: Draw by layer order
        for ($l = 0; $l <= $maxLayer; $l++) {
            $layerElements = $elementsByLayer[$l] ?? [];
            foreach ($layerElements as $el) {
                $this->ctx->drawElement($el);
            }
        }

        $this->ctx->endFrame();
    }

    /**
     * Recursively collect drawable elements from VNode tree.
     *
     * @param VNode $node Current node
     * @param array &$elementsByLayer OUT: [layer => [elData, ...]]
     * @param int &$maxLayer OUT: max layer number
     */
    private function collectElements(VNode $node, array &$elementsByLayer, int &$maxLayer): void
    {
        // Skip root node itself (not drawn)
        if (!$node->isRoot()) {
            // Check v-if condition
            $vif = ($node->props !== null) ? ($node->props['v-if'] ?? '') : '';
            if ($vif !== '') {
                $cond = $this->component->getBindValue($vif);
                if (!$cond) return;
            }

            $el = $this->vnodeToElement($node);
            if ($el !== null) {
                $layer = $node->layer;
                if ($layer > $maxLayer) $maxLayer = $layer;
                if (!isset($elementsByLayer[$layer])) {
                    $elementsByLayer[$layer] = [];
                }
                $elementsByLayer[$layer][] = $el;
            }
        }

        // Push scroll context if this is a scroll container
        $wasScrollPush = false;
        if ($node->isScrollContainer) {
            $this->scrollCtxStack[] = [
                'x' => $node->x,
                'y' => $node->y,
                'w' => $node->w,
                'h' => $node->h,
                'scrollTop' => $node->scrollTop,
            ];
            $wasScrollPush = true;
        }

        // Process children — skip for buttons (their child span text
        // is already extracted as the button's label in makeButtonElement)
        if ($node->type !== 'button') {
            if ($node->children instanceof VNode) {
                $this->collectElements($node->children, $elementsByLayer, $maxLayer);
            } elseif (is_array($node->children)) {
                foreach ($node->children as $child) {
                    if ($child instanceof VNode) {
                        $this->collectElements($child, $elementsByLayer, $maxLayer);
                    }
                }
            }
        }

        // Pop scroll context
        if ($wasScrollPush) {
            array_pop($this->scrollCtxStack);
        }
    }

    /**
     * Convert a single VNode to a drawElement()-compatible array.
     *
     * Uses match (PHP 8.4) for type dispatch.
     */
    private function vnodeToElement(VNode $node): ?array
    {
        $style = $node->computedStyle;
        $props = $node->props ?? [];

        $x = $node->x;
        $y = $node->y;
        $w = $node->w;
        $h = $node->h;
        $layer = $node->layer;

        // Apply scroll offset if inside a scroll container
        if (count($this->scrollCtxStack) > 0) {
            $scrollCtx = $this->scrollCtxStack[count($this->scrollCtxStack) - 1];
            $y = $y - $scrollCtx['scrollTop'];

            // Skip if completely outside visible scroll area
            $containerY = $scrollCtx['y'];
            $containerH = $scrollCtx['h'];
            if ($y + $h <= $containerY || $y >= $containerY + $containerH) {
                return null;
            }
        }

        // Dispatch by node type using match
        return match ($node->type) {
            'div'       => $this->makeDivElement($node, $style, $x, $y, $w, $h, $layer),
            'span'      => $this->makeSpanElement($node, $style, $x, $y, $w, $h, $layer),
            'button'    => $this->makeButtonElement($node, $style, $x, $y, $w, $h, $layer),
            'input'     => $this->makeInputElement($node, $style, $x, $y, $w, $h, $layer),
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
                        => $this->makeSpanElement($node, $style, $x, $y, $w, $h, $layer),
            default     => $this->makeDivElement($node, $style, $x, $y, $w, $h, $layer),
        };
    }

    /**
     * Div → rect element (background fill)
     */
    private function makeDivElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        // If this is a scroll container, emit scroll-container element
        if ($node->isScrollContainer) {
            return $this->makeScrollContainerElement($node, $style, $x, $y, $w, $h, $layer);
        }

        $bg = $style['bg'] ?? ($style['background'] ?? 0);

        // Skip invisible divs (no background)
        if ($bg === 0) {
            return null;
        }

        return [
            'type'   => 'rect',
            'x'      => $x,
            'y'      => $y,
            'w'      => $w,
            'h'      => $h,
            'color'  => $bg,
            'layer'  => $layer,
            'group_id' => $node->groupId,
        ];
    }

    /**
     * Span → text element
     */
    private function makeSpanElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $text = '';
        $fontSize = $style['fontSize'] ?? 16;
        $color    = $style['fg'] ?? ($style['color'] ?? 0xFFFFFF);
        $bold     = $style['bold'] ?? 0;
        $align    = $node->props['align'] ?? ($style['textAlign'] ?? 'left');

        // Get text content
        if (is_string($node->children)) {
            $text = $node->children;
        }

        // Handle :bind (dynamic text content)
        $bindKey = $node->props[':bind'] ?? '';
        if ($bindKey !== '' && method_exists($this->component, 'getBindValue')) {
            $text = $this->component->getBindValue($bindKey);
        }

        // v-model binding
        $vModel = $node->props['v-model'] ?? '';
        if ($vModel !== '' && method_exists($this->component, 'getBindValue')) {
            $text = $this->component->getBindValue($vModel);
        }

        if ($text === '') return null;

        // Text alignment calculation
        $containerW = (int)($node->props['container-w'] ?? $w);
        $containerH = (int)($node->props['container-h'] ?? $h);
        $containerX = (int)($node->props['container-x'] ?? $x);

        // Right/center alignment
        if ($align === 'right' || $align === 'center') {
            $textLen = strlen($text);
            $charWidth = (int)($fontSize * 0.6);
            $textWidth = $textLen * $charWidth;

            if ($align === 'right') {
                $x = $containerX + $containerW - 12 - $textWidth;
                if ($x < $containerX + 4) $x = $containerX + 4;
            } else {
                $x = $containerX + (int)(($containerW - $textWidth) / 2);
                if ($x < $containerX) $x = $containerX;
            }

            // Vertical centering
            if ($containerH > $fontSize * 2) {
                $y = $y + (int)(($containerH - $fontSize) / 2);
            }
        }

        return [
            'type'     => 'text',
            'text'     => $text,
            'x'        => $x,
            'y'        => $y,
            'fontSize' => $fontSize,
            'color'    => $color,
            'bold'     => $bold,
            'align'    => $align,
            'layer'    => $layer,
            'group_id' => $node->groupId,
        ];
    }

    /**
     * Button → button element (background + text)
     */
    private function makeButtonElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg     = $style['bg'] ?? 0x4488CC;
        $fg     = $style['fg'] ?? 0xFFFFFF;
        $border = $style['border'] ?? CssMappings::borderColor($bg);

        // Button text: from children (string) or :bind
        $label = '';
        if (is_string($node->children)) {
            $label = $node->children;
        } elseif ($node->children instanceof VNode) {
            // Extract text from child span or text node
            if ($node->children->type === 'span' || $node->children->type === '#text') {
                if (is_string($node->children->children)) {
                    $label = $node->children->children;
                }
                // Check span's bind prop
                $spanBind = $node->children->props[':bind'] ?? $node->children->props['bind'] ?? '';
                if ($spanBind !== '' && method_exists($this->component, 'getBindValue')) {
                    $label = $this->component->getBindValue($spanBind);
                }
            }
        }

        $bindKey = $node->props[':bind'] ?? '';
        if ($bindKey !== '' && method_exists($this->component, 'getBindValue')) {
            $label = $this->component->getBindValue($bindKey);
        }

        // If no text in VNode, check for adjacent span with :bind
        if ($label === '' && isset($node->props['@click'])) {
            // Try to read button text from component's addBtnText property
            // via the old pattern where text is in a separate element
            $label = $node->props['label'] ?? '';
        }

        // Label centering
        $labelFontSize = 22;
        $labelLen = strlen($label);
        $labelCharW = (int)($labelFontSize * 0.6);
        $labelX = $x + (int)((($w) - $labelLen * $labelCharW) / 2);
        $labelY = $y + (int)(($h - $labelFontSize) / 2);

        return [
            'type'      => 'button',
            'x'         => $x,
            'y'         => $y,
            'w'         => $w,
            'h'         => $h,
            'bg'        => $bg,
            'fg'        => $fg,
            'border'    => $border,
            'label'     => $label,
            'labelX'    => $labelX,
            'labelY'    => $labelY,
            'labelFontSize' => $labelFontSize,
            'layer'     => $layer,
            'group_id'  => $node->groupId,
        ];
    }

    /**
     * Input → textbox element
     */
    private function makeInputElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): ?array
    {
        $bg     = $style['bg'] ?? 0x1E1E1E;
        $fg     = $style['fg'] ?? 0xFFFFFF;
        $fontSize = $style['fontSize'] ?? 16;
        $align  = $node->props['align'] ?? 'left';
        $placeholder = $node->props['placeholder'] ?? '';

        // v-model binding
        $bindKey = $node->props['v-model'] ?? '';
        $text = '';
        if ($bindKey !== '' && method_exists($this->component, 'getBindValue')) {
            $text = $this->component->getBindValue($bindKey);
        }

        return [
            'type'        => 'textbox',
            'bind'        => $bindKey,
            'placeholder' => $placeholder,
            'text'        => $text,
            'x'           => $x,
            'y'           => $y,
            'w'           => $w,
            'h'           => $h,
            'align'       => $align,
            'fontSize'    => $fontSize,
            'color'       => $fg,
            'bg'          => $bg,
            'cursor'      => true,
            'layer'       => $layer,
            'group_id'    => $node->groupId,
        ];
    }

    /**
     * Scroll container → rect + scrollbar
     */
    private function makeScrollContainerElement(VNode $node, array $style, int $x, int $y, int $w, int $h, int $layer): array
    {
        $bg = $style['bg'] ?? 0x2D2D2D;
        $sbW = 12;
        $sbBg = 0x4A4A4A;
        $sbThumb = 0x888888;

        // Calculate content height from children
        $contentH = $node->contentHeight;
        if ($contentH === 0) {
            $childList = [];
            if ($node->children instanceof VNode) {
                $childList = [$node->children];
            } elseif (is_array($node->children)) {
                $childList = $node->children;
            }
            foreach ($childList as $child) {
                if ($child instanceof VNode) {
                    $itemH = (int)($child->props['item-height'] ?? 0);
                    $contentH += max($child->h, $itemH);
                }
            }
        }

        return [
            'type'            => 'scroll-container',
            'x'               => $x,
            'y'               => $y,
            'w'               => $w,
            'h'               => $h,
            'bg'              => $bg,
            'scrollbar-w'     => $sbW,
            'scrollbar-bg'    => $sbBg,
            'scrollbar-thumb' => $sbThumb,
            'content-height'  => $contentH,
            'scroll-top'      => $node->scrollTop,
            'layer'           => $layer,
            'group_id'        => $node->groupId,
        ];
    }
}
