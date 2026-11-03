<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Tenant\TenantAwareDb;
use think\facade\Db;

/**
 * Collection Repository | Collection仓储
 *
 * Handles persistence of Collection entities.
 * 处理Collection实体的持久化。
 *
 * @package Infrastructure\Lowcode\Collection\Repository
 */
class CollectionRepository
{
    protected string $table = 'lowcode_collections';
    protected TenantAwareDb $tenantDb;

    public function __construct(?TenantAwareDb $tenantDb = null)
    {
        $this->tenantDb = $tenantDb ?? new TenantAwareDb();
    }

    /**
     * Save collection | 保存Collection
     *
     * @param CollectionInterface $collection Collection to save | 要保存的Collection
     * @return int Collection ID | Collection ID
     */
    public function save(CollectionInterface $collection): int
    {
        $schema = [
            'title' => $collection->getTitle(),
            'description' => $collection->getDescription(),
            'options' => $collection->getOptions(),
        ];

        // Resolve tenant/site for persistence | 解析持久化所需的租户/站点信息
        $tenantId = null;
        $siteId = null;

        if (method_exists($collection, 'getTenantId')) {
            $tenantId = $collection->getTenantId();
        }

        if (method_exists($collection, 'getSiteId')) {
            $siteId = $collection->getSiteId();
        }

        $data = [
            'name' => $collection->getName(),
            'table_name' => $collection->getTableName(),
            'schema' => json_encode($schema),
            // NOTE(T-002): 如 Domain 暂未暴露租户信息，则默认视为系统模板（tenant_id=0, site_id=0）。
            'tenant_id' => $tenantId !== null ? (int) $tenantId : 0,
            'site_id' => $siteId !== null ? (int) $siteId : 0,
        ];

        if ($collection->getId()) {
            // Update existing | 更新已存在的
            Db::name($this->table)
                ->where('id', $collection->getId())
                ->where('tenant_id', $data['tenant_id'])
                ->update($data);
            return $collection->getId();
        } else {
            // Insert new | 插入新的
            return (int)Db::name($this->table)->insertGetId($data);
        }
    }

    /**
     * Find by name | 按名称查找
     *
     * NOTE(租户必填): `$tenantId` 是**必填**参数，没有默认值也不再接受 null。
     * 「系统模板空间」必须显式传 0 —— 那仍然是一次按 `tenant_id = 0` 的过滤，
     * 与「不过滤租户」是两件事。省略即报错，不给 fail-open 留口子。
     *
     * @param string   $name     Collection name | Collection名称
     * @param int      $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @param int|null $siteId   Site ID (optional) | 站点ID（可选）
     * @return CollectionInterface|null
     */
    public function findByName(string $name, int $tenantId, ?int $siteId = null): ?CollectionInterface
    {
        $query = $this->tenantDb
            ->name($this->table, $tenantId)
            ->where('name', $name);

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
     * Find by ID | 按ID查找
     *
     * NOTE(租户必填): 修复前 `$tenantId === null` 会退化成 `Db::name()` 全表查询，
     * 即「拿着 ID 就能读到任意租户的集合」。现在租户是必填的，跨租户读只能靠猜 ID 之外的漏洞。
     * 若将来确实需要平台级遍历，请新增显式命名的 `findByIdUnscoped()`（可被 grep 审计），
     * 不要在这里把参数改回可空。
     *
     * @param int $id Collection ID | Collection ID
     * @param int $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @return CollectionInterface|null
     */
    public function findById(int $id, int $tenantId): ?CollectionInterface
    {
        $data = $this->tenantDb
            ->name($this->table, $tenantId)
            ->where('id', $id)
            ->find();

        if (!$data) {
            return null;
        }

        return $this->hydrate($data);
    }

    /**
     * Delete collection | 删除Collection
     *
     * NOTE(租户必填): 修复前省略 `$tenantId` 时会执行 `where('id', $id)->delete()`，
     * 也就是**按主键跨租户删除**（CollectionManager::delete 就漏传了这个参数）。
     * 现在租户必填，且条件里始终包含 `tenant_id`。
     *
     * @param int $id Collection ID | Collection ID
     * @param int $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @return bool 是否真的删除了行（跨租户调用返回 false，不会静默删掉别人的行）
     */
    public function delete(int $id, int $tenantId): bool
    {
        return (bool) $this->tenantDb
            ->name($this->table, $tenantId)
            ->where('id', $id)
            ->delete();
    }

    /**
     * List collections | 列出Collections
     *
     * NOTE(租户必填 + 参数顺序): `$tenantId` 提到第一位且无默认值。
     * 原先它排在三个带默认值的参数之后，一旦去掉默认值就会命中 PHP 的
     * 「可选参数在必填参数之前」弃用规则（隐式当作必填），因此这里同步调整了参数顺序。
     *
     * @param int      $tenantId Tenant ID (0 = system template space) | 租户ID（0 表示系统模板空间）
     * @param array    $filters  Filters | 筛选条件
     * @param int      $page     Page number | 页码
     * @param int      $pageSize Page size | 每页数量
     * @param int|null $siteId   Site ID (optional) | 站点ID（可选）
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(
        int $tenantId,
        array $filters = [],
        int $page = 1,
        int $pageSize = 20,
        ?int $siteId = null
    ): array {
        $query = $this->tenantDb->name($this->table, $tenantId);

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        // Apply filters | 应用筛选
        if (isset($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }

        // Get total | 获取总数
        $total = $query->count();

        // Get list with pagination | 分页获取列表
        $list = $query->page($page, $pageSize)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $collections = array_map(fn ($data) => $this->hydrate($data), $list);

        return [
            'list' => $collections,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Hydrate collection from database row | 从数据库行填充Collection
     *
     * @param array $data Database row | 数据库行
     * @return CollectionInterface
     */
    protected function hydrate(array $data): CollectionInterface
    {
        $schema = json_decode($data['schema'], true) ?? [];

        $collection = new Collection($data['name'], [
            'id' => $data['id'],
            'table_name' => $data['table_name'],
            'title' => $schema['title'] ?? $data['name'],
            'description' => $schema['description'] ?? '',
            'options' => $schema['options'] ?? [],
            'created_at' => $data['created_at'] ?? null,
            'updated_at' => $data['updated_at'] ?? null,
            // Multi-tenant metadata | 多租户元数据
            'tenant_id' => $data['tenant_id'] ?? 0,
            'site_id' => $data['site_id'] ?? 0,
        ]);

        return $collection;
    }
}
