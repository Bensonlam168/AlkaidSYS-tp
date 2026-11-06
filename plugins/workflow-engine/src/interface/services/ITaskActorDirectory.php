<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\interface\services;

/**
 * 租户内的人员目录（解析任务参与者时的唯一数据来源）
 *
 * ── 为什么把「查库」拆成一个独立接口 ──────────────────────────────────────
 * 1）**可替换**：宿主可以用自己的组织架构服务实现它（部门树、岗位、外部 HR 同步……），
 *    不必改引擎的解析规则；
 * 2）**可测**：参与者解析的核心是「配置 → 用户 id」这套规则，规则本身就值得逐条断言。
 *    插件自身的 PHPUnit 配置**不连数据库**（见 `plugins/workflow-engine/phpunit.xml` 的注释），
 *    若解析器直接 `Db::` 查询，这些规则就只能靠真库才能验证。把查询抽到本接口后，
 *    规则测试可以注入内存实现，一行 SQL 都不用发。
 *
 * ── 租户口径（红线）────────────────────────────────────────────────────────
 * **每个方法都必须带 `$tenantId`**，且实现方必须把它变成 SQL 条件：
 * 少了这条过滤，A 租户的任务就会把 B 租户的用户拉进候选集 —— 那是跨租户越权，
 * 不是「查得慢一点」的问题。因此这里刻意不提供「省略租户即不过滤」的重载。
 *
 * ── 返回值的边界 ──────────────────────────────────────────────────────────
 * 「查不到」一律返回 `null` / `[]`，**不抛异常**：查不到是正常的数据状态
 * （角色还没建、用户已离职被删、部门不存在），由解析器决定是记 WARNING 还是当空集处理。
 * 只有「参数本身非法」（例如 user id 非正整数）才允许抛异常。
 *
 * @package Alkaid\Workflow\Engine\interface\services
 */
interface ITaskActorDirectory
{
    /**
     * 按 slug 查本租户内的角色
     *
     * @param int    $tenantId 当前租户ID
     * @param string $slug     角色标识（`roles.slug`，例如 `admin` / `finance`）
     *
     * @return array{id:int,name?:string,slug?:string}|null 查不到返回 null
     */
    public function findRoleBySlug(int $tenantId, string $slug): ?array;

    /**
     * 取本租户内该角色的全部用户ID
     *
     * 返回的每个 id 都必须**同时**满足「属于 $tenantId 租户」与「拥有该角色」；
     * 角色属于别的租户时返回空数组（调用方按「本租户内没有这个人」处理并记 WARNING）。
     *
     * @param int $tenantId 当前租户ID
     * @param int $roleId   角色ID（应已由 {@see self::findRoleBySlug()} 在本租户内校验过）
     *
     * @return int[]
     */
    public function findUserIdsByRoleId(int $tenantId, int $roleId): array;

    /**
     * 按 id 查本租户内的部门
     *
     * @param int $tenantId 当前租户ID
     * @param int $deptId   部门ID
     *
     * @return array{id:int,name?:string}|null 查不到（或属于别的租户）返回 null
     */
    public function findDeptById(int $tenantId, int $deptId): ?array;

    /**
     * 批量取本租户内的用户姓名
     *
     * 这个方法的第二个用途是**存在性校验**：返回结果里没有出现的 id，
     * 就等于「本租户内没有这个用户」（不存在，或属于别的租户）。
     * 解析器据此拒绝跨租户引用 —— 否则 `workflow_tasks.assignee` 的外键只校验
     * 「users 表里有这个 id」，完全拦不住「把别的租户的用户写成处理人」。
     *
     * @param int   $tenantId 当前租户ID
     * @param int[] $userIds  待查用户ID
     *
     * @return array<int, string> 用户ID => 姓名（`users.name` 为空时回落 `users.username`）
     */
    public function findUserNames(int $tenantId, array $userIds): array;
}
