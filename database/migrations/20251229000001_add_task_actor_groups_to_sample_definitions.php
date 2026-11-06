<?php

declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 给两条示例工作流的审批节点补上「按角色指派」的参与者配置
 * | Add candidate groups (role:admin) to the review nodes of the sample definitions
 *
 * ## 为什么需要这条迁移
 *
 * `20251228000001` 把示例定义的 `nodes_config` 改成了可执行 LogicFlow 图，但没有配参与者：
 * 审批节点的 `properties` 里既没有 `assignee` 也没有 `candidate_ext_groups`。
 * 后果是审批任务以 `pending` + 空候选人落库，只有 `flow_auto` / `flow.admin` 这类系统身份
 * 能推进（见 `ProcessTaskService::isAllowed()`），普通用户在界面上「看得到任务、办不了」。
 *
 * 本迁移补的正是**参与者**这一块：把 `expense_reimbursement` / `leave_application` 的
 * 两个审批节点配成 `candidate_ext_groups: ["role:admin"]`。
 *
 * ## ⚠️ 这是演示数据，不是部署建议
 *
 * 真实部署应当建 `manager` / `finance` 这类角色，并按 `role:manager` / `role:finance` 指派；
 * 这里用 `admin` 只是为了在**任何**已有库里都能立刻跑通 —— 租户 1 一定有 `admin` 角色
 * （`database/seeds/CorePlatformSeed.php` 建的，slug = `admin`）。
 * 本迁移演示的是「**角色 → 用户展开**」这套机制：建任务时把这个角色下的所有用户
 * 展开成用户ID写进 `workflow_tasks.candidate_users`，原始引用 `role:admin` 写进
 * `candidate_groups` 留作审计。**不是说所有审批都该由 admin 来办。**
 *
 * ## 键名为什么是 `candidate_ext_groups` 而不是 `candidateGroups`
 *
 * 引擎 `parser/handler/TaskParser.php` 读的是 `properties.candidate_ext_groups`
 * （常量 `INodeParser::EXT_FIELD_CANDIDATE_GROUPS_KEY`），并且支持两层写法：
 * 顶层 `properties.candidate_ext_groups`，或 `properties.field.candidate_ext_groups`。
 * 本迁移写顶层，值用**数组**（`["role:admin"]`）—— `TaskModel` 的候选字段类型已放宽为
 * `string|array|null`，两种形态（数组 / 逗号串）解析器都能吃。
 *
 * ## 幂等性与「全新库冷启动」
 *
 * - `up()` 是**收敛式**写入：读当前行的 `nodes_config` → 只给目标节点补上该属性 → 整列写回；
 *   重复执行结果一致，不会累积、也不会覆盖别人配的其他属性；
 * - 目标行不存在时（全新库冷启动：`20251216000003` 会因「租户 1 尚未建立」整体跳过，
 *   于是 `workflow_definitions` 里没有这两条示例）只打印提示，**不中断迁移链**；
 * - 行存在但 `nodes_config` 还不是 `{nodes, edges}` 形态（旧节点表）时同样只提示：
 *   那种定义本来就不具备执行能力，补参与者没有意义，转换由 `20251228000001` 负责。
 *
 * ## down() 能干净回滚
 *
 * 只删除**本迁移加的那一项**：`candidate_ext_groups` 的值恰好等于 `["role:admin"]` 时才删；
 * 若有人已经把该属性改成别的角色，回滚不动它（否则会把别人的配置当成自己的删掉）。
 *
 * @package database/migrations
 */
class AddTaskActorGroupsToSampleDefinitions extends Migrator
{
    /**
     * 示例定义所属租户（与 20251216000003 / 20251228000001 的 TENANT_ID 一致）
     */
    private const TENANT_ID = 1;

    /**
     * 示例定义版本（只改 version = 1 的种子行，不覆盖租户后续发布的新版本）
     */
    private const VERSION = 1;

    /**
     * 本迁移写入的候选人组引用
     */
    private const GROUP_REF = 'role:admin';

    /**
     * 承载候选人组的节点属性键（引擎真正读的键，见类注释）
     */
    private const PROPERTY_KEY = 'candidate_ext_groups';

    /**
     * 定义名 => 需要补参与者的节点 id
     *
     * 节点 id 与 `20251228000001::graphs()` 里的节点一一对应：
     * - expense_reimbursement：`review_manager`（部门经理审批）、`review_finance`（财务审批）
     * - leave_application：`review_manager`（直属领导审批）、`review_hr`（HR审批）
     * `apply` 节点**不加**：第一个任务节点的处理人是流程发起人（申请节点 = 填写人），
     * 由 `ProcessEngines::executeAndJumpToFirstTaskNode()` / 起始边设置，不靠角色。
     *
     * @var array<string, string[]>
     */
    private const TARGETS = [
        'expense_reimbursement' => ['review_manager', 'review_finance'],
        'leave_application'     => ['review_manager', 'review_hr'],
    ];

    /**
     * 写入参与者组引用
     *
     * 刻意使用 up()/down() 而非 change()：JSON 列内某个属性键的增删无法被框架反向推导成回滚。
     */
    public function up(): void
    {
        $this->patchDefinitions(true);
    }

    /**
     * 回滚：删掉本迁移写入的参与者组引用
     */
    public function down(): void
    {
        $this->patchDefinitions(false);
    }

    /**
     * 需要补参与者的节点（定义名 ⇒ 节点 id）
     *
     * 公开给测试用：端到端测试拿这份清单去 patch `20251228000001::graphs()` 的图，
     * 保证「迁移声明的目标节点」与「测试断言的节点」是同一份事实，不各抄一遍。
     *
     * @return array<string, string[]>
     */
    public static function targets(): array
    {
        return self::TARGETS;
    }

    /**
     * 本迁移写入的候选人组引用（公开给测试用，同上）
     */
    public static function groupRef(): string
    {
        return self::GROUP_REF;
    }

    /**
     * 在内存中的 LogicFlow 图上增删候选人组属性（**纯函数**，不碰数据库）
     *
     * 抽成静态方法而不是写在 patchDefinitions() 里，是为了让端到端测试
     * 能对「迁移真正执行的那段逻辑」断言，而不是在测试里重抄一份 patch 代码。
     *
     * @param array<string, mixed> $graph LogicFlow 图（`{nodes: [...], edges: [...]}`）
     * @param bool                 $add   true = 写入 `["role:admin"]`；false = 只删掉值恰好等于它的那一项
     * @return array{graph: array<string, mixed>, nodes: string[]} 处理后的图 + 实际改动的节点 id
     */
    public static function patchGraph(array $graph, bool $add): array
    {
        if (!isset($graph['nodes']) || !is_array($graph['nodes'])) {
            return ['graph' => $graph, 'nodes' => []];
        }

        $targetIds = [];

        foreach (self::TARGETS as $nodeIds) {
            $targetIds = array_merge($targetIds, $nodeIds);
        }

        $changed = [];

        foreach ($graph['nodes'] as $index => $node) {
            $nodeId = is_array($node) ? (string) ($node['id'] ?? '') : '';

            if (!in_array($nodeId, $targetIds, true)) {
                continue;
            }

            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

            if ($add) {
                $properties[self::PROPERTY_KEY] = [self::GROUP_REF];
            } else {
                // 只删自己加的那一项：别人若已改成别的角色，回滚不能动它
                if (($properties[self::PROPERTY_KEY] ?? null) !== [self::GROUP_REF]) {
                    continue;
                }

                unset($properties[self::PROPERTY_KEY]);
            }

            $graph['nodes'][$index]['properties'] = $properties;
            $changed[] = $nodeId;
        }

        return ['graph' => $graph, 'nodes' => array_values(array_unique($changed))];
    }

    /**
     * 批量修改示例定义的审批节点参与者配置
     *
     * @param bool $add true = 写入 `["role:admin"]`；false = 只删掉值恰好等于它的那一项
     */
    private function patchDefinitions(bool $add): void
    {
        $action = $add ? '写入' : '回滚';

        if (!$this->hasTable('workflow_definitions')) {
            $this->writeNotice("跳过示例定义参与者{$action}：workflow_definitions 表不存在");

            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        foreach (self::TARGETS as $name => $nodeIds) {
            $where = [
                'tenant_id' => self::TENANT_ID,
                'name'      => $name,
                'version'   => self::VERSION,
            ];

            $row = Db::table('workflow_definitions')->where($where)->find();

            if (!is_array($row)) {
                // 全新库冷启动：示例数据块因「租户 1 不存在」被整体跳过，这里无事可做。
                // 这不是错误 —— 迁移链不能因为种子缺失而中断。
                $this->writeNotice("跳过示例定义 {$name} 的参与者{$action}：租户 " . self::TENANT_ID
                    . ' 下不存在该定义（version=' . self::VERSION . '）');

                continue;
            }

            $graph = json_decode((string) ($row['nodes_config'] ?? ''), true);

            if (!is_array($graph) || !isset($graph['nodes']) || !is_array($graph['nodes'])) {
                // 旧形态（节点表，无 nodes 数组）：转换由 20251228000001 负责，本条迁移不越界
                $this->writeNotice("跳过示例定义 {$name} 的参与者{$action}：nodes_config 不是可执行的 {nodes, edges} 形态");

                continue;
            }

            $patched = self::patchGraph($graph, $add);

            if ($patched['nodes'] === []) {
                $this->writeNotice("示例定义 {$name} 无需{$action}参与者：目标节点 " . implode(' / ', $nodeIds)
                    . ' 未命中或已是目标值');

                continue;
            }

            $payload = json_encode($patched['graph'], JSON_UNESCAPED_UNICODE);

            if (!is_string($payload)) {
                $this->writeNotice("跳过示例定义 {$name} 的参与者{$action}：nodes_config json_encode 失败（"
                    . json_last_error_msg() . '）');

                continue;
            }

            Db::table('workflow_definitions')->where($where)->update([
                'nodes_config' => $payload,
                'updated_at'   => $timestamp,
            ]);

            $this->writeNotice("示例定义 {$name} 已{$action}参与者组 " . self::GROUP_REF
                . '（节点：' . implode(' / ', $patched['nodes']) . '）');
        }

        if ($add) {
            $this->writeNotice('提示：role:admin 是演示配置（租户 1 必然存在该角色）。'
                . '真实部署请建 manager / finance 等角色，并按 role:manager / role:finance 指派；'
                . '本迁移演示的是「角色 → 用户展开」机制，不是建议用 admin 承办所有审批。');
        }
    }

    /**
     * 输出迁移提示
     *
     * 不用 `$this->notice(...)`：本仓库装的 think-migration 3.x + Phinx 里
     * `Migrator` / `AbstractMigration` 没有该方法（调用会 `Call to undefined method`），
     * 父类只提供 `protected $output`，因此走它并对 null 做守卫。
     *
     * @param string $message 提示内容
     */
    private function writeNotice(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln('    > ' . $message);
        }
    }
}
