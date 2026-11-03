<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\FormDesigner\Service;

use Infrastructure\Lowcode\FormDesigner\Repository\FormSchemaRepository;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Schema\SchemaVersionService;
use think\facade\Cache;
use think\facade\Event;

/**
 * Form Schema Manager Service | 表单Schema管理服务
 *
 * Manages Form Schema CRUD operations with caching.
 * 管理表单Schema的CRUD操作，包含缓存。
 *
 * Based on design: 09-lowcode-framework/43-lowcode-form-designer.md
 *
 * @package Infrastructure\Lowcode\FormDesigner\Service
 */
class FormSchemaManager
{
    protected string $cachePrefix = 'lowcode:form:';
    protected int $cacheTtl = 3600; // 1 hour | 1小时
    protected SchemaVersionService $schemaVersionService;

    /**
     * Constructor | 构造函数
     *
     * Uses constructor property promotion for cleaner dependency injection.
     * 使用构造器属性提升实现更简洁的依赖注入。
     *
     * @param FormSchemaRepository $repository Form schema repository | 表单schema仓储
     * @param CollectionManager|null $collectionManager Collection manager | Collection管理器
     */
    public function __construct(
        protected readonly FormSchemaRepository $repository,
        protected readonly ?CollectionManager $collectionManager = null
    ) {
        // 按需惰性构造，避免破坏现有 DI 配置
        $this->schemaVersionService = new SchemaVersionService();
    }

    /**
     * Create form schema | 创建表单Schema
     *
     * @param array $data Form schema data | 表单Schema数据
     * @return array Created form schema | 创建的表单Schema
     * @throws \Exception
     */
    public function create(array $data): array
    {
        // Validate required fields | 验证必需字段
        $this->validateFormData($data, true);

        // Validate schema structure | 验证Schema结构
        if (isset($data['schema'])) {
            $this->validateSchema($data['schema']);
        }

        // NOTE:
        //  - Form metadata is allowed to reference a collection_name that does not yet exist.
        //  - The actual runtime data access path (FormDataManager) will resolve the collection
        //    with tenant context and throw a clear error if it is still missing when data APIs
        //    are invoked.
        //  - Therefore we intentionally do not enforce a strict existence check here to keep
        //    creation flexible and to avoid unnecessary hard coupling to CollectionManager.

        // Save to database | 保存到数据库
        $formId = $this->repository->save($data);
        $data['id'] = $formId;

        // NOTE(缺陷 B 修复): 这里原先是 `$this->repository->findById($formId, $data['tenant_id'] ?? null)`。
        // `?? null` 把「payload 里没有 tenant_id」退化成了「不按租户过滤」——
        // 一旦上游少传/丢掉 tenant_id，读回来的就是任意租户的行。
        // 现在先显式解析租户（缺失或非法即抛异常），再带着它做读操作，绝不把「缺失」当成「不过滤」。
        $tenantId = $this->requireTenantId($data);

        // Cache the form | 缓存表单
        $this->cache($data);

        // Trigger event | 触发事件
        Event::trigger('lowcode.form.created', [
            'form' => $data,
        ]);

        // 记录 Schema 版本与哈希（不阻断主流程）
        $this->schemaVersionService->recordForm($data);

        return $this->repository->findById($formId, $tenantId);
    }

    /**
     * 显式解析并校验租户上下文 | Resolve tenant context, fail-closed
     *
     * 只接受「存在且为正整数」的 tenant_id：
     *   - 缺失（键不存在）→ 抛异常，而不是返回 null 让下游「不过滤」；
     *   - 0 / 负数 / 非数字 → 同样抛异常（lowcode_forms.tenant_id 有外键指向 tenants，
     *     0 是系统模板空间，本表不承载模板行）。
     *
     * @param array $data 表单数据 | Form payload
     * @return int 已校验的租户ID | Validated tenant ID
     * @throws \InvalidArgumentException
     */
    protected function requireTenantId(array $data): int
    {
        if (!array_key_exists('tenant_id', $data) || $data['tenant_id'] === null || $data['tenant_id'] === '') {
            throw new \InvalidArgumentException(
                'tenant_id is required for form schema read/write: 缺失租户上下文时不允许继续'
                . '（旧实现会退化成「不按租户过滤」的读，属于跨租户缺陷）'
            );
        }

        if (!is_numeric($data['tenant_id'])) {
            throw new \InvalidArgumentException('tenant_id must be numeric for form schema operations');
        }

        $tenantId = (int) $data['tenant_id'];
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException(
                "tenant_id must be a positive tenant ID for form schema operations, got {$tenantId}"
            );
        }

        return $tenantId;
    }

    /**
     * Get form schema by name | 按名称获取表单Schema
     *
     * @param string $name Form name | 表单名称
     * @param int $tenantId Tenant ID | 租户ID
     * @param int|null $siteId Site ID | 站点ID
     * @return array|null
     */
    public function get(string $name, int $tenantId, ?int $siteId = null): ?array
    {
        // Try cache first | 优先从缓存获取
        $cacheKey = $this->getCacheKey($name, $tenantId, $siteId);
        $cached = Cache::get($cacheKey);

        if ($cached !== null && $cached !== false) {
            return $cached;
        }

        // Load from database | 从数据库加载
        $form = $this->repository->findByName($name, $tenantId, $siteId);

        if (!$form) {
            return null;
        }

        // Cache the form | 缓存表单
        $this->cache($form);

        return $form;
    }

    /**
     * Get form schema by ID | 按ID获取表单Schema
     *
     * NOTE(租户必填): `$tenantId` 不再可空 —— 原先 null 意味着「不按租户过滤」。
     *
     * @param int $id Form ID | 表单ID
     * @param int $tenantId Tenant ID | 租户ID
     * @return array|null
     */
    public function getById(int $id, int $tenantId): ?array
    {
        return $this->repository->findById($id, $tenantId);
    }

    /**
     * Get forms by collection | 按Collection获取表单
     *
     * @param string $collectionName Collection name | Collection名称
     * @param int $tenantId Tenant ID | 租户ID
     * @return array
     */
    public function getByCollection(string $collectionName, int $tenantId): array
    {
        return $this->repository->findByCollectionName($collectionName, $tenantId);
    }

    /**
     * Update form schema | 更新表单Schema
     *
     * @param int $id Form ID | 表单ID
     * @param array $data Update data | 更新数据
     * @param int $tenantId Tenant ID | 租户ID
     * @return array Updated form schema | 更新后的表单Schema
     * @throws \Exception
     */
    public function update(int $id, array $data, int $tenantId): array
    {
        // Get existing form | 获取已存在的表单
        $existingForm = $this->repository->findById($id, $tenantId);

        if (!$existingForm) {
            throw new \InvalidArgumentException("Form not found: ID {$id}");
        }

        // Validate update data | 验证更新数据
        $this->validateFormData($data, false);

        // Validate schema if provided | 如果提供了schema则验证
        if (isset($data['schema'])) {
            $this->validateSchema($data['schema']);
        }

        // Merge with existing data | 与已存在数据合并
        $data['id'] = $id;
        $data['tenant_id'] = $tenantId;

        // Save to database | 保存到数据库
        $this->repository->save($data);

        // Clear cache | 清除缓存
        $this->clearCache($existingForm['name'], $existingForm['tenant_id'], $existingForm['site_id'] ?? null);

        // Trigger event | 触发事件
        Event::trigger('lowcode.form.updated', [
            'form_id' => $id,
            'updated_data' => $data,
        ]);

        // 记录 Schema 版本与哈希（不阻断主流程）
        $this->schemaVersionService->recordForm(array_merge($existingForm, $data));

        return $this->repository->findById($id, $tenantId);
    }

    /**
     * Delete form schema | 删除表单Schema
     *
     * @param int $id Form ID | 表单ID
     * @param int $tenantId Tenant ID | 租户ID
     * @return void
     * @throws \Exception
     */
    public function delete(int $id, int $tenantId): void
    {
        // Get existing form | 获取已存在的表单
        $existingForm = $this->repository->findById($id, $tenantId);

        if (!$existingForm) {
            throw new \InvalidArgumentException("Form not found: ID {$id}");
        }

        // Delete from database | 从数据库删除
        $this->repository->delete($id, $tenantId);

        // Clear cache | 清除缓存
        $this->clearCache($existingForm['name'], $existingForm['tenant_id'], $existingForm['site_id'] ?? null);

        // Trigger event | 触发事件
        Event::trigger('lowcode.form.deleted', [
            'form_id' => $id,
            'form_name' => $existingForm['name'],
        ]);
    }

    /**
     * List forms | 列出表单
     *
     * @param int $tenantId Tenant ID | 租户ID
     * @param array $filters Filters | 筛选条件
     * @param int $page Page number | 页码
     * @param int $pageSize Page size | 每页数量
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->repository->list($tenantId, $filters, $page, $pageSize);
    }

    /**
     * Duplicate form | 复制表单
     *
     * @param int $id Source form ID | 源表单ID
     * @param string $newName New form name | 新表单名称
     * @param int $tenantId Tenant ID | 租户ID
     * @return array Duplicated form | 复制的表单
     * @throws \Exception
     */
    public function duplicate(int $id, string $newName, int $tenantId): array
    {
        // Get source form | 获取源表单
        $sourceForm = $this->repository->findById($id, $tenantId);

        if (!$sourceForm) {
            throw new \InvalidArgumentException("Form not found: ID {$id}");
        }

        // Create duplicate | 创建副本
        $duplicateData = $sourceForm;
        unset($duplicateData['id']);
        unset($duplicateData['created_at']);
        unset($duplicateData['updated_at']);
        $duplicateData['name'] = $newName;
        $duplicateData['title'] = $sourceForm['title'] . ' (副本)';

        return $this->create($duplicateData);
    }

    /**
     * Validate form data | 验证表单数据
     *
     * @param array $data Form data | 表单数据
     * @param bool $isCreate Is create operation | 是否是创建操作
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateFormData(array $data, bool $isCreate): void
    {
        if ($isCreate) {
            if (empty($data['tenant_id'])) {
                throw new \InvalidArgumentException('tenant_id is required');
            }
            if (empty($data['name'])) {
                throw new \InvalidArgumentException('name is required');
            }
            if (empty($data['title'])) {
                throw new \InvalidArgumentException('title is required');
            }
            if (empty($data['schema'])) {
                throw new \InvalidArgumentException('schema is required');
            }
        }

        // Validate name format | 验证name格式
        if (isset($data['name']) && !preg_match('/^[a-zA-Z0-9_]+$/', $data['name'])) {
            throw new \InvalidArgumentException('name must be alphanumeric with underscores');
        }

        // Validate layout | 验证layout
        if (isset($data['layout']) && !in_array($data['layout'], ['horizontal', 'vertical', 'inline'])) {
            throw new \InvalidArgumentException('layout must be one of: horizontal, vertical, inline');
        }
    }

    /**
     * Validate JSON Schema | 验证JSON Schema
     *
     * @param array $schema JSON Schema | JSON Schema
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateSchema(array $schema): void
    {
        // Basic schema structure validation | 基本Schema结构验证
        if (!isset($schema['type']) || $schema['type'] !== 'object') {
            throw new \InvalidArgumentException('Schema type must be object');
        }

        if (!isset($schema['properties']) || !is_array($schema['properties'])) {
            throw new \InvalidArgumentException('Schema must have properties');
        }

        // Validate each field | 验证每个字段
        foreach ($schema['properties'] as $fieldName => $fieldSchema) {
            if (!isset($fieldSchema['type'])) {
                throw new \InvalidArgumentException("Field {$fieldName} must have a type");
            }
            if (!isset($fieldSchema['x-component'])) {
                throw new \InvalidArgumentException("Field {$fieldName} must have x-component");
            }
        }
    }

    /**
     * Cache form schema | 缓存表单Schema
     *
     * @param array $form Form schema | 表单Schema
     * @return void
     */
    protected function cache(array $form): void
    {
        $cacheKey = $this->getCacheKey(
            $form['name'],
            $form['tenant_id'],
            $form['site_id'] ?? null
        );
        Cache::set($cacheKey, $form, $this->cacheTtl);
    }

    /**
     * Clear cache | 清除缓存
     *
     * @param string $name Form name | 表单名称
     * @param int $tenantId Tenant ID | 租户ID
     * @param int|null $siteId Site ID | 站点ID
     * @return void
     */
    public function clearCache(string $name, int $tenantId, ?int $siteId = null): void
    {
        $cacheKey = $this->getCacheKey($name, $tenantId, $siteId);
        Cache::delete($cacheKey);
        // 清理 SchemaParser 派生缓存，保持解析结果一致
        Cache::delete('lowcode:schema_parser:' . "{$name}:t{$tenantId}:s" . ($siteId ?? 0));
    }

    /**
     * Get cache key | 获取缓存key
     *
     * @param string $name Form name | 表单名称
     * @param int $tenantId Tenant ID | 租户ID
     * @param int|null $siteId Site ID | 站点ID
     * @return string
     */
    protected function getCacheKey(string $name, int $tenantId, ?int $siteId = null): string
    {
        $key = $this->cachePrefix . "t{$tenantId}:";
        if ($siteId !== null) {
            $key .= "s{$siteId}:";
        }
        $key .= $name;
        return $key;
    }
}
