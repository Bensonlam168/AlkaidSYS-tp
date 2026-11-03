<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\FormDesigner\Service;

use think\Validate;
use think\facade\Cache;

/**
 * Form Validator Manager | 表单验证器管理器
 *
 * Manages form validators with caching.
 * 管理表单验证器并缓存。
 *
 * @package Infrastructure\Lowcode\FormDesigner\Service
 */
class FormValidatorManager
{
    protected FormValidatorGenerator $generator;

    protected string $cachePrefix = 'lowcode:validator:';
    protected int $cacheTtl = 3600; // 1 hour | 1小时

    /**
     * Constructor | 构造函数
     *
     * @param FormValidatorGenerator $generator Validator generator | 验证器生成器
     */
    public function __construct(FormValidatorGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Get validator for form schema | 获取表单Schema的验证器
     *
     * @param array $schema JSON Schema | JSON Schema
     * @param string|null $cacheKey Cache key | 缓存key
     * @return Validate ThinkPHP Validate instance | ThinkPHP验证器实例
     */
    public function getValidator(array $schema, ?string $cacheKey = null, array $context = []): Validate
    {
        $cachePayload = null;
        // Try cache if key provided | 如果提供了key则尝试缓存
        if ($cacheKey !== null) {
            $fullCacheKey = $this->cachePrefix . $this->buildCacheKey($cacheKey, $context);
            $cachePayload = Cache::get($fullCacheKey);

            if (is_array($cachePayload) && isset($cachePayload['rules'], $cachePayload['messages'])) {
                return $this->generator->buildFromDefinition($cachePayload['rules'], $cachePayload['messages'], $context);
            }
        }

        // Generate validator | 生成验证器
        $validator = $this->generator->generate($schema, $context);

        // Cache rule/message snapshot if key provided | 如果提供了key则缓存 rule/message 快照
        if ($cacheKey !== null) {
            $fullCacheKey = $this->cachePrefix . $this->buildCacheKey($cacheKey, $context);
            Cache::set($fullCacheKey, $this->extractDefinition($validator), $this->cacheTtl);
        }

        return $validator;
    }

    /**
     * Export validator definition (rules/messages) for frontend alignment.
     * 导出验证器定义（规则/消息），用于前后端一致校验。
     */
    public function getDefinition(array $schema, array $context = [], ?string $cacheKey = null): array
    {
        $validator = $this->getValidator($schema, $cacheKey, $context);

        return $this->extractDefinition($validator);
    }

    /**
     * Clear validator cache | 清除验证器缓存
     *
     * @param string $cacheKey Cache key | 缓存key
     * @return void
     */
    public function clearCache(string $cacheKey): void
    {
        $fullCacheKey = $this->cachePrefix . $cacheKey;
        Cache::delete($fullCacheKey);
    }

    /**
     * Validate data against schema | 根据Schema验证数据
     *
     * @param array $data Data to validate | 要验证的数据
     * @param array $schema JSON Schema | JSON Schema
     * @param string|null $cacheKey Cache key | 缓存key
     * @return bool|string True if valid, error message if invalid | 验证成功返回true，失败返回错误消息
     */
    public function validate(array $data, array $schema, ?string $cacheKey = null, array $context = []): bool|string
    {
        $validator = $this->getValidator($schema, $cacheKey, $context);

        if (!$validator->check($data)) {
            return $validator->getError();
        }

        return true;
    }

    /**
     * Get validation rules from schema | 从Schema获取验证规则
     *
     * @param array $schema JSON Schema | JSON Schema
     * @return array Validation rules | 验证规则
     */
    public function getRules(array $schema): array
    {
        $validator = $this->generator->generate($schema);

        // Use reflection to access protected property | 使用反射访问受保护属性
        $reflection = new \ReflectionClass($validator);
        $property = $reflection->getProperty('rule');
        $property->setAccessible(true);

        return $property->getValue($validator);
    }

    /**
     * Get validation messages from schema | 从Schema获取验证消息
     *
     * @param array $schema JSON Schema | JSON Schema
     * @return array Validation messages | 验证消息
     */
    public function getMessages(array $schema): array
    {
        $validator = $this->generator->generate($schema);

        // Use reflection to access protected property | 使用反射访问受保护属性
        $reflection = new \ReflectionClass($validator);
        $property = $reflection->getProperty('message');
        $property->setAccessible(true);

        return $property->getValue($validator);
    }

    /**
     * Build tenant/site-aware cache key | 构建租户/站点隔离的缓存键
     */
    protected function buildCacheKey(string $cacheKey, array $context = []): string
    {
        $tenant = $context['tenant_id'] ?? 0;
        $site = $context['site_id'] ?? 0;
        return "{$cacheKey}:t{$tenant}:s{$site}";
    }

    /**
     * 从验证器提取 rule/message 以用于缓存（避免序列化闭包）
     */
    protected function extractDefinition(Validate $validator): array
    {
        $ref = new \ReflectionClass($validator);

        $ruleProp = $ref->getProperty('rule');
        $ruleProp->setAccessible(true);
        $rules = $ruleProp->getValue($validator);

        $msgProp = $ref->getProperty('message');
        $msgProp->setAccessible(true);
        $messages = $msgProp->getValue($validator);

        return [
            'rules' => $rules,
            'messages' => $messages,
        ];
    }
}
