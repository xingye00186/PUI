<?php

use native_types;

/**
 * ReactiveComponent - 响应式组件基类
 *
 * AOT 兼容版本: 去掉 __get/__set 魔术方法，改用直接属性声明 + 手动脏标记。
 * 子类声明实际属性并在修改状态后调用 $this->dirty = true。
 *
 * 继承自 BaseComponent，提供响应式状态管理能力。
 * 子类应声明业务属性 (display, expression 等) 并在修改时标记 dirty。
 */
abstract class ReactiveComponent extends BaseComponent
{
    /** 全局变更队列 */
    protected ?ChangeQueue $queue = null;

    /** 脏标记: 是否需要重绘 */
    public bool $dirty = false;

    /** 模板文件路径(可选) */
    public string $template = '';

    /** v5 M4: group 级 dirty 追踪 */
    protected array $dirtyGroups = [];

    /** v5 M4: 是否需要全量重绘 (首帧/强制刷新) */
    protected bool $fullDirty = true;

    public function __construct(?string $componentId = null)
    {
        $id = $componentId ?? get_class($this);
        parent::__construct($id);
    }

    public function initShared(int $tableSize = 10240): void
    {
        $this->queue = new ChangeQueue();
    }

    /** v5 M4: 标记特定 group 为 dirty */
    public function markGroupDirty(string $groupId): void
    {
        $this->dirtyGroups[$groupId] = true;
        $this->dirty = true;
    }

    /** v5 M4: 标记全量 dirty (全部 group 重绘) */
    public function markFullDirty(): void
    {
        $this->fullDirty = true;
        $this->dirty = true;
    }

    /** v5 M4: 消费 dirty 状态 (渲染器调用后重置) */
    public function consumeDirty(): array
    {
        $groups = $this->dirtyGroups;
        $full = $this->fullDirty;
        $this->dirtyGroups = [];
        $this->fullDirty = false;
        return ['full' => $full, 'groups' => $groups];
    }

    // ===== 子类必须实现的抽象方法 =====

    /**
     * 获取绑定值（用于文本渲染）
     *
     * @param string $bindKey 绑定键名
     * @return string 绑定的值
     */
    abstract public function getBindValue(string $bindKey): string;

    /**
     * 处理按钮点击
     *
     * @param array $btn 按钮数据
     */
    abstract public function dispatchClick(array $btn): void;

    /**
     * 求值条件表达式
     *
     * @param array $cond 条件数组 ['prop' => 'xxx', 'op' => 'truthy', 'value' => 'xxx']
     * @return bool 条件结果
     */
    abstract public function evalCondition(array $cond): bool;
}