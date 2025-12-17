<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Interfaces;

/**
 * 节点注册器接口
 *
 * 管理节点类型的注册和实例化。
 *
 * @package Alkaid\Workflow\Engine\Nodes\Interfaces
 */
interface NodeRegistryInterface
{
    /**
     * 注册节点类型
     *
     * @param string $type 节点类型
     * @param string $class 节点类名
     * @param string|null $subType 节点子类型
     * @return static
     */
    public function register(string $type, string $class, ?string $subType = null): static;

    /**
     * 注销节点类型
     *
     * @param string $type 节点类型
     * @param string|null $subType 节点子类型
     * @return static
     */
    public function unregister(string $type, ?string $subType = null): static;

    /**
     * 检查节点类型是否已注册
     *
     * @param string $type 节点类型
     * @param string|null $subType 节点子类型
     * @return bool
     */
    public function has(string $type, ?string $subType = null): bool;

    /**
     * 获取节点类名
     *
     * @param string $type 节点类型
     * @param string|null $subType 节点子类型
     * @return string|null
     */
    public function getClass(string $type, ?string $subType = null): ?string;

    /**
     * 创建节点实例
     *
     * @param string $type 节点类型
     * @param string|null $subType 节点子类型
     * @param array $config 节点配置
     * @return NodeInterface
     */
    public function create(string $type, ?string $subType = null, array $config = []): NodeInterface;

    /**
     * 从数据创建节点实例
     *
     * @param array $data 节点数据
     * @return NodeInterface
     */
    public function createFromArray(array $data): NodeInterface;

    /**
     * 获取所有已注册的节点类型
     *
     * @return array
     */
    public function getRegisteredTypes(): array;

    /**
     * 获取节点类型信息
     *
     * @return array 包含类型、子类型、名称、描述、配置 Schema 等信息
     */
    public function getTypesInfo(): array;

    /**
     * 批量注册节点类型
     *
     * @param array $types 节点类型映射 ['type' => 'class'] 或 ['type:subtype' => 'class']
     * @return static
     */
    public function registerMany(array $types): static;

    /**
     * 清除所有注册
     *
     * @return static
     */
    public function clear(): static;
}
