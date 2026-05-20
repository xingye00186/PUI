<?php

/**
 * BaseRenderer - 泛化数据驱动渲染器 (v6 M1)
 *
 * 支持分段布局: 通过 attachLayout/detachLayout 管理活跃布局列表。
 * 支持渲染抽象: 通过 RenderContext 进行后端无关绘制。
 * 保持两阶段分层渲染逻辑 (v5 M3 layer 机制)。
 *
 * AOT 限制:
 *   - foreach 遍历关联数组时 key 类型推断错误 → 使用 array_keys() + for 循环
 *   - 函数返回嵌套数组后子数组类型丢失 → 使用 (array) 类型转换修复
 */
class BaseRenderer
{
    private int $hWnd;
    private ReactiveComponent $component;
    private RenderContext $ctx;
    private array $activeLayouts = [];

    public function __construct(int $hWnd, ReactiveComponent $component, RenderContext $ctx)
    {
        $this->hWnd = $hWnd;
        $this->component = $component;
        $this->ctx = $ctx;
    }

    /** v6 M1: 挂载组件布局到渲染列表 */
    public function attachLayout(string $name): void
    {
      
        $this->activeLayouts[$name] = 1;
        $this->component->onAttach($name);
    }

    /** v6 M1: 从渲染列表卸载组件布局 */
    public function detachLayout(string $name): void
    {
      
        unset($this->activeLayouts[$name]);
        $this->component->onDetach($name);
    }

    /**
     * 获取所有活跃布局合并后的数据
     * AOT 修复: array_keys() + for 循环, 避免 foreach 遍历关联数组时的类型推断问题
     */
    public function getActiveLayout(): array
    {
        $allElements = []; $allButtons = [];

        foreach ($this->activeLayouts as $name => $tem_) {
        
            $seg = callLayoutSegment($name);
            
            foreach ((array)$seg['elements'] as $el) $allElements[] = $el;
            foreach ((array)$seg['buttons'] as $btn) $allButtons[] = $btn;
        }
        return ['elements' => $allElements, 'buttons' => $allButtons];
    }

    /** 从组件属性获取绑定值 */
    protected function getBindValue(string $bindKey): string
    {
        return $this->component->getBindValue($bindKey);
    }

    /** 渲染文本元素（支持对齐和动态字号） */
    protected function renderTextElement(int $hdc, array $el): void
    {
        $bindKey = $el['bind'] ?? '';

        if ($bindKey !== '') {
            $text = $this->getBindValue($bindKey);
            if ($text === '') {
                return;
            }
        } else {
            return;
        }

        $fontSize = $el['fontSize'] ?? 16;
        $color    = $el['color'] ?? 0xFFFFFF;
        $bold     = $el['bold'] ?? 0;
        $align    = $el['align'] ?? 'left';
        $x        = $el['x'] ?? 0;
        $y        = $el['y'] ?? 0;

        // 动态字号调整（长数字时缩小）
        $textLen = strlen($text);
        if ($textLen > 12 && $fontSize > 24) {
            $fontSize = 24;
        }
        if ($textLen > 16 && $fontSize > 18) {
            $fontSize = 18;
        }

        // 右对齐
        if ($align === 'right' && isset($el['containerW'])) {
            $containerW = $el['containerW'];
            $containerX = $el['containerX'] ?? 0;
            $charWidth  = (int)($fontSize * 0.6);
            $textWidth  = $textLen * $charWidth;
            $rightEdge  = $containerX + $containerW;
            $x = $rightEdge - 12 - $textWidth;
            if ($x < $containerX + 4) {
                $x = $containerX + 4;
            }
        }

        // 居中对齐
        if ($align === 'center' && isset($el['containerW'])) {
            $containerW = $el['containerW'];
            $containerX = $el['containerX'] ?? 0;
            $charWidth  = (int)($fontSize * 0.6);
            $textWidth  = $textLen * $charWidth;
            $x = $containerX + (int)(($containerW - $textWidth) / 2);
            if ($x < $containerX) {
                $x = $containerX;
            }
        }

        $this->ctx->drawText($hdc, $x, $y, $text, $fontSize, $color, $bold);
    }

    /**
     * 数据驱动渲染: 两阶段分层渲染 (v5 M3 + v6 M1 activeLayouts)
     *
     * AOT 修复: array_keys() + for 循环, 避免 foreach 遍历关联数组时的类型推断问题
     */
    public function render(): void
    {
        // v5 M4: 消费 dirty 状态
        $dirtyInfo = $this->component->consumeDirty();

        $hdc = $this->ctx->beginFrame($this->hWnd);

        // v6 M1: 从 activeLayouts 动态收集布局数据
        // AOT 修复: array_keys() + for 循环
        $elements = []; $buttons = [];
        $layoutNames = array_keys($this->activeLayouts);
        $count = count($layoutNames);
        for ($i = 0; $i < $count; $i++) {
            $name = strval($layoutNames[$i]);
            $seg = callLayoutSegment($name);
            foreach ((array)$seg['elements'] as $el) $elements[] = $el;
            foreach ((array)$seg['buttons'] as $btn) $buttons[] = $btn;
        }

        // ====== Phase 1: 确定最高活跃层 ======
        $maxLayer = 0;
        foreach ($elements as $el) {
            // AOT 安全检查
            if (!is_array($el)) continue;
            // condition 字段也必须是数组（AOT 可能将其推断为 int）
            $cond = $el['condition'] ?? null;
            if ($cond !== null && !is_array($cond)) continue;
            if ($cond !== null && !$this->component->evalCondition($cond)) continue;
            $layer = $el['layer'] ?? 0;
            if ($layer > $maxLayer) $maxLayer = $layer;
        }
        foreach ($buttons as $btn) {
            // AOT 安全检查
            if (!is_array($btn)) continue;
            // condition 字段也必须是数组（AOT 可能将其推断为 int）
            $cond = $btn['condition'] ?? null;
            if ($cond !== null && !is_array($cond)) continue;
            if ($cond !== null && !$this->component->evalCondition($cond)) continue;
            $layer = $btn['layer'] ?? 0;
            if ($layer > $maxLayer) $maxLayer = $layer;
        }

        // ====== Phase 2: 分层渲染 ======
        for ($l = 0; $l <= $maxLayer; $l++) {
            // 本层元素
            foreach ($elements as $el) {
                // AOT 安全检查: 确保 $el 是有效数组
                if (!is_array($el)) continue;
                if (($el['layer'] ?? 0) !== $l) continue;
                // condition 字段也必须是数组（AOT 可能将其推断为 int）
                $cond = $el['condition'] ?? null;
                if ($cond !== null && !is_array($cond)) continue;
                if ($cond !== null && !$this->component->evalCondition($cond)) continue;
                $type = $el['type'] ?? 'rect';
                if ($type === 'rect') {
                    $this->ctx->fillRect(
                        $hdc,
                        $el['x'] ?? 0,
                        $el['y'] ?? 0,
                        $el['w'] ?? 0,
                        $el['h'] ?? 0,
                        $el['color'] ?? 0
                    );
                } elseif ($type === 'text') {
                    $this->renderTextElement($hdc, $el);
                }
            }
            // 本层按钮
            foreach ($buttons as $btn) {
                // AOT 安全检查: 确保 $btn 是有效数组
                if (!is_array($btn)) continue;
                $btnLayer = $btn['layer'] ?? 0;
                if ($btnLayer !== $l) continue;
                // condition 字段也必须是数组（AOT 可能将其推断为 int）
                $cond = $btn['condition'] ?? null;
                if ($btnLayer < $maxLayer && $cond !== null) continue;
                if ($cond !== null && !is_array($cond)) continue;
                if ($cond !== null && !$this->component->evalCondition($cond)) continue;
                // 安全访问: 使用 ?? 提供默认值
                $this->ctx->drawButton(
                    $hdc,
                    $btn['x'] ?? 0,
                    $btn['y'] ?? 0,
                    $btn['w'] ?? 0,
                    $btn['h'] ?? 0,
                    $btn['bg'] ?? 0,
                    $btn['border'] ?? 0
                );
                // 按钮文字居中
                $label = $btn['label'] ?? '';
                $labelLen = strlen($label);
                $labelFontSize = 22;
                $labelCharW = (int)($labelFontSize * 0.6);
                $labelX = ($btn['x'] ?? 0) + (int)((($btn['w'] ?? 0) - $labelLen * $labelCharW) / 2);
                $labelY = ($btn['y'] ?? 0) + (int)((($btn['h'] ?? 0) - $labelFontSize) / 2);
                $this->ctx->drawText($hdc, $labelX, $labelY, $label, $labelFontSize, $btn['fg'] ?? 0xFFFFFF, 1);
            }
        }

        $this->ctx->endFrame($this->hWnd, $hdc);
    }
}