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
}
