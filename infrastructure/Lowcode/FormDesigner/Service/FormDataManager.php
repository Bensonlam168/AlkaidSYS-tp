<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\FormDesigner\Service;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use think\facade\Db;

/**
 * Form Data Manager | 表单数据管理器
 *
 * Manages form data persistence in dynamic collection tables.
 * 管理动态集合表中的表单数据持久化。
 *
 * @package Infrastructure\Lowcode\FormDesigner\Service
 */
class FormDataManager
{
    protected FormSchemaManager $schemaManager;
    protected FormValidatorManager $validatorManager;
    protected CollectionManager $collectionManager;

    /**
     * Constructor | 构造函数
     *
     * @param FormSchemaManager $schemaManager Form schema manager | 表单Schema管理器
     * @param FormValidatorManager $validatorManager Form validator manager | 表单验证器管理器
     * @param CollectionManager $collectionManager Collection manager | Collection管理器
     */
    public function __construct(
        FormSchemaManager $schemaManager,
        FormValidatorManager $validatorManager,
        CollectionManager $collectionManager
    ) {
        $this->schemaManager = $schemaManager;
        $this->validatorManager = $validatorManager;
        $this->collectionManager = $collectionManager;
    }

    /**
     * Save form data | 保存表单数据
     *
     * @param string $formName Form name | 表单名称
     * @param array  $data     Form data | 表单数据
     * @param int    $tenantId Tenant ID | 租户ID
     * @param int    $siteId   Site ID | 站点ID
     *
     * @return int|string Saved ID | 保存的ID
     *
     * @throws \Exception
     */
    public function save(string $formName, array $data, int $tenantId, int $siteId = 0)
    {
        // 1. Get form schema | 获取表单Schema
        $form = $this->schemaManager->get($formName, $tenantId, $siteId);
        if (!$form) {
            throw new \InvalidArgumentException("Form not found: {$formName}");
        }

        // 2. Validate data | 验证数据
        $validationResult = $this->validatorManager->validate($data, $form['schema']);
        if ($validationResult !== true) {
            throw new \InvalidArgumentException('Validation failed: ' . $validationResult);
        }

        // 3. Get collection and table name | 获取Collection和表名
        if (empty($form['collection_name'])) {
            throw new \RuntimeException('Form is not bound to a collection');
        }

        // NOTE(P0: lowcode-collections-tenant): pass tenantId to collection manager for future
        // per-tenant collection resolution. Under current P0 phase, collections are still global.
        $collection = $this->collectionManager->get($form['collection_name'], $tenantId);
        if (!$collection) {
            throw new \RuntimeException("Collection not found: {$form['collection_name']}");
        }

        $tableName = $collection->getTableName();

        // 4. Save to database | 保存到数据库
        // Filter data to only include fields in the collection (plus id)
        // 过滤数据，只包含Collection中的字段（加上id）
        $fields = $collection->getFields();
        $saveData = [];
        foreach ($fields as $field) {
            $fieldName = $field->getName();
            if (array_key_exists($fieldName, $data)) {
                $saveData[$fieldName] = $data[$fieldName];
            }
        }

        // 强制注入多租户字段，防止客户端伪造或越权访问
        $saveData['tenant_id'] = $tenantId;
        $saveData['site_id'] = $siteId;

        if (isset($data['id']) && !empty($data['id'])) {
            // Update | 更新
            $saveData['updated_at'] = date('Y-m-d H:i:s');
            Db::table($tableName)
                ->where('id', $data['id'])
                ->where('tenant_id', $tenantId)
                ->where('site_id', $siteId)
                ->update($saveData);

            return $data['id'];
        } else {
            // Insert | 插入
            $saveData['created_at'] = date('Y-m-d H:i:s');
            $saveData['updated_at'] = date('Y-m-d H:i:s');

            return Db::table($tableName)->insertGetId($saveData);
        }
    }

    /**
     * Get form data by ID | 按ID获取表单数据
     *
     * @param string $formName Form name | 表单名称
     * @param int $id Data ID | 数据ID
     * @param int $tenantId Tenant ID | 租户ID
     * @param int $siteId Site ID | 站点ID
     * @return array|null
     */
    public function get(string $formName, int $id, int $tenantId, int $siteId = 0): ?array
    {
        $tableName = $this->getTableName($formName, $tenantId, $siteId);
        return Db::table($tableName)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->find($id);
    }

    /**
     * Delete form data | 删除表单数据
     *
     * @param string $formName Form name | 表单名称
     * @param int $id Data ID | 数据ID
     * @param int $tenantId Tenant ID | 租户ID
     * @param int $siteId Site ID | 站点ID
     * @return bool
     */
    public function delete(string $formName, int $id, int $tenantId, int $siteId = 0): bool
    {
        $tableName = $this->getTableName($formName, $tenantId, $siteId);
        $deleted = Db::table($tableName)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->delete();

        return (bool) $deleted;
    }

    /**
     * List form data | 列出表单数据
     *
     * @param string $formName Form name | 表单名称
     * @param array $filters  Filters | 筛选条件
     * @param int   $page     Page number | 页码
     * @param int   $pageSize Page size | 每页数量
     * @param int   $tenantId Tenant ID | 租户ID
     * @param int   $siteId   Site ID | 站点ID
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(string $formName, array $filters = [], int $page = 1, int $pageSize = 20, int $tenantId, int $siteId = 0): array
    {
        $tableName = $this->getTableName($formName, $tenantId, $siteId);

        $query = Db::table($tableName)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId);

        // Apply filters | 应用筛选
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $query->where($key, $value);
            }
        }

        $total = $query->count();
        $list = $query->page($page, $pageSize)->select()->toArray();

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Get table name for form | 获取表单对应的表名
     *
     * @param string $formName Form name | 表单名称
     * @param int $tenantId Tenant ID | 租户ID
     * @param int $siteId Site ID | 站点ID
     * @return string
     * @throws \RuntimeException
     */
    protected function getTableName(string $formName, int $tenantId, int $siteId): string
    {
        $form = $this->schemaManager->get($formName, $tenantId, $siteId);
        if (!$form) {
            throw new \InvalidArgumentException("Form not found: {$formName}");
        }

        if (empty($form['collection_name'])) {
            throw new \RuntimeException('Form is not bound to a collection');
        }

        // NOTE(P0: lowcode-collections-tenant): pass tenantId to collection manager here as well.
        $collection = $this->collectionManager->get($form['collection_name'], $tenantId);
        if (!$collection) {
            throw new \RuntimeException("Collection not found: {$form['collection_name']}");
        }

        return $collection->getTableName();
    }
}
