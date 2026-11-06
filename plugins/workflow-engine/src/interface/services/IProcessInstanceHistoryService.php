<?php

declare(strict_types=1);

/**
 *+------------------
 * ingenious
 *+------------------
 * Copyright (c) https://gitcode.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Software Registration Number: 2024SR0694589
 *+------------------
 * Official Website: https://madong.tech
 */

namespace Alkaid\Workflow\Engine\interface\services;

/**
 * 流程实例历史服务（= 流程轨迹 / 事件时间线的读写契约）
 *
 * ── 本接口为什么只剩 5 个方法（上游 30 多个声明全部被注释掉了）──────────────
 * 上游 `ingenious` 的历史服务是「实例历史表 + 任务历史表」的双表快照模型，
 * 30 多个方法里绝大多数（`create/update/page/interrupt/resume/pending/activate/
 * withdraw/highLight/approvalRecord/createCCInstance/...`）在本仓库**没有任何调用方**：
 * 引擎只有一处调用历史服务
 * （见 {@see \Alkaid\Workflow\Engine\Core\ProcessEngines::startProcessInstanceById}）。
 * 把注释掉的声明逐条恢复，就等于承诺「这些方法都已实现」，而没人调用的实现就是死代码。
 * 因此这里只声明**引擎真正会走的**入口 + 本服务真正提供的读取口。
 *
 * ── 「历史」在本仓库的口径：事件时间线，不是快照副本 ──────────────────────
 * 不建 `workflow_instance_history` 表。`workflow_instances` 已完整保存实例状态
 * （status / started_at / completed_at / duration / tenant_id），且实例从不物理删除，
 * 再造一张「只在启动时写一次、零读取方」的快照表纯属死重。
 * 轨迹改为落 `workflow_event_logs`（该表早已建好外键与索引，此前零写入）：
 * 一行一个事件（`instance_started` / `task_completed` / `instance_completed` /
 * `instance_rejected`），按 `created_at, id` 正序即得流程轨迹。
 *
 * ── 任务级轨迹为什么不走 IProcessTaskHistoryService ────────────────────────
 * {@see IProcessTaskHistoryService}（created/updated/findById/list）全仓库零调用方，
 * 而任务级的事实已由两处覆盖：`workflow_tasks` 的
 * `result/comment/completed_at/rejected_at/cancelled_at`（单条任务的结果与意见）
 * 与本接口的 `task_completed` 事件（时间线上的顺序与操作人）。
 * 再实现四个没人调用的方法只会制造第二份真相。
 *
 * @author Mr.April
 * @since  1.0
 */
interface IProcessInstanceHistoryService
{
    /**
     * 记录「流程实例已启动」（`workflow_instances` 落库之后调用）
     *
     * 调用点保持不变：`ProcessEngines::startProcessInstanceById()` 第 3.1 步。
     * 那里的守卫（服务缺失 / 没有该方法 → 跳过并记 INFO）是**故意保留**的防御性代码：
     * 宿主可以用 `process_instance_history_service` 覆盖成自己的实现，
     * 覆盖物不一定实现了本方法。
     *
     * @param object          $processInstance 刚落库的实例（引擎视角对象，含 id / tenant_id）
     * @param string|int|null $operator        发起人原始标识（`flow_auto` 这类非数字身份也要能留痕）
     *
     * @return void
     */
    public function createHistoryProcessInstance(object $processInstance, string|int|null $operator = null): void;

    /**
     * 记录「任务已完成」事件
     *
     * @param string|int      $processInstanceId 实例ID
     * @param string|int      $processTaskId     任务ID
     * @param string|int|null $operator          操作人原始标识
     * @param array           $extra             附加快照（节点名 / 审批意见 / 完成时间等，由调用方给）
     *
     * @return void
     */
    public function recordTaskCompleted(
        string|int $processInstanceId,
        string|int $processTaskId,
        string|int|null $operator,
        array $extra = []
    ): void;

    /**
     * 记录「实例已完成」事件（实例走到 end 且状态变为 completed）
     *
     * @param string|int      $processInstanceId 实例ID
     * @param string|int|null $operator          操作人（查不到就 null，不伪造）
     * @param array           $extra             附加快照（status / duration / completed_at 等）
     *
     * @return void
     */
    public function recordInstanceCompleted(
        string|int $processInstanceId,
        string|int|null $operator,
        array $extra = []
    ): void;

    /**
     * 记录「实例已拒绝」事件（拒绝终态）
     *
     * @param string|int      $processInstanceId 实例ID
     * @param string|int|null $operator          操作人（查不到就 null，不伪造）
     * @param array           $extra             附加快照（status / duration / completed_at 等）
     *
     * @return void
     */
    public function recordInstanceRejected(
        string|int $processInstanceId,
        string|int|null $operator,
        array $extra = []
    ): void;

    /**
     * 读取流程轨迹（按 created_at, id 正序）
     *
     * **强制租户过滤**：租户取自当前租户上下文（`TenantContext` / `extension_parameters.tenant_id`），
     * 取不到即抛 {@see \Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException}。
     * 其他租户查同一个实例ID只能拿到空数组（不是「读到了但看不见」）。
     *
     * @param string $processInstanceId 实例ID
     *
     * @return array<int, array<string, mixed>> 事件列表（`event_data` 已解码为数组）
     */
    public function timeline(string $processInstanceId): array;
}
