<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * 开始节点
 *
 * 工作流的入口点，每个工作流必须有且只有一个开始节点。
 * 开始节点负责初始化工作流执行，并将控制流传递给后续节点。
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class StartNode extends AbstractNode
{
    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_START;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->config['description'] ?? '工作流开始节点';
    }

    /**
     * {@inheritDoc}
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        // 记录工作流开始
        $startData = [
            'node_id' => $this->id,
            'node_name' => $this->name,
            'started_at' => date('Y-m-d H:i:s'),
            'instance_id' => $context->getInstanceId(),
            'workflow_id' => $context->getVariable('workflow_id'),
            'tenant_id' => $context->getTenantId(),
        ];

        // 处理输入参数映射
        if (!empty($this->config['input_mapping'])) {
            $this->applyInputMapping($context);
        }

        // 设置初始变量（如果配置了）
        if (!empty($this->config['initial_variables'])) {
            foreach ($this->config['initial_variables'] as $key => $value) {
                $context->setVariable($key, $value);
            }
        }

        // 获取下一个节点
        $nextNodes = $this->getDefaultNextNodes();

        return NodeResult::success($startData, $nextNodes)
            ->setMeta('event', 'workflow.started');
    }

    /**
     * 应用输入参数映射
     *
     * @param ExecutionContext $context 执行上下文
     */
    protected function applyInputMapping(ExecutionContext $context): void
    {
        $mapping = $this->config['input_mapping'] ?? [];
        $inputData = $context->getVariables();

        foreach ($mapping as $targetKey => $sourceKey) {
            if (isset($inputData[$sourceKey])) {
                $context->setVariable($targetKey, $inputData[$sourceKey]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigSchema(): array
    {
        $baseSchema = parent::getConfigSchema();

        return array_merge_recursive($baseSchema, [
            'properties' => [
                'input_mapping' => [
                    'type' => 'object',
                    'title' => '输入参数映射',
                    'description' => '将输入参数映射到工作流变量',
                ],
                'initial_variables' => [
                    'type' => 'object',
                    'title' => '初始变量',
                    'description' => '工作流启动时设置的初始变量',
                ],
                'form_schema' => [
                    'type' => 'object',
                    'title' => '表单 Schema',
                    'description' => '启动工作流时的输入表单定义',
                ],
            ],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function canExecute(ExecutionContext $context): bool
    {
        // 开始节点总是可以执行
        if ($context->isCancelled()) {
            return false;
        }
        return true;
    }
}
