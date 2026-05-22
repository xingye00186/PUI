<?php
/**
 * v6 M5: FocusManager - 焦点系统管理器
 *
 * 管理桌面应用的焦点状态，支持:
 * - Tab 键导航
 * - 焦点状态跟踪
 * - Focus ring 绘制
 *
 * 使用方式:
 *   $focus = new FocusManager($elements, $buttons);
 *   $focus->setFocusedIndex(0);
 *   // 在渲染时绘制焦点环
 *   $focusRect = $focus->getFocusedRect();
 */

class FocusManager
{
    /** 可聚焦元素列表 */
    private array $focusableElements = [];

    /** 当前焦点索引 */
    private int $focusedIndex = -1;

    /** 焦点环颜色 */
    private int $focusRingColor;

    /** 焦点环宽度 */
    private int $focusRingWidth;

    /** 焦点是否可见 */
    private bool $focusVisible = true;

    public function __construct(array $elements = [], array $buttons = [], int $focusRingColor = 0x4488FF, int $focusRingWidth = 2)
    {
        $this->focusRingColor = $focusRingColor;
        $this->focusRingWidth = $focusRingWidth;
        $this->buildFocusableList($elements, $buttons);
    }

    /**
     * 从布局数据构建可聚焦元素列表
     */
    public function buildFocusableList(array $elements, array $buttons): void
    {
        $this->focusableElements = [];

        // 添加按钮
        foreach ($buttons as $btn) {
            if (isset($btn['handler']) && $btn['handler'] !== '') {
                $this->focusableElements[] = [
                    'type' => 'button',
                    'x' => $btn['x'] ?? 0,
                    'y' => $btn['y'] ?? 0,
                    'w' => $btn['w'] ?? 0,
                    'h' => $btn['h'] ?? 0,
                    'label' => $btn['label'] ?? '',
                ];
            }
        }

        // 添加文本框
        foreach ($elements as $el) {
            if (($el['type'] ?? '') === 'textbox') {
                $this->focusableElements[] = [
                    'type' => 'textbox',
                    'x' => $el['x'] ?? 0,
                    'y' => $el['y'] ?? 0,
                    'w' => $el['w'] ?? 0,
                    'h' => $el['h'] ?? 0,
                ];
            }
        }

        // 默认聚焦第一个元素
        if (count($this->focusableElements) > 0 && $this->focusedIndex < 0) {
            $this->focusedIndex = 0;
        }
    }

    /**
     * 设置焦点索引
     */
    public function setFocusedIndex(int $index): void
    {
        $count = count($this->focusableElements);
        if ($count === 0) {
            $this->focusedIndex = -1;
            return;
        }
        $this->focusedIndex = max(0, min($index, $count - 1));
    }

    /**
     * 获取当前焦点索引
     */
    public function getFocusedIndex(): int
    {
        return $this->focusedIndex;
    }

    /**
     * 获取当前焦点元素的矩形区域
     * 返回 null 表示没有焦点
     */
    public function getFocusedRect(): ?array
    {
        if ($this->focusedIndex < 0 || $this->focusedIndex >= count($this->focusableElements)) {
            return null;
        }

        $el = $this->focusableElements[$this->focusedIndex];
        $w = $el['w'];
        $h = $el['h'];

        return [
            'x' => $el['x'] - $this->focusRingWidth,
            'y' => $el['y'] - $this->focusRingWidth,
            'w' => $w + $this->focusRingWidth * 2,
            'h' => $h + $this->focusRingWidth * 2,
            'color' => $this->focusRingColor,
        ];
    }

    /**
     * Tab 键: 移动到下一个焦点
     */
    public function focusNext(): void
    {
        $count = count($this->focusableElements);
        if ($count === 0) return;

        $this->focusedIndex++;
        if ($this->focusedIndex >= $count) {
            $this->focusedIndex = 0; // 循环到第一个
        }
    }

    /**
     * Shift+Tab 键: 移动到上一个焦点
     */
    public function focusPrev(): void
    {
        $count = count($this->focusableElements);
        if ($count === 0) return;

        $this->focusedIndex--;
        if ($this->focusedIndex < 0) {
            $this->focusedIndex = $count - 1; // 循环到最后
        }
    }

    /**
     * 根据坐标查找最近的焦点元素
     */
    public function focusAt(int $x, int $y): void
    {
        $count = count($this->focusableElements);
        for ($i = 0; $i < $count; $i++) {
            $el = $this->focusableElements[$i];
            if ($x >= $el['x'] && $x < $el['x'] + $el['w'] &&
                $y >= $el['y'] && $y < $el['y'] + $el['h']) {
                $this->focusedIndex = $i;
                return;
            }
        }
    }

    /**
     * 隐藏焦点环
     */
    public function hideFocus(): void
    {
        $this->focusVisible = false;
    }

    /**
     * 显示焦点环
     */
    public function showFocus(): void
    {
        $this->focusVisible = true;
    }

    /**
     * 是否应该绘制焦点环
     */
    public function shouldDrawFocus(): bool
    {
        return $this->focusVisible && $this->focusedIndex >= 0;
    }

    /**
     * 获取焦点元素数量
     */
    public function getFocusableCount(): int
    {
        return count($this->focusableElements);
    }
}