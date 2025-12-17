<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant;

use think\facade\Db;
use think\db\BaseQuery;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;

/**
 * Tenant Scope Manager | 租户范围管理器
 *
 * Centralized manager for tenant-scoped database queries.
 * Provides fluent interface for building tenant-aware queries.
 * 集中管理租户范围数据库查询的管理器。提供流畅的接口来构建租户感知查询。
 *
 * @package Alkaid\Workflow\Engine\Multitenant
 */
class TenantScopeManager
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table;

    /**
     * Current tenant ID
     *
     * @var int|null
     */
    protected ?int $tenantId = null;

    /**
     * Whether tenant scope is enabled
     *
     * @var bool
     */
    protected bool $scopeEnabled = true;

    /**
     * Tenant column name
     *
     * @var string
     */
    protected string $tenantColumn = 'tenant_id';

    /**
     * Constructor
     *
     * @param string $table Table name
     * @param string $tenantColumn Tenant column name
     */
    public function __construct(string $table, string $tenantColumn = 'tenant_id')
    {
        $this->table = $table;
        $this->tenantColumn = $tenantColumn;
    }

    /**
     * Create instance for table | 为表创建实例
     *
     * @param string $table Table name
     * @param string $tenantColumn Tenant column name
     * @return static
     */
    public static function forTable(string $table, string $tenantColumn = 'tenant_id'): static
    {
        return new static($table, $tenantColumn);
    }

    /**
     * Set tenant scope | 设置租户范围
     *
     * @param int $tenantId Tenant ID
     * @return static
     */
    public function forTenant(int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Use current tenant from context | 使用上下文中的当前租户
     *
     * @return static
     */
    public function forCurrentTenant(): static
    {
        $this->tenantId = TenantContext::getTenantId();
        return $this;
    }

    /**
     * Disable tenant scope | 禁用租户范围
     *
     * @return static
     */
    public function withoutScope(): static
    {
        $this->scopeEnabled = false;
        return $this;
    }

    /**
     * Enable tenant scope | 启用租户范围
     *
     * @return static
     */
    public function withScope(): static
    {
        $this->scopeEnabled = true;
        return $this;
    }

    /**
     * Get base query with tenant scope | 获取带租户范围的基础查询
     *
     * @return BaseQuery
     * @throws TenantIsolationException If tenant ID required but not set
     */
    public function query(): BaseQuery
    {
        $query = Db::name($this->table);

        if ($this->scopeEnabled) {
            $tenantId = $this->getTenantIdOrFail();
            $query->where($this->tenantColumn, $tenantId);
        }

        return $query;
    }

    /**
     * Find record by ID | 按ID查找记录
     *
     * @param mixed $id Record ID
     * @param string $pk Primary key column
     * @return array|null
     */
    public function find(mixed $id, string $pk = 'id'): ?array
    {
        $record = $this->query()->where($pk, $id)->find();
        return $record ?: null;
    }

    /**
     * Find record by conditions | 按条件查找记录
     *
     * @param array $conditions Query conditions
     * @return array|null
     */
    public function findWhere(array $conditions): ?array
    {
        $record = $this->query()->where($conditions)->find();
        return $record ?: null;
    }

    /**
     * Get all records | 获取所有记录
     *
     * @param array $conditions Query conditions
     * @param string $orderBy Order by column
     * @param string $orderDirection Order direction
     * @return array
     */
    public function all(array $conditions = [], string $orderBy = 'id', string $orderDirection = 'asc'): array
    {
        $query = $this->query();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->order($orderBy, $orderDirection)->select()->toArray();
    }

    /**
     * Get paginated records | 获取分页记录
     *
     * @param int $page Page number
     * @param int $perPage Records per page
     * @param array $conditions Query conditions
     * @return array ['data' => array, 'total' => int, 'page' => int, 'per_page' => int]
     */
    public function paginate(int $page = 1, int $perPage = 20, array $conditions = []): array
    {
        $query = $this->query();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        $total = (clone $query)->count();
        $data = $query->page($page, $perPage)->select()->toArray();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Insert record with tenant ID | 插入带租户ID的记录
     *
     * @param array $data Record data
     * @return int|string Inserted ID
     * @throws TenantIsolationException If tenant ID required but not set
     */
    public function insert(array $data): int|string
    {
        $data = $this->applyTenantId($data);
        return Db::name($this->table)->insertGetId($data);
    }

    /**
     * Insert multiple records with tenant ID | 批量插入带租户ID的记录
     *
     * @param array $dataList Records data
     * @return int Number of inserted records
     */
    public function insertAll(array $dataList): int
    {
        $dataList = array_map(
            fn($data) => $this->applyTenantId($data),
            $dataList
        );
        return Db::name($this->table)->insertAll($dataList);
    }

    /**
     * Update records | 更新记录
     *
     * @param array $data Update data
     * @param array $conditions Update conditions
     * @return int Number of updated records
     */
    public function update(array $data, array $conditions = []): int
    {
        $query = $this->query();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->update($data);
    }

    /**
     * Update record by ID | 按ID更新记录
     *
     * @param mixed $id Record ID
     * @param array $data Update data
     * @param string $pk Primary key column
     * @return int Number of updated records
     */
    public function updateById(mixed $id, array $data, string $pk = 'id'): int
    {
        return $this->update($data, [$pk => $id]);
    }

    /**
     * Delete records | 删除记录
     *
     * @param array $conditions Delete conditions
     * @return int Number of deleted records
     */
    public function delete(array $conditions = []): int
    {
        $query = $this->query();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->delete();
    }

    /**
     * Delete record by ID | 按ID删除记录
     *
     * @param mixed $id Record ID
     * @param string $pk Primary key column
     * @return int Number of deleted records
     */
    public function deleteById(mixed $id, string $pk = 'id'): int
    {
        return $this->delete([$pk => $id]);
    }

    /**
     * Soft delete record | 软删除记录
     *
     * @param mixed $id Record ID
     * @param string $deleteColumn Delete flag column
     * @param string $pk Primary key column
     * @return int Number of updated records
     */
    public function softDelete(mixed $id, string $deleteColumn = 'deleted_at', string $pk = 'id'): int
    {
        return $this->updateById($id, [$deleteColumn => date('Y-m-d H:i:s')], $pk);
    }

    /**
     * Count records | 统计记录数
     *
     * @param array $conditions Query conditions
     * @return int
     */
    public function count(array $conditions = []): int
    {
        $query = $this->query();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->count();
    }

    /**
     * Check if record exists | 检查记录是否存在
     *
     * @param array $conditions Query conditions
     * @return bool
     */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
    }

    /**
     * Validate record belongs to current tenant | 验证记录属于当前租户
     *
     * @param array $record Record data
     * @return bool
     * @throws TenantIsolationException If validation fails
     */
    public function validateOwnership(array $record): bool
    {
        if (!$this->scopeEnabled || !TenantContext::isIsolationEnforced()) {
            return true;
        }

        $currentTenantId = $this->getTenantIdOrFail();
        $recordTenantId = $record[$this->tenantColumn] ?? null;

        if ($recordTenantId !== null && $recordTenantId !== $currentTenantId) {
            throw TenantIsolationException::crossTenantAccess(
                $currentTenantId,
                (int)$recordTenantId,
                $this->table
            );
        }

        return true;
    }

    /**
     * Get tenant ID or fail | 获取租户ID，失败则抛出异常
     *
     * @return int
     * @throws TenantIsolationException If tenant ID not available
     */
    protected function getTenantIdOrFail(): int
    {
        $tenantId = $this->tenantId ?? TenantContext::getTenantId();

        if ($tenantId === null && TenantContext::isIsolationEnforced()) {
            throw TenantIsolationException::missingTenantContext("query table {$this->table}");
        }

        return $tenantId ?? 0;
    }

    /**
     * Apply tenant ID to data | 将租户ID应用到数据
     *
     * @param array $data Record data
     * @return array
     * @throws TenantIsolationException If tenant ID required but not set
     */
    protected function applyTenantId(array $data): array
    {
        if ($this->scopeEnabled && !isset($data[$this->tenantColumn])) {
            $data[$this->tenantColumn] = $this->getTenantIdOrFail();
        }

        return $data;
    }

    /**
     * Get current table name | 获取当前表名
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get current tenant ID | 获取当前租户ID
     *
     * @return int|null
     */
    public function getTenantId(): ?int
    {
        return $this->tenantId ?? TenantContext::getTenantId();
    }
}
