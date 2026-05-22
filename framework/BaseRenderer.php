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
    private array $componentMap = [];  // v6 M5: group_id -> component mapping

    public function __construct(ReactiveComponent $component, RenderContext $ctx)
    {
        $this->component = $component;
        $this->ctx = $ctx;
        // v6 M5: Build component map from root's children for bind value lookup
        $this->buildComponentMap($component);
    }

    /**
     * v6 M5: 更新组件映射（在每次渲染前调用）
     * 因为子组件是在运行时动态挂载的，需要在渲染前刷新映射
     */
    public function updateComponentMap(): void
    {
        $this->componentMap = [];
        $this->buildComponentMap($this->component);
    }

    /**
     * v6 M5: Build component map (group_id -> component)
     * 支持大小写不敏感的匹配（组件ID可能是 DisplayPanel，但 group_id 是 display-panel）
     */
    private function buildComponentMap(ReactiveComponent $comp): void
    {
        $this->componentMap[strtolower($comp->getId())] = $comp;
        foreach ($comp->getChildren() as $child) {
            $this->componentMap[strtolower($child->getId())] = $child;
            $this->buildComponentMap($child);
        }
    }

    /**
     * 从组件属性获取绑定值
     * 用于在绘制前解析元素的 bind 字段
     *
     * v6 M5: 支持子组件绑定 - 如果元素有 group_id，从对应的子组件获取值
     */
    protected function getBindValue(string $bindKey, string $groupId = ''): string
    {
        // v6 M5: 如果有 group_id，从对应的子组件获取值（大小写不敏感）
        if ($groupId !== '') {
            $groupIdLower = strtolower($groupId);
            if (isset($this->componentMap[$groupIdLower])) {
                return $this->componentMap[$groupIdLower]->getBindValue($bindKey);
            }
        }
        // 否则从根组件获取
        return $this->component->getBindValue($bindKey);
    }

    /**
     * v6 M5: Get list item text from todoItems array
     * Extracts text from the JSON array based on index
     *
     * @param string $bindKey e.g., "item_text_5" -> returns text of item at index 5
     */
    protected function getListItemText(string $bindKey): string
    {
        // Extract index from "item_text_N"
        if (!preg_match('/^item_text_(\d+)$/', $bindKey, $m)) {
            return '';
        }
        $index = (int)$m[1];

        // Get todoItems from component
        $todoItems = $this->component->getBindValue('todoItems');
        $items = json_decode($todoItems, true) ?? [];

        if ($index < 0 || $index >= count($items)) {
            return '';  // Empty for non-existent items
        }

        return $items[$index]['text'] ?? '';
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

        // ====== v6 M5 FIX: 按 flex_index 排序 ======
        // flex_index=-1 的背景矩形先渲染，flex_index=0 的内容后渲染
        usort($elements, function($a, $b) {
            $idxA = isset($a['flex_index']) ? (int)$a['flex_index'] : 0;
            $idxB = isset($b['flex_index']) ? (int)$b['flex_index'] : 0;
            return $idxA - $idxB; // 升序：-1 < 0
        });

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
        // v6 M5: Scroll context for offsetting list items inside scroll-container
        $scrollCtx = null; // {x, y, w, h, scrollTop} or null

        for ($l = 0; $l <= $maxLayer; $l++) {
            for ($i = 0; $i < $elCount; $i++) {
                $el = $elements[$i];
                if (!is_array($el)) continue;
                if (($el['layer'] ?? 0) !== $l) continue;
                // condition 字段必须是数组
                $cond = $el['condition'] ?? null;
                if ($cond !== null && !is_array($cond)) continue;
                if ($cond !== null && !$this->component->evalCondition($cond)) continue;

                // ====== v6 M5: Track scroll-container context ======
                $elType = $el['type'] ?? '';
                if ($elType === 'scroll-container') {
                    // Read scrollTop from binding
                    $scrollTopVal = 0;
                    $scrollTopBind = $el['scroll-top-bind'] ?? '';
                    if ($scrollTopBind !== '') {
                        $scrollTopStr = $this->getBindValue($scrollTopBind, $el['group_id'] ?? '');
                        $scrollTopVal = (int)$scrollTopStr;
                    }

                    // v6 M6: Dynamic content-height from actual items count
                    $itemsBind = $el['items-bind'] ?? '';
                    $itemHeight = $el['item-height'] ?? 50;
                    $actualCount = 0;
                    $contentHeight = $el['content-height'] ?? 0;

                    if ($itemsBind !== '') {
                        $itemsJson = $this->getBindValue($itemsBind, $el['group_id'] ?? '');
                        $items = json_decode($itemsJson, true) ?? [];
                        $actualCount = count($items);
                        $contentHeight = max($itemHeight, $actualCount * $itemHeight);
                        $el['content-height'] = $contentHeight;
                    }

                    // Clamp scrollTop to valid range
                    $containerH = $el['h'] ?? 0;
                    $maxScrollTop = max(0, $contentHeight - $containerH);
                    if ($scrollTopVal > $maxScrollTop) {
                        $scrollTopVal = $maxScrollTop;
                    }
                    if ($scrollTopVal < 0) {
                        $scrollTopVal = 0;
                    }

                    $scrollCtx = [
                        'x' => $el['x'] ?? 0,
                        'y' => $el['y'] ?? 0,
                        'w' => $el['w'] ?? 0,
                        'h' => $containerH,
                        'scrollTop' => $scrollTopVal,
                        'actualCount' => $actualCount,
                    ];
                    // Pass scrollTop to drawScrollContainer for thumb position
                    $el['scroll-top'] = $scrollTopVal;
                    // Render the scroll container background + scrollbar
                    $this->ctx->drawElement($el);
                    continue;
                }

                // ====== v6 M5: Apply scroll offset for children inside scroll-container ======
                $isScrollChild = ($el['scroll-container'] ?? false);
                if ($isScrollChild && $scrollCtx !== null) {
                    // v6 M8 FIX: Dynamic slot-to-item mapping for scrolling
                    // When scrolled, slot N shows item (N - baseIndex)
                    $scrollTopVal = $scrollCtx['scrollTop'] ?? 0;
                    $itemHeight = $el['item-height'] ?? 50;
                    $baseIndex = (int)($scrollTopVal / $itemHeight);
                    $listIndex = $el['list_index'] ?? -1;
                    $actualItemIndex = $listIndex - $baseIndex;
                    $actualCount = $scrollCtx['actualCount'] ?? 0;
                    
                    // Check if this item index is within valid range
                    if ($actualItemIndex < 0 || $actualItemIndex >= $actualCount) {
                        continue;
                    }
                    
                    $el['y'] = ($el['y'] ?? 0) - $scrollTopVal;
                    $elH = $el['h'] ?? 0;
                    // Skip if element is completely outside the scroll container's visible area
                    if ($el['y'] + $elH <= $scrollCtx['y'] || $el['y'] >= $scrollCtx['y'] + $scrollCtx['h']) {
                        continue;
                    }
                }

                // 预处理：解析绑定值并添加到 el['text']（AOT 安全写法）
                // v6 M5: 使用 group_id 查找正确的组件获取绑定值
                $bindKey = $el['bind'] ?? '';
                $groupId = $el['group_id'] ?? '';
                if ($bindKey !== '') {
                    // v6 M5: Handle list item bindings (item_text_N pattern)
                    if (strpos($bindKey, 'item_text_') === 0) {
                        $el['text'] = $this->getListItemText($bindKey);
                    } else {
                        $el['text'] = $this->getBindValue($bindKey, $groupId);
                    }
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