<?php

/**
 * RenderContext - 后端无关渲染抽象基类 (v6 M3)
 *
 * 提供与渲染后端无关的绘制接口抽象。当前封装已有 5 个 GDI 原语，
 * 新增 drawElement 统一绘制接口，通过 type 字段分发到具体绘制方法。
 *
 * AOT 兼容: abstract class + extends 模式已验证通过 Swoole Compiler。
 *
 * v6 M3: 新增 drawElement 统一接口，保留原有原语方法（向后兼容）
 */
abstract class RenderContext
{
    /** 开始一帧渲染 (创建双缓冲) - hdc 由实现类内部持有 */
    abstract public function beginFrame(): void;

    /** 结束一帧渲染 (提交双缓冲) */
    abstract public function endFrame(): void;

    /**
     * 统一绘制接口 - 根据元素类型分发到具体绘制方法
     *
     * @param array $el 元素数据（包含所有静态和动态属性）
     *   - type='rect': 绘制矩形 (x, y, w, h, color)
     *   - type='text': 绘制文本 (x, y, text, fontSize, color, bold, align, containerW, containerX)
     *   - type='button': 绘制按钮 (x, y, w, h, bg, border, fg, label)
     *   注：动态值（如 text 的绑定文本）已预处理到元素属性中
     */
    abstract public function drawElement(array $el): void;

    /** 填充矩形 */
    abstract public function fillRect(int $x, int $y, int $w, int $h, int $color): void;

    /** 绘制文本 */
    abstract public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void;

    /** 绘制按钮 (背景 + 边框) */
    abstract public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void;
}
