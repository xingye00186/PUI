<?php

/**
 * ComponentInterface - 组件接口
 *
 * 定义所有组件必须实现的核心方法。
 * 支持组件树结构、生命周期管理和布局数据获取。
 *
 * AOT 兼容性:
 *   - 使用接口而非抽象类，允许多实现
 *   - 所有方法返回类型明确，避免推断问题
 */
interface ComponentInterface
{
    /**
     * 获取组件唯一标识
     */
    public function getId(): string;

    /**
     * 获取组件布局数据（不含偏移/绑定替换）
     * 返回格式: ['elements' => [], 'buttons' => []]
     */
    public function getLayout(): array;

    /**
     * 获取子组件列表
     * @return ComponentInterface[]
     */
    public function getChildren(): array;

    /**
     * 获取父组件引用
     */
    public function getParent(): ?ComponentInterface;

    /**
     * 获取组件配置属性（offset、props 等）
     * @return array
     */
    public function getProps(): array;

    /**
     * 组件挂载回调
     */
    public function onAttach(): void;

    /**
     * 组件卸载回调
     */
    public function onDetach(): void;
}