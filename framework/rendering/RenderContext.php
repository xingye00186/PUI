<?php

/**
 * RenderContext - 后端无关渲染抽象基类 (v6 M2)
 *
 * 提供与渲染后端无关的绘制接口抽象。当前封装已有 5 个 GDI 原语，
 * 未来可扩展至 26+ 方法支持多后端 (Skia/OpenGL/Web Canvas)。
 *
 * AOT 兼容: abstract class + extends 模式已验证通过 Swoole Compiler。
 *
 * v6 M2: hWnd 和 hdc 都由实现类内部持有，绘制方法不需要 hdc 参数
 */
abstract class RenderContext
{
    /** 开始一帧渲染 (创建双缓冲) - hdc 由实现类内部持有 */
    abstract public function beginFrame(): void;

    /** 结束一帧渲染 (提交双缓冲) */
    abstract public function endFrame(): void;

    /** 填充矩形 */
    abstract public function fillRect(int $x, int $y, int $w, int $h, int $color): void;

    /** 绘制文本 */
    abstract public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void;

    /** 绘制按钮 (背景 + 边框) */
    abstract public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void;
}
