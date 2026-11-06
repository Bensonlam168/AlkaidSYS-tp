<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流「用户目录」只读仓储（`users` 表）
 *
 * ## 为什么工作流要自己读用户表
 *
 * 转办 / 委派要做两件事：**校验目标用户属于当前租户**、**回填显示的姓名**。
 * 这两件事都属于工作流动作的前置条件，但数据属于用户域，因此单独放一个**只读**仓储，
 * 而不是让 `WorkflowTaskService` 直接写 SQL（那会破坏「数据访问只在 Repository」的分层约定），
 * 也不去复用 `Infrastructure\User\Repository\UserRepository::findById()` ——
 * 后者会 `new User($tenantId, $username, $email, $password)`，而 `User::$password` 是非空 string：
 * 一旦库里某行 `password` 为 NULL（测试夹具常见），校验目标用户就会抛 `TypeError`，
 * 把「用户表里有一行脏数据」变成「转办功能 500」。
 *
 * ## 边界
 *
 * - **只读**：不写、不改、不删用户；
 * - **必须带 `tenant_id`**：`users.tenant_id` 是 NOT NULL，跨租户用户一律视为「不存在」；
 * - 不做授权判断：它只回答「这个人在不在这个租户里」，不回答「他能不能办这个任务」。
 *
 * @package Infrastructure\Workflow\Repository
 */
class WorkflowUserDirectoryRepository
{
    /**
     * 表名（无前缀）
     */
    protected string $table = 'users';

    /**
     * 用户是否存在于指定租户
     *
     * @param int $tenantId 租户ID
     * @param int $userId   用户ID
     */
    public function existsInTenant(int $tenantId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $count = Db::name($this->table)
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->count();

        return (int) $count > 0;
    }

    /**
     * 用户显示名（`name` 优先，回退 `username`）
     *
     * @param int $tenantId 租户ID
     * @param int $userId   用户ID
     * @return string|null 用户不存在时返回 null（调用方据此决定是否写 `assignee_name`）
     */
    public function displayName(int $tenantId, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $row = Db::name($this->table)
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->field(['name', 'username'])
            ->find();

        if (!is_array($row)) {
            return null;
        }

        foreach (['name', 'username'] as $column) {
            $value = $row[$column] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 100);
            }
        }

        return null;
    }
}
