<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services\Support;

use Alkaid\Workflow\Engine\Core\ServiceContext;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\TenantScopeManager;

/**
 * 服务层公共支撑：租户作用域解析 + 未实现方法的统一出口
 *
 * 多租户是本仓库的**红线**：所有针对 `workflow_*` 表的读写都必须带 `tenant_id` 条件。
 * 因此本 trait 只暴露两个取数入口，服务实现里不允许直接 `Db::name(...)`：
 * - `scoped()`  ：返回一个已经带上 `where tenant_id = ?` 的 TenantScopeManager；
 * - `tenantId()`：只取租户值（用于写库时显式填 tenant_id）。
 *
 * 租户来源优先级（与 ThinkPHPAdapter 的接线保持一致）：
 * 1）`TenantContext`（协程安全，见 src/Multitenant/TenantContext.php），
 *    HTTP 请求由中间件写入，CLI 由 ThinkPHPAdapter::setupTenantContext() 写入；
 * 2）`extension_parameters.tenant_id`（引擎配置项，CLI/队列场景的显式声明）；
 * 3）两者都没有 → 抛 TenantIsolationException（fail-closed，绝不放行无作用域查询）。
 *
 * @package Alkaid\Workflow\Engine\Services\Support
 */
trait ResolvesWorkflowTenant
{
    /**
     * 解析当前租户ID
     *
     * @param string $operation 操作描述（用于异常信息定位）
     * @throws TenantIsolationException 没有可用的租户上下文时
     */
    protected function tenantId(string $operation): int
    {
        $tenantId = TenantContext::getTenantId();

        if ($tenantId === null) {
            $configured = ServiceContext::getConfig('tenant_id');

            if (is_numeric($configured)) {
                $tenantId = (int) $configured;
            }
        }

        if ($tenantId === null) {
            throw TenantIsolationException::missingTenantContext($operation);
        }

        return $tenantId;
    }

    /**
     * 把操作人收敛成「可写库的用户ID」
     *
     * 为什么不能直接 `(int) $operator`：
     * `workflow_instances.created_by/assignee`、`workflow_tasks.created_by/assignee` 上都有
     * `fk_*_created_by -> users.id` / `fk_*_assignee -> users.id` 外键
     * （见 database/migrations/20251222000001_align_workflow_column_types.php 的 FOREIGN_KEYS），
     * 写入不存在的用户ID会直接抛 MySQL 1452。而引擎的操作人可能是：
     * - `flow_auto` / `flow.admin`（ProcessConstEnum::AUTO_ID / ADMIN_ID）→ 不是用户ID；
     * - `'0'`（ThinkPHPAdapter::CONSOLE_SYSTEM_USER_ID，系统/控制台身份）→ 不是真实用户，写 0 会撞外键。
     * 这两类都必须写 NULL（列允许 NULL）：它们表示「非人类用户发起」，不是「第 0 号用户」。
     * 只有「正整数」才当作用户ID落库。
     *
     * @param mixed $operator 操作人
     */
    protected function userIdOrNull(mixed $operator): ?int
    {
        if (!is_scalar($operator)) {
            return null;
        }

        $value = trim((string) $operator);

        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $userId = (int) $value;

        return $userId > 0 ? $userId : null;
    }

    /**
     * 获取带租户作用域的查询器
     *
     * @param string $table     表名（不含前缀）
     * @param string $operation 操作描述
     */
    protected function scoped(string $table, string $operation): TenantScopeManager
    {
        return TenantScopeManager::forTable($table)->forTenant($this->tenantId($operation));
    }

    /**
     * 主键收敛：本仓库的 workflow_* 表主键都是 `int unsigned` 自增
     *
     * 传进来的非数字 ID（例如 API 层造的 `'inst_'.uniqid()`）在库里不可能存在，
     * 直接判为「不存在」而不是把字符串丢进 SQL 比较：
     * `WHERE id = 'test_workflow_id'` 在 MySQL 里会被隐式转成 double 并产生警告，
     * 既查不到也污染日志。
     *
     * @param string|int $id 主键
     * @return int|null 合法则返回整数主键，否则 null
     */
    protected function numericId(string|int $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        $value = (int) $id;

        return $value > 0 ? $value : null;
    }

    /**
     * 统一抛出「未实现」异常
     *
     * 不返回假对象、不返回空数组：空实现会让调用方拿到「看起来成功」的假信号，
     * 比直接报错更难排查。每处调用都要求写明「为什么现在不能实现」。
     *
     * @param string $method 方法名
     * @param string $reason 不能实现的具体原因
     * @throws \BadMethodCallException
     */
    protected function unimplemented(string $method, string $reason): never
    {
        throw new \BadMethodCallException(sprintf(
            '未实现：引擎当前不调用此方法（%s）。原因：%s',
            $method,
            $reason
        ));
    }
}
