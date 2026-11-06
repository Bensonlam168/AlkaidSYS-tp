<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\interface\services;

use Alkaid\Workflow\Engine\Entities\TaskActorResolution;
use Alkaid\Workflow\Engine\model\TaskModel;

/**
 * 任务参与者解析器：把节点上配置的「谁有权办」解析成可落库的用户 id
 *
 * ── 为什么需要它（本仓库此前的缺陷）────────────────────────────────────────
 * 任务节点（`ingenious:task`）的 `properties` 里早就能配参与者：
 * `assignee` / `candidate_ext_users` / `candidate_ext_groups`（见 `parser/handler/TaskParser.php`）。
 * 但创建任务时这些配置**被整体丢弃**：`ProcessTaskService::buildTaskColumns()` 只把
 * 「assignee 那一个字符串」写进 `assignee` 与 `candidate_users`，
 * `candidate_groups` 恒为空、`assignee_name` 恒为 null。
 * 结果是「谁有权办这个任务」只能写死一个人，配了角色等于没配。
 *
 * ── 默认实现支持的配置形态（逐条口径，实现见 DefaultTaskActorResolver）──────
 *
 * | 配置位置 | 取值 | 解析为 |
 * | -------- | ---- | ------ |
 * | `assignee` | 纯数字（`5`） | 用户 5 |
 * | `assignee` | `user:5` | 用户 5 |
 * | `assignee` | `${expr}` | 表达式结果（id 或 id 数组） |
 * | `candidate_users` | 逗号串 / 数组，元素同 `assignee` 三种形态 | 逐个解析 |
 * | `candidate_groups` | `role:<slug>` | 本租户内该角色的全部用户 id |
 * | `candidate_groups` | `dept:<id>` | 本租户内该部门的用户 id |
 * | `candidate_groups` | 裸值（无前缀，如 `admin`） | 按 `role:<值>` 处理 |
 *
 * 分隔符：逗号 / 分号 / 竖线（**不含空白** —— 表达式里本来就有空格）。
 *
 * ── 失败口径（两类，别混）───────────────────────────────────────────────────
 * 1）**语法错误 → 抛 `LFlowException`**：任何改数据都修不好的配置，猜比报错更危险。
 *    - 未知前缀（`post:3`、`team:x`）、前缀后为空、非数字的用户 id（`abc`）；
 *    - 表达式求值失败或求值结果不是 id / id 数组（不静默变成 null —— 那会让任务
 *      「看起来指派了、实际没人」，是本仓库最难查的一类故障）；
 *    - `candidate_groups` 里写表达式（组的取值是 `role:` / `dept:` 引用，
 *      表达式请放进 `candidate_users`，否则一个字符串既可能是角色也可能是一个 id）。
 * 2）**数据状态 → 不抛，记 `warnings`**：部署侧改数据就能修好的，不该让流程建不出任务。
 *    - `role:<slug>` 在本租户内不存在（若该角色属于别的租户，就是跨租户引用，已拒绝）；
 *    - 角色存在但一个成员都没有；
 *    - `dept:<id>` 不存在；或存在但本仓库表结构里没有「用户 ↔ 部门」关联列，无法展开；
 *    - 解析出的用户 id 不属于本租户（跨租户引用，已拒绝并记录）。
 *
 * 两类都不会「静默跳过」：前者抛异常，后者进 `warnings` 被调用方写成 WARNING 日志。
 *
 * ── 可替换性 ──────────────────────────────────────────────────────────────
 * 宿主可用容器配置 `task_actor_resolver`（或 `service_dependencies['taskActorResolver']`）
 * 换掉默认实现，例如接自己的组织架构服务。引擎只依赖本接口。
 *
 * @package Alkaid\Workflow\Engine\interface\services
 */
interface ITaskActorResolver
{
    /**
     * 解析一个任务节点配置的参与者
     *
     * @param TaskModel          $taskModel         任务节点模型（提供 assignee / candidateUsers / candidateGroups）
     * @param array              $variables         运行时变量（表达式求值上下文，通常来自流程实例变量）
     * @param string             $scene             场景描述（写进异常与日志，便于定位是哪个节点出问题）
     * @param string|int|null    $preferredAssignee 强制处理人（驳回等场景「沿用原处理人」时用）；
     *                                              仍需通过租户校验，无法解析时按 warnings 处理
     *
     * @return TaskActorResolution
     *
     * @throws \Alkaid\Workflow\Engine\ex\LFlowException 配置语法错误 / 表达式求值失败
     * @throws \Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException 没有租户上下文
     */
    public function resolve(
        TaskModel $taskModel,
        array $variables = [],
        string $scene = '',
        string|int|null $preferredAssignee = null
    ): TaskActorResolution;
}
