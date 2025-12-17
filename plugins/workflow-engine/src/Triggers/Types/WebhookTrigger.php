<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Triggers\Types;

use Alkaid\Workflow\Engine\Triggers\Interfaces\TriggerInterface;
use Alkaid\Workflow\Engine\Triggers\TriggerContext;
use Alkaid\Workflow\Engine\Triggers\TriggerResult;

/**
 * Webhook 触发器
 *
 * 通过 HTTP Webhook 调用触发工作流执行。
 * 支持身份验证、签名验证、内容类型检查等安全特性。
 *
 * @package Alkaid\Workflow\Engine\Triggers\Types
 */
class WebhookTrigger extends AbstractTrigger
{
    /**
     * Webhook URL 前缀
     */
    protected string $webhookUrlPrefix = '/api/workflow/webhook';

    /**
     * 签名算法
     */
    public const SIGNATURE_ALGO_HMAC_SHA256 = 'hmac-sha256';
    public const SIGNATURE_ALGO_HMAC_SHA1 = 'hmac-sha1';

    /**
     * 获取触发器类型
     */
    public function getType(): string
    {
        return TriggerInterface::TYPE_WEBHOOK;
    }

    /**
     * 获取触发器名称
     */
    public function getName(): string
    {
        return 'Webhook 触发器';
    }

    /**
     * 获取触发器描述
     */
    public function getDescription(): string
    {
        return '通过 HTTP Webhook 调用触发工作流执行，适用于与外部系统集成';
    }

    /**
     * 获取配置 Schema
     */
    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'title' => '触发器名称',
                    'description' => 'Webhook 的显示名称',
                    'maxLength' => 100,
                ],
                'secret' => [
                    'type' => 'string',
                    'title' => '密钥',
                    'description' => '用于签名验证的密钥',
                    'minLength' => 16,
                    'maxLength' => 128,
                ],
                'require_signature' => [
                    'type' => 'boolean',
                    'title' => '要求签名验证',
                    'description' => '是否要求请求携带有效签名',
                    'default' => false,
                ],
                'signature_algorithm' => [
                    'type' => 'string',
                    'title' => '签名算法',
                    'description' => '签名验证使用的算法',
                    'enum' => [self::SIGNATURE_ALGO_HMAC_SHA256, self::SIGNATURE_ALGO_HMAC_SHA1],
                    'default' => self::SIGNATURE_ALGO_HMAC_SHA256,
                ],
                'signature_header' => [
                    'type' => 'string',
                    'title' => '签名头',
                    'description' => '包含签名的 HTTP 头名称',
                    'default' => 'X-Webhook-Signature',
                ],
                'allowed_methods' => [
                    'type' => 'array',
                    'title' => '允许的 HTTP 方法',
                    'description' => '允许的 HTTP 请求方法',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['GET', 'POST', 'PUT', 'PATCH'],
                    ],
                    'default' => ['POST'],
                ],
                'allowed_content_types' => [
                    'type' => 'array',
                    'title' => '允许的内容类型',
                    'description' => '允许的 Content-Type',
                    'items' => ['type' => 'string'],
                    'default' => ['application/json'],
                ],
                'allowed_ips' => [
                    'type' => 'array',
                    'title' => '允许的 IP 地址',
                    'description' => 'IP 白名单（空表示不限制）',
                    'items' => ['type' => 'string'],
                ],
                'rate_limit' => [
                    'type' => 'integer',
                    'title' => '速率限制',
                    'description' => '每分钟最大请求次数（0 表示不限制）',
                    'minimum' => 0,
                    'default' => 60,
                ],
                'timeout' => [
                    'type' => 'integer',
                    'title' => '超时时间',
                    'description' => '请求处理超时时间（秒）',
                    'minimum' => 1,
                    'maximum' => 300,
                    'default' => 30,
                ],
                'retry_count' => [
                    'type' => 'integer',
                    'title' => '重试次数',
                    'description' => '失败后重试次数',
                    'minimum' => 0,
                    'maximum' => 10,
                    'default' => 3,
                ],
                'data_mapping' => [
                    'type' => 'object',
                    'title' => '数据映射',
                    'description' => '将 Webhook 数据映射到工作流变量',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * 注册时的钩子方法
     *
     * @param string $workflowId 工作流ID
     * @param array $config 配置
     * @return void
     */
    protected function onRegister(string $workflowId, array $config): void
    {
        // 生成 Webhook URL
        $webhookUrl = $this->generateWebhookUrl($workflowId);

        // 存储 Webhook URL
        $this->registrations[$workflowId]['webhook_url'] = $webhookUrl;

        // 只有在显式配置 require_signature 为 true 且未设置密钥时，才自动生成
        if (!empty($config['require_signature']) && empty($config['secret'])) {
            $this->registrations[$workflowId]['secret'] = $this->generateSecret();
        }
    }

    /**
     * 生成 Webhook URL
     *
     * @param string $workflowId 工作流ID
     * @return string
     */
    public function generateWebhookUrl(string $workflowId): string
    {
        $token = $this->generateToken($workflowId);
        return sprintf('%s/%s', $this->webhookUrlPrefix, $token);
    }

    /**
     * 生成 Webhook Token
     *
     * @param string $workflowId 工作流ID
     * @return string
     */
    protected function generateToken(string $workflowId): string
    {
        return hash('sha256', $workflowId . microtime(true) . random_bytes(16));
    }

    /**
     * 生成密钥
     *
     * @return string
     */
    protected function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 检查触发条件
     *
     * @param TriggerContext $context 触发上下文
     * @return bool
     */
    protected function checkTriggerConditions(TriggerContext $context): bool
    {
        if (!$context->isWebhook()) {
            return false;
        }

        $workflowId = $context->getWorkflowId();
        if (!$workflowId || !$this->isRegistered($workflowId)) {
            return false;
        }

        $config = $this->getWorkflowConfig($workflowId);

        // 验证 IP 白名单
        if (!$this->validateIp($context->getIpAddress(), $config['allowed_ips'] ?? [])) {
            return false;
        }

        // 验证签名
        if (!empty($config['secret'])) {
            $signatureHeader = $config['signature_header'] ?? 'X-Webhook-Signature';
            $signature = $context->getHeader($signatureHeader);
            $algorithm = $config['signature_algorithm'] ?? self::SIGNATURE_ALGO_HMAC_SHA256;

            if (!$this->validateSignature($context, $config['secret'], $signature, $algorithm)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 验证 IP 地址
     *
     * @param string|null $ip IP 地址
     * @param array $allowedIps 允许的 IP 列表
     * @return bool
     */
    protected function validateIp(?string $ip, array $allowedIps): bool
    {
        // 空白名单表示不限制
        if (empty($allowedIps)) {
            return true;
        }

        if (empty($ip)) {
            return false;
        }

        foreach ($allowedIps as $allowedIp) {
            // 支持 CIDR 格式
            if (str_contains($allowedIp, '/')) {
                if ($this->ipInCidr($ip, $allowedIp)) {
                    return true;
                }
            } elseif ($ip === $allowedIp) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查 IP 是否在 CIDR 范围内
     *
     * @param string $ip IP 地址
     * @param string $cidr CIDR 表示法
     * @return bool
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);

        return ($ip & $mask) === ($subnet & $mask);
    }

    /**
     * 验证签名
     *
     * @param TriggerContext $context 触发上下文
     * @param string $secret 密钥
     * @param string|null $signature 签名
     * @param string $algorithm 算法
     * @return bool
     */
    protected function validateSignature(
        TriggerContext $context,
        string $secret,
        ?string $signature,
        string $algorithm
    ): bool {
        if (empty($signature)) {
            return false;
        }

        // 使用请求体数据计算签名
        $payload = json_encode($context->getData());

        $expectedSignature = match ($algorithm) {
            self::SIGNATURE_ALGO_HMAC_SHA1 => hash_hmac('sha1', $payload, $secret),
            self::SIGNATURE_ALGO_HMAC_SHA256 => hash_hmac('sha256', $payload, $secret),
            default => hash_hmac('sha256', $payload, $secret),
        };

        // 支持带前缀的签名格式（如 "sha256=xxx"）
        $providedSignature = $signature;
        if (str_contains($signature, '=')) {
            [, $providedSignature] = explode('=', $signature, 2);
        }

        return hash_equals($expectedSignature, $providedSignature);
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
        // 应用数据映射
        $mappedData = $this->applyDataMapping($context->getData(), $config['data_mapping'] ?? []);

        // 构建触发数据
        $triggerData = [
            'trigger_type' => $this->getType(),
            'trigger_name' => $config['name'] ?? $this->getName(),
            'webhook_url' => $config['webhook_url'] ?? null,
            'triggered_at' => date('Y-m-d H:i:s'),
            'request_data' => $context->getData(),
            'mapped_data' => $mappedData,
            'headers' => $context->getHeaders(),
            'query' => $context->getQuery(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
        ];

        return TriggerResult::success($triggerData)
            ->setMeta('webhook_url', $config['webhook_url'] ?? null)
            ->setMeta('data_mapping_applied', !empty($config['data_mapping']));
    }

    /**
     * 应用数据映射
     *
     * @param array $data 原始数据
     * @param array $mapping 映射规则
     * @return array 映射后的数据
     */
    protected function applyDataMapping(array $data, array $mapping): array
    {
        if (empty($mapping)) {
            return $data;
        }

        $result = [];
        foreach ($mapping as $targetKey => $sourcePath) {
            $result[$targetKey] = $this->getNestedValue($data, $sourcePath);
        }

        return $result;
    }

    /**
     * 获取嵌套值
     *
     * @param array $data 数据
     * @param string $path 路径（点分隔）
     * @return mixed
     */
    protected function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * 获取 Webhook URL
     *
     * @param string $workflowId 工作流ID
     * @return string|null
     */
    public function getWebhookUrl(string $workflowId): ?string
    {
        return $this->registrations[$workflowId]['webhook_url'] ?? null;
    }

    /**
     * 设置 Webhook URL 前缀
     *
     * @param string $prefix 前缀
     * @return static
     */
    public function setWebhookUrlPrefix(string $prefix): static
    {
        $this->webhookUrlPrefix = $prefix;
        return $this;
    }
}
