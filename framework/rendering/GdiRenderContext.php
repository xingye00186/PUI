<?php
/**
 * GdiRenderContext - Win32 GDI 后端实现 (v6 M3)
 *
 * 直接委托给 C++ phpx 扩展提供的 vue_* stub 函数。
 * 当前封装 5 个基础原语 + drawElement 统一接口。
 *
 * hWnd 和 hdc 都由此类持有，绘制方法不需要传递 hdc 参数。
 */
class GdiRenderContext extends RenderContext
{
    private int $hWnd;
    private int $hdc = 0;

    public function __construct(int $hWnd)
    {
        $this->hWnd = $hWnd;
    }

    public function beginFrame(): void
    {
        $this->hdc = vue_begin_paint($this->hWnd);
    }

    public function endFrame(): void
    {
        vue_end_paint($this->hWnd, $this->hdc);
        $this->hdc = 0;
    }

    /**
     * 统一绘制接口 - 根据元素类型分发到具体绘制方法
     *
     * @param array $el 元素数据（包含所有静态和动态属性）
     */
    public function drawElement(array $el): void
    {
        $type = $el['type'] ?? 'rect';

        if ($type === 'rect') {
            $this->fillRect(
                $el['x'] ?? 0,
                $el['y'] ?? 0,
                $el['w'] ?? 0,
                $el['h'] ?? 0,
                $el['color'] ?? 0
            );
        } elseif ($type === 'text') {
            $this->drawTextElement($el);
        } elseif ($type === 'button') {
            $this->drawButtonElement($el);
        } elseif ($type === 'textbox') {
            $this->drawTextBoxElement($el);
        }
    }

    /**
     * 绘制文本元素
     * el 已包含完整预处理数据：text, fontSize, x, y (对齐计算已完成)
     */
    private function drawTextElement(array $el): void
    {
        $text = $el['text'] ?? '';
        if ($text === '') return;

        $fontSize = $el['fontSize'] ?? 16;
        $color    = $el['color'] ?? 0xFFFFFF;
        $bold     = $el['bold'] ?? 0;
        $x        = $el['x'] ?? 0;
        $y        = $el['y'] ?? 0;

        $this->drawText($x, $y, $text, $fontSize, $color, $bold);
    }

    /**
     * 绘制按钮元素（背景 + 边框 + 文字）
     * el 已包含完整预处理数据：x, y, w, h, bg, border, labelFontSize, labelX, labelY
     */
    private function drawButtonElement(array $el): void
    {
        // 绘制按钮背景和边框
        $this->drawButton(
            $el['x'] ?? 0,
            $el['y'] ?? 0,
            $el['w'] ?? 0,
            $el['h'] ?? 0,
            $el['bg'] ?? 0,
            $el['border'] ?? 0
        );

        // 绘制按钮文字（坐标已在 BaseRenderer 预处理）
        $this->drawText(
            $el['labelX'] ?? 0,
            $el['labelY'] ?? 0,
            $el['label'] ?? '',
            $el['labelFontSize'] ?? 22,
            $el['fg'] ?? 0xFFFFFF,
            1
        );
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        vue_fill_rect($this->hdc, $x, $y, $w, $h, $color);
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void
    {
        vue_draw_text($this->hdc, $x, $y, $text, $fontSize, $color, $bold);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        vue_draw_button($this->hdc, $x, $y, $w, $h, $bg, $border);
    }

    /**
     * v6 M4: Draw TextBox element (input field background + text + cursor)
     *
     * AOT兼容: 内联 borderColor 计算, 避免 require_once
     */
    private function drawTextBoxElement(array $el): void
    {
        $x = $el['x'] ?? 0;
        $y = $el['y'] ?? 0;
        $w = $el['w'] ?? 0;
        $h = $el['h'] ?? 0;
        $bg = $el['bg'] ?? 0x1E1E1E;
        // 内联 borderColor 计算 (CssMappings::borderColor 的简化版本)
        $r = min(255, (($bg >> 16) & 0xFF) + 20);
        $g = min(255, (($bg >> 8) & 0xFF) + 20);
        $b = min(255, ($bg & 0xFF) + 20);
        $border = ($r << 16) | ($g << 8) | $b;
        $text = $el['text'] ?? '';
        $fontSize = $el['fontSize'] ?? 16;
        $color = $el['color'] ?? 0xFFFFFF;
        $align = $el['align'] ?? 'left';

        // Draw background
        $this->fillRect($x, $y, $w, $h, $bg);

        // Draw border (button-like)
        vue_draw_button($this->hdc, $x, $y, $w, $h, $bg, $border);

        // Draw text (placeholder if empty)
        $displayText = $text;
        if ($displayText === '' && isset($el['placeholder'])) {
            $displayText = $el['placeholder'];
            $color = 0x666666; // Dimmed color for placeholder
        }

        if ($displayText !== '') {
            // Text alignment
            $textLen = strlen($displayText);
            $charWidth = (int)($fontSize * 0.6);
            $textWidth = $textLen * $charWidth;
            $textX = $x + 8; // Padding
            if ($align === 'right') {
                $textX = $x + $w - 8 - $textWidth;
            } elseif ($align === 'center') {
                $textX = $x + (int)(($w - $textWidth) / 2);
            }
            $textY = $y + (int)(($h - $fontSize) / 2);

            $this->drawText($textX, $textY, $displayText, $fontSize, $color, 0);
        }

        // Draw cursor if focused
        if (($el['cursor'] ?? false) && $text !== '') {
            $cursorX = $x + 8 + $textLen * $charWidth;
            $cursorY = $y + (int)(($h - $fontSize) / 2);
            $cursorH = $fontSize;
            vue_fill_rect($this->hdc, $cursorX, $cursorY, 2, $cursorH, 0xFFFFFF);
        }
    }
}
