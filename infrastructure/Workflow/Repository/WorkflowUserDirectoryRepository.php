<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Repository;

use think\facade\Db;

/**
 * 工作流「用户目录」只读仓储（`users` 表）
 *
 * ## 它提供三类读（都属于工作流动作的前置条件 / 数据源）
 *
 * | 方法 | 用途 |
 * | ---- | ---- |
 * | {@see existsInTenant()} | 转办 / 委派的目标用户是否属于当前租户（越权前置校验） |
 * | {@see displayName()} | 回填 `assignee_name`（任务列表要显示人名，而不是裸 ID） |
 * | {@see search()} | 转办 / 委派「选人」下拉框的分页目录（`GET /v1/workflow/users`） |
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
 * - **字段最小化**：对外只承诺 `id` / `name` / `username`，绝不把 `password` / `email` / `phone`
 *   之类的列带到工作流响应里（见 {@see search()} 的说明）；
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

    /**
     * 分页搜索**本租户**用户（转办 / 委派「选人」下拉框的数据源）
     *
     * ## 为什么字段只回三个
     *
     * 这个接口的唯一用途是「让人从下拉框里挑一个处理人」，需要的只有
     * `id`（提交给 `target_user` / `delegate_to` 的值）、`name`（显示名）、`username`（重名时区分）。
     * `users` 表还有 `password` / `email` / `phone` / `last_login_ip` 等列 ——
     * **一个都不能出现在这里**：下拉框不需要它们，多返回一个字段就是多一个泄露面。
     * 因此本方法用 `field([...])` 白名单列，而不是 `select *` 后在 PHP 里删键
     * （后者一旦有人「顺手加个字段」就会静默外泄，回归见
     * `tests/Feature/Workflow/WorkflowUserDirectoryEndpointTest`）。
     *
     * ## 租户过滤是硬约束
     *
     * `tenant_id` 是**必填的显式参数**（无默认值、不读上下文），并且直接下推到 SQL ——
     * 分页必须在库里做，否则「先取全表再过滤」会让 `total` 与 `list` 对不上，
     * 更糟的是会先把别的租户的用户拉进内存。
     *
     * ## `status` 为什么不过滤
     *
     * 与 {@see self::existsInTenant()} 保持同一口径：转办 / 委派的目标用户校验只看
     * 「在不在本租户」，不看 `status`。若目录过滤了 `status` 而动作不过滤，
     * 会出现「下拉框里没有他、接口却接受他」的两套真相；要收紧就两处一起收，
     * 不能只在这一侧悄悄筛掉（那会让「为什么我搜不到某个人」变成难查的问题）。
     *
     * @param int         $tenantId 租户ID
     * @param string|null $keyword  关键字（模糊匹配 `name` / `username`；null 或空串 = 不过滤）
     * @param int         $page     页码（>=1）
     * @param int         $pageSize 每页条数（>=1）
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function search(int $tenantId, ?string $keyword, int $page, int $pageSize): array
    {
        $page     = max($page, 1);
        $pageSize = max($pageSize, 1);

        $query = Db::name($this->table)->where('tenant_id', $tenantId);

        $keyword = is_string($keyword) ? trim($keyword) : '';

        if ($keyword !== '') {
            // 转义 LIKE 通配符：`%` / `_` 是用户输入的一部分，不是通配指令
            // （与 WorkflowDefinitionRepository::search() 的 name 过滤同一手法）
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';

            $query->where(function ($sub) use ($like): void {
                $sub->whereLike('name', $like)->whereOr('username', 'like', $like);
            });
        }

        $total = (int) (clone $query)->count();

        $rows = $query
            ->field(['id', 'name', 'username'])
            ->order('id', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = [];

        foreach ($rows as $row) {
            $list[] = $this->normalizeSelectable($row);
        }

        return [
            'list'  => $list,
            'total' => $total,
        ];
    }

    /**
     * 「可选用户」行归一化：只保留白名单字段，且把类型定死
     *
     * `name` 可空（`users.name` 是 `NULL` allowed 的真实姓名列），这里统一转成「非空字符串」：
     * 前端下拉框的 label 需要稳定可读，故 `name` 为空时回退 `username`，与
     * {@see self::displayName()} 的口径一致（同一个用户在任务列表和下拉框里显示同一个名字）。
     *
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, username: string}
     */
    private function normalizeSelectable(array $row): array
    {
        $username = (string) ($row['username'] ?? '');
        $name     = is_string($row['name'] ?? null) ? trim((string) $row['name']) : '';

        return [
            'id'       => (int) ($row['id'] ?? 0),
            'name'     => $name !== '' ? $name : $username,
            'username' => $username,
        ];
    }
}
