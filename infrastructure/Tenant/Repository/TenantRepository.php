<?php

declare(strict_types=1);

namespace Infrastructure\Tenant\Repository;

use Domain\Tenant\Model\Tenant;
use think\facade\Db;

/**
 * Tenant Repository | 租户仓储
 *
 * @package Infrastructure\Tenant\Repository
 */
class TenantRepository
{
    protected string $table = 'tenants';

    public function save(Tenant $tenant): int
    {
        $data = [
            'name' => $tenant->getName(),
            'slug' => $tenant->getSlug(),
            'domain' => $tenant->getDomain(),
            'status' => $tenant->getStatus(),
            'config' => json_encode($tenant->getConfig()),
            'max_sites' => $tenant->getMaxSites(),
            'max_users' => $tenant->getMaxUsers(),
        ];

        if ($tenant->getId()) {
            Db::name($this->table)->where('id', $tenant->getId())->update($data);
            return $tenant->getId();
        }

        return Db::name($this->table)->insertGetId($data);
    }

    public function findById(int $id): ?Tenant
    {
        $data = Db::name($this->table)->where('id', $id)->find();
        if (!$data) {
            return null;
        }

        $tenant = new Tenant($data['name'], $data['slug']);
        $tenant->fromArray($data);
        return $tenant;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $data = Db::name($this->table)->where('slug', $slug)->find();
        if (!$data) {
            return null;
        }

        $tenant = new Tenant($data['name'], $data['slug']);
        $tenant->fromArray($data);
        return $tenant;
    }

    public function delete(int $id): bool
    {
        return Db::name($this->table)->where('id', $id)->delete() > 0;
    }

    /**
     * Find tenant by domain | 按域名查找租户
     *
     * @param string $domain Domain name | 域名
     * @return Tenant|null
     */
    public function findByDomain(string $domain): ?Tenant
    {
        $data = Db::name($this->table)->where('domain', $domain)->find();
        if (!$data) {
            return null;
        }

        $tenant = new Tenant($data['name'], $data['slug']);
        $tenant->fromArray($data);
        return $tenant;
    }

    /**
     * List tenants with pagination | 分页列出租户
     *
     * @param array $filters Filter conditions | 筛选条件
     * @param int $page Page number | 页码
     * @param int $pageSize Page size | 每页数量
     * @return array{list: array, total: int, page: int, pageSize: int}
     */
    public function list(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Db::name($this->table);

        // Apply filters | 应用筛选条件
        if (!empty($filters['name'])) {
            $query->whereLike('name', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['slug'])) {
            $query->whereLike('slug', '%' . $filters['slug'] . '%');
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['domain'])) {
            $query->whereLike('domain', '%' . $filters['domain'] . '%');
        }

        // Get total count | 获取总数
        $total = $query->count();

        // Get paginated data | 获取分页数据
        $offset = ($page - 1) * $pageSize;
        $data = $query->limit($offset, $pageSize)->order('id', 'desc')->select()->toArray();

        // Convert to Tenant objects and then to arrays | 转换为 Tenant 对象再转数组
        $list = array_map(function ($row) {
            $tenant = new Tenant($row['name'], $row['slug']);
            $tenant->fromArray($row);
            return $tenant->toArray();
        }, $data);

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Check if slug exists | 检查 slug 是否存在
     *
     * @param string $slug Slug to check | 要检查的 slug
     * @param int|null $excludeId Exclude this ID from check | 排除此 ID
     * @return bool
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Db::name($this->table)->where('slug', $slug);
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }
        return $query->count() > 0;
    }
}
