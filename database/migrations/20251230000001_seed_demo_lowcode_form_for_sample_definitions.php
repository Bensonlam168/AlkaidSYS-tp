<?php

declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * 给两条示例工作流定义的 `apply` 节点接上一张**演示用**低代码表单
 * | Attach a demo lowcode form to the sample definitions' apply nodes
 *
 * ## 为什么需要这条迁移（缺陷：任务表单解析链没有可演示的数据）
 *
 * `GET /v1/workflow/tasks/{id}/form` 现在会沿
 * `task → instance.definition_id → nodes_config → 节点 properties.form → lowcode_forms`
 * 解析表单模板（见 `Infrastructure\Workflow\Support\WorkflowTaskFormResolver`）。
 * 但两条种子定义的节点 `properties` 只有
 * `{"_alkaid_node_type":"task","_alkaid_node_class":"TaskNode"}`，**没有 `form`**；
 * `lowcode_forms` 表在全新库里也是**空的**。
 *
 * 结果是：功能写完、测试也绿，但任何人对着开发库跑一遍种子流程，
 * 拿到的都是 `resolved: false` / `reason: node_has_no_form` ——
 * 「表单能渲染」这件事**没有任何真实数据能证明**，上线即死。
 * 因此本迁移补一条最小演示数据：一张表单 + 两处指向它的节点配置。
 *
 * ## 这是**演示数据**，不是业务配置
 *
 * - 表单名 `workflow_demo_apply_form`，明确带 `demo` 前缀，便于识别与替换；
 * - 字段只有四个（申请人 / 申请事由 / 金额 / 备注），刻意做成「两张示例流程都能用」的通用形态，
 *   不假装它是报销或请假的真实业务表单；
 * - 只改 `version = 1` 的种子行，**不碰**租户后续发布的新版本
 *   （真实业务表单应由设计器 / `POST /v1/lowcode/forms` 创建后，再由定义配置指向它）；
 * - 真实部署若已有自己的表单与节点配置，本迁移的语义就是「没有任何需要纠正的地方」——
 *   它只做收敛式写入，不覆盖别人的非空配置（见 {@see self::rewriteApplyNodeForm()}）。
 *
 * ## 为什么不改 `20251228000001`
 *
 * 那条迁移在开发库 / 测试库都已记为 applied，Phinx **不会重跑**：改它对既有库毫无效果，
 * 只会让「文件内容」与「当初执行的代码」脱钩，新库与旧库从此不一致。
 * 按仓库既定做法：历史迁移不动，用**版本号更大**的新迁移把数据收敛到应有状态。
 *
 * ## 幂等性与「租户 1 不存在」的处理
 *
 * - 表单行按唯一键 `(tenant_id, name)` 判定：存在则 UPDATE 成当前定义（收敛式），不存在则 INSERT；
 *   重复执行不报错、不产生重复行；
 * - 节点配置是**收敛式 UPDATE**（读出 `nodes_config` → 给 `apply` 节点写 `properties.form` → 写回），
 *   同样可重复执行，且「已经是目标值」时不制造无意义的 `updated_at`；
 * - `20251216000003` 在**全新库**里会因为「租户 1 尚未建立」而整体跳过，此时
 *   `workflow_definitions` 里也没有种子行。本迁移对此的处理是：**只打 notice、不中断迁移链**
 *   （租户不存在时连表单也不插入 —— `lowcode_forms.tenant_id` 有指向 `tenants.id` 的外键，
 *   插进去必然撞 1452）。
 *
 * ## down() 的边界
 *
 * - 节点配置：**只在当前值恰好等于本迁移写入的表单名时**才移除该键。
 *   若期间有人把节点改成指向另一张表单，回滚不得把人工配置一起抹掉
 *   （回滚只负责撤销自己做过的事，不负责把别人的改动还原成「没有」）；
 * - 表单行：按 `(tenant_id, name)` 精确删除。
 *
 * @package database/migrations
 */
class SeedDemoLowcodeFormForSampleDefinitions extends Migrator
{
    /**
     * 演示数据所属租户（与 `20251216000003` / `20251228000001` 的 TENANT_ID 一致）
     */
    private const TENANT_ID = 1;

    /**
     * 演示表单标识（同时也是 `lowcode_forms.name`）
     *
     * 命名规则遵循 `FormSchemaManager::validateFormData()`：只允许字母/数字/下划线。
     */
    private const FORM_NAME = 'workflow_demo_apply_form';

    /**
     * 演示表单标题
     */
    private const FORM_TITLE = '工作流演示表单（申请信息）';

    /**
     * 需要挂上表单的节点 id（两条示例定义里「填写申请」节点的 id 都是 `apply`）
     */
    private const APPLY_NODE_ID = 'apply';

    /**
     * 只改 version = 1 的种子行（与 20251228000001 一致）
     */
    private const VERSION = 1;

    /**
     * 种子定义名（`20251216000003` 写入的两条）
     *
     * @var string[]
     */
    private const DEFINITION_NAMES = ['expense_reimbursement', 'leave_application'];

    /**
     * 演示表单的 Schema（JSON Schema + 低代码表单设计器扩展键）
     *
     * 口径说明（决定 `form_rules` 会长成什么样，生成器见
     * `Infrastructure\Lowcode\FormDesigner\Service\FormValidatorGenerator`）：
     * - `required` 里的字段会得到 `require` 规则 —— 这是 `form_rules` **非空**的最小来源；
     * - `type = number` + `minimum` / `maximum` → `number|egt:..|elt:..`；
     * - `maxLength` → `max:..`；`enum` → `in:a,b,c`；
     * - 每个字段必须带 `x-component`（`FormSchemaManager::validateSchema()` 的硬性要求，
     *   前端也靠它决定渲染哪个组件）。
     *
     * @return array<string, mixed>
     */
    public static function demoFormSchema(): array
    {
        return [
            'type'       => 'object',
            'title'      => self::FORM_TITLE,
            'required'   => ['applicant_name', 'reason'],
            'properties' => [
                'applicant_name' => [
                    'type'        => 'string',
                    'title'       => '申请人',
                    'maxLength'   => 50,
                    'x-component' => 'Input',
                ],
                'reason' => [
                    'type'        => 'string',
                    'title'       => '申请事由',
                    'maxLength'   => 500,
                    'x-component' => 'Textarea',
                ],
                'amount' => [
                    'type'        => 'number',
                    'title'       => '金额',
                    'minimum'     => 0,
                    'maximum'     => 1000000,
                    'x-component' => 'InputNumber',
                ],
                'remark' => [
                    'type'        => 'string',
                    'title'       => '备注',
                    'maxLength'   => 200,
                    'x-component' => 'Input',
                ],
            ],
        ];
    }

    /**
     * 演示表单标识（供测试引用，避免测试里再抄一遍字面量）
     */
    public static function demoFormName(): string
    {
        return self::FORM_NAME;
    }

    /**
     * 写入演示表单 + 把两条种子定义的 `apply` 节点指向它
     *
     * 刻意使用 up()/down() 而非 change()：JSON 列的值更新无法被框架反向推导成回滚，
     * 且 `scripts/check-migrations.php` 的检查 C 明确规定新迁移不得用 change()。
     */
    public function up(): void
    {
        if (!$this->hasTable('lowcode_forms') || !$this->hasTable('workflow_definitions')) {
            $this->writeNotice('跳过演示表单：lowcode_forms 或 workflow_definitions 表不存在');

            return;
        }

        // 租户不存在时插表单必撞外键：全新库冷启动下 20251216000003 也会整体跳过，
        // 此时本迁移无事可做。这不是错误 —— 迁移链不能因为种子缺失而中断。
        if (!$this->tenantExists(self::TENANT_ID)) {
            $this->writeNotice('跳过演示表单：租户 ' . self::TENANT_ID . ' 不存在（全新库尚未初始化租户）');

            return;
        }

        $this->upsertDemoForm();
        $this->attachFormToSampleDefinitions();
    }

    /**
     * 回滚：摘掉 `apply` 节点上的表单指向，并删除演示表单行
     */
    public function down(): void
    {
        if (!$this->hasTable('lowcode_forms') || !$this->hasTable('workflow_definitions')) {
            $this->writeNotice('跳过演示表单回滚：lowcode_forms 或 workflow_definitions 表不存在');

            return;
        }

        $this->detachFormFromSampleDefinitions();
        $this->deleteDemoForm();
    }

    // -----------------------------------------------------------------------
    // 表单行
    // -----------------------------------------------------------------------

    /**
     * 插入或更新演示表单（幂等：按唯一键 `(tenant_id, name)`）
     */
    protected function upsertDemoForm(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $schema    = json_encode(self::demoFormSchema(), JSON_UNESCAPED_UNICODE);

        if (!is_string($schema)) {
            // 静态数组的 json_encode 不可能失败；真失败就明确报错，不写半截数据
            throw new RuntimeException('演示表单 schema 编码失败：' . json_last_error_msg());
        }

        $existingId = $this->findDemoFormId();

        $payload = [
            'title'           => self::FORM_TITLE,
            'description'     => '演示数据：用于验证 GET /v1/workflow/tasks/{id}/form 的表单解析链（可随时替换为真实业务表单）',
            'schema'          => $schema,
            'collection_name' => null,
            'layout'          => 'vertical',
            'status'          => 1,
            'updated_at'      => $timestamp,
        ];

        if ($existingId !== null) {
            Db::table('lowcode_forms')
                ->where('id', $existingId)
                ->where('tenant_id', self::TENANT_ID)
                ->update($payload);

            $this->writeNotice('已更新演示表单 ' . self::FORM_NAME . '（id=' . $existingId . '）');

            return;
        }

        Db::table('lowcode_forms')->insert(array_merge($payload, [
            'tenant_id'  => self::TENANT_ID,
            // lowcode_forms.site_id 默认 0；工作流侧查找刻意不按站点过滤（定义本身无 site_id），
            // 因此这里固定写 0，保证「同租户唯一一行」的语义清晰
            'site_id'    => 0,
            'name'       => self::FORM_NAME,
            'created_at' => $timestamp,
        ]));

        $this->writeNotice('已插入演示表单 ' . self::FORM_NAME . '（租户 ' . self::TENANT_ID . '）');
    }

    /**
     * 删除演示表单行（按租户 + 名称精确删除，绝不按 ID 区间）
     */
    protected function deleteDemoForm(): void
    {
        $existingId = $this->findDemoFormId();

        if ($existingId === null) {
            $this->writeNotice('跳过删除演示表单 ' . self::FORM_NAME . '：该行不存在');

            return;
        }

        Db::table('lowcode_forms')
            ->where('id', $existingId)
            ->where('tenant_id', self::TENANT_ID)
            ->delete();

        $this->writeNotice('已删除演示表单 ' . self::FORM_NAME . '（id=' . $existingId . '）');
    }

    /**
     * 按 (tenant_id, name) 反查演示表单主键
     */
    protected function findDemoFormId(): ?int
    {
        $row = Db::table('lowcode_forms')
            ->where('tenant_id', self::TENANT_ID)
            ->where('name', self::FORM_NAME)
            ->find();

        if (!is_array($row) || !isset($row['id'])) {
            return null;
        }

        return (int) $row['id'];
    }

    // -----------------------------------------------------------------------
    // 节点配置
    // -----------------------------------------------------------------------

    /**
     * 把两条种子定义 `apply` 节点的 `properties.form` 指向演示表单
     */
    protected function attachFormToSampleDefinitions(): void
    {
        foreach (self::DEFINITION_NAMES as $name) {
            $this->rewriteApplyNodeForm($name, self::FORM_NAME);
        }
    }

    /**
     * 把两条种子定义 `apply` 节点上的表单指向摘掉（仅当它正是本迁移写进去的那个值）
     */
    protected function detachFormFromSampleDefinitions(): void
    {
        foreach (self::DEFINITION_NAMES as $name) {
            $this->rewriteApplyNodeForm($name, null);
        }
    }

    /**
     * 收敛式改写某条种子定义 `apply` 节点的 `properties.form`
     *
     * 为什么用「读出整份 JSON → 改一个键 → 写回」而不是 SQL 字符串替换：
     * `nodes_config` 是 JSON 列，字符串替换无法保证只命中 `apply` 节点上的那一个键
     * （别的节点/别的层级也可能有 `"form"`），解成数组再定位是唯一可靠的做法。
     *
     * @param string      $definitionName 定义名（租户 1 / version 1）
     * @param string|null $formName       写入的表单标识；null = 移除该键
     */
    protected function rewriteApplyNodeForm(string $definitionName, ?string $formName): void
    {
        $row = Db::table('workflow_definitions')
            ->where('tenant_id', self::TENANT_ID)
            ->where('name', $definitionName)
            ->where('version', self::VERSION)
            ->find();

        if (!is_array($row) || !isset($row['id'])) {
            // 全新库冷启动时 20251216000003 会整体跳过，这里无事可做
            $this->writeNotice(sprintf(
                '跳过示例定义 %s：租户 %d 下不存在该定义（version=%d）',
                $definitionName,
                self::TENANT_ID,
                self::VERSION
            ));

            return;
        }

        $config = $this->decodeNodesConfig($row['nodes_config'] ?? null);

        if ($config === null) {
            $this->writeNotice(sprintf(
                '跳过示例定义 %s：nodes_config 不是可解析的 {nodes, edges} 图（先跑 20251228000001？）',
                $definitionName
            ));

            return;
        }

        $changed = false;

        foreach ($config['nodes'] as $index => $node) {
            if (!is_array($node) || (string) ($node['id'] ?? '') !== self::APPLY_NODE_ID) {
                continue;
            }

            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $current    = $properties['form'] ?? null;

            if ($formName === null) {
                // 回滚：只在当前值确为本迁移写入的值时才摘除
                if (!is_string($current) || $current !== self::FORM_NAME) {
                    continue;
                }

                unset($properties['form']);
            } else {
                if (is_string($current) && $current === $formName) {
                    // 已经是目标值：不制造一次无意义的 updated_at
                    continue;
                }

                $properties['form'] = $formName;
            }

            $config['nodes'][$index]['properties'] = $properties;
            $changed = true;
        }

        if (!$changed) {
            $this->writeNotice(sprintf(
                '示例定义 %s 的 %s 节点无需改动（已符合期望或不存在该节点）',
                $definitionName,
                self::APPLY_NODE_ID
            ));

            return;
        }

        $payload = json_encode($config, JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            throw new RuntimeException(sprintf(
                '示例定义 %s 的 nodes_config 编码失败：%s',
                $definitionName,
                json_last_error_msg()
            ));
        }

        Db::table('workflow_definitions')
            ->where('id', (int) $row['id'])
            ->where('tenant_id', self::TENANT_ID)
            ->update([
                'nodes_config' => $payload,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

        $this->writeNotice(sprintf(
            '已把示例定义 %s 的 %s 节点 properties.form 设为 %s',
            $definitionName,
            self::APPLY_NODE_ID,
            $formName === null ? '(已移除)' : $formName
        ));
    }

    /**
     * 解析 `nodes_config` 列
     *
     * 只接受引擎可执行的 `{nodes: [...], edges: [...]}` 形态：
     * 旧形态（节点表 `{start:{...}, apply:{...}}`）没有 `nodes` 数组，
     * 强行改写只会造出「看起来改了、引擎其实读不到」的假数据。
     *
     * @return array{nodes: array<int, mixed>, edges: array<int, mixed>}|null
     */
    protected function decodeNodesConfig(mixed $raw): ?array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['nodes']) || !is_array($decoded['nodes']) || $decoded['nodes'] === []) {
            return null;
        }

        // edges 允许为空数组（引擎侧仍可解析），但键必须存在，否则说明这不是 LogicFlow 图
        if (!array_key_exists('edges', $decoded) || !is_array($decoded['edges'])) {
            return null;
        }

        /** @var array{nodes: array<int, mixed>, edges: array<int, mixed>} $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------------
    // 工具
    // -----------------------------------------------------------------------

    /**
     * 判断租户是否存在（`lowcode_forms.tenant_id` 有外键指向 `tenants.id`）
     */
    protected function tenantExists(int $tenantId): bool
    {
        if (!$this->hasTable('tenants')) {
            return false;
        }

        $row = $this->fetchRow(sprintf('SELECT `id` FROM `tenants` WHERE `id` = %d LIMIT 1', $tenantId));

        return is_array($row) && $row !== [];
    }

    /**
     * 输出迁移提示
     *
     * 为什么不用 `$this->notice(...)`：本仓库装的 think-migration + Phinx 里
     * `Migrator` / `AbstractMigration` **没有** `notice()` 方法（`grep -rn "function notice"`
     * 在 vendor 零命中），调用会直接 `Error: Call to undefined method` 并把迁移打断。
     * 父类只提供 `protected $output`，因此这里走它，并对 null 做守卫。
     *
     * @param string $message 提示内容
     */
    protected function writeNotice(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln('    > ' . $message);
        }
    }
}
