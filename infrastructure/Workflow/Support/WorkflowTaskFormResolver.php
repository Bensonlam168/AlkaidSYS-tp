<?php

declare(strict_types=1);

namespace Infrastructure\Workflow\Support;

use Infrastructure\Lowcode\FormDesigner\Repository\FormSchemaRepository;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorGenerator;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use Infrastructure\Lowcode\Schema\SchemaParser;
use Infrastructure\Workflow\Repository\WorkflowDefinitionRepository;
use Infrastructure\Workflow\Repository\WorkflowInstanceRepository;

/**
 * 任务表单解析器（把「任务」解析成「可渲染的表单」）
 *
 * ## 为什么需要它
 *
 * `workflow_tasks` 表**没有** `form_schema` / `form_rules` 列（表结构事实见
 * `WorkflowTaskService` 的类注释），表单模板属于流程定义体上的配置。
 * 改造前 `GET /v1/workflow/tasks/{id}/form` 恒返回 `form_schema: null` + `form_rules: []`，
 * 前端拿不到任何可渲染结构 —— 审批界面只能靠前端自己猜字段，等于「表单功能上线即死」。
 *
 * ## 解析链（唯一权威口径）
 *
 * ```
 * workflow_tasks 行
 *   └─ instance_id ──→ workflow_instances.definition_id   ← 指向**具体版本行**
 *        └─→ workflow_definitions.nodes_config.nodes[]
 *             └─ 按 task.node_id（缺省回退 node_name）找到节点
 *                  └─ 读 properties.form（表单**标识**，字符串，不是内联 schema）
 *                       └─→ lowcode_forms（按 name + tenant_id）
 *                            ├─ schema → SchemaParser → components（= form_schema）
 *                            └─ schema → FormValidatorManager → {rules, messages}
 * ```
 *
 * ### 版本正确性（这是整条链最关键的性质）
 *
 * 实例的 `definition_id` 指向**具体版本行**（`workflow_definitions` 的唯一键是
 * `(tenant_id, name, version)`），发布新版本会**新增一行**而不是原地改旧行
 * （见 `WorkflowDefinitionService` 的版本策略）。因此「在跑实例读到的表单模板」
 * 天然锁在它启动时那一版定义上：定义发了 v2 把 `properties.form` 换成另一张表单，
 * 老实例的审批界面**不会**跟着变。测试
 * `tests/Feature/Workflow/WorkflowTaskFormResolutionTest::testFormTemplateStaysOnTheInstanceVersion`
 * 正面钉住这条性质（它同时也是「前端不会在流程走到一半时突然换掉表单」的保证）。
 *
 * ### `properties.form` 与 `properties.ext.form` 的优先级
 *
 * **`properties.form` 优先，`properties.ext.form` 仅作兼容回退。**
 * 依据是引擎自己的解析实现：`TaskParser::parseNode()` 只读
 * `$properties->get(INodeParser::FORM_KEY)`（`FORM_KEY = 'form'`，注释即「表单标识」），
 * 它对 `ext` 的**回退取值只覆盖参与者/会签那几项**（`candidate_ext_users` / `candidate_ext_groups` /
 * `candidate_ext_handler` / `countersign_*`），`form` **没有** ext 回退。
 * 也就是说：只写 `ext.form` 的定义，引擎侧 `TaskModel::$form` 仍然是空 ——
 * 宿主若把 `ext.form` 当主入口，就会造出「引擎没认、宿主认了」的第二套真相。
 * 因此这里的口径是「以引擎为准，ext 只做兼容」：读到了会在返回值里如实带上 `form_name`，
 * 但排序永远是 `properties.form` 在前。
 *
 * ### 为什么 `site_id` 传 null（不按站点过滤）
 *
 * `workflow_definitions` **没有** `site_id` 列，工作流定义本身不区分站点。
 * 若表单查找按请求里的 `site_id` 过滤，同一个任务会因为客户端带了不同的
 * `X-Site-ID` 而渲染出不同结果（甚至查不到）—— 那是把「站点」错误地混进
 * 「流程版本」这条已经确定的时间线。`lowcode_forms` 的唯一键是
 * `(tenant_id, name)`，同租户下同名表单只有一行，故按租户读是完备的。
 *
 * ## 返回契约：「没配」与「配了但找不到」必须可区分
 *
 * 三者都不允许静默返回 `null`：
 *
 * | reason | 含义 | 谁的问题 |
 * | ------ | ---- | -------- |
 * | `node_has_no_form` | 节点上没配表单标识（正常形态：审批/复核节点通常不需要填写表单） | 设计如此，前端应退化成纯审批界面 |
 * | `form_not_found` | 节点配了表单名，但本租户下没有这张表单 | 配置错误，需要修定义或建表单 |
 * | `definition_not_found` | 实例指向的定义行已不存在 | 数据不一致（见下方说明） |
 * | `instance_not_found` | 实例行缺失 | 数据不一致 |
 * | `node_not_found` | 定义里找不到该任务对应的节点 | 数据不一致（任务来自另一版定义 / 定义被原地改过） |
 *
 * 后三个是**纵深防御**分支：外键（`ON DELETE CASCADE`）正常时走不到，
 * 但「跨库恢复」「绕过外键的运维操作」会让它们真的发生 —— 那时前端需要的是
 * 一个明确原因，而不是一个空的表单。
 *
 * @package Infrastructure\Workflow\Support
 */
final class WorkflowTaskFormResolver
{
    /**
     * 未解出原因：节点上没有配置表单标识
     */
    public const REASON_NODE_HAS_NO_FORM = 'node_has_no_form';

    /**
     * 未解出原因：节点配置了表单标识，但本租户下不存在该表单
     */
    public const REASON_FORM_NOT_FOUND = 'form_not_found';

    /**
     * 未解出原因：实例指向的定义行已不存在
     */
    public const REASON_DEFINITION_NOT_FOUND = 'definition_not_found';

    /**
     * 未解出原因：实例行不存在
     */
    public const REASON_INSTANCE_NOT_FOUND = 'instance_not_found';

    /**
     * 未解出原因：定义里找不到任务对应的节点
     */
    public const REASON_NODE_NOT_FOUND = 'node_not_found';

    /**
     * 引擎口径的表单标识键（`Alkaid\Workflow\Engine\parser\INodeParser::FORM_KEY`）
     *
     * 刻意写字面量而不是 `use` 引擎常量：本仓库约定宿主层不直接依赖插件内部符号
     * （`infrastructure/Workflow` 只经 `WorkflowEngineBootstrap` 触碰引擎接口），
     * 且该常量值由插件注释与 `TaskParser` 双重印证，改它属于引擎破坏性变更。
     */
    private const NODE_FORM_KEY = 'form';

    /**
     * 兼容回退：节点自定义扩展属性容器
     */
    private const NODE_EXT_KEY = 'ext';

    private ?WorkflowInstanceRepository $instances;

    private ?WorkflowDefinitionRepository $definitions;

    private ?FormSchemaRepository $forms;

    private ?FormValidatorManager $validators;

    private ?SchemaParser $parser;

    /**
     * @param WorkflowInstanceRepository|null   $instances   实例仓储（取 `definition_id`）
     * @param WorkflowDefinitionRepository|null $definitions 定义仓储（取版本行上的 `nodes_config`）
     * @param FormSchemaRepository|null         $forms       低代码表单仓储（按 name + tenant 读 schema）
     * @param FormValidatorManager|null         $validators  校验器管理器（与 `/v1/lowcode/forms/{name}` 同一个）
     * @param SchemaParser|null                 $parser      Schema 解析器（产出 components）
     */
    public function __construct(
        ?WorkflowInstanceRepository $instances = null,
        ?WorkflowDefinitionRepository $definitions = null,
        ?FormSchemaRepository $forms = null,
        ?FormValidatorManager $validators = null,
        ?SchemaParser $parser = null
    ) {
        $this->instances   = $instances;
        $this->definitions = $definitions;
        $this->forms       = $forms;
        $this->validators  = $validators;
        $this->parser      = $parser;
    }

    /**
     * 解析任务对应的表单
     *
     * @param int                  $tenantId 当前租户（**必须**是请求上下文里的租户，不是任务行里的）
     * @param array<string, mixed> $task     任务行（调用方必须已完成租户归属校验：404 / 403 已区分）
     * @return array{
     *     form_name: string|null,
     *     form_schema: array<int, array<string, mixed>>|null,
     *     form_rules: array<string, mixed>,
     *     form_messages: array<string, mixed>,
     *     resolved: bool,
     *     reason: string|null
     * }
     */
    public function resolve(int $tenantId, array $task): array
    {
        // 1）任务所属实例：`definition_id` 就在这一行上
        $instanceId = (int) ($task['instance_id'] ?? 0);

        $instance = $instanceId > 0 ? $this->instances()->findForTenant($tenantId, $instanceId) : null;

        if ($instance === null) {
            return $this->unresolved(self::REASON_INSTANCE_NOT_FOUND);
        }

        // 2）实例指向的**版本行**：版本正确性的来源，绝不按 name 去取「最新版本」
        $definitionId = (int) ($instance['definition_id'] ?? 0);

        $definition = $definitionId > 0 ? $this->definitions()->findForTenant($tenantId, $definitionId) : null;

        if ($definition === null) {
            // 行被物理删除（正常有外键 CASCADE 会连带删实例，故只可能是异常运维/恢复），
            // 或该定义行属于别的租户（那也不能读：表单查找本身按 tenant 过滤，读不到别人的表单）
            return $this->unresolved(self::REASON_DEFINITION_NOT_FOUND);
        }

        // 3）在定义体的图里找任务所在节点，读它配置的表单标识
        [$formName, $nodeFound] = $this->locateFormName($definition, $task);

        if (!$nodeFound) {
            return $this->unresolved(self::REASON_NODE_NOT_FOUND);
        }

        if ($formName === null) {
            return $this->unresolved(self::REASON_NODE_HAS_NO_FORM);
        }

        // 4）按「表单标识 + 租户」取表单（site 口径见类注释）
        $form = $this->forms()->findByName($formName, $tenantId, null);

        if ($form === null) {
            // 重要：这里把 form_name 一并带回 —— 前端/运维能立刻看到「配的是哪张表单」
            return $this->unresolved(self::REASON_FORM_NOT_FOUND, $formName);
        }

        $schema = is_array($form['schema'] ?? null) ? $form['schema'] : [];

        // 5）与 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1` **同一套**生成器：
        //    复用 SchemaParser 一次拿到 components 与 {rules, messages}，
        //    绝不在这里另写一份规则映射（那必然与低代码侧漂移）
        $context = ['tenant_id' => $tenantId, 'site_id' => null];

        $parsed = $this->parser()->parse($schema, $context, $formName);

        return [
            'form_name'     => $formName,
            'form_schema'   => $this->componentsOf($parsed),
            'form_rules'    => $this->definitionPart($parsed, 'rules'),
            'form_messages' => $this->definitionPart($parsed, 'messages'),
            'resolved'      => true,
            'reason'        => null,
        ];
    }

    // =====================================================================
    // 内部：从定义体定位表单标识
    // =====================================================================

    /**
     * 在定义体的 `nodes_config.nodes[]` 里定位任务节点，读出表单标识
     *
     * 匹配键的优先级：`task.node_id` → `task.node_name`。
     * 为什么两个都试：引擎建任务时这两列写的是**同一个值**（`ProcessTaskService::buildTaskColumns()`
     * 里 `node_id` 与 `node_name` 都取 `$taskModel->getName()`，即 LogicFlow 节点的 `id`），
     * 但历史数据/夹具里可能只有其中一个；多试一个键的代价为零，漏掉一个键的代价是「表单忽然不展示」。
     *
     * @param array<string, mixed> $definition 定义行（`nodes_config` 已由仓储解码为数组）
     * @param array<string, mixed> $task       任务行
     * @return array{0: string|null, 1: bool} [表单标识, 是否找到节点]
     */
    private function locateFormName(array $definition, array $task): array
    {
        $nodes = $definition['nodes_config']['nodes'] ?? null;

        if (!is_array($nodes) || $nodes === []) {
            return [null, false];
        }

        $keys = $this->matchKeys($task);

        if ($keys === []) {
            return [null, false];
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodeId = (string) ($node['id'] ?? '');

            if ($nodeId === '' || !in_array($nodeId, $keys, true)) {
                continue;
            }

            // 节点找到了：此后「没有表单」是配置事实，不再继续找别的节点
            return [$this->formNameOf($node), true];
        }

        return [null, false];
    }

    /**
     * 任务的节点匹配键（去重、去空）
     *
     * @param array<string, mixed> $task
     * @return string[]
     */
    private function matchKeys(array $task): array
    {
        $keys = [];

        foreach (['node_id', 'node_name'] as $column) {
            $value = $task[$column] ?? null;

            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '' && !in_array($value, $keys, true)) {
                $keys[] = $value;
            }
        }

        return $keys;
    }

    /**
     * 从节点配置里读表单标识（`properties.form` 优先，`properties.ext.form` 兜底）
     *
     * 非字符串 / 空串一律视为「没配」：让返回一路走到 `node_has_no_form`，
     * 而不是把 `true` / `["a"]` 之类脏值拼成表单名去查库。
     *
     * @param array<string, mixed> $node 节点（`nodes_config.nodes[]` 的一项）
     */
    private function formNameOf(array $node): ?string
    {
        $properties = $node['properties'] ?? null;

        if (!is_array($properties)) {
            return null;
        }

        $name = $this->scalarName($properties[self::NODE_FORM_KEY] ?? null);

        if ($name !== null) {
            return $name;
        }

        $ext = $properties[self::NODE_EXT_KEY] ?? null;

        if (!is_array($ext)) {
            return null;
        }

        return $this->scalarName($ext[self::NODE_FORM_KEY] ?? null);
    }

    /**
     * 把「表单标识」归一成非空字符串（其余形态一律 null）
     */
    private function scalarName(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }

        $name = trim((string) $raw);

        return $name === '' ? null : $name;
    }

    // =====================================================================
    // 内部：解析结果取值
    // =====================================================================

    /**
     * 从 `SchemaParser::parse()` 的结果里取组件列表
     *
     * 与 `GET /v1/lowcode/forms/{name}?with_parsed=1` 的 `data.parsed.components` 是**同一个数组**，
     * 前端因此可以只写一套渲染代码：工作流里读 `data.form_schema`，低代码里读 `data.parsed.components`。
     *
     * @param array<string, mixed> $parsed
     * @return array<int, array<string, mixed>>
     */
    private function componentsOf(array $parsed): array
    {
        $components = $parsed['components'] ?? [];

        return is_array($components) ? array_values($components) : [];
    }

    /**
     * 从解析结果里取 `validator.rules` / `validator.messages`
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function definitionPart(array $parsed, string $key): array
    {
        $validator = $parsed['validator'] ?? null;

        if (!is_array($validator)) {
            return [];
        }

        $part = $validator[$key] ?? [];

        return is_array($part) ? $part : [];
    }

    /**
     * 未解出的统一返回（保持键集合恒定：前端不需要在两种形状之间分支）
     *
     * @return array{
     *     form_name: string|null,
     *     form_schema: array<int, array<string, mixed>>|null,
     *     form_rules: array<string, mixed>,
     *     form_messages: array<string, mixed>,
     *     resolved: bool,
     *     reason: string|null
     * }
     */
    private function unresolved(string $reason, ?string $formName = null): array
    {
        return [
            'form_name'     => $formName,
            'form_schema'   => null,
            'form_rules'    => [],
            'form_messages' => [],
            'resolved'      => false,
            'reason'        => $reason,
        ];
    }

    // =====================================================================
    // 内部：依赖（惰性构造，控制器/服务无需关心装配顺序）
    // =====================================================================

    private function instances(): WorkflowInstanceRepository
    {
        return $this->instances ??= new WorkflowInstanceRepository();
    }

    private function definitions(): WorkflowDefinitionRepository
    {
        return $this->definitions ??= new WorkflowDefinitionRepository();
    }

    private function forms(): FormSchemaRepository
    {
        return $this->forms ??= new FormSchemaRepository();
    }

    /**
     * 校验器管理器：与低代码控制器**同一个类**，保证 rules/messages 口径一致
     */
    private function validators(): FormValidatorManager
    {
        return $this->validators ??= new FormValidatorManager(new FormValidatorGenerator());
    }

    private function parser(): SchemaParser
    {
        return $this->parser ??= new SchemaParser($this->validators());
    }
}
