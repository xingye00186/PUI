<?php

/**
 * GdiRenderContext - Win32 GDI 后端实现 (v6 M2)
 *
 * 直接委托给 C++ phpx 扩展提供的 vue_* stub 函数。
 * 当前封装 5 个基础原语，未来 C++ 层扩展后可逐步添加新方法。
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
}
