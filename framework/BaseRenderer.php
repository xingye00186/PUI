<?php

/**
 * BaseRenderer - 泛化数据驱动渲染器 (v6 M3)
 *
 * 职责：数据预处理（绑定值解析、字号自适应、对齐计算）+ 绘制调度
 * 绘制逻辑委托给 RenderContext.drawElement()，BaseRenderer 专注于数据转换。
 *
 * AOT 限制:
 *   - foreach 遍历关联数组时 key 类型推断错误 → 使用 array_keys() + for 循环
 *   - 函数返回嵌套数组后子数组类型丢失 → 使用 (array) 类型转换修复
 */
class BaseRenderer
{
    private ReactiveComponent $component;
    private RenderContext $ctx;

    public function __construct(ReactiveComponent $component, RenderContext $ctx)
    {
        $this->component = $component;
        $this->ctx = $ctx;
    }

    /**
     * 从组件属性获取绑定值
     * 用于在绘制前解析元素的 bind 字段
     */
    protected function getBindValue(string $bindKey): string
    {
        return $this->component->getBindValue($bindKey);
    }

    /**
     * 数据驱动渲染: 两阶段分层渲染 (v6 M3)
     *
     * Phase 1: 确定最高活跃层
     * Phase 2: 分层遍历 elements，调用 ctx->drawElement() 统一绘制
     *
     * @param array $layout 预处理后的布局数据 ['elements' => [...]]
     *   elements 包含: rect, text, button 等类型
     * AOT 修复: array_keys() + for 循环, 避免 foreach 遍历关联数组时的类型推断问题
     */
    public function render(array $layout): void
    {
        $this->ctx->beginFrame();

        // 获取预处理后的布局数据（统一 elements 数组）
        $elements = (array)($layout['elements'] ?? []);

        // ====== Phase 1: 确定最高活跃层 ======
        $maxLayer = 0;

        // 遍历所有元素 (AOT 安全)
        $elCount = count($elements);
        for ($i = 0; $i < $elCount; $i++) {
            $el = $elements[$i];
            if (!is_array($el)) continue;
            // condition 字段必须是数组（AOT 可能将其推断为 int）
            $cond = $el['condition'] ?? null;
            if ($cond !== null && !is_array($cond)) continue;
            if ($cond !== null && !$this->component->evalCondition($cond)) continue;
            $layer = $el['layer'] ?? 0;
            if ($layer > $maxLayer) $maxLayer = $layer;
        }

        // ====== Phase 2: 分层渲染，统一调用 drawElement ======
        for ($l = 0; $l <= $maxLayer; $l++) {
            for ($i = 0; $i < $elCount; $i++) {
                $el = $elements[$i];
                if (!is_array($el)) continue;
                if (($el['layer'] ?? 0) !== $l) continue;
                // condition 字段必须是数组
                $cond = $el['condition'] ?? null;
                if ($cond !== null && !is_array($cond)) continue;
                if ($cond !== null && !$this->component->evalCondition($cond)) continue;

                // 预处理：解析绑定值并添加到 el['text']（AOT 安全写法）
                $bindKey = $el['bind'] ?? '';
                if ($bindKey !== '') {
                    $el['text'] = $this->getBindValue($bindKey);
                }

                // 字号自适应（长数字时缩小）
                $fontSize = $el['fontSize'] ?? 16;
                $textLen = strlen($el['text'] ?? '');
                if ($textLen > 12 && $fontSize > 24) {
                    $el['fontSize'] = 24;
                    $fontSize = 24;
                }
                if ($textLen > 16 && $fontSize > 18) {
                    $el['fontSize'] = 18;
                    $fontSize = 18;
                }

                // 对齐计算（修改 x 坐标）
                $align = $el['align'] ?? 'left';
                if (($align === 'right' || $align === 'center') && isset($el['containerW'])) {
                    $containerW = $el['containerW'];
                    $containerX = $el['containerX'] ?? 0;
                    $charWidth = (int)($fontSize * 0.6);
                    $textWidth = $textLen * $charWidth;
                    if ($align === 'right') {
                        $el['x'] = $containerX + $containerW - 12 - $textWidth;
                        if ($el['x'] < $containerX + 4) $el['x'] = $containerX + 4;
                    } else {
                        $el['x'] = $containerX + (int)(($containerW - $textWidth) / 2);
                        if ($el['x'] < $containerX) $el['x'] = $containerX;
                    }
                }

                // 按钮标签居中计算
                if ($el['type'] === 'button') {
                    $label = $el['label'] ?? '';
                    $labelLen = strlen($label);
                    $labelFontSize = 22;
                    $labelCharW = (int)($labelFontSize * 0.6);
                    $el['labelFontSize'] = $labelFontSize;
                    $el['labelX'] = ($el['x'] ?? 0) + (int)((($el['w'] ?? 0) - $labelLen * $labelCharW) / 2);
                    $el['labelY'] = ($el['y'] ?? 0) + (int)((($el['h'] ?? 0) - $labelFontSize) / 2);
                }

                // 统一调用 drawElement 绘制
                $this->ctx->drawElement($el);
            }
        }

        $this->ctx->endFrame();
    }
}