<?php

declare(strict_types=1);

namespace Infrastructure\Tenant;

use InvalidArgumentException;
use think\db\BaseQuery;
use think\facade\Db;

/**
 * 多租户感知的 DB 包装器
 * - 自动注入 tenant_id/site_id 过滤
 * - 作为 T-074 的基础抽象，后续可扩展审计与 trace_id 记录
 */
class TenantAwareDb
{
    /**
     * 构建基于租户/站点范围的查询
     */
    public function table(string $table, int $tenantId, ?int $siteId = null): BaseQuery
    {
        $this->assertTenant($tenantId);
        $query = Db::name($table)->where('tenant_id', $tenantId);
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        return $query;
    }

    /**
     * 简化 name() 入口
     */
    public function name(string $table, int $tenantId, ?int $siteId = null): BaseQuery
    {
        return $this->table($table, $tenantId, $siteId);
    }

    protected function assertTenant(int $tenantId): void
    {
        if ($tenantId < 0) {
            throw new InvalidArgumentException('tenant_id must be >= 0');
        }
    }
}
