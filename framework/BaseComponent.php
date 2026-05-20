<?php

use native_types;

/**
 * BaseComponent - 组件基类
 *
 * 提供组件树结构的基础实现，支持父子组件引用、子组件管理和属性配置。
 * 子类需要实现 getLayout() 方法返回布局数据。
 *
 * AOT 兼容性:
 *   - 使用 strval() 确保字符串类型
 *   - 使用 (array) 类型转换保留数组引用
 *   - 使用 array_keys() + for 循环遍历关联数组
 */
abstract class BaseComponent implements ComponentInterface
{
    /** 组件唯一标识 */
    protected string $id = '';

    /** 父组件引用 */
    protected ?ComponentInterface $parent = null;

    /** 子组件列表 (id => ComponentInterface) */
    protected array $children = [];

    /** 组件配置属性 (x, y, z 等偏移量) */
    protected array $props = [];

    /** 是否已挂载 */
    protected bool $attached = false;

    public function __construct(string $id = '')
    {
        $this->id = $id;
    }

    /** @inheritDoc */
    public function getId(): string
    {
        return $this->id;
    }

    /** @inheritDoc */
    public function getParent(): ?ComponentInterface
    {
        return $this->parent;
    }

    /** @inheritDoc */
    public function getChildren(): array
    {
        return $this->children;
    }

    /** @inheritDoc */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * 设置父组件引用
     */
    public function setParent(ComponentInterface $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * 设置组件配置属性
     */
    public function setProps(array $props): void
    {
        $this->props = $props;
    }

    /**
     * 添加子组件
     *
     * @param ComponentInterface $child 子组件
     * @param array $props 子组件属性（offset 等）
     */
    public function addChild(ComponentInterface $child, array $props = []): void
    {
        $childId = $child->getId();
        $this->children[$childId] = $child;
        $child->setParent($this);
        if (count($props) > 0) {
            $child->setProps($props);
        }
    }

    /**
     * 移除子组件
     *
     * @param string $childId 子组件标识
     */
    public function removeChild(string $childId): void
    {
        if (isset($this->children[$childId])) {
            $this->children[$childId]->onDetach();
            unset($this->children[$childId]);
        }
    }

    /**
     * 检查组件是否已挂载
     */
    public function isAttached(): bool
    {
        return $this->attached;
    }

    /**
     * 标记组件已挂载（由 Application 调用）
     */
    public function markAttached(): void
    {
        $this->attached = true;
    }

    /**
     * 标记组件已卸载（由 Application 调用）
     */
    public function markDetached(): void
    {
        $this->attached = false;
    }

    /**
     * 获取所有后代的列表（深度优先）
     * AOT 兼容: 使用 for 循环代替 foreach
     *
     * @return ComponentInterface[]
     */
    public function getAllDescendants(): array
    {
        $result = [];
        $this->collectDescendants($this, $result);
        return $result;
    }

    private function collectDescendants(ComponentInterface $comp, array &$result): void
    {
        $children = $comp->getChildren();
        $childIds = array_keys($children);
        $count = count($childIds);
        for ($i = 0; $i < $count; $i++) {
            $child = $children[$childIds[$i]];
            $result[] = $child;
            $this->collectDescendants($child, $result);
        }
    }

    /**
     * 获取初始组件树（包含自身和所有后代）
     * 用于 Application 初始化挂载
     *
     * @return ComponentInterface[]
     */
    public function getBaseComponents(): array
    {
        $result = [$this];
        $descendants = $this->getAllDescendants();
        foreach ($descendants as $desc) {
            $result[] = $desc;
        }
        return $result;
    }

    // ===== 子类必须实现的方法 =====

    abstract public function getLayout(): array;
    abstract public function onAttach(): void;
    abstract public function onDetach(): void;
}