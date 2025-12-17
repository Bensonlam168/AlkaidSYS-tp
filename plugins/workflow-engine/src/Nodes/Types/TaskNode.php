<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * 任务节点
 *
 * 执行具体业务逻辑的节点，支持多种任务类型：
 * - 用户任务：需要人工处理
 * - 服务任务：自动执行服务调用
 * - 脚本任务：执行脚本代码
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class TaskNode extends AbstractNode
{
    /**
     * 任务处理器
     *
     * @var callable|null
     */
    protected $handler = null;

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return NodeInterface::TYPE_TASK;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubType(): ?string
    {
        return $this->config['sub_type'] ?? $this->config['task_type'] ?? NodeInterface::TASK_SERVICE;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return $this->config['description'] ?? '任务节点';
    }

    /**
     * {@inheritDoc}
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        $taskType = $this->getSubType();

        return match ($taskType) {
            NodeInterface::TASK_USER => $this->executeUserTask($context),
            NodeInterface::TASK_SERVICE => $this->executeServiceTask($context),
            NodeInterface::TASK_SCRIPT => $this->executeScriptTask($context),
            NodeInterface::TASK_SEND => $this->executeSendTask($context),
            NodeInterface::TASK_RECEIVE => $this->executeReceiveTask($context),
            NodeInterface::TASK_MANUAL => $this->executeManualTask($context),
            NodeInterface::TASK_BUSINESS_RULE => $this->executeBusinessRuleTask($context),
            default => $this->executeServiceTask($context),
        };
    }

    /**
     * 执行用户任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeUserTask(ExecutionContext $context): NodeResult
    {
        // 用户任务需要等待用户处理
        $assignee = $this->resolveAssignee($context);
        $candidateUsers = $this->resolveCandidateUsers($context);
        $candidateGroups = $this->resolveCandidateGroups($context);

        $taskData = [
            'task_id' => $this->generateTaskId(),
            'task_type' => 'user',
            'node_id' => $this->id,
            'node_name' => $this->name,
            'assignee' => $assignee,
            'candidate_users' => $candidateUsers,
            'candidate_groups' => $candidateGroups,
            'form' => $this->config['form'] ?? null,
            'due_date' => $this->calculateDueDate(),
            'priority' => $this->config['priority'] ?? 'normal',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // 用户任务返回等待状态
        return NodeResult::waiting($taskData, '等待用户处理')
            ->setMeta('task_type', 'user')
            ->setMeta('assignee', $assignee);
    }

    /**
     * 执行服务任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeServiceTask(ExecutionContext $context): NodeResult
    {
        // 如果有自定义处理器，使用它
        if ($this->handler !== null) {
            $result = ($this->handler)($context, $this);
            if ($result instanceof NodeResult) {
                return $result;
            }
            // 处理器返回数组时转换为结果
            if (is_array($result)) {
                return NodeResult::success($result, $this->getDefaultNextNodes());
            }
        }

        // 检查是否配置了服务类
        $serviceClass = $this->config['service_class'] ?? null;
        $serviceMethod = $this->config['service_method'] ?? 'execute';

        if ($serviceClass) {
            return $this->invokeService($context, $serviceClass, $serviceMethod);
        }

        // 执行表达式（如果配置了）
        if (!empty($this->config['expression'])) {
            return $this->executeExpression($context, $this->config['expression']);
        }

        // 默认行为：直接通过
        return NodeResult::success(
            [
                'node_id' => $this->id,
                'executed_at' => date('Y-m-d H:i:s'),
            ],
            $this->getDefaultNextNodes()
        );
    }

    /**
     * 执行脚本任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeScriptTask(ExecutionContext $context): NodeResult
    {
        $script = $this->config['script'] ?? '';
        $scriptFormat = $this->config['script_format'] ?? 'php';

        if (empty($script)) {
            return NodeResult::success(
                ['message' => '脚本为空，跳过执行'],
                $this->getDefaultNextNodes()
            );
        }

        // 目前仅支持 PHP 脚本
        if ($scriptFormat !== 'php') {
            return NodeResult::failed("不支持的脚本格式: {$scriptFormat}");
        }

        try {
            // 准备脚本上下文变量
            $variables = $context->getVariables();

            // 创建沙箱环境执行脚本
            $result = $this->executeSandboxScript($script, $variables);

            // 更新上下文变量
            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    $context->setVariable($key, $value);
                }
            }

            return NodeResult::success(
                ['script_result' => $result],
                $this->getDefaultNextNodes()
            );
        } catch (\Throwable $e) {
            return NodeResult::failed("脚本执行失败: {$e->getMessage()}");
        }
    }

    /**
     * 在沙箱环境执行脚本
     *
     * @param string $script 脚本代码
     * @param array $variables 变量
     * @return mixed
     */
    protected function executeSandboxScript(string $script, array $variables): mixed
    {
        // 创建闭包作为沙箱
        $sandbox = function (array $vars) use ($script) {
            extract($vars);
            return eval($script);
        };

        return $sandbox($variables);
    }

    /**
     * 执行发送任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeSendTask(ExecutionContext $context): NodeResult
    {
        // 发送任务通常用于发送消息/通知
        $messageType = $this->config['message_type'] ?? 'notification';
        $recipient = $this->resolveValue($context, $this->config['recipient'] ?? '');
        $content = $this->resolveMessage($context, $this->config['content'] ?? '');

        $sendData = [
            'task_type' => 'send',
            'message_type' => $messageType,
            'recipient' => $recipient,
            'content' => $content,
            'sent_at' => date('Y-m-d H:i:s'),
        ];

        // 实际发送逻辑由外部系统处理
        return NodeResult::success($sendData, $this->getDefaultNextNodes())
            ->setMeta('requires_send', true);
    }

    /**
     * 执行接收任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeReceiveTask(ExecutionContext $context): NodeResult
    {
        // 接收任务等待外部消息
        return NodeResult::waiting(
            [
                'task_type' => 'receive',
                'message_type' => $this->config['expected_message_type'] ?? 'any',
                'timeout' => $this->config['timeout'] ?? 0,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            '等待接收消息'
        );
    }

    /**
     * 执行手动任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeManualTask(ExecutionContext $context): NodeResult
    {
        // 手动任务类似用户任务，但不需要表单
        return NodeResult::waiting(
            [
                'task_type' => 'manual',
                'node_id' => $this->id,
                'instructions' => $this->config['instructions'] ?? '请手动完成此任务',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            '等待手动处理'
        );
    }

    /**
     * 执行业务规则任务
     *
     * @param ExecutionContext $context 执行上下文
     * @return NodeResult
     */
    protected function executeBusinessRuleTask(ExecutionContext $context): NodeResult
    {
        $ruleId = $this->config['rule_id'] ?? null;

        if (!$ruleId) {
            return NodeResult::failed('未配置业务规则ID');
        }

        // 业务规则执行由外部规则引擎处理
        return NodeResult::success(
            [
                'task_type' => 'business_rule',
                'rule_id' => $ruleId,
                'executed_at' => date('Y-m-d H:i:s'),
            ],
            $this->getDefaultNextNodes()
        )->setMeta('requires_rule_execution', true);
    }

    /**
     * 调用服务
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $serviceClass 服务类
     * @param string $method 方法名
     * @return NodeResult
     */
    protected function invokeService(ExecutionContext $context, string $serviceClass, string $method): NodeResult
    {
        try {
            if (!class_exists($serviceClass)) {
                return NodeResult::failed("服务类不存在: {$serviceClass}");
            }

            $service = new $serviceClass();

            if (!method_exists($service, $method)) {
                return NodeResult::failed("服务方法不存在: {$serviceClass}::{$method}");
            }

            // 准备参数
            $args = $this->prepareServiceArgs($context);

            // 调用服务方法
            $result = $service->$method($context, $args);

            // 处理结果
            if ($result instanceof NodeResult) {
                return $result;
            }

            return NodeResult::success(
                ['service_result' => $result],
                $this->getDefaultNextNodes()
            );
        } catch (\Throwable $e) {
            return NodeResult::fromException($e);
        }
    }

    /**
     * 执行表达式
     *
     * @param ExecutionContext $context 执行上下文
     * @param string $expression 表达式
     * @return NodeResult
     */
    protected function executeExpression(ExecutionContext $context, string $expression): NodeResult
    {
        try {
            $variables = $context->getVariables();

            // 替换变量
            $evaluated = preg_replace_callback(
                '/\$\{(\w+)\}/',
                function ($matches) use ($variables) {
                    return var_export($variables[$matches[1]] ?? null, true);
                },
                $expression
            );

            $result = eval("return {$evaluated};");

            // 如果表达式设置了输出变量
            if (!empty($this->config['result_variable'])) {
                $context->setVariable($this->config['result_variable'], $result);
            }

            return NodeResult::success(
                ['expression_result' => $result],
                $this->getDefaultNextNodes()
            );
        } catch (\Throwable $e) {
            return NodeResult::failed("表达式执行失败: {$e->getMessage()}");
        }
    }

    /**
     * 准备服务参数
     *
     * @param ExecutionContext $context 执行上下文
     * @return array
     */
    protected function prepareServiceArgs(ExecutionContext $context): array
    {
        $argsConfig = $this->config['service_args'] ?? [];
        $args = [];

        foreach ($argsConfig as $key => $value) {
            $args[$key] = $this->resolveValue($context, $value);
        }

        return $args;
    }

    /**
     * 解析指派人
     *
     * @param ExecutionContext $context 执行上下文
     * @return string|null
     */
    protected function resolveAssignee(ExecutionContext $context): ?string
    {
        $assignee = $this->config['assignee'] ?? null;

        if (!$assignee) {
            return null;
        }

        return $this->resolveValue($context, $assignee);
    }

    /**
     * 解析候选用户
     *
     * @param ExecutionContext $context 执行上下文
     * @return array
     */
    protected function resolveCandidateUsers(ExecutionContext $context): array
    {
        $candidates = $this->config['candidate_users'] ?? [];

        if (is_string($candidates)) {
            $candidates = array_map('trim', explode(',', $candidates));
        }

        return array_map(
            fn($user) => $this->resolveValue($context, $user),
            $candidates
        );
    }

    /**
     * 解析候选用户组
     *
     * @param ExecutionContext $context 执行上下文
     * @return array
     */
    protected function resolveCandidateGroups(ExecutionContext $context): array
    {
        $groups = $this->config['candidate_groups'] ?? [];

        if (is_string($groups)) {
            $groups = array_map('trim', explode(',', $groups));
        }

        return array_map(
            fn($group) => $this->resolveValue($context, $group),
            $groups
        );
    }

    /**
     * 解析值（支持变量替换）
     *
     * @param ExecutionContext $context 执行上下文
     * @param mixed $value 原始值
     * @return mixed
     */
    protected function resolveValue(ExecutionContext $context, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // 检查是否是变量引用
        if (preg_match('/^\$\{(\w+)\}$/', $value, $matches)) {
            return $context->getVariable($matches[1]);
        }

        // 替换字符串中的变量
        return preg_replace_callback(
            '/\$\{(\w+)\}/',
            fn($matches) => (string) $context->getVariable($matches[1], $matches[0]),
            $value
        );
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
        return (string) $this->resolveValue($context, $message);
    }

    /**
     * 计算截止日期
     *
     * @return string|null
     */
    protected function calculateDueDate(): ?string
    {
        $duration = $this->config['due_duration'] ?? null;

        if (!$duration) {
            return $this->config['due_date'] ?? null;
        }

        try {
            $date = new \DateTime();
            $date->add(new \DateInterval($duration));
            return $date->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 生成任务ID
     *
     * @return string
     */
    protected function generateTaskId(): string
    {
        return sprintf(
            'task_%s_%s',
            substr(md5(uniqid((string) mt_rand(), true)), 0, 12),
            time()
        );
    }

    /**
     * 设置任务处理器
     *
     * @param callable $handler 处理器
     * @return static
     */
    public function setHandler(callable $handler): static
    {
        $this->handler = $handler;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigSchema(): array
    {
        $baseSchema = parent::getConfigSchema();

        return array_merge_recursive($baseSchema, [
            'properties' => [
                'task_type' => [
                    'type' => 'string',
                    'title' => '任务类型',
                    'enum' => [
                        NodeInterface::TASK_USER,
                        NodeInterface::TASK_SERVICE,
                        NodeInterface::TASK_SCRIPT,
                        NodeInterface::TASK_SEND,
                        NodeInterface::TASK_RECEIVE,
                        NodeInterface::TASK_MANUAL,
                        NodeInterface::TASK_BUSINESS_RULE,
                    ],
                    'default' => NodeInterface::TASK_SERVICE,
                ],
                'assignee' => [
                    'type' => 'string',
                    'title' => '指派人',
                    'description' => '任务指派给的用户ID，支持变量 ${variable}',
                ],
                'candidate_users' => [
                    'type' => 'array',
                    'title' => '候选用户',
                    'description' => '可以处理此任务的用户列表',
                ],
                'candidate_groups' => [
                    'type' => 'array',
                    'title' => '候选用户组',
                    'description' => '可以处理此任务的用户组列表',
                ],
                'form' => [
                    'type' => 'string',
                    'title' => '表单标识',
                    'description' => '用户任务使用的表单ID或表单定义',
                ],
                'service_class' => [
                    'type' => 'string',
                    'title' => '服务类',
                    'description' => '服务任务调用的类名',
                ],
                'service_method' => [
                    'type' => 'string',
                    'title' => '服务方法',
                    'description' => '服务任务调用的方法名',
                    'default' => 'execute',
                ],
                'script' => [
                    'type' => 'string',
                    'title' => '脚本代码',
                    'description' => '脚本任务执行的代码',
                ],
                'expression' => [
                    'type' => 'string',
                    'title' => '表达式',
                    'description' => '要执行的表达式',
                ],
                'due_duration' => [
                    'type' => 'string',
                    'title' => '截止时长',
                    'description' => 'ISO 8601 时间间隔格式，如 PT1H（1小时）、P1D（1天）',
                ],
                'priority' => [
                    'type' => 'string',
                    'title' => '优先级',
                    'enum' => ['low', 'normal', 'high', 'urgent'],
                    'default' => 'normal',
                ],
            ],
        ]);
    }
}
