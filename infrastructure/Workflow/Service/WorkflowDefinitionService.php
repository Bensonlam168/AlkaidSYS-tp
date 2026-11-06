<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Service;

use Infrastructure\I18n\LanguageService;
use Infrastructure\Workflow\Exception\WorkflowActionException;
use Infrastructure\Workflow\Repository\WorkflowDefinitionRepository;
use Infrastructure\Workflow\Repository\WorkflowInstanceRepository;
use Infrastructure\Workflow\Repository\WorkflowTriggerRepository;
use Alkaid\Workflow\Engine\Definition\BpmnConverter;
use Alkaid\Workflow\Engine\ex\LFlowException;
use PDOException;
use Throwable;

/**
 * 工作流定义应用服务（定义控制器的唯一业务入口）
 *
 * ## 分层位置
 *
 * 控制器只做「取参 → 调本服务 → 包统一响应」；本服务负责「入参校验 + 归属校验 + 版本/状态机 + 落库」；
 * 数据访问一律走 `Infrastructure\Workflow\Repository\*`，图的可执行性一律走
 * {@see WorkflowDefinitionValidator}（真跑引擎解析器，不另写一套规则）。
 *
 * ## 版本 / 发布 / 归档的最终口径（**唯一权威，改之前先读这里**）
 *
 * 三个字段：`version`（版本号）、`status`（draft/published/archived）、`is_active`（是否可启动）。
 * 表上的唯一键是 `uk_tenant_name_version (tenant_id, name, version)` ——
 * **同一个 name 可以有多个版本并存**，这是表结构本身就允许的，不是本服务的发明。
 *
 * | status      | is_active | 含义                                             | 能启动新实例 | 能原地改 |
 * | ----------- | --------- | ------------------------------------------------ | ------------ | -------- |
 * | `draft`     | 0         | 工作副本（还没发布，或已发布的下一版正在编辑）   | ✗            | ✓        |
 * | `published` | 1         | **当前可启动的发布版本**（每个 name 至多一行）    | ✓            | ✗（自动 fork） |
 * | `published` | 0         | 已被新版本取代的历史发布版本（**行内容不动**）    | ✗            | ✗        |
 * | `archived`  | 0         | 已归档，永久停用（只为在跑实例与审计保留）       | ✗            | ✗        |
 *
 * 由此得到三条硬结论：
 *
 * 1. **新版本 = 插新行，绝不在原行改 `version`。** 这是审批系统里最典型的事故源：
 *    `workflow_instances.definition_id` 指着**某一行**，引擎启动/推进时按该行的
 *    `nodes_config` 现读现解析（`ProcessDefineService::getById()` → `ModelParser::parse()`）。
 *    如果在跑实例指着的那一行被就地改成另一张图，正在审批中的流程会中途换道 ——
 *    已经走到「apply」的实例，下一个节点突然按新图走，历史轨迹与业务预期同时失效。
 *    插新行则天然满足「在跑实例永远按它启动时那一版跑完」。
 * 2. **`publish` 只从 `draft` 进入**，并把同家族其他 `published` 行的 `is_active` 置 0。
 *    老版本的 `status` **保持 `published`**（它确实发布过），只是不再可启动 ——
 *    把它改成 `archived` 会让「这条实例基于哪一版」失去解释，也会把「被新版本取代」
 *    与「被管理员显式归档」两件事混成一个状态。
 * 3. **`archive` 是终态**：归档后 `is_active=0` → 启动实例一律被拒
 *    （`WorkflowInstanceService::start()` 的闸，实测见交付报告）。
 *    `PUT` 已归档定义 → 400；要改就 `clone` 出一条新的。
 *
 * ## 为什么 `PUT` 已发布定义会「fork 出一条草稿」
 *
 * 直接拒（旧 Mock 的 `cannot_update_published`）等于这条流程永远没法演进到 v2；
 * 就地改又违反上面第 1 条。因此 `PUT` 已发布行的语义是「基于它产出一份可编辑的工作副本」：
 * - 若家族里已有**更高版本的草稿**，直接编辑那份（连续编辑不会产出 v2/v3/v4 空壳）；
 * - 否则新建 `version = max + 1` 的草稿行，把补丁打在它上面；
 * - 响应是 **201 + 新行**（body 里的 `id` / `version` / `status` 说明一切），
 *   并在 `data.forked_from` 里给出源版本 id —— 客户端不会误以为「改的就是原来那一行」。
 *
 * ## 删除为什么比 Mock 严格
 *
 * `workflow_instances.definition_id` 是 **`ON DELETE CASCADE`**
 * （information_schema.REFERENTIAL_CONSTRAINTS 实测，见交付报告）。
 * 物理删除一个有实例的定义会连带删掉实例、任务、轨迹 —— 那是不可恢复的数据丢失，
 * 而旧 Mock 只是 `unset($array[$id])`。因此：
 * - `published` 一律拒绝（先归档）；
 * - 有任一实例（任何状态）一律拒绝，并指出「改用归档」。
 *
 * @package Infrastructure\Workflow\Service
 */
class WorkflowDefinitionService
{
    private ?LanguageService $lang;

    private ?WorkflowDefinitionRepository $definitions;

    private ?WorkflowInstanceRepository $instances;

    private ?WorkflowTriggerRepository $triggers;

    private ?WorkflowDefinitionValidator $validator;

    /**
     * @param LanguageService|null               $lang        语言服务（控制器传入同一实例；null 时惰性新建）
     * @param WorkflowDefinitionRepository|null  $definitions 定义仓储
     * @param WorkflowInstanceRepository|null    $instances   实例仓储（删除前的引用检查）
     * @param WorkflowTriggerRepository|null     $triggers    触发器仓储（删除前的引用统计）
     * @param WorkflowDefinitionValidator|null   $validator   定义体校验器
     */
    public function __construct(
        ?LanguageService $lang = null,
        ?WorkflowDefinitionRepository $definitions = null,
        ?WorkflowInstanceRepository $instances = null,
        ?WorkflowTriggerRepository $triggers = null,
        ?WorkflowDefinitionValidator $validator = null
    ) {
        $this->lang        = $lang;
        $this->definitions = $definitions;
        $this->instances   = $instances;
        $this->triggers    = $triggers;
        $this->validator   = $validator;
    }

    // =====================================================================
    // 读
    // =====================================================================

    /**
     * 定义列表（强制租户过滤 + 分页下推）
     *
     * @param array<string, mixed> $filters status / type / category / name / active
     * @return array{list: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $tenantId, array $filters, int $page, int $pageSize): array
    {
        $result = $this->definitions()->search($tenantId, $filters, $page, $pageSize);

        return [
            'list'  => array_map(fn (array $row): array => $this->presentSummary($row), $result['list']),
            'total' => $result['total'],
        ];
    }

    /**
     * 定义详情（含定义体）
     *
     * @return array<string, mixed>
     */
    public function detail(int $tenantId, int $id): array
    {
        return $this->presentDetail($this->requireOwned($tenantId, $id));
    }

    // =====================================================================
    // 写
    // =====================================================================

    /**
     * 创建定义（永远创建 `version = 1` 的草稿）
     *
     * 为什么不允许客户端指定 `version`：版本号是「同一 name 的演进次序」，
     * 由服务端单调分配才有意义；让客户端填等于把唯一键冲突留给别人去撞。
     *
     * @param array<string, mixed> $payload name / display_name / description / type / category / icon /
     *                                      nodes_config / bpmn_xml / triggers_config / variables_schema
     * @return array<string, mixed>
     */
    public function create(int $tenantId, int $userId, array $payload): array
    {
        $name = $this->normalizeName($payload['name'] ?? null);

        if ($this->definitions()->existsName($tenantId, $name)) {
            throw WorkflowActionException::conflicting(
                sprintf('名称「%s」在本租户下已存在（含历史版本），请换名或对已有定义执行 PUT 生成新版本 | name already exists in this tenant', $name),
                ['name' => $name]
            );
        }

        $row = [
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'display_name' => $this->normalizeDisplayName($payload['display_name'] ?? null, $name),
            'description'  => $this->normalizeNullableString($payload['description'] ?? null),
            'type'         => $this->normalizeType($payload['type'] ?? null),
            'category'     => $this->normalizeNullableString($payload['category'] ?? null),
            'icon'         => $this->normalizeNullableString($payload['icon'] ?? null),
            'version'      => 1,
            // 新定义一定是草稿且不可启动：草稿没有任何发布审核，直接可启动等于绕过发布门
            'status'       => WorkflowDefinitionRepository::STATUS_DRAFT,
            'is_active'    => 0,
            'created_by'   => $userId,
            'updated_by'   => $userId,
        ];

        // 定义体：优先 LogicFlow（唯一执行格式），其次 BPMN 导入
        [$graph, $bpmnXml] = $this->resolveBody($payload);

        if ($graph !== null) {
            $row['nodes_config'] = $graph;
        }

        if ($bpmnXml !== null) {
            $row['bpmn_xml'] = $bpmnXml;
        }

        foreach (['triggers_config', 'variables_schema'] as $column) {
            $value = $payload[$column] ?? null;

            if ($value === null) {
                continue;
            }

            if (!is_array($value)) {
                throw WorkflowActionException::invalid([$column => $this->t('workflow.validation_failed')]);
            }

            $row[$column] = $value;
        }

        $id = $this->insertOrConflict($row, $name);

        return $this->presentDetail($this->requireOwned($tenantId, $id));
    }

    /**
     * 更新定义（草稿原地改；已发布 fork 出新版本草稿；已归档拒绝）
     *
     * @param array<string, mixed> $payload 允许的键同 {@see create()}
     * @return array{row: array<string, mixed>, forked: bool, source_id: int|null}
     */
    public function update(int $tenantId, int $userId, int $id, array $payload): array
    {
        $current = $this->requireOwned($tenantId, $id);

        if ((string) $current['status'] === WorkflowDefinitionRepository::STATUS_ARCHIVED) {
            throw WorkflowActionException::conflict(
                '该定义已归档（终态），不能修改；如需继续演进请先克隆 | archived definitions are immutable, clone it instead',
                ['current_status' => (string) $current['status']]
            );
        }

        $patch = $this->buildPatch($payload);

        if ($patch === []) {
            throw WorkflowActionException::invalid([
                'payload' => '没有可更新的字段 | no updatable field provided',
            ]);
        }

        $patch['updated_by'] = $userId;

        if ((string) $current['status'] === WorkflowDefinitionRepository::STATUS_PUBLISHED) {
            $target = $this->resolveEditableCopy($tenantId, $userId, $current, $patch);

            return [
                'row'       => $this->presentDetail($target),
                'forked'    => true,
                'source_id' => (int) $current['id'],
            ];
        }

        $this->definitions()->updateForTenant($tenantId, (int) $current['id'], $patch);

        return [
            'row'       => $this->presentDetail($this->requireOwned($tenantId, (int) $current['id'])),
            'forked'    => false,
            'source_id' => null,
        ];
    }

    /**
     * 删除定义（已发布 / 有实例一律拒绝，见类注释）
     *
     * @return array{deleted_id: int, deleted_triggers: int}
     */
    public function delete(int $tenantId, int $id): array
    {
        $current = $this->requireOwned($tenantId, $id);

        if ((string) $current['status'] === WorkflowDefinitionRepository::STATUS_PUBLISHED) {
            throw WorkflowActionException::conflict(
                $this->t('workflow.cannot_delete_published') . ' | archive it first',
                ['current_status' => (string) $current['status']]
            );
        }

        $instanceCount = $this->instances()->countByDefinition((int) $current['id']);

        if ($instanceCount > 0) {
            throw WorkflowActionException::conflicting(
                sprintf(
                    '该定义已被 %d 个流程实例引用，删除会级联删除实例、任务与轨迹（'
                    . 'workflow_instances.definition_id 为 ON DELETE CASCADE），请改用归档 | '
                    . 'definition is referenced by %d instance(s); archive it instead',
                    $instanceCount,
                    $instanceCount
                ),
                ['instance_count' => $instanceCount]
            );
        }

        // 触发器随定义 `ON DELETE CASCADE` 一起消失：没有定义，触发器没有语义。
        // 先数一遍是为了把「删掉了几个触发器」如实回报，而不是让调用方去猜。
        $triggerCount = $this->triggers()->countByDefinition($tenantId, (int) $current['id']);

        $this->definitions()->deleteForTenant($tenantId, (int) $current['id']);

        return ['deleted_id' => (int) $current['id'], 'deleted_triggers' => $triggerCount];
    }

    /**
     * 发布（draft → published + is_active=1，同家族旧版本降为不可启动）
     *
     * @return array<string, mixed> 发布后的定义行
     */
    public function publish(int $tenantId, int $userId, int $id): array
    {
        $current = $this->requireOwned($tenantId, $id);

        if ((string) $current['status'] !== WorkflowDefinitionRepository::STATUS_DRAFT) {
            throw WorkflowActionException::conflict(
                '只有草稿可以发布：当前状态为「' . (string) $current['status'] . '」 | only drafts can be published',
                ['current_status' => (string) $current['status']]
            );
        }

        // 发布前必须过引擎校验：一条「能存进库但引擎跑不起来」的发布版本
        // 等于把一个 500 留给所有启动实例的调用方。
        $report = $this->validator()->validateRow($current);

        if (!$report['valid']) {
            throw WorkflowActionException::conflict(
                '定义未通过引擎校验，不能发布 | definition failed engine validation',
                [
                    'issues'  => $report['issues'],
                    'summary' => $report['summary'],
                ]
            );
        }

        $this->definitions()->updateForTenant($tenantId, (int) $current['id'], [
            'status'     => WorkflowDefinitionRepository::STATUS_PUBLISHED,
            'is_active'  => 1,
            'updated_by' => $userId,
        ]);

        $this->definitions()->demotePublishedSiblings($tenantId, (string) $current['name'], (int) $current['id']);

        return $this->presentDetail($this->requireOwned($tenantId, (int) $current['id']));
    }

    /**
     * 归档（终态：status=archived + is_active=0，之后不可启动、不可编辑）
     *
     * @return array<string, mixed>
     */
    public function archive(int $tenantId, int $userId, int $id): array
    {
        $current = $this->requireOwned($tenantId, $id);

        if ((string) $current['status'] === WorkflowDefinitionRepository::STATUS_ARCHIVED) {
            throw WorkflowActionException::conflict(
                '该定义已归档（终态），不能重复归档 | definition is already archived',
                ['current_status' => (string) $current['status']]
            );
        }

        $this->definitions()->updateForTenant($tenantId, (int) $current['id'], [
            'status'     => WorkflowDefinitionRepository::STATUS_ARCHIVED,
            'is_active'  => 0,
            'updated_by' => $userId,
        ]);

        return $this->presentDetail($this->requireOwned($tenantId, (int) $current['id']));
    }

    /**
     * 校验定义体（真跑引擎解析器，返回结构化 issues）
     *
     * 为什么校验失败也返回 200：本端点是**查询式**的报告（前端设计器要拿到 issues 逐条高亮），
     * 不是命令式动作。真正「拿校验当门」的是 `publish`，那里校验失败返回 400。
     *
     * @return array<string, mixed>
     */
    public function validate(int $tenantId, int $id): array
    {
        $current = $this->requireOwned($tenantId, $id);
        $report  = $this->validator()->validateRow($current);

        $report['definition_id'] = (int) $current['id'];
        $report['name']          = (string) $current['name'];
        $report['version']       = (int) $current['version'];
        $report['status']        = (string) $current['status'];

        return $report;
    }

    /**
     * 克隆（新 name、version=1 的草稿；复制定义体，不复制触发器）
     *
     * 为什么不连触发器一起复制：触发器挂的是 `definition_id`，而克隆出来的是**草稿**
     * （不可启动）。复制一个 active 的 webhook 触发器指向草稿，会让外部回调直接 400 ——
     * 那是一个「看起来配好了、其实一调就错」的配置。要加触发器请在发布后单独创建。
     *
     * @param array<string, mixed> $payload name / display_name
     * @return array<string, mixed>
     */
    public function clone(int $tenantId, int $userId, int $id, array $payload): array
    {
        $source = $this->requireOwned($tenantId, $id);

        $name = $this->normalizeName(
            $payload['name'] ?? ($source['name'] . '_copy'),
            'name'
        );

        if ($this->definitions()->existsName($tenantId, $name)) {
            throw WorkflowActionException::conflicting(
                sprintf('名称「%s」在本租户下已存在，无法克隆 | name already exists in this tenant', $name),
                ['name' => $name]
            );
        }

        $row = [
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'display_name' => $this->normalizeDisplayName(
                $payload['display_name'] ?? null,
                (string) $source['display_name'] . ' (Copy)'
            ),
            'description'  => $source['description'] ?? null,
            'type'         => (string) $source['type'],
            'category'     => $source['category'] ?? null,
            'icon'         => $source['icon'] ?? null,
            'version'      => 1,
            'status'       => WorkflowDefinitionRepository::STATUS_DRAFT,
            'is_active'    => 0,
            'created_by'   => $userId,
            'updated_by'   => $userId,
        ];

        // 复制定义体与配置（json 列已由仓储解码成数组，这里原样带走）
        foreach (WorkflowDefinitionRepository::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $source) && $source[$column] !== null) {
                $row[$column] = $source[$column];
            }
        }

        // bpmn_xml 是纯文本列，不是 json 列
        if (isset($source['bpmn_xml']) && $source['bpmn_xml'] !== null) {
            $row['bpmn_xml'] = (string) $source['bpmn_xml'];
        }

        $newId = $this->insertOrConflict($row, $name);

        return $this->presentDetail($this->requireOwned($tenantId, $newId));
    }

    // =====================================================================
    // 内部：入参归一化 / 校验
    // =====================================================================

    /**
     * 读取并校验租户归属（404 / 403 严格区分）
     *
     * @return array<string, mixed>
     */
    private function requireOwned(int $tenantId, int $id): array
    {
        if ($id <= 0) {
            throw WorkflowActionException::notFound($this->t('workflow.definition_not_found'));
        }

        $row = $this->definitions()->findByIdUnscoped($id);

        if ($row === null) {
            throw WorkflowActionException::notFound($this->t('workflow.definition_not_found'));
        }

        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId) {
            // 存在但归属别的租户：403（不是 404 —— 不能把「存在性」也藏起来）
            throw WorkflowActionException::forbidden($this->t('workflow.access_denied'));
        }

        return $row;
    }

    /**
     * 定义名归一化 + 格式校验
     *
     * @param mixed $raw 原始值
     */
    private function normalizeName(mixed $raw, string $field = 'name'): string
    {
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            throw WorkflowActionException::invalid([$field => $this->t('workflow.name_required')]);
        }

        $name = trim((string) $raw);

        if (preg_match(WorkflowDefinitionRepository::NAME_PATTERN, $name) !== 1) {
            throw WorkflowActionException::invalid([$field => $this->t('workflow.invalid_name_format')]);
        }

        if (mb_strlen($name) > 100) {
            throw WorkflowActionException::invalid([$field => 'name 长度不能超过 100 | name too long']);
        }

        return $name;
    }

    /**
     * 显示名归一化（空 → 回落到 name；列宽 200）
     */
    private function normalizeDisplayName(mixed $raw, string $fallback): string
    {
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return mb_substr($fallback, 0, 200);
        }

        return mb_substr(trim((string) $raw), 0, 200);
    }

    /**
     * 可空字符串列归一化（`''` → null，避免「空串与 NULL 混用」）
     */
    private function normalizeNullableString(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * 类型归一化：未给 → approval（列默认值）；给了但不在取值域 → 422
     */
    private function normalizeType(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return 'approval';
        }

        $type = is_scalar($raw) ? trim((string) $raw) : '';

        if (!in_array($type, WorkflowDefinitionRepository::TYPES, true)) {
            throw WorkflowActionException::invalid([
                'type' => $this->t('workflow.invalid_type')
                    . '（' . implode('/', WorkflowDefinitionRepository::TYPES) . '）',
            ]);
        }

        return $type;
    }

    /**
     * 解析请求体里的定义体（LogicFlow 优先；其次 BPMN 导入）
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>|null, 1: string|null} [graph, bpmnXml]
     */
    private function resolveBody(array $payload): array
    {
        $graph   = null;
        $bpmnXml = null;

        if (array_key_exists('nodes_config', $payload) && $payload['nodes_config'] !== null) {
            $raw = $payload['nodes_config'];

            if (!is_array($raw)) {
                throw WorkflowActionException::invalid([
                    'nodes_config' => '节点配置必须是 LogicFlow 对象 {nodes: [...], edges: [...]} | nodes_config must be a LogicFlow object',
                ]);
            }

            $graph = $raw;
        }

        if (array_key_exists('bpmn_xml', $payload) && is_scalar($payload['bpmn_xml'])) {
            $xml = trim((string) $payload['bpmn_xml']);
            $bpmnXml = $xml === '' ? null : $xml;
        }

        // 只给了 BPMN：立刻导入成 LogicFlow。导入失败必须当场拒绝 ——
        // 静默存下一份「只有 bpmn_xml、没有可执行图」的定义，等于把错误推迟到发布/启动时。
        if ($graph === null && $bpmnXml !== null) {
            $graph = $this->importBpmnGraph($bpmnXml);
        }

        return [$graph, $bpmnXml];
    }

    /**
     * BPMN XML → LogicFlow 图（导入失败时抛 422，消息里带上具体不支持的 BPMN 元素）
     *
     * @return array<string, mixed>
     */
    private function importBpmnGraph(string $bpmnXml): array
    {
        try {
            return (new BpmnConverter())->fromBpmn($bpmnXml);
        } catch (LFlowException $e) {
            // BpmnConversionException 继承 LFlowException，消息本身已说清是哪个元素不支持
            throw WorkflowActionException::invalid([
                'bpmn_xml' => 'BPMN 导入失败：' . $e->getMessage() . ' | BPMN import failed',
            ]);
        } catch (Throwable $e) {
            throw WorkflowActionException::internal('导入 BPMN 定义体', $e);
        }
    }

    /**
     * 从请求体组装「可更新字段」补丁（只收白名单键）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildPatch(array $payload): array
    {
        $patch = [];

        if (array_key_exists('display_name', $payload)) {
            $displayName = $payload['display_name'];

            // display_name 列是 NOT NULL：不允许通过 PUT 把它清空
            if (!is_scalar($displayName) || trim((string) $displayName) === '') {
                throw WorkflowActionException::invalid([
                    'display_name' => 'display_name 不能为空 | display_name must not be empty',
                ]);
            }

            $patch['display_name'] = $this->normalizeDisplayName($displayName, '');
        }

        if (array_key_exists('description', $payload)) {
            $patch['description'] = $this->normalizeNullableString($payload['description']);
        }

        if (array_key_exists('type', $payload)) {
            $patch['type'] = $this->normalizeType($payload['type']);
        }

        if (array_key_exists('category', $payload)) {
            $patch['category'] = $this->normalizeNullableString($payload['category']);
        }

        if (array_key_exists('icon', $payload)) {
            $patch['icon'] = $this->normalizeNullableString($payload['icon']);
        }

        [$graph, $bpmnXml] = $this->resolveBody($payload);

        if ($graph !== null) {
            $patch['nodes_config'] = $graph;
        }

        if ($bpmnXml !== null) {
            $patch['bpmn_xml'] = $bpmnXml;
        }

        foreach (['triggers_config', 'variables_schema'] as $column) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            if (!is_array($payload[$column])) {
                throw WorkflowActionException::invalid([$column => $this->t('workflow.validation_failed')]);
            }

            $patch[$column] = $payload[$column];
        }

        return $patch;
    }

    /**
     * 为已发布定义产出（或复用）一份可编辑副本
     *
     * @param array<string, mixed> $current 已发布的源行
     * @param array<string, mixed> $patch   待应用的补丁
     * @return array<string, mixed> 编辑后的副本行
     */
    private function resolveEditableCopy(int $tenantId, int $userId, array $current, array $patch): array
    {
        $name   = (string) $current['name'];
        $draft  = $this->definitions()->findLatestDraft($tenantId, $name);

        // 已有更高版本的草稿：直接编辑它（连续编辑不产出 v2/v3/v4 空壳）
        if ($draft !== null && (int) $draft['version'] > (int) $current['version']) {
            $this->definitions()->updateForTenant($tenantId, (int) $draft['id'], $patch);

            return $this->requireOwned($tenantId, (int) $draft['id']);
        }

        // 否则从源行 fork 出一份新草稿：内容整体复制，再叠加补丁
        $row = [
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'display_name' => (string) $current['display_name'],
            'description'  => $current['description'] ?? null,
            'type'         => (string) $current['type'],
            'category'     => $current['category'] ?? null,
            'icon'         => $current['icon'] ?? null,
            'version'      => $this->definitions()->maxVersion($tenantId, $name) + 1,
            'status'       => WorkflowDefinitionRepository::STATUS_DRAFT,
            'is_active'    => 0,
            'created_by'   => $userId,
            'updated_by'   => $userId,
        ];

        foreach (WorkflowDefinitionRepository::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $current) && $current[$column] !== null) {
                $row[$column] = $current[$column];
            }
        }

        if (isset($current['bpmn_xml']) && $current['bpmn_xml'] !== null) {
            $row['bpmn_xml'] = (string) $current['bpmn_xml'];
        }

        foreach ($patch as $key => $value) {
            $row[$key] = $value;
        }

        // 并发 fork 可能撞 (tenant,name,version) 唯一键：翻译成 409，让调用方重试一次
        $newId = $this->insertOrConflict($row, $name);

        return $this->requireOwned($tenantId, $newId);
    }

    /**
     * 插入并把唯一键冲突翻译成 409
     *
     * 为什么必须靠数据库而不是「先查后写」：两个并发请求在 `existsName()` 与 `insert()`
     * 之间都能通过检查，只有唯一索引能真正拦住第二个。
     *
     * ⚠️ 不能只 `catch (\PDOException)`：ThinkPHP 把 PDO 异常**重新封装**成
     * `think\db\exception\PDOException`（它继承 `DbException` → `think\Exception` →
     * `RuntimeException`，**不是** `\PDOException` 的子类），原生异常只作为 `Database Status`
     * 数据挂在异常对象上。因此这里 catch `Throwable` 再按 SQLSTATE / MySQL 1062 判定。
     *
     * @param array<string, mixed> $row
     * @return int 新行主键
     */
    private function insertOrConflict(array $row, string $name): int
    {
        try {
            return $this->definitions()->insert($row);
        } catch (Throwable $e) {
            if ($this->isDuplicateKey($e)) {
                throw WorkflowActionException::conflicting(
                    sprintf(
                        '定义「%s」的 (tenant_id, name, version) 已存在（并发写入或版本号冲突），请重试 | '
                        . 'duplicate key (tenant_id, name, version)',
                        $name
                    ),
                    ['name' => $name]
                );
            }

            throw WorkflowActionException::internal('保存工作流定义', $e);
        }
    }

    /**
     * 判定异常是否为「唯一键冲突」（SQLSTATE 23000 / MySQL 1062）
     *
     * 只看 SQLSTATE 会漏：ThinkPHP 的封装异常 `getCode()` 是它自己的 10501，
     * 真正的 SQLSTATE 在 `getData()['PDO Error Info']` 里；某些路径下又只剩消息文本，
     * 因此三者都认。
     */
    private function isDuplicateKey(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

            if ($sqlState === '23000') {
                return true;
            }
        }

        if (method_exists($e, 'getData')) {
            try {
                $data = $e->getData();
                $info = is_array($data['PDO Error Info'] ?? null) ? $data['PDO Error Info'] : [];

                if ((string) ($info['SQLSTATE'] ?? '') === '23000') {
                    return true;
                }
            } catch (Throwable) {
                // getData 不可用：退化为消息文本判定
            }
        }

        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), '1062');
    }

    // =====================================================================
    // 内部：输出
    // =====================================================================

    /**
     * 列表行（不含定义体，避免列表接口背几百 KB 的图）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSummary(array $row): array
    {
        $graph = is_array($row['nodes_config'] ?? null) ? $row['nodes_config'] : [];

        return [
            'id'           => (int) $row['id'],
            'tenant_id'    => (int) $row['tenant_id'],
            'name'         => (string) $row['name'],
            'display_name' => (string) $row['display_name'],
            'description'  => $row['description'] ?? null,
            'type'         => (string) $row['type'],
            'category'     => $row['category'] ?? null,
            'icon'         => $row['icon'] ?? null,
            'version'      => (int) $row['version'],
            'status'       => (string) $row['status'],
            'is_active'    => (int) ($row['is_active'] ?? 0),
            'is_startable' => WorkflowDefinitionRepository::isStartable($row),
            'has_graph'    => WorkflowDefinitionRepository::isExecutable($row),
            'node_count'   => is_array($graph['nodes'] ?? null) ? count($graph['nodes']) : 0,
            'edge_count'   => is_array($graph['edges'] ?? null) ? count($graph['edges']) : 0,
            'created_at'   => $row['created_at'] ?? null,
            'updated_at'   => $row['updated_at'] ?? null,
            'created_by'   => $row['created_by'] ?? null,
            'updated_by'   => $row['updated_by'] ?? null,
        ];
    }

    /**
     * 详情行（含定义体与触发器/变量配置）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentDetail(array $row): array
    {
        $graph = is_array($row['nodes_config'] ?? null) ? $row['nodes_config'] : null;

        return array_merge($this->presentSummary($row), [
            // 唯一执行格式：LogicFlow JSON（见 LogicFlowSerializer 的类注释）
            'nodes_config'     => $graph,
            'bpmn_xml'         => $row['bpmn_xml'] ?? null,
            'triggers_config'  => is_array($row['triggers_config'] ?? null) ? $row['triggers_config'] : [],
            'variables_schema' => is_array($row['variables_schema'] ?? null) ? $row['variables_schema'] : [],
        ]);
    }

    // =====================================================================
    // 依赖
    // =====================================================================

    private function t(string $key): string
    {
        $this->lang ??= new LanguageService();

        return $this->lang->trans($key);
    }

    private function definitions(): WorkflowDefinitionRepository
    {
        return $this->definitions ??= new WorkflowDefinitionRepository();
    }

    private function instances(): WorkflowInstanceRepository
    {
        return $this->instances ??= new WorkflowInstanceRepository();
    }

    private function triggers(): WorkflowTriggerRepository
    {
        return $this->triggers ??= new WorkflowTriggerRepository();
    }

    private function validator(): WorkflowDefinitionValidator
    {
        return $this->validator ??= new WorkflowDefinitionValidator();
    }
}
