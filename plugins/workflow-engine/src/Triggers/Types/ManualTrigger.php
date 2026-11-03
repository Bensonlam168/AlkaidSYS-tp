<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * 手动触发器
 *
 * 最简单的触发器类型，由用户手动触发工作流执行。
 * 适用于需要人工启动的工作流场景。
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class ManualTrigger extends AbstractTrigger
{
    /**
     * 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_MANUAL;
    }

    /**
     * 获取触发器名称
     */
    public function getName(): string
    {
        return '手动触发器';
    }

    /**
     * 获取触发器描述
     */
    public function getDescription(): string
    {
        return '由用户手动触发工作流执行，适用于需要人工启动的业务流程';
    }

    /**
     * 获取配置 Schema
     *
     * 手动触发器配置相对简单
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '触发器名称',
                    'description' => '触发器的显示名称',
                    'maxLength' => 100,
                ],
                'description' => [
                    'type' => 'string',
                    'title' => '描述',
                    'description' => '触发器的详细描述',
                    'maxLength' => 500,
                ],
                'confirm_required' => [
                    'type' => 'boolean',
                    'title' => '需要确认',
                    'description' => '执行前是否需要用户确认',
                    'default' => false,
                ],
                'confirm_message' => [
                    'type' => 'string',
                    'title' => '确认消息',
                    'description' => '确认对话框显示的消息',
                    'maxLength' => 200,
                ],
                'permission_required' => [
                    'type' => 'string',
                    'title' => '所需权限',
                    'description' => '执行此触发器所需的权限代码',
                ],
                'input_schema' => [
                    'type' => 'object',
                    'title' => '输入参数 Schema',
                    'description' => '定义用户需要输入的参数结构',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * 检查是否应该触发
     *
     * 手动触发器允许未注册的工作流也能触发
     *
     * @param TriggerContext $context 触发上下文
     * @return bool
     */
    public function shouldTrigger(TriggerContext $context): bool
    {
        // 检查触发器类型是否匹配
        if ($context->getTriggerType() !== $this->getType()) {
            return false;
        }

        // 手动触发器允许未注册的工作流
        return $this->checkTriggerConditions($context);
    }

    /**
     * 检查触发条件
     *
     * 手动触发器总是可以触发
     *
     * @param TriggerContext $context 触发上下文
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        // 检查是否为手动触发
        if (!$context->isManual()) {
            return false;
        }

        $workflowId = $context->getWorkflowId();
        if (!$workflowId) {
            return false;
        }

        $config = $this->getWorkflowConfig($workflowId);
        if (!$config) {
            return true; // 未注册的工作流也允许手动触发
        }

        // 检查权限（如果配置了）
        if (!empty($config['permission_required'])) {
            // 权限检查逻辑可以通过元数据传递
            $userPermissions = $context->getMeta('user_permissions', []);
            if (!in_array($config['permission_required'], $userPermissions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 执行触发逻辑
     *
     * @param TriggerContext $context 触发上下文
     * @param array $config 触发器配置
     * @return TriggerResult
     */
    protected function doTrigger(TriggerContext $context, array $config): TriggerResult
    {
        // 构建触发数据
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $this->getName(),
            'triggered_by' => $context->getUserId(),
            'triggered_at' => date('Y-m-d H:i:s'),
            'tenant_id' => $context->getTenantId(),
            'input_data' => $context->getData(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
        ];

        // 合并配置中的元数据
        if (!empty($config['metadata'])) {
            $triggerData['config_metadata'] = $config['metadata'];
        }

        return TriggerResult::success($triggerData)
            ->setMeta('trigger_name', $config['name'] ?? $this->getName())
            ->setMeta('confirm_required', $config['confirm_required'] ?? false);
    }

    /**
     * 验证输入参数（如果配置了 input_schema）
     *
     * @param array $input 输入数据
     * @param array $schema 输入 Schema
     * @return bool
     */
    public function validateInput(array $input, array $schema): bool
    {
        if (empty($schema)) {
            return true;
        }

        // 简单的必填字段验证
        foreach ($schema['required'] ?? [] as $field) {
            if (!isset($input[$field])) {
                return false;
            }
        }

        return true;
    }
}
