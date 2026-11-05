<?php

declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 把两条示例工作流定义的 `nodes_config` 改成**可执行**的 LogicFlow 图
 * | Make sample workflow definitions executable (LogicFlow JSON)
 *
 * ## 为什么需要这条迁移（缺陷：示例定义跑不了）
 *
 * `20251216000003_insert_initial_data.php` 给 `expense_reimbursement` /
 * `leave_application` 写进 `nodes_config` 的是一张**节点表**：
 *
 *     {"start":{"id":"start","name":"开始"},"apply":{"id":"apply","name":"填写申请"}, ...}
 *
 * 这份数据存在三个致命缺口，导致引擎解析必然是空模型：
 *   1. **没有 `nodes` / `edges` 两个键** —— 引擎 `ModelParser::parse()` 只吃
 *      `{nodes:[...],edges:[...]}`（src/parser/ModelParser.php:44-70），
 *      而 `ProcessDefineEntity::hasEngineReadableContent()` 也只认这两个键；
 *   2. **没有节点 `type`** —— 解析器是按 `ingenious:<解析器键>` 分派的
 *      （`ServiceContext::find('task')` 等 8 个键）；
 *   3. **没有连线** —— 连「谁接谁」都不存在，就算补出节点也走不动。
 *
 * 更糟的是 `ModelParser` 在 `nodes` 或 `edges` 任一为空时**静默返回空模型**
 * （src/parser/ModelParser.php:67-69），故障会延迟到
 * `ProcessEngines::startProcessInstanceById()` 的 `$model->getStart()->execute()`
 * 报成「Call to a member function on null」，极难定位。
 *
 * ## 为什么新建一条迁移而不是改 `20251216000003`
 *
 * `20251216000003` 在开发库 / 测试库都已记为 applied，Phinx **不会重跑**已应用的迁移。
 * 改它对既有库毫无效果，只会让「文件内容」与「当初实际执行的代码」脱钩，
 * 新库与旧库从此不一致 —— 这正是 AGENTS.md「迁移文件改名 / 删除的协调流程」要避免的状态。
 * 因此按仓库既定做法：历史迁移不动，用**版本号更大**的新迁移把数据收敛到应有状态。
 *
 * ## 拓扑来源：严格按原 `bpmn_xml` 与 `nodes` 语义还原，不新增业务
 *
 * 原始 `bpmn_xml`（`getSampleBpmnXml()`）给出的是**直线流程**，没有任何网关：
 *
 *   · expense_reimbursement：startEvent → userTask(填写报销申请) → userTask(部门经理审批)
 *                            → userTask(财务审批) → endEvent，4 条 sequenceFlow；
 *   · leave_application：   startEvent → userTask(填写请假申请) → userTask(直属领导审批)
 *                            → userTask(HR审批) → endEvent，4 条 sequenceFlow。
 *
 * 本迁移**原样复现这两条链**：节点 id 与 `nodes_config` 原有键名（start / apply /
 * review_manager / review_finance|review_hr / end）逐一对应，显示名取自原 `nodes` 的 `name`。
 * 不新增「金额判断」之类的网关 —— 原始定义里没有，凭空加一条会把
 * 「财务审批」从必经步骤变成条件步骤，等于改写已发布定义的业务语义。
 *
 * ## 与原数据的差异（只有格式，没有业务）
 *
 * 1. 增加 `x` / `y`：LogicFlow 节点必备字段；主应用模型没有坐标列，
 *    这里按「一列一个层级」给确定性布局（起始 200/160，每列 +220），
 *    与 `LogicFlowSerializer::computeLayout()` 的默认布局同一口径；
 * 2. 增加 `_alkaid_node_class` / `_alkaid_node_type`：序列化器保留键，
 *    让 `LogicFlowSerializer::fromLogicFlow()` 能无损反向还原成主应用 `WorkflowDefinition`
 *    （值为节点短类名，落在序列化器的白名单内）；
 * 3. 边的 `properties` 只带 `_alkaid_type = sequence`（连线语义），**不带 `expr`**：
 *    原定义没有条件网关，给直线边写死一个恒真表达式只会让后续维护者误以为这里有分支；
 * 4. 节点 `properties` **不写 `assignee`**：原定义没有指定审批人，
 *    本仓库也没有「角色 → 用户」的解析实现（`ProcessTaskService::parseActorTokens()`
 *    只接受数字用户ID）。指派关系属于业务配置，由设计器/API 填写，不由数据迁移臆造。
 *    代价见 {@see self::up()} 的 notice 说明：不指派时任务落 `pending`，
 *    只有 `flow_auto` / `flow.admin` 这类系统身份能推进（TaskStateMap: pending → DOING）。
 *
 * ## 幂等性
 *
 * `up()` 是**收敛式 UPDATE**（按 `tenant_id + name + version` 精确匹配后写绝对值），
 * `down()` 写回原始节点表。重复执行不报错、不产生重复行；
 * 目标行不存在时（例如全新库 `20251216000003` 因租户 1 尚未建立而整体跳过）只打印 notice，
 * 不中断迁移链。
 *
 * @package database/migrations
 */
class AddExecutableLogicFlowToSampleDefinitions extends Migrator
{
    /**
     * 示例定义所属租户（与 20251216000003 的 TENANT_ID 一致）
     */
    private const TENANT_ID = 1;

    /**
     * 示例定义版本（只改 version = 1 的种子行，不覆盖租户后续发布的新版本）
     */
    private const VERSION = 1;

    /**
     * LogicFlow JSON 图（定义名 ⇒ 图），既是 `up()` 的写入值，也是端到端测试的唯一真相源
     *
     * 返回结构严格对齐引擎 `ModelParser::parse()` 的输入契约：
     * - `nodes[]`：`id` / `type` = `ingenious:<8 个解析器键之一>` / `x` / `y` /
     *   `text.value`（引擎从这里取 display_name）/ `properties`；
     * - `edges[]`：`id` / `sourceNodeId` / `targetNodeId` / `properties` / `pointsList`。
     *
     * ⚠️ 边**不能**写 `startPoint` / `endPoint`：引擎 `AbstractINodeParser::parse()`
     * 走 `getStartPoint()->getX()`，而 `ModelParser` 用 `PropertyCopier::jsonObjToLfModel()`
     * 复制时不会把 JSON 里的 `{x,y}` 转成 `LfPoint`，实测直接
     * `Error: Call to undefined method stdClass::getX()`。
     * 只能写 `pointsList`（该分支读的是属性访问 `$point->x`，在 stdClass 上正常工作）。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function graphs(): array
    {
        return self::attachPoints([
            'expense_reimbursement' => [
                'name'         => 'expense_reimbursement',
                'display_name' => '费用报销流程',
                'type'         => 'approval',
                'nodes'        => [
                    self::node('start', 'start', 200, 160, '开始'),
                    self::node('apply', 'task', 420, 160, '填写申请'),
                    self::node('review_manager', 'task', 640, 160, '部门经理审批'),
                    self::node('review_finance', 'task', 860, 160, '财务审批'),
                    self::node('end', 'end', 1080, 160, '结束'),
                ],
                'edges'        => [
                    self::edge('flow_start_apply', 'start', 'apply'),
                    self::edge('flow_apply_review_manager', 'apply', 'review_manager'),
                    self::edge('flow_review_manager_review_finance', 'review_manager', 'review_finance'),
                    self::edge('flow_review_finance_end', 'review_finance', 'end'),
                ],
            ],
            'leave_application' => [
                'name'         => 'leave_application',
                'display_name' => '请假申请流程',
                'type'         => 'approval',
                'nodes'        => [
                    self::node('start', 'start', 200, 160, '开始'),
                    self::node('apply', 'task', 420, 160, '填写申请'),
                    self::node('review_manager', 'task', 640, 160, '直属领导审批'),
                    self::node('review_hr', 'task', 860, 160, 'HR审批'),
                    self::node('end', 'end', 1080, 160, '结束'),
                ],
                'edges'        => [
                    self::edge('flow_start_apply', 'start', 'apply'),
                    self::edge('flow_apply_review_manager', 'apply', 'review_manager'),
                    self::edge('flow_review_manager_review_hr', 'review_manager', 'review_hr'),
                    self::edge('flow_review_hr_end', 'review_hr', 'end'),
                ],
            ],
        ]);
    }

    /**
     * 给每条边补 `pointsList`（两端节点坐标）
     *
     * 只写 `pointsList`、**不写** `startPoint` / `endPoint`（见 {@see self::graphs()} 的警告）。
     * 坐标与节点 `x`/`y` 完全一致，因此画布渲染与引擎解析两侧口径相同。
     *
     * @param array<string, array<string, mixed>> $graphs 定义名 ⇒ 图
     * @return array<string, array<string, mixed>>
     */
    private static function attachPoints(array $graphs): array
    {
        foreach ($graphs as $name => $graph) {
            $points = [];
            foreach ($graph['nodes'] as $node) {
                $points[$node['id']] = ['x' => $node['x'], 'y' => $node['y']];
            }

            foreach ($graph['edges'] as $index => $edge) {
                $source = $points[$edge['sourceNodeId']] ?? null;
                $target = $points[$edge['targetNodeId']] ?? null;

                if ($source === null || $target === null) {
                    // 拓扑自检：边的两端必须是已声明节点，否则引擎侧会得到悬空 transition
                    throw new RuntimeException(sprintf(
                        '示例定义 %s 的边 %s 引用了未声明的节点（source=%s, target=%s）',
                        $name,
                        (string) $edge['id'],
                        (string) $edge['sourceNodeId'],
                        (string) $edge['targetNodeId']
                    ));
                }

                $graphs[$name]['edges'][$index]['pointsList'] = [$source, $target];
            }
        }

        return $graphs;
    }

    /**
     * 原始 `nodes_config`（`down()` 的回滚值，逐字对应 20251216000003 的写入内容）
     *
     * 键顺序与 `20251216000003::insertSampleWorkflowDefinitions()` 里的数组一致，
     * 保证回滚后的 JSON 与当初插入的字节级一致（`json_encode` 保序）。
     *
     * @return array<string, array<string, array{id: string, name: string}>>
     */
    public static function legacyNodesConfig(): array
    {
        return [
            'expense_reimbursement' => [
                'start'          => ['id' => 'start', 'name' => '开始'],
                'apply'          => ['id' => 'apply', 'name' => '填写申请'],
                'review_manager' => ['id' => 'review_manager', 'name' => '部门经理审批'],
                'review_finance' => ['id' => 'review_finance', 'name' => '财务审批'],
                'end'            => ['id' => 'end', 'name' => '结束'],
            ],
            'leave_application' => [
                'start'          => ['id' => 'start', 'name' => '开始'],
                'apply'          => ['id' => 'apply', 'name' => '填写申请'],
                'review_manager' => ['id' => 'review_manager', 'name' => '直属领导审批'],
                'review_hr'      => ['id' => 'review_hr', 'name' => 'HR审批'],
                'end'            => ['id' => 'end', 'name' => '结束'],
            ],
        ];
    }

    /**
     * 写入可执行 LogicFlow 图
     *
     * 刻意使用 up()/down() 而非 change()：JSON 列的值更新无法被框架反向推导成回滚。
     */
    public function up(): void
    {
        if (!$this->hasTable('workflow_definitions')) {
            $this->writeNotice('跳过示例定义 LogicFlow 升级：workflow_definitions 表不存在');

            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        foreach (self::graphs() as $name => $graph) {
            $where = [
                'tenant_id' => self::TENANT_ID,
                'name'      => $name,
                'version'   => self::VERSION,
            ];

            $row = Db::table('workflow_definitions')->where($where)->find();

            if (!is_array($row)) {
                // 全新库冷启动时 20251216000003 会因「租户 1 不存在」而整体跳过，
                // 此时本迁移无事可做。这不是错误：迁移链不能因为种子缺失而中断。
                $this->writeNotice("跳过示例定义 {$name}：租户 " . self::TENANT_ID . ' 下不存在该定义（version=' . self::VERSION . '）');

                continue;
            }

            $payload = json_encode($graph, JSON_UNESCAPED_UNICODE);

            if (!is_string($payload)) {
                $this->writeNotice("跳过示例定义 {$name}：LogicFlow 图 json_encode 失败（" . json_last_error_msg() . '）');

                continue;
            }

            Db::table('workflow_definitions')->where($where)->update([
                'nodes_config' => $payload,
                'updated_at'   => $timestamp,
            ]);

            $this->writeNotice("已更新示例定义 {$name} 的 nodes_config 为可执行 LogicFlow（"
                . count($graph['nodes']) . ' 个节点 / ' . count($graph['edges']) . ' 条边）');
        }

        // 参与者未指派是**已知内容缺口**，不是本迁移能决定的事：写清楚，避免下一个人
        // 以为「定义可执行」等于「任务有人办」。任务会落 status = pending（assignee 为空），
        // 引擎侧只有 flow_auto / flow.admin 能推进（见 WorkflowStateMap：pending → DOING）。
        $this->writeNotice('提示：两条示例定义的审批节点未指派参与者（原定义即如此）。'
            . '需由设计器/API 补 assignee（数字用户ID），否则普通用户无法执行任务。');
    }

    /**
     * 回滚：把 `nodes_config` 还原成原始的节点表形态
     */
    public function down(): void
    {
        if (!$this->hasTable('workflow_definitions')) {
            $this->writeNotice('跳过示例定义 LogicFlow 回滚：workflow_definitions 表不存在');

            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        foreach (self::legacyNodesConfig() as $name => $legacy) {
            $where = [
                'tenant_id' => self::TENANT_ID,
                'name'      => $name,
                'version'   => self::VERSION,
            ];

            $row = Db::table('workflow_definitions')->where($where)->find();

            if (!is_array($row)) {
                $this->writeNotice("跳过示例定义 {$name} 回滚：目标行不存在");

                continue;
            }

            Db::table('workflow_definitions')->where($where)->update([
                'nodes_config' => json_encode($legacy, JSON_UNESCAPED_UNICODE),
                'updated_at'   => $timestamp,
            ]);

            $this->writeNotice("已回滚示例定义 {$name} 的 nodes_config 为原始节点表");
        }
    }

    /**
     * 输出迁移提示
     *
     * 为什么不用 `$this->notice(...)`：本仓库装的 think-migration 3.x + Phinx 里
     * `Migrator` / `AbstractMigration` **没有** `notice()` 方法（`grep -rn "function notice"`
     * 在 vendor 零命中），调用会直接
     * `Error: Call to undefined method ...::notice()` 并把迁移打断。
     * 父类只提供 `protected $output`（`Phinx\Migration\AbstractMigration`），因此这里走它，
     * 并对 null 做守卫（独立构造 Migrator 时 output 可能未注入）。
     *
     * 注：`20251216000003` 里遗留的 `$this->notice(...)` 属于同一类缺陷，
     * 但那是既有已应用迁移，按约束不在本次改动范围内（见交付说明的风险项）。
     *
     * @param string $message 提示内容
     */
    private function writeNotice(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln('    > ' . $message);
        }
    }

    /**
     * 构造一个 LogicFlow 节点
     *
     * @param string $id          节点 id（同时是引擎侧节点名，任务表 node_name 就取这个值）
     * @param string $parserKey   引擎解析器键（start / end / task / decision / fork / join / custom / wfSubProcess）
     * @param int    $x           画布横坐标
     * @param int    $y           画布纵坐标
     * @param string $displayName 显示名（写入 `text.value`，引擎从这里取 display_name）
     * @return array<string, mixed>
     */
    private static function node(string $id, string $parserKey, int $x, int $y, string $displayName): array
    {
        return [
            'id'         => $id,
            'type'       => 'ingenious:' . $parserKey,
            // LfNode::$x/$y 是 int，给浮点会在 PropertyCopier 赋值时丢精度
            'x'          => $x,
            'y'          => $y,
            'text'       => ['value' => $displayName],
            'properties' => [
                // 序列化器保留键：让 LogicFlowSerializer::fromLogicFlow() 能还原成主应用节点类
                '_alkaid_node_class' => self::nodeClassFor($parserKey),
                '_alkaid_node_type'  => $parserKey,
            ],
        ];
    }

    /**
     * 构造一条 LogicFlow 顺序边
     *
     * @param string $id     边 id（引擎侧 TransitionModel 名）
     * @param string $source 源节点 id
     * @param string $target 目标节点 id
     * @return array<string, mixed>
     */
    private static function edge(string $id, string $source, string $target): array
    {
        return [
            'id'           => $id,
            // 实测没有任何解析器读边的 type（AbstractINodeParser 只看 id/targetNodeId/properties/pointsList），
            // 这里取 LogicFlow 折线边的惯例值，让画布能直接渲染
            'type'         => 'polyline',
            'sourceNodeId' => $source,
            'targetNodeId' => $target,
            'text'         => ['value' => ''],
            'properties'   => [
                // 连线语义（主应用 WorkflowConnection::TYPE_SEQUENCE）；无条件网关，故不写 expr
                '_alkaid_type' => 'sequence',
            ],
        ];
    }

    /**
     * 解析器键 ⇒ 主应用节点短类名（`LogicFlowSerializer::NODE_CLASS_WHITELIST` 内的取值）
     */
    private static function nodeClassFor(string $parserKey): string
    {
        return match ($parserKey) {
            'start'    => 'StartNode',
            'end'      => 'EndNode',
            'task'     => 'TaskNode',
            'decision' => 'GatewayNode',
            default    => 'TaskNode',
        };
    }
}
