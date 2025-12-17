<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeRegistryInterface;
use Alkaid\Workflow\Engine\Nodes\Types\EndNode;
use Alkaid\Workflow\Engine\Nodes\Types\GatewayNode;
use Alkaid\Workflow\Engine\Nodes\Types\StartNode;
use Alkaid\Workflow\Engine\Nodes\Types\TaskNode;

/**
 * 节点注册器
 *
 * 管理节点类型的注册和实例化。
 * 提供节点类型的注册、查找、实例化功能。
 *
 * @package Alkaid\Workflow\Engine\Nodes
 */
class NodeRegistry implements NodeRegistryInterface
{
    /**
     * 节点类型映射
     * 'type:subtype' => 'class'
     *
     * @var array<string, string>
     */
    protected array $typeMap = [];

    /**
     * 节点实例缓存（单例模式）
     *
     * @var array<string, NodeInterface>
     */
    protected array $instances = [];

    /**
     * 是否启用单例模式
     */
    protected bool $singletonMode = false;

    /**
     * 构造函数
     *
     * @param bool $autoRegisterBuiltin 是否自动注册内置节点类型
     */
    public function __construct(bool $autoRegisterBuiltin = true)
    {
        if ($autoRegisterBuiltin) {
            $this->registerBuiltinTypes();
        }
    }

    /**
     * 注册内置节点类型
     */
    protected function registerBuiltinTypes(): void
    {
        // 注册开始节点
        $this->register(NodeInterface::TYPE_START, StartNode::class);

        // 注册结束节点
        $this->register(NodeInterface::TYPE_END, EndNode::class);

        // 注册任务节点类型
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_USER);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_SERVICE);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_SCRIPT);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_SEND);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_RECEIVE);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_MANUAL);
        $this->register(NodeInterface::TYPE_TASK, TaskNode::class, NodeInterface::TASK_BUSINESS_RULE);

        // 注册网关节点类型
        $this->register(NodeInterface::TYPE_GATEWAY, GatewayNode::class);
        $this->register(NodeInterface::TYPE_GATEWAY, GatewayNode::class, NodeInterface::GATEWAY_EXCLUSIVE);
        $this->register(NodeInterface::TYPE_GATEWAY, GatewayNode::class, NodeInterface::GATEWAY_PARALLEL);
        $this->register(NodeInterface::TYPE_GATEWAY, GatewayNode::class, NodeInterface::GATEWAY_INCLUSIVE);
        $this->register(NodeInterface::TYPE_GATEWAY, GatewayNode::class, NodeInterface::GATEWAY_EVENT);
    }

    /**
     * {@inheritDoc}
     */
    public function register(string $type, string $class, ?string $subType = null): static
    {
        $key = $this->makeKey($type, $subType);
        $this->typeMap[$key] = $class;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function unregister(string $type, ?string $subType = null): static
    {
        $key = $this->makeKey($type, $subType);
        unset($this->typeMap[$key]);
        unset($this->instances[$key]);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $type, ?string $subType = null): bool
    {
        $key = $this->makeKey($type, $subType);
        return isset($this->typeMap[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function getClass(string $type, ?string $subType = null): ?string
    {
        // 首先尝试精确匹配
        $key = $this->makeKey($type, $subType);
        if (isset($this->typeMap[$key])) {
            return $this->typeMap[$key];
        }

        // 如果有子类型但没找到，尝试基础类型
        if ($subType !== null) {
            $baseKey = $this->makeKey($type, null);
            return $this->typeMap[$baseKey] ?? null;
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $type, ?string $subType = null, array $config = []): NodeInterface
    {
        $class = $this->getClass($type, $subType);

        if (!$class) {
            throw new \InvalidArgumentException(
                "未注册的节点类型: {$type}" . ($subType ? ":{$subType}" : '')
            );
        }

        // 确保子类型在配置中
        if ($subType !== null && !isset($config['sub_type'])) {
            $config['sub_type'] = $subType;
        }

        // 单例模式
        if ($this->singletonMode) {
            $key = $this->makeKey($type, $subType);
            if (!isset($this->instances[$key])) {
                $this->instances[$key] = new $class(null, $config);
            }
            return $this->instances[$key];
        }

        return new $class(null, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function createFromArray(array $data): NodeInterface
    {
        $type = $data['type'] ?? null;

        if (!$type) {
            throw new \InvalidArgumentException('节点数据缺少类型字段');
        }

        $subType = $data['sub_type'] ?? null;
        $class = $this->getClass($type, $subType);

        if (!$class) {
            throw new \InvalidArgumentException(
                "未注册的节点类型: {$type}" . ($subType ? ":{$subType}" : '')
            );
        }

        return $class::fromArray($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getRegisteredTypes(): array
    {
        $types = [];

        foreach (array_keys($this->typeMap) as $key) {
            [$type, $subType] = $this->parseKey($key);
            if (!isset($types[$type])) {
                $types[$type] = [];
            }
            if ($subType && !in_array($subType, $types[$type], true)) {
                $types[$type][] = $subType;
            }
        }

        return $types;
    }

    /**
     * {@inheritDoc}
     */
    public function getTypesInfo(): array
    {
        $info = [];

        foreach ($this->typeMap as $key => $class) {
            [$type, $subType] = $this->parseKey($key);

            // 创建临时实例获取信息
            try {
                $node = new $class();
                $infoKey = $subType ? "{$type}:{$subType}" : $type;

                $info[$infoKey] = [
                    'type' => $type,
                    'sub_type' => $subType,
                    'class' => $class,
                    'name' => $node->getName() ?: $type,
                    'description' => $node->getDescription(),
                    'config_schema' => $node->getConfigSchema(),
                ];
            } catch (\Throwable) {
                // 忽略实例化失败的类型
                continue;
            }
        }

        return $info;
    }

    /**
     * {@inheritDoc}
     */
    public function registerMany(array $types): static
    {
        foreach ($types as $key => $class) {
            if (str_contains($key, ':')) {
                [$type, $subType] = explode(':', $key, 2);
                $this->register($type, $class, $subType);
            } else {
                $this->register($key, $class);
            }
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): static
    {
        $this->typeMap = [];
        $this->instances = [];
        return $this;
    }

    /**
     * 生成键名
     *
     * @param string $type 节点类型
     * @param string|null $subType 子类型
     * @return string
     */
    protected function makeKey(string $type, ?string $subType): string
    {
        return $subType ? "{$type}:{$subType}" : $type;
    }

    /**
     * 解析键名
     *
     * @param string $key 键名
     * @return array [type, subType]
     */
    protected function parseKey(string $key): array
    {
        if (str_contains($key, ':')) {
            return explode(':', $key, 2);
        }
        return [$key, null];
    }

    /**
     * 启用单例模式
     *
     * @param bool $enabled 是否启用
     * @return static
     */
    public function enableSingletonMode(bool $enabled = true): static
    {
        $this->singletonMode = $enabled;
        return $this;
    }

    /**
     * 获取节点类型列表（简化版）
     *
     * @return array
     */
    public function getTypeList(): array
    {
        $list = [];

        foreach ($this->typeMap as $key => $class) {
            [$type, $subType] = $this->parseKey($key);

            try {
                $node = new $class();
                $list[] = [
                    'key' => $key,
                    'type' => $type,
                    'sub_type' => $subType,
                    'name' => $node->getDisplayName() ?: $node->getName() ?: $type,
                    'description' => $node->getDescription(),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $list;
    }

    /**
     * 从配置创建注册器
     *
     * @param array $config 配置数组
     * @return static
     */
    public static function fromConfig(array $config): static
    {
        $autoRegister = $config['auto_register_builtin'] ?? true;
        $registry = new static($autoRegister);

        if (isset($config['types'])) {
            $registry->registerMany($config['types']);
        }

        if (isset($config['singleton_mode'])) {
            $registry->enableSingletonMode($config['singleton_mode']);
        }

        return $registry;
    }

    /**
     * 导出配置
     *
     * @return array
     */
    public function exportConfig(): array
    {
        return [
            'auto_register_builtin' => false, // 导出的配置包含所有类型，无需自动注册
            'singleton_mode' => $this->singletonMode,
            'types' => $this->typeMap,
        ];
    }
}
