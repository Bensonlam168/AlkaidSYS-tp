<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * 结束节点
 *
 * 工作流的出口点，标志工作流执行完成。
 * 可以有多个结束节点表示不同的结束状态。
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class EndNode extends AbstractNode
{
    /**
     * 结束状态常量
     */
    public const END_STATUS_COMPLETED = 'completed';
    public const END_STATUS_TERMINATED = 'terminated';
    public const END_STATUS_CANCELLED = 'cancelled';
    public const END_STATUS_ERROR = 'error';

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_END;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->config['description'] ?? '工作流结束节点';
    }

    /**
     * {@inheritDoc}
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        // 确定结束状态
        $endStatus = $this->config['end_status'] ?? self::END_STATUS_COMPLETED;

        // 处理输出参数映射
        $outputData = $this->collectOutputData($context);

        // 构建结束数据
        $endData = [
            'node_id' => $this->id,
            'node_name' => $this->name,
            'end_status' => $endStatus,
            'ended_at' => date('Y-m-d H:i:s'),
            'instance_id' => $context->getInstanceId(),
            'output_data' => $outputData,
        ];

        // 添加结束消息（如果配置了）
        if (!empty($this->config['end_message'])) {
            $endData['message'] = $this->resolveMessage($context, $this->config['end_message']);
        }

        // 标记上下文为完成状态
        $context->setVariable('_workflow_completed', true);
        $context->setVariable('_workflow_end_status', $endStatus);

        // 结束节点没有下一个节点
        return NodeResult::success($endData, [])
            ->setMeta('event', 'workflow.ended')
            ->setMeta('end_status', $endStatus);
    }

    /**
     * 收集输出数据
     *
     * @param ExecutionContext $context 执行上下文
     * @return array
     */
    protected function collectOutputData(ExecutionContext $context): array
    {
        $outputMapping = $this->config['output_mapping'] ?? [];

        if (empty($outputMapping)) {
            // 如果没有配置映射，返回所有非内部变量
            return array_filter(
                $context->getVariables(),
                fn($key) => !str_starts_with($key, '_'),
                ARRAY_FILTER_USE_KEY
            );
        }

        $output = [];
        foreach ($outputMapping as $outputKey => $variableKey) {
            $output[$outputKey] = $context->getVariable($variableKey);
        }

        return $output;
    }

    /**
     * 解析消息模板
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $message 消息模板
     * @return string
     */
    protected function resolveMessage(ExecutionContext $context, string $message): string
    {
        return preg_replace_callback(
            '/\$\{(\w+)\}/',
            fn($matches) => (string) $context->getVariable($matches[1], $matches[0]),
            $message
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigSchema(): array
    {
        $baseSchema = parent::getConfigSchema();

        return array_merge_recursive($baseSchema, [
            'properties' => [
                'end_status' => [
                    'type' => 'string',
                    'title' => '结束状态',
                    'description' => '工作流的结束状态',
                    'enum' => [
                        self::END_STATUS_COMPLETED,
                        self::END_STATUS_TERMINATED,
                        self::END_STATUS_CANCELLED,
                        self::END_STATUS_ERROR,
                    ],
                    'default' => self::END_STATUS_COMPLETED,
                ],
                'end_message' => [
                    'type' => 'string',
                    'title' => '结束消息',
                    'description' => '工作流结束时的消息，支持变量替换 ${variable}',
                    'maxLength' => 500,
                ],
                'output_mapping' => [
                    'type' => 'object',
                    'title' => '输出参数映射',
                    'description' => '将工作流变量映射到输出参数',
                ],
                'terminate_all' => [
                    'type' => 'boolean',
                    'title' => '终止所有分支',
                    'description' => '是否终止所有并行分支',
                    'default' => false,
                ],
            ],
        ]);
    }
}
