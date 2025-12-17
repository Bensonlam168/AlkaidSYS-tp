<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Nodes\Types;

use Alkaid\Workflow\Engine\Nodes\Interfaces\NodeInterface;
use Alkaid\Workflow\Engine\Nodes\NodeResult;
use Alkaid\Workflow\Engine\Swoole\Execution\ExecutionContext;

/**
 * Notify Node | 通知节点
 *
 * A service task node that sends notifications through various channels.
 * Supports email, SMS, push, webhook, and custom notification channels.
 * 通过多种渠道发送通知的服务任务节点。
 * 支持邮件、短信、推送、Webhook 和自定义通知渠道。
 *
 * Configuration options:
 * - channel: string - Notification channel (email, sms, push, webhook, wechat, dingtalk)
 * - template: string - Message template ID or content
 * - recipients: array - List of recipients (supports variables)
 * - subject: string - Subject line (for email)
 * - variables: array - Template variables
 * - priority: string - Message priority (high, normal, low)
 * - schedule: string - Send time (immediate or scheduled)
 * - retry: array - Retry configuration
 *
 * @package Alkaid\Workflow\Engine\Nodes\Types
 */
class NotifyNode extends AbstractNode
{
    /**
     * Notification channels | 通知渠道
     */
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_WEBHOOK = 'webhook';
    public const CHANNEL_WECHAT = 'wechat';
    public const CHANNEL_DINGTALK = 'dingtalk';
    public const CHANNEL_FEISHU = 'feishu';
    public const CHANNEL_INTERNAL = 'internal';

    /**
     * Priority levels | 优先级
     */
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW = 'low';

    /**
     * Notification status | 通知状态
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIAL = 'partial';

    /**
     * Constructor
     *
     * @param string|null $id Node ID
     * @param array $config Node configuration
     */
    public function __construct(?string $id = null, array $config = [])
    {
        $config['sub_type'] = $config['sub_type'] ?? NodeInterface::TASK_SEND;
        parent::__construct($id, $config);
    }

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
        return $this->config['sub_type'] ?? NodeInterface::TASK_SEND;
    }

    /**
     * {@inheritDoc}
     */
    public function setConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Execute the notify node | 执行通知节点
     *
     * @param ExecutionContext $context
     * @return NodeResult
     */
    protected function doExecute(ExecutionContext $context): NodeResult
    {
        try {
            // Build notification data
            $notification = $this->buildNotification($context);

            // Send notification
            $result = $this->sendNotification($notification, $context);

            // Store result in context
            $context->setVariable("notify_result_{$this->id}", $result);

            // Check if all recipients received the notification
            if ($result['status'] === self::STATUS_SENT) {
                return NodeResult::success([
                    'notification_id' => $result['notification_id'],
                    'channel' => $notification['channel'],
                    'recipients_count' => count($notification['recipients']),
                    'sent_count' => $result['sent_count'],
                    'status' => self::STATUS_SENT,
                    'message' => '通知发送成功',
                ], $this->getDefaultNextNodes());
            }

            if ($result['status'] === self::STATUS_PARTIAL) {
                return NodeResult::success([
                    'notification_id' => $result['notification_id'],
                    'channel' => $notification['channel'],
                    'recipients_count' => count($notification['recipients']),
                    'sent_count' => $result['sent_count'],
                    'failed_count' => $result['failed_count'],
                    'status' => self::STATUS_PARTIAL,
                    'message' => '部分通知发送成功',
                    'failures' => $result['failures'] ?? [],
                ], $this->getDefaultNextNodes());
            }

            return NodeResult::failed(
                "通知发送失败: {$result['error']}",
                500
            );
        } catch (\Throwable $e) {
            return NodeResult::failed(
                "通知发送异常: {$e->getMessage()}",
                $e->getCode() ?: 500
            );
        }
    }

    /**
     * Build notification data | 构建通知数据
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function buildNotification(ExecutionContext $context): array
    {
        $channel = $this->getConfigValue('channel', self::CHANNEL_EMAIL);
        $recipients = $this->resolveRecipients($context);
        $template = $this->resolveTemplate($context);
        $variables = $this->resolveVariables($context);

        return [
            'channel' => $channel,
            'recipients' => $recipients,
            'template' => $template,
            'subject' => $this->interpolateString(
                $this->getConfigValue('subject', ''),
                $context
            ),
            'content' => $this->renderTemplate($template, $variables),
            'variables' => $variables,
            'priority' => $this->getConfigValue('priority', self::PRIORITY_NORMAL),
            'metadata' => [
                'workflow_instance_id' => $context->getInstanceId(),
                'tenant_id' => $context->getTenantId(),
                'node_id' => $this->id,
                'node_name' => $this->name,
            ],
        ];
    }

    /**
     * Resolve recipients | 解析收件人
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveRecipients(ExecutionContext $context): array
    {
        $recipientsConfig = $this->getConfigValue('recipients', []);
        $resolved = [];

        foreach ($recipientsConfig as $recipient) {
            if (is_string($recipient)) {
                // Variable reference or direct value
                if (str_starts_with($recipient, '${')) {
                    $varName = substr($recipient, 2, -1);
                    $value = $context->getVariable($varName);
                    if (is_array($value)) {
                        $resolved = array_merge($resolved, $value);
                    } elseif ($value !== null) {
                        $resolved[] = $value;
                    }
                } else {
                    $resolved[] = $recipient;
                }
            } elseif (is_array($recipient)) {
                // Structured recipient (name, email/phone, etc.)
                $resolved[] = $this->resolveRecipientObject($recipient, $context);
            }
        }

        return array_unique($resolved, SORT_REGULAR);
    }

    /**
     * Resolve recipient object | 解析收件人对象
     *
     * @param array $recipient
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveRecipientObject(array $recipient, ExecutionContext $context): array
    {
        $resolved = [];

        foreach ($recipient as $key => $value) {
            if (is_string($value) && str_starts_with($value, '${')) {
                $varName = substr($value, 2, -1);
                $resolved[$key] = $context->getVariable($varName);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Resolve template | 解析模板
     *
     * @param ExecutionContext $context
     * @return string
     */
    protected function resolveTemplate(ExecutionContext $context): string
    {
        $template = $this->getConfigValue('template', '');

        // Check if it's a template ID or content
        if (str_starts_with($template, 'tpl_')) {
            // Load template from storage (simplified)
            return $this->loadTemplate($template);
        }

        // Treat as inline template content
        return $this->interpolateString($template, $context);
    }

    /**
     * Load template by ID | 根据 ID 加载模板
     *
     * @param string $templateId
     * @return string
     */
    protected function loadTemplate(string $templateId): string
    {
        // In real implementation, load from database or file system
        // For now, return a placeholder
        return "Template: {$templateId}";
    }

    /**
     * Resolve template variables | 解析模板变量
     *
     * @param ExecutionContext $context
     * @return array
     */
    protected function resolveVariables(ExecutionContext $context): array
    {
        $variablesConfig = $this->getConfigValue('variables', []);
        $resolved = [];

        foreach ($variablesConfig as $key => $value) {
            if (is_string($value) && str_starts_with($value, '${')) {
                $varName = substr($value, 2, -1);
                $resolved[$key] = $context->getVariable($varName);
            } else {
                $resolved[$key] = $value;
            }
        }

        // Add common workflow variables
        $resolved['_workflow_instance_id'] = $context->getInstanceId();
        $resolved['_node_id'] = $this->id;
        $resolved['_node_name'] = $this->name;
        $resolved['_timestamp'] = date('Y-m-d H:i:s');

        return $resolved;
    }

    /**
     * Render template with variables | 使用变量渲染模板
     *
     * @param string $template
     * @param array $variables
     * @return string
     */
    protected function renderTemplate(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            function ($matches) use ($variables) {
                $key = $matches[1];
                $value = $variables[$key] ?? '';
                return is_scalar($value) ? (string)$value : json_encode($value);
            },
            $template
        );
    }

    /**
     * Send notification | 发送通知
     *
     * @param array $notification
     * @param ExecutionContext $context
     * @return array
     */
    protected function sendNotification(array $notification, ExecutionContext $context): array
    {
        $channel = $notification['channel'];
        $notificationId = $this->generateNotificationId();

        return match ($channel) {
            self::CHANNEL_EMAIL => $this->sendEmailNotification($notificationId, $notification),
            self::CHANNEL_SMS => $this->sendSmsNotification($notificationId, $notification),
            self::CHANNEL_PUSH => $this->sendPushNotification($notificationId, $notification),
            self::CHANNEL_WEBHOOK => $this->sendWebhookNotification($notificationId, $notification),
            self::CHANNEL_WECHAT => $this->sendWechatNotification($notificationId, $notification),
            self::CHANNEL_DINGTALK => $this->sendDingtalkNotification($notificationId, $notification),
            self::CHANNEL_FEISHU => $this->sendFeishuNotification($notificationId, $notification),
            self::CHANNEL_INTERNAL => $this->sendInternalNotification($notificationId, $notification),
            default => [
                'notification_id' => $notificationId,
                'status' => self::STATUS_FAILED,
                'error' => "不支持的通知渠道: {$channel}",
            ],
        };
    }

    /**
     * Generate notification ID | 生成通知 ID
     *
     * @return string
     */
    protected function generateNotificationId(): string
    {
        return sprintf(
            'notify_%s_%s',
            date('YmdHis'),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Send email notification | 发送邮件通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendEmailNotification(string $notificationId, array $notification): array
    {
        // In real implementation, integrate with email service
        // This is a simplified mock implementation
        $recipients = $notification['recipients'];
        $sentCount = 0;
        $failedCount = 0;
        $failures = [];

        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Mock: would actually send email here
                $sentCount++;
            } else {
                $failedCount++;
                $failures[] = ['recipient' => $email, 'error' => '无效的邮箱地址'];
            }
        }

        return [
            'notification_id' => $notificationId,
            'status' => $failedCount === 0 ? self::STATUS_SENT :
                ($sentCount > 0 ? self::STATUS_PARTIAL : self::STATUS_FAILED),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'failures' => $failures,
        ];
    }

    /**
     * Send SMS notification | 发送短信通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendSmsNotification(string $notificationId, array $notification): array
    {
        // Mock implementation
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => count($notification['recipients']),
            'failed_count' => 0,
        ];
    }

    /**
     * Send push notification | 发送推送通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendPushNotification(string $notificationId, array $notification): array
    {
        // Mock implementation
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => count($notification['recipients']),
            'failed_count' => 0,
        ];
    }

    /**
     * Send webhook notification | 发送 Webhook 通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendWebhookNotification(string $notificationId, array $notification): array
    {
        $webhookUrl = $this->getConfigValue('webhook_url', '');
        if (empty($webhookUrl)) {
            return [
                'notification_id' => $notificationId,
                'status' => self::STATUS_FAILED,
                'error' => 'Webhook URL 未配置',
            ];
        }

        // Mock implementation - would use cURL in real implementation
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => 1,
            'failed_count' => 0,
        ];
    }

    /**
     * Send WeChat notification | 发送微信通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendWechatNotification(string $notificationId, array $notification): array
    {
        // Mock implementation for WeChat Work/Official Account
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => count($notification['recipients']),
            'failed_count' => 0,
        ];
    }

    /**
     * Send DingTalk notification | 发送钉钉通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendDingtalkNotification(string $notificationId, array $notification): array
    {
        // Mock implementation for DingTalk
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => count($notification['recipients']),
            'failed_count' => 0,
        ];
    }

    /**
     * Send Feishu notification | 发送飞书通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendFeishuNotification(string $notificationId, array $notification): array
    {
        // Mock implementation for Feishu/Lark
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => count($notification['recipients']),
            'failed_count' => 0,
        ];
    }

    /**
     * Send internal notification | 发送站内通知
     *
     * @param string $notificationId
     * @param array $notification
     * @return array
     */
    protected function sendInternalNotification(string $notificationId, array $notification): array
    {
        // Mock implementation for internal message system
        return [
            'notification_id' => $notificationId,
            'status' => self::STATUS_SENT,
            'sent_count' => count($notification['recipients']),
            'failed_count' => 0,
        ];
    }

    /**
     * Interpolate string with context variables | 使用上下文变量插值字符串
     *
     * @param string $template
     * @param ExecutionContext $context
     * @return string
     */
    protected function interpolateString(string $template, ExecutionContext $context): string
    {
        return preg_replace_callback(
            '/\$\{([^}]+)\}/',
            function ($matches) use ($context) {
                $varPath = $matches[1];
                $value = $context->getVariable($varPath);
                return is_scalar($value) ? (string)$value : json_encode($value);
            },
            $template
        );
    }

    /**
     * Get configuration schema | 获取配置 Schema
     *
     * @return array
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => [
                        self::CHANNEL_EMAIL,
                        self::CHANNEL_SMS,
                        self::CHANNEL_PUSH,
                        self::CHANNEL_WEBHOOK,
                        self::CHANNEL_WECHAT,
                        self::CHANNEL_DINGTALK,
                        self::CHANNEL_FEISHU,
                        self::CHANNEL_INTERNAL,
                    ],
                    'default' => self::CHANNEL_EMAIL,
                    'description' => '通知渠道',
                ],
                'recipients' => [
                    'type' => 'array',
                    'items' => [
                        'oneOf' => [
                            ['type' => 'string'],
                            [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'email' => ['type' => 'string', 'format' => 'email'],
                                    'phone' => ['type' => 'string'],
                                    'user_id' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'description' => '收件人列表',
                ],
                'template' => [
                    'type' => 'string',
                    'description' => '消息模板 ID 或内容',
                ],
                'subject' => [
                    'type' => 'string',
                    'maxLength' => 200,
                    'description' => '消息主题（用于邮件）',
                ],
                'variables' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => '模板变量',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => [self::PRIORITY_HIGH, self::PRIORITY_NORMAL, self::PRIORITY_LOW],
                    'default' => self::PRIORITY_NORMAL,
                    'description' => '消息优先级',
                ],
                'webhook_url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Webhook URL（用于 webhook 渠道）',
                ],
                'retry' => [
                    'type' => 'object',
                    'properties' => [
                        'max_attempts' => ['type' => 'integer', 'minimum' => 0, 'default' => 3],
                        'delay' => ['type' => 'integer', 'minimum' => 0, 'default' => 1000],
                    ],
                    'description' => '重试配置',
                ],
            ],
            'required' => ['channel', 'recipients'],
        ];
    }

    /**
     * Validate configuration | 验证配置
     *
     * @param array $config
     * @return bool
     */
    public function validateConfig(array $config = []): bool
    {
        $configToValidate = !empty($config) ? $config : $this->config;

        // Channel is required
        if (empty($configToValidate['channel'])) {
            return false;
        }

        // Validate channel
        $validChannels = [
            self::CHANNEL_EMAIL,
            self::CHANNEL_SMS,
            self::CHANNEL_PUSH,
            self::CHANNEL_WEBHOOK,
            self::CHANNEL_WECHAT,
            self::CHANNEL_DINGTALK,
            self::CHANNEL_FEISHU,
            self::CHANNEL_INTERNAL,
        ];
        if (!in_array($configToValidate['channel'], $validChannels, true)) {
            return false;
        }

        // Recipients are required
        if (empty($configToValidate['recipients'])) {
            return false;
        }

        // Webhook URL required for webhook channel
        if ($configToValidate['channel'] === self::CHANNEL_WEBHOOK) {
            if (empty($configToValidate['webhook_url'])) {
                return false;
            }
        }

        return parent::validateConfig($configToValidate);
    }
}
