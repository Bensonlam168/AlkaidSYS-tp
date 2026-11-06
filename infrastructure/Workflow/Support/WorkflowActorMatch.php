<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Support;

/**
 * 工作流任务「参与者」判定（宿主侧唯一实现）
 *
 * ## 为什么需要独立一层
 *
 * 任务表里表达「谁有权办这件事」的只有两列（`workflow_tasks.assignee` / `candidate_users`
 * / `candidate_groups`），而三个地方都要做同一套判定：
 *   1. 待办列表（`GET /workflow/tasks/pending`，必须下推到 SQL）；
 *   2. 认领（`POST .../claim`）与动作授权（approve/reject/complete/transfer/delegate）；
 *   3. 单条任务的访问控制。
 *
 * 如果三处各写一遍，迟早会出现「列表里能看到、点进去 403」或者更糟的「列表看不到但能审批」。
 * 因此判定口径集中在本类，SQL 侧只做 `JSON_CONTAINS` 的机械翻译，语义一律看这里。
 *
 * ## 两列的真实取值形态（**踩过的坑**）
 *
 * 引擎（`ProcessTaskService::buildTaskColumns()`）写库时是：
 * - `candidate_users`：`json_encode(array_map('string', $userIds))` → `["6102","6103"]`（**字符串**数字）；
 * - `candidate_groups`：原始组引用原样保留 → `["role:admin","dept:3"]`（**带前缀**）。
 *
 * 因此：
 * - 用户ID 比较必须**归一化成 int** 再比，不能在 int 与 `"6102"` 之间做严格比较
 *   （`in_array(6102, ['6102'], true) === false`，这是最容易写出的假阴性）；
 * - 组必须**去掉 `role:` 前缀**再与 `app\Request::userGroups()`（角色 slug）比较；
 * - `dept:` 前缀本仓库**无法展开**（`users` 表没有 `dept_id`，也没有用户-部门关联表，
 *   见引擎 `DefaultTaskActorDirectory` 的类注释），因此 `dept:` 一律不参与匹配 ——
 *   不是「匹配成功」，而是「无法判断」，绝不因此放行。
 *
 * @package Infrastructure\Workflow\Support
 */
final class WorkflowActorMatch
{
    /** 角色组前缀（引擎口径：`role:<slug>`） */
    public const PREFIX_ROLE = 'role:';

    /** 部门组前缀（本仓库无法展开，仅用于识别与提示） */
    public const PREFIX_DEPT = 'dept:';

    /**
     * 把 JSON 列 / 数组归一化成整数用户ID 列表
     *
     * @param mixed $raw 原始值（json 字符串 / 数组 / null）
     * @return int[] 去重后的正整数列表（保持首次出现顺序）
     */
    public static function normalizeUserIds(mixed $raw): array
    {
        $values = self::decodeList($raw);
        $ids    = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && ctype_digit(trim($value))) {
                $id = (int) trim($value);
            } elseif (is_float($value) && $value > 0 && $value === (float) (int) $value) {
                $id = (int) $value;
            } else {
                continue;
            }

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 把 JSON 列 / 数组归一化成原始组引用列表（**保留前缀**）
     *
     * @param mixed $raw 原始值
     * @return string[]
     */
    public static function normalizeGroupRefs(mixed $raw): array
    {
        $refs = [];

        foreach (self::decodeList($raw) as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $ref = trim((string) $value);

            if ($ref === '') {
                continue;
            }

            $refs[] = $ref;
        }

        return array_values(array_unique($refs));
    }

    /**
     * 组引用 → 可用于比较的角色 slug 列表
     *
     * - `role:admin` → `admin`；
     * - 裸值 `admin`  → `admin`（引擎 `DefaultTaskActorResolver` 的口径：无前缀按 `role:` 处理）；
     * - `dept:3` → 丢弃（本仓库无法把部门展开成用户，见类注释）。
     *
     * @param string[] $refs 原始组引用
     * @return string[]
     */
    public static function groupSlugs(array $refs): array
    {
        $slugs = [];

        foreach ($refs as $ref) {
            if (str_starts_with($ref, self::PREFIX_DEPT)) {
                continue;
            }

            $slug = str_starts_with($ref, self::PREFIX_ROLE)
                ? substr($ref, strlen(self::PREFIX_ROLE))
                : $ref;

            $slug = trim($slug);

            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * 任务是否设置了**任一**候选约束（候选用户或候选组）
     *
     * 口径：只要设了约束，非候选人就必须被拒绝；两者都为空才是「开放任务」。
     */
    public static function hasCandidacy(array $task): bool
    {
        return self::normalizeUserIds($task['candidate_users'] ?? null) !== []
            || self::normalizeGroupRefs($task['candidate_groups'] ?? null) !== [];
    }

    /**
     * 用户是否命中候选范围（候选用户 或 候选组）
     *
     * @param array    $task           任务行（`candidate_users` / `candidate_groups` 可为 json 字符串）
     * @param int      $userId         当前用户
     * @param string[] $userGroupSlugs 当前用户在本租户下的角色 slug（`app\Request::userGroups()`）
     */
    public static function isCandidate(array $task, int $userId, array $userGroupSlugs): bool
    {
        if (in_array($userId, self::normalizeUserIds($task['candidate_users'] ?? null), true)) {
            return true;
        }

        $matched = array_intersect(
            array_map('strval', $userGroupSlugs),
            self::groupSlugs(self::normalizeGroupRefs($task['candidate_groups'] ?? null))
        );

        return $matched !== [];
    }

    /**
     * 任务的受理人（`assignee`）—— 空值统一成 null，避免 0 / '' / '0' 混在一起
     */
    public static function assigneeOf(array $task): ?int
    {
        $raw = $task['assignee'] ?? null;

        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    /**
     * 解码「列表型」列（json 字符串 / 数组 / null）
     *
     * json 列经 ThinkPHP 查询构造器取回时是字符串，直接 foreach 会遍历**字符**，
     * 是「候选用户永远匹配不上」的经典来源。
     *
     * @return array<int, mixed>
     */
    private static function decodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
