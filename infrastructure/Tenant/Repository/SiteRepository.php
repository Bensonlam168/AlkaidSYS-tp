<?php

declare(strict_types=1);

namespace Infrastructure\Tenant\Repository;

use think\facade\Db;

/**
 * Site Repository | 站点仓储
 *
 * 提供 `sites` 表的最小读取能力，供 `app\middleware\SiteIdentify` 在入口做站点归属校验。
 * 站点归属于租户（`sites.tenant_id` → `tenants.id`，ON DELETE CASCADE），
 * 因此本仓储的多租户约定与 `DeptRepository` / `RoleRepository` 一致：
 * **凡是参与「放行 / 拒绝」判断的查询，租户条件必须与主键条件出现在同一个链式调用里，
 * 且租户参数是必填的 `int $tenantId`（不提供「省略即不过滤」的兜底默认值）**。
 * 参见 `docs/technical-specs/multi-tenant/tenant-parameter-contract.md`（TENANT-01）。
 *
 * ## 为什么有两个「按 id 查站点」的方法，而不是一个
 *
 * 入口校验需要区分三种拒绝原因（不存在 / 属于别的租户 / 存在但不可用），而 TENANT-01
 * 明确要求**放行判断只能基于租户内查询**（`findContextById()`）。若为了区分原因把租户条件
 * 从 where 里摘掉，就等于给「跨租户读」开了一条参与放行的路径 —— 那正是规范要消灭的写法。
 *
 * 因此这里刻意拆成两个方法，职责**不可互换**：
 *   1. `findContextById(int $siteId, int $tenantId)`：租户内读，**唯一参与放行判断**的查询；
 *   2. `findOwnershipHintUnscoped(int $siteId)`：**无租户条件**的读，方法名自带 `Unscoped` 后缀
 *      可被 grep 审计（`grep -rn 'findOwnershipHintUnscoped(' app domain infrastructure`），
 *      它的返回值**只允许**用来选择拒绝原因（写进日志的 reason），
 *      **绝不允许**参与「这个站点能不能用」的判断。
 *
 * 这与 `docs/technical-specs/multi-tenant/tenant-parameter-contract.md` §2.4 记录的
 * `RoleRepository::rejectWriteOutOfTenant()` 属于同一个模式：
 * 「用于报错的跨租户读」与「用于放行的跨租户读」必须分开。
 *
 * @package Infrastructure\Tenant\Repository
 */
class SiteRepository
{
    /**
     * 站点状态：可用（唯一允许通过的取值）
     *
     * 与迁移 `20251119210100_create_sites_table.php` 的
     * `enum('active','inactive','maintenance')` 对齐；枚举外的取值一律按「不可用」处理（fail-closed）。
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * 表名
     */
    protected string $table = 'sites';

    /**
     * 租户内按主键查询站点上下文（**唯一参与放行判断**的查询）
     *
     * `where('id', $siteId)` 与 `where('tenant_id', $tenantId)` 写在同一个链式调用里，
     * 没有「传了才加条件」的分支 —— 少传一个实参在 PHP 层会直接报错，而不是静默跨租户。
     *
     * @param int $siteId   站点 ID（`sites.id`，正整数）
     * @param int $tenantId 租户 ID（必填，用于隔离）
     * @return array{id: int, tenant_id: int, status: string}|null 站点存在且属于该租户时返回上下文，否则 null
     */
    public function findContextById(int $siteId, int $tenantId): ?array
    {
        $row = Db::name($this->table)
            ->field('id,tenant_id,status')
            ->where('id', $siteId)
            ->where('tenant_id', $tenantId)
            ->find();

        return $this->normalizeContext($row);
    }

    /**
     * 按主键查询站点归属信息（**跨租户读，仅供诊断，不得参与放行**）
     *
     * 用途：只在 `findContextById()` 返回 null 之后调用一次，用于区分
     * 「站点根本不存在」（`site_not_found`）与「站点属于别的租户」（`site_tenant_mismatch`）。
     * 两者**对外都是 403 / 2002**，且响应正文完全一致（不泄露站点 id 的存在性），
     * 区别只体现在服务端日志的 reason 上 —— 这正是允许存在这条跨租户读的原因。
     *
     * 方法名的 `Unscoped` 后缀是刻意的：调用它即可被 grep 审计。
     *
     * @param int $siteId 站点 ID（`sites.id`）
     * @return array{id: int, tenant_id: int, status: string}|null 存在则返回归属与状态，否则 null
     */
    public function findOwnershipHintUnscoped(int $siteId): ?array
    {
        $row = Db::name($this->table)
            ->field('id,tenant_id,status')
            ->where('id', $siteId)
            ->find();

        return $this->normalizeContext($row);
    }

    /**
     * 统一把数据库行规范化成「站点上下文」数组
     *
     * - 未命中（`find()` 返回 null / 空数组）→ null；
     * - 命中 → `id` / `tenant_id` 转 int，`status` 转 string。
     *
     * 为什么显式转 int：MySQL 驱动在非严格模式下可能把无符号整数取成字符串，
     * 而调用方（`SiteIdentify`）会用 `===` 比较租户 ID，类型不一致会导致误判。
     *
     * @param array<string, mixed>|null $row 数据库行
     * @return array{id: int, tenant_id: int, status: string}|null
     */
    private function normalizeContext(?array $row): ?array
    {
        if (empty($row) || !isset($row['id'])) {
            return null;
        }

        return [
            'id'        => (int) $row['id'],
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'status'    => (string) ($row['status'] ?? ''),
        ];
    }
}
