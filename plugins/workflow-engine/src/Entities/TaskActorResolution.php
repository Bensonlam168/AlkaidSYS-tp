<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Entities;

/**
 * 任务参与者解析结果（值对象，不可变）
 *
 * ── 它回答的问题 ───────────────────────────────────────────────────────────
 * 节点上配置的「谁有权办这个任务」（`assignee` / `candidate_users` / `candidate_groups`）
 * 经过 {@see \Alkaid\Workflow\Engine\interface\services\ITaskActorResolver} 解析之后，
 * 落库需要的那几列就全在这里：处理人、处理人姓名、展开后的候选人 id、
 * 以及**原样保留**的候选人组引用。
 *
 * ── 为什么 `candidateGroupRefs` 与 `candidateUserIds` 必须同时存在 ─────────
 * `ProcessTaskService::isAllowed()` 只认 `assignee` + `candidate_users`
 * （见 ProcessTaskEntity::getActorIds()），**不读** `candidate_groups`。
 * 所以「谁能办」必须是把角色/部门**展开成用户 id** 写进 `candidate_users`；
 * 而 `candidate_groups` 里保留原始引用（`role:admin` 这类），用于展示「为什么是他」与事后审计。
 * 只做前者会丢掉配置意图，只做后者等于没有任何人获得权限。
 *
 * ── `warnings` 是「被拒绝/跳过」的书面记录 ────────────────────────────────
 * 解析过程中遇到的「角色在本租户内不存在」「该 id 不属于本租户」「dept: 无关联列可展开」
 * 这里都留一条人类可读的原因，由调用方（`ProcessTaskService::buildTaskColumns()`）
 * 统一记 WARNING。这样既不会因为数据问题让流程建不出任务（流程照常推进），
 * 也不会出现「任务生成了但没人能办，日志里却什么都没说」这种最难查的故障。
 *
 * @package Alkaid\Workflow\Engine\Entities
 */
final class TaskActorResolution
{
    /**
     * @param int|null                $assignee           处理人（单个，可空）
     * @param string|null             $assigneeName       处理人姓名（`users.name`，为空时回落 `users.username`）
     * @param int[]                   $candidateUserIds   展开后的候选人用户ID（并集，**含** assignee，已去重）
     * @param string[]                $candidateGroupRefs 原始候选人组引用（原样保留，不归一化）
     * @param array<int, string>      $userNames          已解析到的用户ID => 姓名（供按人拆任务时会签使用）
     * @param string[]               $warnings           被拒绝/跳过的原因（调用方据此记 WARNING）
     */
    public function __construct(
        public readonly ?int $assignee = null,
        public readonly ?string $assigneeName = null,
        public readonly array $candidateUserIds = [],
        public readonly array $candidateGroupRefs = [],
        public readonly array $userNames = [],
        public readonly array $warnings = [],
    ) {
    }

    /**
     * 是否解析不出任何可执行的人（既无处理人，也无候选人）
     *
     * 这不等于「任务创建失败」：任务照样以 pending 落库，流程继续推进，
     * 客户端表现为「任务存在但没人能推进」（只有 flow_auto / flow.admin 能执行），
     * 因此调用方必须为此记一条 WARNING。
     */
    public function hasNoActor(): bool
    {
        return $this->assignee === null && $this->candidateUserIds === [];
    }

    /**
     * 派生出「会签场景下某一个人的那条任务」
     *
     * 会签节点是**一个参与者一条任务记录**（见 ProcessTaskService::createCountersignTask），
     * 每条记录的处理人是该参与者，候选人也只放他自己 —— 不能让 A 的会签任务被 B 代签，
     * 否则会签的「每人一次意见」语义会被绕开。候选人组引用原样带过去，保持审计一致。
     *
     * @param int $actorId 该条会签任务的处理人
     */
    public function forActor(int $actorId): self
    {
        return new self(
            assignee: $actorId,
            assigneeName: $this->userNames[$actorId] ?? null,
            candidateUserIds: [$actorId],
            candidateGroupRefs: $this->candidateGroupRefs,
            userNames: $this->userNames,
            warnings: $this->warnings,
        );
    }
}
