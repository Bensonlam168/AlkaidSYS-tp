<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionCacheService;
use Infrastructure\Workflow\Repository\WorkflowDefinitionRepository;
use Infrastructure\Workflow\Repository\WorkflowInstanceRepository;
use Infrastructure\Workflow\Support\WorkflowTaskFormResolver;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use Tests\Traits\WorkflowExecutableFixture;
use Tests\Traits\WorkflowFixtureIsolation;
use think\facade\Db;

/**
 * `GET /v1/workflow/tasks/{id}/form` 的**表单解析链**端到端测试
 *
 * ## 守住的缺陷
 *
 * 改造前该端点恒返回 `{task_id, form_schema: null, form_data, form_rules: []}` ——
 * 表里没有 `form_schema` 列，于是「表单模板」这件事被如实降级成 null 占位。
 * 后果是前端审批界面**无法渲染表单**（拿不到字段、组件与校验规则），功能上线即死。
 *
 * 现在模板沿
 * `task → instance.definition_id（具体版本行）→ nodes_config → 节点 properties.form → lowcode_forms`
 * 解析（`Infrastructure\Workflow\Support\WorkflowTaskFormResolver`），本测试逐条钉住：
 *
 * | 用例 | 断言的核心语义 |
 * | ---- | -------------- |
 * | `testFormResolvesSchemaRulesAndSubmittedData` | 解出 components / rules / messages，且 `form_data` 是任务上已填的值 |
 * | `testFormFollowsPrimaryFormKeyOverExtFallback` | `properties.form` 优先于 `properties.ext.form`（与引擎口径一致） |
 * | `testFormFallsBackToExtFormWhenPrimaryMissing` | 只写 `ext.form` 时仍能兼容解出 |
 * | `testFormTemplateStaysOnTheInstanceVersion` | **版本正确性**：发新版本不改在跑实例的表单模板 |
 * | `testReasonNodeHasNoForm` | 没配 → `node_has_no_form`（不是静默 null） |
 * | `testReasonFormNotFound` | 配了但本租户没有 → `form_not_found`，且回带 `form_name` |
 * | `testFormOfAnotherTenantIsNotFound` | 同名表单属于别的租户 → 同样 `form_not_found`（不跨租户读） |
 * | `testReasonDefinitionNotFound` | 实例指向的定义行不存在 → `definition_not_found` |
 * | `testCrossTenantFormIsForbidden` | 跨租户读别人任务的表单 → 403 |
 *
 * ## 为什么一律读响应 + 读库断言
 *
 * 「返回了 200」不能证明表单真的解出来了（改造前也是 200）。因此每个用例都断言
 * `resolved` 与具体字段内容，并且对「版本正确性」额外断言实例的 `definition_id`
 * 没有被新版本改写（那是宿主/引擎版本策略的落库事实）。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskFormResolutionTest extends ThinkPHPTestCase
{
    use WorkflowFixtureIsolation;
    use WorkflowExecutableFixture;

    /** 实例、任务与表单所属租户 */
    private const TENANT_ID = 1;

    /** 另一个租户（跨租户隔离用例） */
    private const OTHER_TENANT_ID = 2;

    /** 主操作人（租户 1） */
    private const USER_ID = 6801;

    /** 夹具角色 */
    private const ROLE_ID = 6811;

    /** 权限码对应的固定 id（实际 id 以 ensureWorkflowPermission() 返回值为准） */
    private const PERMISSION_INSTANCE_READ = 6821;
    private const PERMISSION_INSTANCE_WRITE = 6822;
    private const PERMISSION_TASK_READ = 6823;
    private const PERMISSION_TASK_WRITE = 6824;

    /** 测试定义的名称前缀（清理时按前缀删除） */
    private const DEFINITION_PREFIX = 'form_resolve_';

    /** 测试表单的名称前缀（清理时按前缀删除，绝不碰真实表单） */
    private const FORM_PREFIX = 'wf_form_fixture_';

    /** 由本测试插入/复用的权限 id */
    private array $permissionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app()->bind(\think\Request::class, \app\Request::class);

        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件：其它测试类可能把它绑定成 no-op，容器会缓存解析结果
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->purgeFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeFixtures();
        $this->purgeWorkflowFixtures();

        parent::tearDown();
    }

    // =================================================================
    // 1) 主链路：解出 schema + rules + 已填值
    // =================================================================

    /**
     * 节点配了表单 → `resolved: true`，components / rules / messages 全部非空
     */
    public function testFormResolvesSchemaRulesAndSubmittedData(): void
    {
        $formName = $this->insertForm('expense_like', $this->richFormSchema());
        [$instanceId, $taskId] = $this->startInstanceWithForm($formName);

        // 模拟「用户填了一半先保存」：任务行的 form_data 就是已提交值
        Db::table('workflow_tasks')->where('id', $taskId)->update([
            'form_data' => json_encode(['reason' => '差旅报销', 'amount' => 800], JSON_UNESCAPED_UNICODE),
        ]);

        $data = $this->getForm($taskId);

        $this->assertSame($taskId, $data['task_id'] ?? null, 'task_id 必须是路径上的那个任务');
        $this->assertSame($formName, $data['form_name'] ?? null, 'form_name 必须是节点上配置的表单标识');
        $this->assertTrue($data['resolved'] ?? false, '节点配了表单且本租户存在该表单时必须 resolved=true');
        $this->assertArrayHasKey('reason', $data, '返回契约必须包含 reason 键');
        $this->assertNull($data['reason'], '解出表单时 reason 必须为 null');

        // components：字段/组件/必填标记（与 GET /v1/lowcode/forms/{name}?with_parsed=1 的 parsed.components 同源）
        $this->assertIsArray($data['form_schema'] ?? null, 'form_schema 必须是解析出的组件数组');
        $this->assertNotEmpty($data['form_schema'], 'form_schema 不能为空 —— 否则前端仍然无法渲染');

        // ⚠️ 只断言字段**集合**，不断言顺序：`lowcode_forms.schema` 是 MySQL `json` 列，
        // 而 MySQL 存 JSON 对象时会**重排键**（先按键名长度、再按字节序），
        // 因此「设计器里的字段顺序」在落库那一刻就已经丢了 ——
        // 这与低代码 `GET /v1/lowcode/forms/{name}?with_parsed=1` 的行为完全一致，
        // 前端若需要固定顺序，必须由 schema 里显式的排序键决定（不能依赖数组顺序）。
        $fields = array_column($data['form_schema'], 'field');
        sort($fields);
        $this->assertSame(
            ['amount', 'applicant_name', 'reason'],
            $fields,
            '组件字段集合应与表单 schema 一致'
        );

        $required = [];
        $components = [];
        foreach ($data['form_schema'] as $component) {
            $required[$component['field']] = $component['required'] ?? null;
            $components[$component['field']] = $component['component'] ?? null;
        }

        $this->assertTrue($required['applicant_name'], 'schema.required 里的字段必须标记 required');
        $this->assertTrue($required['reason'], 'schema.required 里的字段必须标记 required');
        $this->assertFalse($required['amount'], '未列入 required 的字段不得标记 required');
        $this->assertSame('InputNumber', $components['amount'], 'x-component 必须原样带到前端');

        // rules：来自 FormValidatorManager（与低代码接口同一套生成器，不是另写一份）
        $this->assertIsArray($data['form_rules'] ?? null);
        $this->assertNotEmpty($data['form_rules'], 'form_rules 不能为空 —— 前端要靠它做与后端一致的校验');
        $this->assertSame('require|max:50', $data['form_rules']['applicant_name'] ?? null);
        $this->assertSame('require|max:200', $data['form_rules']['reason'] ?? null);
        $this->assertSame('number|egt:0|elt:1000000', $data['form_rules']['amount'] ?? null);

        // messages：字段级中文文案
        $this->assertIsArray($data['form_messages'] ?? null);
        $this->assertNotEmpty($data['form_messages'], 'form_messages 不能为空');
        $this->assertSame('申请人不能为空', $data['form_messages']['applicant_name.require'] ?? null);
        $this->assertSame('金额不能大于1000000', $data['form_messages']['amount.elt'] ?? null);

        // form_data：任务上已填的值必须原样带出（模板 + 已填值是两件事，都要）
        // 同样按「键值对」断言而不是整份 `assertSame`：`workflow_tasks.form_data` 也是
        // MySQL `json` 列，键顺序会被库重排（与上一条同样的原因）。
        $formData = $data['form_data'] ?? [];
        $this->assertIsArray($formData);
        $this->assertCount(2, $formData, 'form_data 必须恰好是任务上已填的两个字段');
        $this->assertSame('差旅报销', $formData['reason'] ?? null, 'form_data 必须是该任务已提交的值');
        $this->assertSame(800, $formData['amount'] ?? null, '数值字段必须保持数值类型（不能被转成字符串）');

        // 落库事实：实例仍指向启动时那一版定义
        $instance = Db::table('workflow_instances')->where('id', $instanceId)->find();
        $this->assertIsArray($instance);
        $this->assertGreaterThan(0, (int) $instance['definition_id']);
    }

    // =================================================================
    // 2) 优先级：properties.form 优先于 properties.ext.form
    // =================================================================

    /**
     * 引擎只读 `properties.form`（`TaskParser` 对 `ext` 无 form 回退），
     * 宿主侧口径是「以引擎为准 + ext 仅兼容」，因此两者同时存在时必须取 `properties.form`。
     */
    public function testFormFollowsPrimaryFormKeyOverExtFallback(): void
    {
        $primary  = $this->insertForm('primary', $this->simpleFormSchema('primary_field'));
        $fallback = $this->insertForm('fallback', $this->simpleFormSchema('fallback_field'));

        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', $primary, $fallback)
        );

        $data = $this->getForm($this->startAndTakeTask($definitionId));

        $this->assertTrue($data['resolved'] ?? false);
        $this->assertSame($primary, $data['form_name'] ?? null, 'properties.form 必须优先于 properties.ext.form');
        $this->assertSame(
            ['primary_field'],
            array_column($data['form_schema'] ?? [], 'field'),
            '解出的字段必须来自 properties.form 指向的表单'
        );
    }

    /**
     * 只有 `properties.ext.form` 时仍能解出（兼容回退），但 `form_name` 明确指向 ext 里那个表单
     *
     * ⚠️ 这是**宿主侧兼容**：引擎自己的 `TaskModel::$form` 在此形态下仍为空。
     * 之所以保留，是因为历史/第三方定义可能把 form 写在 `ext` 里；
     * 若哪天确认无人使用，应删除这个回退（而不是让它悄悄变成主入口）。
     */
    public function testFormFallsBackToExtFormWhenPrimaryMissing(): void
    {
        $fallback = $this->insertForm('ext_only', $this->simpleFormSchema('ext_only_field'));

        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', null, $fallback)
        );

        $data = $this->getForm($this->startAndTakeTask($definitionId));

        $this->assertTrue($data['resolved'] ?? false, 'ext.form 必须能作为兼容回退被解出');
        $this->assertSame($fallback, $data['form_name'] ?? null);
        $this->assertSame(['ext_only_field'], array_column($data['form_schema'] ?? [], 'field'));
    }

    // =================================================================
    // 3) 版本正确性（本轮最关键的语义）
    // =================================================================

    /**
     * 发布新版本定义后，**在跑实例**的表单仍是旧版本的表单模板
     *
     * 为什么这条必须钉住：`instance.definition_id` 指向具体版本行，而不是「按名字取最新版本」。
     * 一旦有人把解析链改成「先按 name 查最新 published 定义」，审批界面就会在流程走到一半时
     * 突然换掉表单（用户眼前的上半截表单与下半截不一致，已填的值也可能对不上新字段）。
     */
    public function testFormTemplateStaysOnTheInstanceVersion(): void
    {
        $definitionName = self::DEFINITION_PREFIX . uniqid();
        $oldForm = $this->insertForm('v1', $this->simpleFormSchema('v1_field'));
        $newForm = $this->insertForm('v2', $this->simpleFormSchema('v2_field'));

        // v1：apply 节点指向 oldForm
        $definitionId = $this->insertExecutableDefinition(
            $definitionName,
            self::TENANT_ID,
            $this->approvalGraphWithForm($definitionName, $oldForm, null),
            1
        );

        $taskId = $this->startAndTakeTask($definitionId);

        $before = $this->getForm($taskId);
        $this->assertTrue($before['resolved'] ?? false);
        $this->assertSame($oldForm, $before['form_name'] ?? null);
        $this->assertSame(['v1_field'], array_column($before['form_schema'] ?? [], 'field'));

        // 发布 v2：同名定义的第二个版本，apply 节点指向 newForm，并成为唯一 is_active 的发布版本
        Db::table('workflow_definitions')
            ->where('name', $definitionName)
            ->where('id', '<>', $definitionId)
            ->update(['is_active' => 0]);

        $v2Id = $this->insertExecutableDefinition(
            $definitionName,
            self::TENANT_ID,
            $this->approvalGraphWithForm($definitionName, $newForm, null),
            2
        );

        $this->assertGreaterThan($definitionId, $v2Id, '新版本必须是独立的一行');

        // 在跑实例的 definition_id 不得被改写
        $instanceId = (int) Db::table('workflow_tasks')->where('id', $taskId)->value('instance_id');
        $instanceDefinitionId = (int) Db::table('workflow_instances')->where('id', $instanceId)->value('definition_id');
        $this->assertSame($definitionId, $instanceDefinitionId, '发布新版本不得改写在跑实例的 definition_id');

        // 表单模板仍是 v1 的那张
        $after = $this->getForm($taskId);
        $this->assertTrue($after['resolved'] ?? false);
        $this->assertSame($oldForm, $after['form_name'] ?? null, '在跑实例必须仍解析到旧版本定义上的表单');
        $this->assertSame(
            ['v1_field'],
            array_column($after['form_schema'] ?? [], 'field'),
            '在跑实例的审批界面不得因为定义了新版本而换掉表单模板'
        );
    }

    // =================================================================
    // 4) 各种 reason（没配 / 配了但找不到 / 定义行不存在）
    // =================================================================

    /**
     * 节点没配表单 → `node_has_no_form`（正常的审批节点形态，不是错误）
     */
    public function testReasonNodeHasNoForm(): void
    {
        // approvalGraph() 的节点 properties 里没有 form
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());

        $data = $this->getForm($this->startAndTakeTask($definitionId));

        $this->assertFalse($data['resolved'] ?? true, '没配表单时 resolved 必须是 false');
        $this->assertSame('node_has_no_form', $data['reason'] ?? null);
        $this->assertArrayHasKey('form_name', $data);
        $this->assertNull($data['form_name'], '没配表单时 form_name 必须是 null');
        $this->assertArrayHasKey('form_schema', $data);
        $this->assertNull($data['form_schema'], '没配表单时 form_schema 必须是 null（不是空数组）');
        $this->assertSame([], $data['form_rules'] ?? 'x', '没配表单时 form_rules 必须是空数组');
        $this->assertSame([], $data['form_messages'] ?? 'x', '没配表单时 form_messages 必须是空数组');
    }

    /**
     * 节点配了表单名但本租户下没有 → `form_not_found`，且必须回带 `form_name`
     *
     * 回带 form_name 的用途：配置错误要能被一眼定位（「配的是哪张表单」），
     * 否则运维只能逐个节点翻定义体。
     */
    public function testReasonFormNotFound(): void
    {
        $missing = self::FORM_PREFIX . 'missing_' . uniqid();

        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', $missing, null)
        );

        $data = $this->getForm($this->startAndTakeTask($definitionId));

        $this->assertFalse($data['resolved'] ?? true);
        $this->assertSame('form_not_found', $data['reason'] ?? null);
        $this->assertSame($missing, $data['form_name'] ?? null, '必须回带配置的表单标识，便于定位配置错误');
        $this->assertArrayHasKey('form_schema', $data);
        $this->assertNull($data['form_schema']);
    }

    /**
     * 表单存在但属于**别的租户** → 同样 `form_not_found`（绝不跨租户读表单）
     *
     * 这是租户隔离在表单链路上的落点：`lowcode_forms` 的唯一键是 `(tenant_id, name)`，
     * 两个租户可以有同名表单；按 name 查时必须带租户条件。
     */
    public function testFormOfAnotherTenantIsNotFound(): void
    {
        $sharedName = self::FORM_PREFIX . 'shared_' . uniqid();

        // 租户 2 下建同名表单（租户 1 下刻意不建）
        $this->insertFormRow($sharedName, self::OTHER_TENANT_ID, $this->simpleFormSchema('other_tenant_field'));

        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', $sharedName, null)
        );

        $data = $this->getForm($this->startAndTakeTask($definitionId));

        $this->assertFalse($data['resolved'] ?? true);
        $this->assertSame('form_not_found', $data['reason'] ?? null, '别的租户的同名表单不得被本租户读到');
    }

    /**
     * 实例指向的定义行已不存在 → `definition_not_found`
     *
     * 外键（`ON DELETE CASCADE`）正常时走不到这个分支，因此这里用**仓储替身**
     * 精确命中它（而不是关掉外键制造脏数据）—— 该分支是「跨库恢复 / 绕过外键的运维」
     * 这类真实异常场景下的兜底，必须保证它返回的是一个可解释的 reason，而不是 500。
     */
    public function testReasonDefinitionNotFound(): void
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());
        $taskId = $this->startAndTakeTask($definitionId);

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);

        $resolver = new WorkflowTaskFormResolver(
            new WorkflowInstanceRepository(),
            $this->missingDefinitionRepository()
        );

        $result = $resolver->resolve(self::TENANT_ID, $task);

        $this->assertFalse($result['resolved']);
        $this->assertSame('definition_not_found', $result['reason']);
        $this->assertNull($result['form_name']);
        $this->assertNull($result['form_schema']);
    }

    /**
     * 任务行的 `node_id` 在定义体里找不到 → `node_not_found`（数据不一致的兜底）
     */
    public function testReasonNodeNotFound(): void
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());
        $taskId = $this->startAndTakeTask($definitionId);

        // 伪造「任务来自另一版定义」的状态：节点名在图里不存在
        Db::table('workflow_tasks')->where('id', $taskId)->update([
            'node_id'   => 'node_that_does_not_exist',
            'node_name' => 'node_that_does_not_exist',
        ]);

        $data = $this->getForm($taskId);

        $this->assertFalse($data['resolved'] ?? true);
        $this->assertSame('node_not_found', $data['reason'] ?? null);
    }

    // =================================================================
    // 5) 跨租户读表单 → 403
    // =================================================================

    /**
     * 别的租户的任务，用本租户身份读表单必须 403（不是 404，也不是 200 + 空 schema）
     */
    public function testCrossTenantFormIsForbidden(): void
    {
        $foreignDefinitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . 't2_' . uniqid(),
            self::OTHER_TENANT_ID,
            $this->approvalGraphWithForm(
                'foreign',
                $this->insertForm('foreign', $this->simpleFormSchema('foreign_field')),
                null
            )
        );

        $foreignInstanceId = $this->seedWorkflowInstanceRow(
            self::OTHER_TENANT_ID,
            $foreignDefinitionId,
            self::USER_ID
        );

        $foreignTaskId = $this->seedWorkflowTaskRow(self::OTHER_TENANT_ID, $foreignInstanceId, [
            'node_id'   => 'apply',
            'node_name' => 'apply',
        ]);

        $response = $this->httpRequest('GET', 'v1/workflow/tasks/' . $foreignTaskId . '/form');
        $body = json_decode($response->getContent(), true);

        $this->assertSame(403, $response->getCode(), '跨租户读任务表单必须 403，实际：' . $response->getContent());
        $this->assertSame(
            403,
            (int) ($body['code'] ?? 0),
            '业务码 403 用于区别于权限中间件的 2002（后者是「没有权限码」，这里是「不是你的数据」）'
        );
        $this->assertSame([], $body['data'] ?? [], '越权响应不得泄漏任何表单内容');
    }

    // =================================================================
    // 辅助：夹具
    // =================================================================

    /**
     * 「字段齐全」的表单 schema：同时覆盖 require / max / number / egt / elt 与 x-component
     *
     * @return array<string, mixed>
     */
    private function richFormSchema(): array
    {
        return [
            'type'       => 'object',
            'title'      => '表单解析测试（富字段）',
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
                    'maxLength'   => 200,
                    'x-component' => 'Textarea',
                ],
                'amount' => [
                    'type'        => 'number',
                    'title'       => '金额',
                    'minimum'     => 0,
                    'maximum'     => 1000000,
                    'x-component' => 'InputNumber',
                ],
            ],
        ];
    }

    /**
     * 「最小」表单 schema：只有一个必填字符串字段（用于只关心字段归属的用例）
     *
     * @return array<string, mixed>
     */
    private function simpleFormSchema(string $field): array
    {
        return [
            'type'       => 'object',
            'title'      => '表单解析测试（单字段）',
            'required'   => [$field],
            'properties' => [
                $field => [
                    'type'        => 'string',
                    'title'       => $field,
                    'x-component' => 'Input',
                ],
            ],
        ];
    }

    /**
     * 插入一张测试表单，返回其 name
     *
     * @param string               $suffix 名称后缀（拼在 `wf_form_fixture_` 之后，便于按前缀清理）
     * @param array<string, mixed> $schema 表单 schema
     */
    private function insertForm(string $suffix, array $schema): string
    {
        $name = self::FORM_PREFIX . $suffix . '_' . uniqid();

        $this->insertFormRow($name, self::TENANT_ID, $schema);

        return $name;
    }

    /**
     * 直接插入一条 `lowcode_forms` 行（**不经过** FormSchemaManager，避免它的缓存/事件依赖）
     *
     * @param array<string, mixed> $schema
     */
    private function insertFormRow(string $name, int $tenantId, array $schema): void
    {
        $now = date('Y-m-d H:i:s');

        Db::table('lowcode_forms')->insert([
            'tenant_id'  => $tenantId,
            'site_id'    => 0,
            'name'       => $name,
            'title'      => '工作流表单解析测试夹具',
            'schema'     => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'layout'     => 'vertical',
            'status'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 构造一张「apply 节点带表单指向」的可执行图
     *
     * @param string      $name        图名
     * @param string|null $formName    `properties.form` 的值（null = 不写该键）
     * @param string|null $extFormName `properties.ext.form` 的值（null = 不写该键）
     * @return array<string, mixed>
     */
    private function approvalGraphWithForm(string $name, ?string $formName, ?string $extFormName): array
    {
        $graph = $this->approvalGraph($name);

        foreach ($graph['nodes'] as $index => $node) {
            if ($node['id'] !== 'apply') {
                continue;
            }

            $properties = $node['properties'];

            if ($formName !== null) {
                $properties['form'] = $formName;
            }

            if ($extFormName !== null) {
                $properties['ext'] = ['form' => $extFormName];
            }

            $graph['nodes'][$index]['properties'] = $properties;
        }

        return $graph;
    }

    /**
     * 启动一条带表单的实例，返回 [实例ID, apply 任务ID]
     *
     * @return array{0: int, 1: int}
     */
    private function startInstanceWithForm(string $formName): array
    {
        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', $formName, null)
        );

        $taskId = $this->startAndTakeTask($definitionId);
        $instanceId = (int) Db::table('workflow_tasks')->where('id', $taskId)->value('instance_id');

        return [$instanceId, $taskId];
    }

    /**
     * 用现成定义启动实例，返回 `apply` 节点上的任务 id
     */
    private function startAndTakeTask(int $definitionId): int
    {
        $response = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'title'         => '表单解析验证实例',
            'variables'     => ['amount' => 100],
        ]);

        $this->assertSame(201, $response->getCode(), '夹具实例应启动成功，实际：' . $response->getContent());

        $instanceId = (int) (json_decode($response->getContent(), true)['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId);

        $taskId = (int) Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('node_name', 'apply')
            ->value('id');

        $this->assertGreaterThan(0, $taskId, '夹具实例应生成 apply 节点上的任务');

        return $taskId;
    }

    /**
     * 读任务表单（以 USER_ID 的 JWT 发起），返回响应体里的 `data`
     *
     * @return array<string, mixed>
     */
    private function getForm(int $taskId): array
    {
        $response = $this->httpRequest('GET', 'v1/workflow/tasks/' . $taskId . '/form');

        $this->assertSame(200, $response->getCode(), '读取任务表单应成功，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['data'] ?? null, 'data 必须是对象');

        return $body['data'];
    }

    /**
     * 「定义行已不存在」用的仓储替身：`findForTenant()` 恒返回 null
     *
     * 刻意用子类覆盖而不是关掉外键造脏数据：用例结束后测试库应保持干净，
     * 而这条分支要验证的是「解析器遇到这种情况会不会给出可解释的 reason」。
     */
    private function missingDefinitionRepository(): WorkflowDefinitionRepository
    {
        return new class () extends WorkflowDefinitionRepository {
            /**
             * @return array<string, mixed>|null
             */
            public function findForTenant(int $tenantId, int $id): ?array
            {
                return null;
            }
        };
    }

    /**
     * 清理本测试写入的低代码表单与定义（按名称前缀圈定，不碰真实数据）
     */
    private function purgeFixtures(): void
    {
        Db::table('lowcode_forms')->whereLike('name', self::FORM_PREFIX . '%')->delete();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
    }

    /**
     * 播种夹具：用户 / 角色 / 权限 / 关联
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            $this->permissionIds = [
                $this->ensureWorkflowPermission('workflow_instance', 'read', self::PERMISSION_INSTANCE_READ),
                $this->ensureWorkflowPermission('workflow_instance', 'write', self::PERMISSION_INSTANCE_WRITE),
                $this->ensureWorkflowPermission('workflow_task', 'read', self::PERMISSION_TASK_READ),
                $this->ensureWorkflowPermission('workflow_task', 'write', self::PERMISSION_TASK_WRITE),
            ];

            Db::table('users')->insert([
                'id'         => self::USER_ID,
                'tenant_id'  => self::TENANT_ID,
                'username'   => 'wf_form_user',
                'email'      => 'wf_form_user@workflow-form.test',
                'password'   => password_hash('password', PASSWORD_DEFAULT),
                'name'       => '表单解析用户',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('roles')->insert([
                'id'         => self::ROLE_ID,
                'tenant_id'  => self::TENANT_ID,
                'name'       => 'wf_form_role',
                'slug'       => 'wf_form_role',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id'    => self::USER_ID,
                'role_id'    => self::ROLE_ID,
                'created_at' => $now,
            ]);

            $rolePermissions = [];

            foreach ($this->permissionIds as $permissionId) {
                $rolePermissions[] = [
                    'role_id'       => self::ROLE_ID,
                    'permission_id' => $permissionId,
                    'created_at'    => $now,
                ];
            }

            Db::table('role_permissions')->insertAll($rolePermissions);
        });

        (new PermissionCacheService())->clearUserCache(self::USER_ID, self::TENANT_ID);
    }

    /**
     * 简易 HTTP 调用（默认以 USER_ID 的 JWT 发起）
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 第三个参数 true：容器缓存了上一次请求实例，必须强制新建，
        // 否则 app\Request 的实例级缓存（userGroups）会跨用例串味
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $headers = $headers === [] ? $this->authHeaders() : $headers;

        if ($method !== 'GET') {
            $headers['Content-Type'] = 'application/json';
            $request = $request->withHeader($headers);
            $request->withPost($data);
            $request->withInput(json_encode($data));
        } else {
            $request->withGet($data);
            $request = $request->withHeader($headers);
        }

        return $this->runHttp($request);
    }

    /**
     * 携带 JWT 的请求头（租户上下文来自 token 里的 tenant_id）
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: self::USER_ID,
                tenantId: self::TENANT_ID,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ];
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $middleware = $this->app()->middleware;

        $ref = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $ref->setAccessible(true);

        $queue = (array) $ref->getValue($middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }
}
