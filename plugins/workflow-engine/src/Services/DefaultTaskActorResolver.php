<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Services;

use Alkaid\Workflow\Engine\Entities\TaskActorResolution;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\Expression\ExpressionEngine;
use Alkaid\Workflow\Engine\interface\services\ITaskActorDirectory;
use Alkaid\Workflow\Engine\interface\services\ITaskActorResolver;
use Alkaid\Workflow\Engine\model\TaskModel;
use Alkaid\Workflow\Engine\Services\Support\ResolvesWorkflowTenant;

/**
 * 任务参与者解析器的默认实现
 *
 * 规则总表与失败口径见 {@see ITaskActorResolver} 的类注释（那里是契约，这里是实现）。
 * 下面只解释**为什么这样实现**，以及几处容易被误读的取舍。
 *
 * ── 为什么「组要展开成用户 id」而不是只存组引用 ────────────────────────────
 * `ProcessTaskService::isAllowed()` 判断「你能不能办这个任务」时的依据是
 * `ProcessTaskEntity::getActorIds()` = `assignee` ∪ `candidate_users`，
 * **完全不读 `candidate_groups`**。所以只写 `candidate_groups`（角色/部门）时
 * 没有任何人获得权限；必须把组展开成用户 id 写进 `candidate_users`，
 * 同时把原始组引用写进 `candidate_groups` 留作展示与审计。两件事都要做。
 *
 * ── 为什么「数据问题不抛、语法问题抛」─────────────────────────────────────
 * 任务创建是流程推进的必经一步。角色还没建、成员被清空、用户离职被删，这些都只该
 * 让「这条任务没人能办」并留下 WARNING，不该让整个流程卡在建任务这一步 ——
 * 于是它们进 `warnings`，由调用方记录。而「前缀写错」「表达式跑不起来」这类
 * 靠改数据修不好的配置，猜一个结果比报错更危险（会静默指错人），因此直接抛。
 *
 * ── 为什么表达式失败必须抛（而不是当 null）───────────────────────────────
 * 若静默变成 null，任务会以「没有处理人」落库，界面上看起来是「待认领」，
 * 而配置里明明写了 `${applicant_id}`。这类「看起来生效了、其实没人」的故障
 * 只能靠翻日志发现，成本最高。因此这里把异常包成 LFlowException 带上表达式原文抛出。
 *
 * ── 快照语义（已知边界，不是缺陷）─────────────────────────────────────────
 * 角色成员是在**建任务那一刻**展开的快照。之后有人加了角色，不会自动进入
 * 已存在任务的 `candidate_users`；需要的话用 `addCandidateActor()` 追加，
 * 或重新指派。这么做是为了让 `isAllowed()` 保持一次读表就够，不引入「每次鉴权都要
 * 展开角色」的隐式查询与租户越权风险。
 *
 * @package Alkaid\Workflow\Engine\Services
 */
class DefaultTaskActorResolver implements ITaskActorResolver
{
    use ResolvesWorkflowTenant;

    /**
     * 用户前缀：`user:5`
     */
    private const PREFIX_USER = 'user:';

    /**
     * 角色前缀：`role:admin`
     */
    private const PREFIX_ROLE = 'role:';

    /**
     * 部门前缀：`dept:3`
     */
    private const PREFIX_DEPT = 'dept:';

    /**
     * 参与者串的分隔符：逗号 / 分号 / 竖线 / 换行
     *
     * **刻意不含空格**：表达式（`${ user.id }`）里本来就有空格，
     * 按空白切分会把一条表达式切成两半，得到一个看不懂的语法错误。
     */
    private const TOKEN_SEPARATORS = '/[,;|\r\n]+/u';

    /**
     * @var ITaskActorDirectory 人员目录（宿主可替换，默认读本仓库的用户/角色表）
     */
    private readonly ITaskActorDirectory $directory;

    /**
     * @var ExpressionEngine 表达式引擎（`${...}` 求值）
     */
    private readonly ExpressionEngine $expression;

    /**
     * @param ITaskActorDirectory|null $directory  人员目录；不传则用默认实现（直接 new 也能量出可用对象，
     *                                             不依赖容器是否初始化 —— 插件独立运行时同样可用）
     * @param ExpressionEngine|null    $expression 表达式引擎；不传则新建一个
     */
    public function __construct(?ITaskActorDirectory $directory = null, ?ExpressionEngine $expression = null)
    {
        $this->directory = $directory ?? new DefaultTaskActorDirectory();
        $this->expression = $expression ?? new ExpressionEngine();

        // 求值失败必须抛（ExpressionEngine 默认会把异常吞成 null，那正是本类要避免的静默）
        $this->expression->setStrictMode(true);
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(
        TaskModel $taskModel,
        array $variables = [],
        string $scene = '',
        string|int|null $preferredAssignee = null
    ): TaskActorResolution {
        $scene = trim($scene);

        if ($scene === '') {
            $scene = '任务节点 ' . trim((string) $taskModel->getName());
        }

        // 租户上下文缺失直接抛（fail-closed，绝不回落租户 1）：解析用户/角色必须限定租户
        $tenantId = $this->tenantId($scene . ' 的参与者解析');

        $warnings = [];

        // ---- 1) assignee：首个作为处理人，其余降级为候选人 ----
        $assigneeIds = [];

        foreach ($this->tokenize($taskModel->getAssignee(), $scene . ' 的 assignee') as $token) {
            $assigneeIds = array_merge($assigneeIds, $this->resolveUserToken($token, $variables, $scene . ' 的 assignee'));
        }

        $assigneeIds = $this->uniqueInts($assigneeIds);

        // ---- 2) candidate_users ----
        $candidateIds = [];

        foreach ($this->tokenize($taskModel->getCandidateUsers(), $scene . ' 的 candidate_users') as $token) {
            $candidateIds = array_merge($candidateIds, $this->resolveUserToken($token, $variables, $scene . ' 的 candidate_users'));
        }

        // ---- 3) candidate_groups：原始引用原样保留，同时展开成用户 id ----
        $groupRefs = $this->tokenize($taskModel->getCandidateGroups(), $scene . ' 的 candidate_groups');

        foreach ($groupRefs as $groupRef) {
            $candidateIds = array_merge($candidateIds, $this->expandGroupRef($tenantId, $groupRef, $scene, $warnings));
        }

        // ---- 4) 强制处理人（驳回沿用原处理人 / 转办）----
        $preferred = null;

        if ($preferredAssignee !== null && !(is_string($preferredAssignee) && trim($preferredAssignee) === '')) {
            $preferred = $this->normalizeUserId($preferredAssignee);

            if ($preferred === null) {
                $warnings[] = sprintf(
                    '%s：强制处理人「%s」不是合法的正整数用户ID，已忽略（不会把它当成 0 号用户写库）',
                    $scene,
                    is_scalar($preferredAssignee) ? (string) $preferredAssignee : get_debug_type($preferredAssignee)
                );
            }
        }

        // 强制处理人优先于节点上的 assignee（驳回的场景是「回到原来那个人手上」）
        $assignee = $preferred ?? ($assigneeIds[0] ?? null);

        // candidate_users = assignee（全部）+ 候选人 + 强制处理人的并集
        $allCandidateIds = $this->uniqueInts(array_merge(
            $assigneeIds,
            $candidateIds,
            $preferred !== null ? [$preferred] : []
        ));

        // ---- 5) 租户内存在性校验（跨租户引用在这里被拒绝）----
        $knownNames = $this->directory->findUserNames($tenantId, $allCandidateIds);
        $rejected = array_values(array_filter(
            $allCandidateIds,
            static fn (int $id): bool => !array_key_exists($id, $knownNames)
        ));

        if ($rejected !== []) {
            $warnings[] = sprintf(
                '%s：用户 %s 在当前租户（#%d）内不存在，已从候选人中剔除。'
                . '若这些 id 属于其他租户，这属于跨租户引用 —— 已拒绝，绝不会把别的租户的用户写进本租户任务',
                $scene,
                implode(', ', $rejected),
                $tenantId
            );
        }

        $candidateIds = array_values(array_filter(
            $allCandidateIds,
            static fn (int $id): bool => array_key_exists($id, $knownNames)
        ));

        if ($assignee !== null && !array_key_exists($assignee, $knownNames)) {
            // 已在上面那条 warning 里说明原因
            $assignee = null;
        }

        return new TaskActorResolution(
            assignee: $assignee,
            assigneeName: $assignee !== null ? ($knownNames[$assignee] ?? null) : null,
            candidateUserIds: $candidateIds,
            candidateGroupRefs: $groupRefs,
            userNames: $knownNames,
            warnings: $warnings,
        );
    }

    // -----------------------------------------------------------------
    // 令牌切分
    // -----------------------------------------------------------------

    /**
     * 把配置值切成令牌列表（保留原始书写形态，不做归一化）
     *
     * @param mixed  $raw   配置值（string / array / null）
     * @param string $scene 场景描述（异常定位用）
     * @return string[]
     */
    private function tokenize(mixed $raw, string $scene): array
    {
        if ($raw === null) {
            return [];
        }

        $tokens = [];

        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            if ($item === null) {
                continue;
            }

            if (!is_scalar($item)) {
                throw new LFlowException(sprintf(
                    '%s：参与者配置里出现了 %s，只接受字符串或字符串数组',
                    $scene,
                    get_debug_type($item)
                ));
            }

            $text = trim((string) $item);

            if ($text === '') {
                continue;
            }

            // 整串就是一条表达式时**不切分**：`${[101, 102]}` 里的逗号属于表达式本身，
            // 按分隔符切开只会得到两条看不懂的残片
            if ($this->isExpression($text)) {
                $tokens[] = $text;
                continue;
            }

            foreach (preg_split(self::TOKEN_SEPARATORS, $text) ?: [] as $token) {
                $token = trim($token);

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        // 去重但保留首次出现的顺序（candidate_groups 要原样落库，顺序即配置顺序）
        return array_values(array_unique($tokens));
    }

    /**
     * 判断令牌是否是 `${...}` 表达式
     */
    private function isExpression(string $token): bool
    {
        return str_starts_with($token, '${') && str_ends_with($token, '}');
    }

    // -----------------------------------------------------------------
    // 用户令牌 -> 用户ID
    // -----------------------------------------------------------------

    /**
     * 把一个用户令牌解析成用户ID列表
     *
     * @param string $token     令牌（`5` / `user:5` / `${expr}`）
     * @param array  $variables 表达式上下文
     * @param string $scene     场景描述
     * @return int[]
     */
    private function resolveUserToken(string $token, array $variables, string $scene): array
    {
        $token = trim($token);

        if ($token === '') {
            return [];
        }

        // 1) 表达式：可返回 id 或 id 数组；失败必须抛（见类注释）
        if ($this->isExpression($token)) {
            return $this->evaluateActorExpression($token, $variables, $scene);
        }

        // 2) user: 前缀
        if (str_starts_with($token, self::PREFIX_USER)) {
            return [$this->requireUserId(substr($token, strlen(self::PREFIX_USER)), $scene, $token)];
        }

        // 3) 其它前缀：位置写错了（角色/部门该配 candidate_groups），或本仓库不认识的组
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*:/', $token, $matched) === 1) {
            throw new LFlowException(sprintf(
                '%s：参与者「%s」使用的前缀「%s:」在本位置不被支持。'
                . '按人指派只接受「数字用户ID」「user:<id>」「${表达式}」；'
                . '角色/部门请配到 candidate_ext_groups（role:<slug> / dept:<id>）',
                $scene,
                $token,
                rtrim($matched[0], ':')
            ));
        }

        // 4) 纯数字
        return [$this->requireUserId($token, $scene, $token)];
    }

    /**
     * 求值 `${...}` 表达式，结果必须是 id 或 id 数组
     *
     * @param string $token     原始令牌（含 `${}` 包裹）
     * @param array  $variables 表达式上下文（流程变量）
     * @param string $scene     场景描述
     * @return int[]
     */
    private function evaluateActorExpression(string $token, array $variables, string $scene): array
    {
        $expression = trim(substr($token, 2, -1));

        if ($expression === '') {
            throw new LFlowException(sprintf('%s：表达式「%s」的内容为空', $scene, $token));
        }

        try {
            $value = $this->expression->evaluate($expression, $variables);
        } catch (\Throwable $e) {
            throw new LFlowException(sprintf(
                '%s：表达式「%s」求值失败（%s: %s）。'
                . '求值失败必须报错，不能静默变成 null —— 否则任务会「看起来指派了、实际没人」',
                $scene,
                $token,
                get_debug_type($e),
                $e->getMessage()
            ), [], 0, $e);
        }

        if ($value === null) {
            throw new LFlowException(sprintf(
                '%s：表达式「%s」的求值结果为 null（变量未赋值？），无法确定处理人',
                $scene,
                $token
            ));
        }

        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            $id = $this->normalizeUserId($item);

            if ($id === null) {
                throw new LFlowException(sprintf(
                    '%s：表达式「%s」的求值结果里含非法用户ID（%s）。只接受正整数ID，或正整数ID数组',
                    $scene,
                    $token,
                    is_scalar($item) ? (string) $item : get_debug_type($item)
                ));
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            throw new LFlowException(sprintf('%s：表达式「%s」的求值结果是空数组，无法确定处理人', $scene, $token));
        }

        return $this->uniqueInts($ids);
    }

    /**
     * 宽松地把值收敛成用户ID（非正整数一律 null）
     */
    private function normalizeUserId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /**
     * 严格地把令牌收敛成用户ID（非法即抛）
     */
    private function requireUserId(string $raw, string $scene, string $token): int
    {
        $id = $this->normalizeUserId(trim($raw));

        if ($id === null) {
            throw new LFlowException(sprintf(
                '%s：参与者「%s」不是合法的用户ID（只接受正整数；如要按角色指派请用 role:<slug>）',
                $scene,
                $token
            ));
        }

        return $id;
    }

    // -----------------------------------------------------------------
    // 组令牌 -> 用户ID
    // -----------------------------------------------------------------

    /**
     * 展开一个候选人组引用
     *
     * @param int      $tenantId 当前租户ID
     * @param string   $ref      原始引用（`role:admin` / `dept:3` / 裸值）
     * @param string   $scene    场景描述
     * @param string[] $warnings 被拒绝/跳过的原因（引用传出）
     * @return int[]
     */
    private function expandGroupRef(int $tenantId, string $ref, string $scene, array &$warnings): array
    {
        if ($this->isExpression($ref)) {
            throw new LFlowException(sprintf(
                '%s：candidate_groups 里不支持表达式「%s」。组的取值只能是 role:<slug> / dept:<id>；'
                . '按人指派的表达式请配到 candidate_ext_users',
                $scene,
                $ref
            ));
        }

        if (str_starts_with($ref, self::PREFIX_ROLE)) {
            return $this->expandRole($tenantId, trim(substr($ref, strlen(self::PREFIX_ROLE))), $ref, $scene, $warnings);
        }

        if (str_starts_with($ref, self::PREFIX_DEPT)) {
            return $this->expandDept($tenantId, trim(substr($ref, strlen(self::PREFIX_DEPT))), $ref, $scene, $warnings);
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*:/', $ref, $matched) === 1) {
            throw new LFlowException(sprintf(
                '%s：候选人组「%s」的前缀「%s:」不被支持。只支持 role:<slug> / dept:<id>，'
                . '无前缀的裸值按 role:<值> 处理',
                $scene,
                $ref,
                rtrim($matched[0], ':')
            ));
        }

        // 裸值：本字段的语义就是「组 = 角色」（dept 必须显式写 dept: 前缀），
        // 因此按 role 处理，同时保留原始引用落库，审计时看到的仍是配置里写的那串。
        return $this->expandRole($tenantId, $ref, $ref, $scene, $warnings);
    }

    /**
     * 角色 → 本租户内的用户ID
     *
     * @param int      $tenantId 当前租户ID
     * @param string   $slug     角色 slug
     * @param string   $ref      原始引用（写进日志）
     * @param string   $scene    场景描述
     * @param string[] $warnings 引用传出
     * @return int[]
     */
    private function expandRole(int $tenantId, string $slug, string $ref, string $scene, array &$warnings): array
    {
        if ($slug === '') {
            throw new LFlowException(sprintf('%s：候选人组「%s」缺少角色标识', $scene, $ref));
        }

        $role = $this->directory->findRoleBySlug($tenantId, $slug);

        if ($role === null) {
            // 数据状态：不抛（流程照常推进），但必须留下可检索的记录
            $warnings[] = sprintf(
                '%s：候选人组「%s」在本租户（#%d）内找不到角色 slug「%s」，未展开出任何人。'
                . '若该角色属于其他租户，这是跨租户引用 —— 已拒绝，不会把别的租户的用户拉进本租户任务',
                $scene,
                $ref,
                $tenantId,
                $slug
            );

            return [];
        }

        $roleId = (int) ($role['id'] ?? 0);
        $userIds = $roleId > 0 ? $this->directory->findUserIdsByRoleId($tenantId, $roleId) : [];

        if ($userIds === []) {
            $warnings[] = sprintf(
                '%s：角色「%s」（id=%d）在本租户（#%d）内没有任何成员，候选人集为空',
                $scene,
                $slug,
                $roleId,
                $tenantId
            );
        }

        return $this->uniqueInts($userIds);
    }

    /**
     * 部门 → 本租户内的用户ID
     *
     * ⚠️ 本仓库表结构里**没有**用户与部门的关联列（`users` 没有 `dept_id`，
     * 也没有 `user_depts` 之类的关联表），因此这个前缀只能识别、不能展开。
     * 这里刻意返回空集 + WARNING，而不是编一个人出来：
     * 「编」出来的指派会让审批流在无人知晓的情况下走错人。
     *
     * @param int      $tenantId 当前租户ID
     * @param string   $rawId    部门ID原文
     * @param string   $ref      原始引用（写进日志）
     * @param string   $scene    场景描述
     * @param string[] $warnings 引用传出
     * @return int[]
     */
    private function expandDept(int $tenantId, string $rawId, string $ref, string $scene, array &$warnings): array
    {
        if ($rawId === '' || !ctype_digit($rawId) || (int) $rawId <= 0) {
            throw new LFlowException(sprintf(
                '%s：候选人组「%s」的部门ID不是正整数（应为 dept:<id>）',
                $scene,
                $ref
            ));
        }

        $deptId = (int) $rawId;
        $dept = $this->directory->findDeptById($tenantId, $deptId);

        if ($dept === null) {
            $warnings[] = sprintf(
                '%s：候选人组「%s」指向的部门在本租户（#%d）内不存在（或属于其他租户 —— 跨租户引用已拒绝）',
                $scene,
                $ref,
                $tenantId
            );

            return [];
        }

        $warnings[] = sprintf(
            '%s：候选人组「%s」已识别，但本仓库无法展开：`users` 表没有 `dept_id` 列，'
            . '也没有 users↔depts 关联表，部门「%s」（id=%d）无法变成具体人员。'
            . '请改用 role:<slug> 指派，或由宿主替换 task_actor_resolver 接入组织架构服务',
            $scene,
            $ref,
            (string) ($dept['name'] ?? ''),
            $deptId
        );

        return [];
    }

    /**
     * 整数数组去重（保留首次出现的顺序）
     *
     * @param array<int|string|mixed> $ids
     * @return int[]
     */
    private function uniqueInts(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            $normalized = $this->normalizeUserId($id);

            if ($normalized !== null && !in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }
}
