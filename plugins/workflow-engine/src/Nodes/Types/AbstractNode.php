<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * 抽象节点基类
 *
 * 提供节点的公共实现，所有具体节点类型都应继承此类。
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
abstract class AbstractNode implements NodeInterface
{
    /**
     * 节点ID
     */
    protected string $id;

    /**
     * 节点名称
     */
    protected string $name = '';

    /**
     * 节点显示名称
     */
    protected string $displayName = '';

    /**
     * 节点配置
     */
    protected array $config = [];

    /**
     * 输入连接
     */
    protected array $inputs = [];

    /**
     * 输出连接
     */
    protected array $outputs = [];

    /**
     * 元数据
     */
    protected array $metadata = [];

    /**
     * 配置验证错误
     */
    protected array $validationErrors = [];

    /**
     * 构造函数
     *
     * @param string|null $id 节点ID
     * @param array $config 节点配置
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $this->id = $id ?? $this->generateId();
        $this->config = $config;
        $this->initFromConfig($config);
    }

    /**
     * 从配置初始化
     *
     * @param array $config 配置数组
     */
    protected function initFromConfig(array $config): void
    {
        if (isset($config['name'])) {
            $this->name = (string) $config['name'];
        }
        if (isset($config['display_name'])) {
            $this->displayName = (string) $config['display_name'];
        }
        if (isset($config['inputs'])) {
            $this->inputs = (array) $config['inputs'];
        }
        if (isset($config['outputs'])) {
            $this->outputs = (array) $config['outputs'];
        }
        if (isset($config['metadata'])) {
            $this->metadata = (array) $config['metadata'];
        }
    }

    /**
     * 生成唯一ID
     *
     * @return string
     */
    protected function generateId(): string
    {
        return sprintf(
            '%s_%s_%s',
            $this->getType(),
            substr(md5(uniqid((string) mt_rand(), true)), 0, 8),
            time()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getDisplayName(): string
    {
        return $this->displayName ?: $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->config['description'] ?? '';
    }

    /**
     * 执行节点的模板方法
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    public function execute(ExecutionContext $context): NodeResult
    {
        // 检查是否可执行
        if (!$this->canExecute($context)) {
            return NodeResult::skipped('节点执行条件不满足', $this->getDefaultNextNodes());
        }

        try {
            // 执行前置钩子
            $this->beforeExecute($context);

            // 执行节点逻辑
            $result = $this->doExecute($context);

            // 设置节点信息
            $result->setNodeInfo($this->id, $this->name);

            // 执行后置钩子
            $this->afterExecute($context, $result);

            return $result;
        } catch (\Throwable $e) {
            $result = NodeResult::fromException($e);
            $result->setNodeInfo($this->id, $this->name);
            $this->onError($context, $e);
            return $result;
        }
    }

    /**
     * 执行节点核心逻辑
     *
     * 子类必须实现此方法
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    abstract protected function doExecute(ExecutionContext $context): NodeResult;

    /**
     * 执行前钩子
     *
     * @param ExecutionContext $context 执行上下文
     */
    protected function beforeExecute(ExecutionContext $context): void
    {
        // 子类可以覆盖此方法
    }

    /**
     * 执行后钩子
     *
     * @param ExecutionContext $context 执行上下文
     * @param NodeResult $result 执行结果
     */
    protected function afterExecute(ExecutionContext $context, NodeResult $result): void
    {
        // 子类可以覆盖此方法
    }

    /**
     * 错误处理钩子
     *
     * @param ExecutionContext $context 执行上下文
     * @param \Throwable $e 异常
     */
    protected function onError(ExecutionContext $context, \Throwable $e): void
    {
        // 子类可以覆盖此方法进行错误处理
    }

    /**
     * {@inheritDoc}
     */
    public function canExecute(ExecutionContext $context): bool
    {
        // 检查上下文是否已取消或暂停
        if ($context->isCancelled() || $context->isPaused()) {
            return false;
        }

        // 检查条件表达式（如果配置了）
        if (!empty($this->config['condition'])) {
            return $this->evaluateCondition($context, $this->config['condition']);
        }

        return true;
    }

    /**
     * 评估条件表达式
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $condition 条件表达式
     * @return bool
     */
    protected function evaluateCondition(ExecutionContext $context, string $condition): bool
    {
        // 简单的条件评估，可以被子类覆盖使用更复杂的表达式引擎
        // 支持简单的变量替换
        $variables = $context->getVariables();

        // 替换变量
        $evaluated = preg_replace_callback(
            '/\$\{(\w+)\}/',
            function ($matches) use ($variables) {
                return var_export($variables[$matches[1]] ?? null, true);
            },
            $condition
        );

        // 安全地评估条件
        try {
            // @codeCoverageIgnoreStart
            return (bool) eval("return {$evaluated};");
            // @codeCoverageIgnoreEnd
        } catch (\Throwable) {
            return true; // 条件评估失败时默认允许执行
        }
    }

    /**
     * 获取默认的下一个节点列表
     *
     * @return array
     */
    protected function getDefaultNextNodes(): array
    {
        return array_map(
            fn ($output) => is_array($output) ? ($output['target'] ?? $output['to'] ?? '') : $output,
            $this->outputs
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 获取单个配置项值
     *
     * @param string $key 配置键
     * @param mixed $default 默认值
     * @return mixed
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;
        $this->initFromConfig($config);
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function validateConfig(array $config = []): bool
    {
        $this->validationErrors = [];
        $configToValidate = !empty($config) ? $config : $this->config;

        // 基础验证：检查必需的配置项
        $schema = $this->getConfigSchema();
        $required = $schema['required'] ?? [];

        foreach ($required as $field) {
            if (!isset($configToValidate[$field])) {
                $this->validationErrors[] = "缺少必需的配置项: {$field}";
            }
        }

        // 子类可以添加额外的验证逻辑
        $this->doValidateConfig();

        return empty($this->validationErrors);
    }

    /**
     * 子类可覆盖的配置验证
     */
    protected function doValidateConfig(): void
    {
        // 子类实现具体验证逻辑
    }

    /**
     * {@inheritDoc}
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '节点名称',
                    'description' => '节点的标识名称',
                    'maxLength' => 100,
                ],
                'display_name' => [
                    'type' => 'string',
                    'title' => '显示名称',
                    'description' => '节点的显示名称',
                    'maxLength' => 200,
                ],
                'description' => [
                    'type' => 'string',
                    'title' => '描述',
                    'description' => '节点的详细描述',
                    'maxLength' => 500,
                ],
                'condition' => [
                    'type' => 'string',
                    'title' => '执行条件',
                    'description' => '节点执行的前置条件表达式',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    /**
     * {@inheritDoc}
     */
    public function setInputs(array $inputs): static
    {
        $this->inputs = $inputs;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getOutputs(): array
    {
        return $this->outputs;
    }

    /**
     * {@inheritDoc}
     */
    public function setOutputs(array $outputs): static
    {
        $this->outputs = $outputs;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getMeta(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function setMeta(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->getType(),
            'sub_type' => $this->getSubType(),
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->getDescription(),
            'config' => $this->config,
            'inputs' => $this->inputs,
            'outputs' => $this->outputs,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function fromArray(array $data): static
    {
        $id = $data['id'] ?? null;
        $config = $data['config'] ?? [];

        // 合并顶层数据到配置
        foreach (['name', 'display_name', 'description', 'inputs', 'outputs', 'metadata', 'sub_type'] as $key) {
            if (isset($data[$key]) && !isset($config[$key])) {
                $config[$key] = $data[$key];
            }
        }

        return new static($id, $config);
    }

    /**
     * 设置节点名称
     *
     * @param string $name 名称
     * @return static
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 设置节点显示名称
     *
     * @param string $displayName 显示名称
     * @return static
     */
    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        return $this;
    }
}
