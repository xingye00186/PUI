<?php
/**
 * Flex Layout Engine v6 M4
 *
 * 将 Flex 容器规范（类似 CSS Flexbox）转换为绝对坐标布局。
 * 在 SFC 编译时计算子元素位置，避免运行时布局计算开销。
 *
 * 支持特性:
 *   - direction: row | column
 *   - gap: 子元素间距 (px)
 *   - justify-content: flex-start | center | flex-end | space-between
 *   - align-items: flex-start | center | flex-end | stretch
 *   - flex-wrap: nowrap | wrap
 *   - flex-grow: 扩展因子
 *   - flex-shrink: 收缩因子
 *   - flex-basis: 基础尺寸
 *
 * AOT 兼容性:
 *   - 使用 array_keys() + for 循环遍历关联数组
 *   - 使用 (array) 类型转换保留数组引用
 */

class FlexLayout
{
    /** 容器尺寸 */
    protected int $containerW = 0;
    protected int $containerH = 0;
    protected int $containerX = 0;
    protected int $containerY = 0;

    /** Flex 属性 */
    protected string $direction = 'row';       // row | column
    protected int $gap = 0;                   // 间距 (px)
    protected string $justify = 'flex-start';  // 主轴对齐
    protected string $align = 'stretch';       // 交叉轴对齐
    protected string $wrap = 'nowrap';        // nowrap | wrap

    public function __construct(
        int $x, int $y, int $w, int $h,
        string $direction = 'row',
        int $gap = 0,
        string $justify = 'flex-start',
        string $align = 'stretch',
        string $wrap = 'nowrap'
    ) {
        $this->containerX = $x;
        $this->containerY = $y;
        $this->containerW = $w;
        $this->containerH = $h;
        $this->direction = $direction;
        $this->gap = $gap;
        $this->justify = $justify;
        $this->align = $align;
        $this->wrap = $wrap;
    }

    /**
     * 计算 Flex 布局
     *
     * @param array $children 子元素定义
     *   每个子元素: ['w' => int, 'h' => int, 'flex-grow' => int, 'flex-shrink' => int, 'flex-basis' => int, 'align-self' => string]
     * @return array 计算后的子元素列表，每个包含 x, y, w, h
     */
    public function layout(array $children): array
    {
        $count = count($children);
        if ($count === 0) {
            return [];
        }

        // 按 flex 属性分离: fixed vs flex
        $fixedItems = [];
        $flexItems = [];
        $totalFlexGrow = 0;
        $totalFlexBasis = 0;
        $totalFlexShrink = 0;

        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];
            $flexGrow = (int)($child['flex-grow'] ?? 0);
            $flexBasis = (int)($child['flex-basis'] ?? 0);
            $flexShrink = (float)($child['flex-shrink'] ?? 1.0);

            if ($flexGrow > 0 || $flexBasis > 0) {
                $flexItems[] = [
                    'index' => $i,
                    'w' => (int)($child['w'] ?? 0),
                    'h' => (int)($child['h'] ?? 0),
                    'flex-grow' => $flexGrow,
                    'flex-basis' => $flexBasis,
                    'flex-shrink' => $flexShrink,
                    'align-self' => $child['align-self'] ?? $this->align,
                ];
                $totalFlexGrow += $flexGrow;
                $totalFlexBasis += $flexBasis;
                $totalFlexShrink += $flexShrink;
            } else {
                $fixedItems[] = [
                    'index' => $i,
                    'w' => (int)($child['w'] ?? 0),
                    'h' => (int)($child['h'] ?? 0),
                    'align-self' => $child['align-self'] ?? $this->align,
                ];
            }
        }

        $result = [];

        if ($this->direction === 'row') {
            $result = $this->layoutRow($children, $fixedItems, $flexItems, $totalFlexGrow, $totalFlexBasis, $totalFlexShrink);
        } else {
            $result = $this->layoutColumn($children, $fixedItems, $flexItems, $totalFlexGrow, $totalFlexBasis, $totalFlexShrink);
        }

        return $result;
    }

    /**
     * 水平方向 (row) 布局
     */
    protected function layoutRow(array $children, array $fixedItems, array $flexItems, int $totalFlexGrow, int $totalFlexBasis, float $totalFlexShrink): array
    {
        $count = count($children);
        $result = array_fill(0, $count, null);

        // 计算固定项尺寸
        $fixedTotalW = 0;
        for ($i = 0; $i < count($fixedItems); $i++) {
            $fixedTotalW += $fixedItems[$i]['w'];
        }

        // 剩余空间
        $gapTotal = ($count - 1) * $this->gap;
        $availableW = $this->containerW - $gapTotal;
        $remainingW = $availableW - $fixedTotalW - $totalFlexBasis;

        // 计算 flex 项的最终宽度
        $flexWidths = [];
        if ($remainingW > 0 && $totalFlexGrow > 0) {
            // 扩展模式
            $unitGrow = $remainingW / $totalFlexGrow;
            for ($i = 0; $i < count($flexItems); $i++) {
                $flexWidths[$i] = (int)($flexItems[$i]['flex-grow'] * $unitGrow);
            }
        } elseif ($remainingW < 0 && $totalFlexShrink > 0) {
            // 收缩模式
            $shrinkFactor = abs($remainingW) / $totalFlexShrink;
            for ($i = 0; $i < count($flexItems); $i++) {
                $flexWidths[$i] = max(0, (int)($flexItems[$i]['flex-basis'] - $flexItems[$i]['flex-shrink'] * $shrinkFactor));
            }
        } else {
            // 使用 flex-basis
            for ($i = 0; $i < count($flexItems); $i++) {
                $flexWidths[$i] = $flexItems[$i]['flex-basis'] > 0 ? $flexItems[$i]['flex-basis'] : $flexItems[$i]['w'];
            }
        }

        // 计算 justify-content 起始位置
        $totalContentW = $fixedTotalW + array_sum($flexWidths);
        $startX = $this->calculateJustifyStart($totalContentW, $count);

        // 布局每个子元素
        $currentX = $startX;
        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];

            // 确定宽度
            $w = (int)($child['w'] ?? 0);
            $isFlex = false;
            for ($j = 0; $j < count($flexItems); $j++) {
                if ($flexItems[$j]['index'] === $i) {
                    $w = $flexWidths[$j];
                    $isFlex = true;
                    break;
                }
            }

            // 确定高度 (align-items 对齐)
            $h = (int)($child['h'] ?? $this->containerH);
            $y = $this->containerY;
            for ($j = 0; $j < count($flexItems); $j++) {
                if ($flexItems[$j]['index'] === $i) {
                    $y = $this->calculateAlignY($flexItems[$j]['h'], $flexItems[$j]['align-self']);
                    break;
                }
            }
            for ($j = 0; $j < count($fixedItems); $j++) {
                if ($fixedItems[$j]['index'] === $i) {
                    $y = $this->calculateAlignY($fixedItems[$j]['h'], $fixedItems[$j]['align-self']);
                    break;
                }
            }

            $result[$i] = [
                'x' => $currentX,
                'y' => $y,
                'w' => $w,
                'h' => $h,
            ];

            $currentX += $w + $this->gap;
        }

        return $result;
    }

    /**
     * 垂直方向 (column) 布局
     */
    protected function layoutColumn(array $children, array $fixedItems, array $flexItems, int $totalFlexGrow, int $totalFlexBasis, float $totalFlexShrink): array
    {
        $count = count($children);
        $result = array_fill(0, $count, null);

        // 计算固定项尺寸
        $fixedTotalH = 0;
        for ($i = 0; $i < count($fixedItems); $i++) {
            $fixedTotalH += $fixedItems[$i]['h'];
        }

        // 剩余空间
        $gapTotal = ($count - 1) * $this->gap;
        $availableH = $this->containerH - $gapTotal;
        $remainingH = $availableH - $fixedTotalH - $totalFlexBasis;

        // 计算 flex 项的最终高度
        $flexHeights = [];
        if ($remainingH > 0 && $totalFlexGrow > 0) {
            $unitGrow = $remainingH / $totalFlexGrow;
            for ($i = 0; $i < count($flexItems); $i++) {
                $flexHeights[$i] = (int)($flexItems[$i]['flex-grow'] * $unitGrow);
            }
        } elseif ($remainingH < 0 && $totalFlexShrink > 0) {
            $shrinkFactor = abs($remainingH) / $totalFlexShrink;
            for ($i = 0; $i < count($flexItems); $i++) {
                $flexHeights[$i] = max(0, (int)($flexItems[$i]['flex-basis'] - $flexItems[$i]['flex-shrink'] * $shrinkFactor));
            }
        } else {
            for ($i = 0; $i < count($flexItems); $i++) {
                $flexHeights[$i] = $flexItems[$i]['flex-basis'] > 0 ? $flexItems[$i]['flex-basis'] : $flexItems[$i]['h'];
            }
        }

        // 计算 justify-content 起始位置
        $totalContentH = $fixedTotalH + array_sum($flexHeights);
        $startY = $this->calculateJustifyStart($totalContentH, $count);

        // 布局每个子元素
        $currentY = $startY;
        for ($i = 0; $i < $count; $i++) {
            $child = $children[$i];

            // 确定高度
            $h = (int)($child['h'] ?? 0);
            $isFlex = false;
            for ($j = 0; $j < count($flexItems); $j++) {
                if ($flexItems[$j]['index'] === $i) {
                    $h = $flexHeights[$j];
                    $isFlex = true;
                    break;
                }
            }

            // 确定宽度 (align-items 对齐)
            $w = (int)($child['w'] ?? $this->containerW);
            $x = $this->containerX;
            for ($j = 0; $j < count($flexItems); $j++) {
                if ($flexItems[$j]['index'] === $i) {
                    $x = $this->calculateAlignX($flexItems[$j]['w'], $flexItems[$j]['align-self']);
                    break;
                }
            }
            for ($j = 0; $j < count($fixedItems); $j++) {
                if ($fixedItems[$j]['index'] === $i) {
                    $x = $this->calculateAlignX($fixedItems[$j]['w'], $fixedItems[$j]['align-self']);
                    break;
                }
            }

            $result[$i] = [
                'x' => $x,
                'y' => $currentY,
                'w' => $w,
                'h' => $h,
            ];

            $currentY += $h + $this->gap;
        }

        return $result;
    }

    /**
     * 计算主轴起始位置 (justify-content)
     */
    protected function calculateJustifyStart(int $totalContentSize, int $itemCount): int
    {
        $gapTotal = ($itemCount - 1) * $this->gap;
        $containerSize = ($this->direction === 'row') ? $this->containerW : $this->containerH;

        switch ($this->justify) {
            case 'center':
                return $this->containerX + (int)(($containerSize - $totalContentSize - $gapTotal) / 2);
            case 'flex-end':
                return $this->containerX + $containerSize - $totalContentSize - $gapTotal;
            case 'space-between':
                // gapTotal 已包含在布局中
                return $this->containerX;
            case 'space-around':
                $gap = (int)(($containerSize - $totalContentSize) / $itemCount);
                return $this->containerX + (int)($gap / 2);
            case 'flex-start':
            default:
                return $this->containerX;
        }
    }

    /**
     * 计算 Y 轴对齐 (align-items, 用于 row 方向)
     */
    protected function calculateAlignY(int $itemH, string $alignSelf): int
    {
        $align = ($alignSelf === 'auto') ? $this->align : $alignSelf;
        switch ($align) {
            case 'center':
                return $this->containerY + (int)(($this->containerH - $itemH) / 2);
            case 'flex-end':
                return $this->containerY + $this->containerH - $itemH;
            case 'flex-start':
            default:
                return $this->containerY;
        }
    }

    /**
     * 计算 X 轴对齐 (align-items, 用于 column 方向)
     */
    protected function calculateAlignX(int $itemW, string $alignSelf): int
    {
        $align = ($alignSelf === 'auto') ? $this->align : $alignSelf;
        switch ($align) {
            case 'center':
                return $this->containerX + (int)(($this->containerW - $itemW) / 2);
            case 'flex-end':
                return $this->containerX + $this->containerW - $itemW;
            case 'flex-start':
            default:
                return $this->containerX;
        }
    }
}