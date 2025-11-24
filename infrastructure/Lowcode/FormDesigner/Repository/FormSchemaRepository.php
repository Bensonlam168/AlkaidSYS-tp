<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\FormDesigner\Repository;

use think\facade\Db;

/**
 * Form Schema Repository | 表单Schema仓储
 * 
 * Handles persistence of Form Schema entities.
 * 处理表单Schema实体的持久化。
 * 
 * Based on design: 09-lowcode-framework/43-lowcode-form-designer.md
 * 
 * @package Infrastructure\Lowcode\FormDesigner\Repository
 */
class FormSchemaRepository
{
    protected string $table = 'lowcode_forms';

    /**
     * Save form schema | 保存表单Schema
     * 
     * @param array $data Form schema data | 表单Schema数据
     * @return int Form ID | 表单ID
     */
    public function save(array $data): int
    {
        // Ensure schema is JSON encoded | 确保schema是JSON编码
        if (isset($data['schema']) && is_array($data['schema'])) {
            $data['schema'] = json_encode($data['schema']);
        }

        if (isset($data['id']) && $data['id']) {
            // Update existing | 更新已存在的
            Db::name($this->table)
                ->where('id', $data['id'])
                ->update($data);
            return (int)$data['id'];
        } else {
            // Insert new | 插入新的
            unset($data['id']);
            return (int)Db::name($this->table)->insertGetId($data);
        }
    }

    /**
     * Find by ID | 按ID查找
     * 
     * @param int $id Form ID | 表单ID
     * @param int|null $tenantId Tenant ID | 租户ID
     * @return array|null
     */
    public function findById(int $id, ?int $tenantId = null): ?array
    {
        $query = Db::name($this->table)->where('id', $id);
        
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        
        $data = $query->find();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Find by name | 按name查找
     * 
     * @param string $name Form name | 表单名称
     * @param int $tenantId Tenant ID | 租户ID
     * @param int|null $siteId Site ID | 站点ID
     * @return array|null
     */
    public function findByName(string $name, int $tenantId, ?int $siteId = null): ?array
    {
        $query = Db::name($this->table)
            ->where('name', $name)
            ->where('tenant_id', $tenantId);
        
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $data = $query->find();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Find by collection name | 按Collection名称查找
     * 
     * @param string $collectionName Collection name | Collection名称
     * @param int $tenantId Tenant ID | 租户ID
     * @return array Forms linked to collection | 关联到Collection的表单
     */
    public function findByCollectionName(string $collectionName, int $tenantId): array
    {
        $list = Db::name($this->table)
            ->where('collection_name', $collectionName)
            ->where('tenant_id', $tenantId)
            ->select()
            ->toArray();

        return array_map(fn($data) => $this->hydrate($data), $list);
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
        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        // Apply filters | 应用筛选
        if (isset($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }
        if (isset($filters['title'])) {
            $query->whereLike('title', '%' . $filters['title'] . '%');
        }
        if (isset($filters['collection_name'])) {
            $query->where('collection_name', $filters['collection_name']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        // Get total | 获取总数
        $total = $query->count();

        // Get list with pagination | 分页获取列表
        $list = $query->page($page, $pageSize)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $forms = array_map(fn($data) => $this->hydrate($data), $list);

        return [
            'list' => $forms,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Delete form | 删除表单
     * 
     * @param int $id Form ID | 表单ID
     * @param int|null $tenantId Tenant ID | 租户ID
     * @return bool
     */
    public function delete(int $id, ?int $tenantId = null): bool
    {
        $query = Db::name($this->table)->where('id', $id);
        
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        
        $result = (bool)$query->delete();
        if (!$result) {
            // echo "Delete failed for ID $id (Tenant: $tenantId)\n";
        }
        return $result;
    }

    /**
     * Hydrate form schema from database row | 从数据库行填充表单Schema
     * 
     * @param array $data Database row | 数据库行
     * @return array
     */
    protected function hydrate(array $data): array
    {
        // Decode schema JSON | 解码schema JSON
        if (isset($data['schema']) && is_string($data['schema'])) {
            $data['schema'] = json_decode($data['schema'], true) ?? [];
        }

        return $data;
    }
}
