<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Schema;

use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use think\facade\Cache;

/**
 * Schema 解析器插件
 *
 * 将表单/集合 Schema 解析为组件、验证、权限映射，供加载器或前端使用。
 * 支持按租户/站点隔离缓存，避免重复解析。
 */
class SchemaParser
{
    protected string $cachePrefix = 'lowcode:schema_parser:';
    protected int $cacheTtl = 3600; // 1小时

    public function __construct(protected FormValidatorManager $validatorManager)
    {
    }

    /**
     * 解析 Schema，返回组件定义、验证规则、权限映射。
     *
     * @param array $schema 低代码表单/集合 Schema
     * @param array $context 上下文（tenant_id/site_id 等）
     * @param string|null $cacheKey 缓存键（建议传入 form_name/collection_name）
     */
    public function parse(array $schema, array $context = [], ?string $cacheKey = null): array
    {
        if ($cacheKey !== null) {
            $fullCacheKey = $this->cachePrefix . $this->buildCacheKey($cacheKey, $context);
            $cached = Cache::get($fullCacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $components = $this->buildComponents($schema);
        $validator = $this->validatorManager->getDefinition($schema, $context, $cacheKey);
        $permissions = $this->extractPermissions($schema);

        $parsed = [
            'components' => $components,
            'validator' => $validator,
            'permissions' => $permissions,
        ];

        if (isset($fullCacheKey)) {
            Cache::set($fullCacheKey, $parsed, $this->cacheTtl);
        }

        return $parsed;
    }

    /**
     * 构建组件映射：提取 x-component/x-decorator 等 UI 元数据。
     */
    protected function buildComponents(array $schema): array
    {
        $components = [];
        $required = $schema['required'] ?? [];

        foreach ($schema['properties'] ?? [] as $field => $config) {
            $components[] = [
                'field' => $field,
                'title' => $config['title'] ?? $field,
                'component' => $config['x-component'] ?? 'Input',
                'decorator' => $config['x-decorator'] ?? 'FormItem',
                'componentProps' => $config['x-component-props'] ?? [],
                'decoratorProps' => $config['x-decorator-props'] ?? [],
                'required' => in_array($field, $required, true),
                'type' => $config['type'] ?? 'string',
                'enum' => $config['enum'] ?? [],
            ];
        }

        return $components;
    }

    /**
     * 提取权限映射：支持 Schema 顶层或字段级 x-permissions。
     */
    protected function extractPermissions(array $schema): array
    {
        $perms = [];

        if (isset($schema['x-permissions']) && is_array($schema['x-permissions'])) {
            $perms['schema'] = $schema['x-permissions'];
        }

        foreach ($schema['properties'] ?? [] as $field => $config) {
            if (isset($config['x-permissions']) && is_array($config['x-permissions'])) {
                $perms['fields'][$field] = $config['x-permissions'];
            }
        }

        return $perms;
    }

    /**
     * 构建租户/站点隔离的缓存键。
     */
    protected function buildCacheKey(string $cacheKey, array $context = []): string
    {
        $tenant = $context['tenant_id'] ?? 0;
        $site = $context['site_id'] ?? 0;
        return "{$cacheKey}:t{$tenant}:s{$site}";
    }
}
