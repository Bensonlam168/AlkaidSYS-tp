<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionCacheService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use Tests\Traits\WorkflowExecutableFixture;
use Tests\Traits\WorkflowFixtureIsolation;
use think\facade\Db;

/**
 * 审批类动作（`approve` / `complete` / `reject`）携带 `form_data` 时的**后端表单校验**
 *
 * ## 守住的缺陷（能绕开表单校验的第二条路）
 *
 * 上一个包给 `POST /v1/workflow/tasks/{id}/form` 加上了后端校验
 * （`WorkflowTaskService::validateFormData()`），但**同一份 `form_data` 还能从审批接口进来**：
 * `buildActionArgs()` 把 `form_data` 的每个键以 `f_` 前缀写进引擎 args，
 * 而 `approve` / `complete` / `reject` 全走这条路，**全程不校验**。
 *
 * 换句话说：修了 `submitForm()` 却不管审批接口，等于修复可绕过 ——
 * `form` 端点会拦下 `amount = -5`，同一个值改从 `/approve` 发出去却会落进
 * `workflow_tasks.result`，并成为后续节点表达式可引用的变量。
 *
 * ## 修法（一条分支覆盖四条入口）
 *
 * 校验插在四个动作的唯一漏斗 `runAction()` 的第 4 步，触发条件是
 * **payload 里带了非空 `form_data`**（不是「action 等于某几个值」）。
 * 因此本文件要钉住的不是一个动作，而是「任何动作都好使」这件事。
 *
 * | 用例 | 断言的核心语义 |
 * | ---- | -------------- |
 * | `testApproveWithInvalidFormDataIsRejectedAndLeavesNoDirtyData` | 审批带违规表单值 → 422；任务 `status` / `result` / `form_data` / `comment` / `updated_at` **全等**、不推进、不写 result |
 * | `testApproveWithoutFormDataStillSucceeds` | **不带** `form_data` 的审批（常态）→ 200 推进，不能因为「没带」被打成 422 |
 * | `testApproveWithValidFormDataWritesValuesIntoResult` | 合规 `form_data` → 200，`result` 里能看到 `f_amount` 等值，且**不写** `workflow_tasks.form_data` |
 * | `testEmptyFormDataOnApproveIsNotValidated` | `form_data: {}` 等价于「没带」（写不出任何键）→ 不校验、不 422 |
 * | `testOutputIsNotValidated` | `output` 不是表单值，**不套**表单规则（负数照样落库）—— 别给它编一套 |
 * | `testAllThreeActionsRejectInvalidFormData` | `approve` / `complete` / `reject` 三个端点各来一次绕过尝试，全部 422 |
 * | `testNodeWithoutFormAcceptsArbitraryFormData` | `review` 这类无表单节点带任意 `form_data` → 放行，`reason = node_has_no_form` |
 * | `testMissingFormTemplateOnApproveIsSkippedButFlagged` | 模板缺失 → 放行 + WARNING + 响应带 `needs_attention`（与 submitForm 口径一致） |
 *
 * ## 为什么每条用例都读库
 *
 * 「返回了 422」本身不能证明是**表单规则**拦下来的（状态冲突、越权也都是 4xx）。
 * 因此每条都用 `data.errors` 的字段名 + 文案定位到具体规则，
 * 并且对 422 的用例追加「库里一个字节都没变」的快照对比 —— 那是「先校验、再落库」的直接证据。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskApproveFormValidationTest extends ThinkPHPTestCase
{
    use WorkflowFixtureIsolation;
    use WorkflowExecutableFixture;

    /** 实例、任务、表单所属租户 */
    private const TENANT_ID = 1;

    /** 主操作人（租户 1；任务受理人） */
    private const USER_ID = 6941;

    /** 夹具角色 */
    private const ROLE_ID = 6942;

    /** 权限码对应的固定 id（实际 id 以 ensureWorkflowPermission() 返回值为准） */
    private const PERMISSION_INSTANCE_READ = 6943;
    private const PERMISSION_INSTANCE_WRITE = 6944;
    private const PERMISSION_TASK_READ = 6945;
    private const PERMISSION_TASK_WRITE = 6946;

    /** 测试定义的名称前缀（清理时按前缀删除） */
    private const DEFINITION_PREFIX = 'approve_form_';

    /** 测试表单的名称前缀（清理时按前缀删除，绝不碰真实表单） */
    private const FORM_PREFIX = 'wf_form_approve_';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 工作流路由（低代码路由本类用不到：规则同源已由 submitForm 的用例钉住）
        $path = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($path)) {
            include_once $path;
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
    // 1) 绕过不再成立：审批带违规表单值 → 422 且零变化
    // =================================================================

    /**
     * `approve` 带 `{amount: -5, reason: "x"}` → 422，且任务行完全没被动过
     *
     * 这条是本包的主证据：同一个请求在修复前会返回 200、
     * 把 `f_amount = -5` 写进 `result` 并把流程推进到下一节点。
     */
    public function testApproveWithInvalidFormDataIsRejectedAndLeavesNoDirtyData(): void
    {
        [$instanceId, $taskId] = $this->startInstanceWithForm($this->insertForm('approve_bad', $this->demoLikeFormSchema()));

        // 前置：造一个「用户之前存过的草稿」形态，断言它不被覆盖
        Db::table('workflow_tasks')->where('id', $taskId)->update([
            'form_data'  => json_encode(['applicant_name' => '张三', 'reason' => '草稿'], JSON_UNESCAPED_UNICODE),
            'updated_at' => '2025-01-01 00:00:00',
        ]);

        $before = $this->rowOf($taskId);

        [$code, $body] = $this->act('approve', $taskId, [
            'comment'   => '绕过校验试试',
            'form_data' => ['amount' => -5, 'reason' => 'x'],
        ]);

        $this->assertSame(422, $code, '审批带违规表单值必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(422, (int) ($body['code'] ?? 0), '业务码与 HTTP 状态一致（与 ApiController::validationError() 同口径）');

        $errors = $body['data']['errors'] ?? [];
        $this->assertSame(
            '金额不能小于0',
            $errors['amount'] ?? null,
            '错误必须定位到 amount，文案取自 form_messages（不是 egt:0 这种原始规则串）'
        );
        $this->assertSame(
            '申请人不能为空',
            $errors['applicant_name'] ?? null,
            '漏必填项同样要报出来（批量模式，不是只报第一条）'
        );
        $this->assertArrayNotHasKey('remark', $errors, '没传的、且非必填的字段不得被报错');

        $after = $this->rowOf($taskId);

        $this->assertSame($before['status'], $after['status'], '422 后任务状态必须不变（不得先推进后校验）');
        $this->assertSame($before['result'], $after['result'], '422 后 result 必须不变：违规表单值一个字节都不许落库');
        $this->assertSame($before['form_data'], $after['form_data'], '422 后 form_data 必须保持原样');
        $this->assertSame($before['comment'], $after['comment'], '422 后 comment 不得被写入');
        $this->assertSame($before['updated_at'], $after['updated_at'], '422 后 updated_at 必须不变（说明这一行根本没被写过）');

        // 不推进：实例下不该出现第二个任务
        $this->assertSame(
            1,
            Db::table('workflow_tasks')->where('instance_id', $instanceId)->count(),
            '422 之后不得推进流程（不该产生新任务）'
        );

        // 实例仍是运行中（没有因为「驳回/完成」而进入终态）
        $this->assertSame('running', $this->instanceStatus($instanceId), '422 之后实例必须仍是 running');

        // 没有留痕：task_completed 只能是「真的办完了」时才写
        $this->assertNotContains(
            'task_completed',
            $this->eventTypes($instanceId),
            '校验失败不得写 task_completed 事件'
        );
    }

    // =================================================================
    // 2) 不带 form_data 的审批（常态）不受影响
    // =================================================================

    /**
     * 只带 `comment` 审批 → 200 并推进流程
     *
     * 这是「绝不能误拦」的主证据：审批通常不带 `form_data`，
     * 若把「没带」当成「交了一份空表单」去跑 require 规则，所有正常审批都会 422。
     */
    public function testApproveWithoutFormDataStillSucceeds(): void
    {
        [$instanceId, $taskId] = $this->startInstanceWithForm($this->insertForm('approve_plain', $this->demoLikeFormSchema()));

        [$code, $body] = $this->act('approve', $taskId, ['comment' => '同意，请财务复核']);

        $this->assertSame(200, $code, '只带 comment 的审批必须成功，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $data = $body['data'] ?? [];
        $this->assertSame('completed', $data['status'] ?? null, '审批后任务必须完成');
        $this->assertSame('同意，请财务复核', $data['comment'] ?? null, '审批意见必须落 comment 列');
        $this->assertSame(
            '同意，请财务复核',
            $data['result']['tf_approval_comment'] ?? null,
            'args 里必须有引擎的审批意见键'
        );
        $this->assertArrayNotHasKey(
            'form_validation',
            $data,
            '没带 form_data 时不应出现 form_validation 键（它只表示「本次真的校验过」）'
        );

        // 流程真的推进了
        $next = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('node_name', 'review')
            ->find();
        $this->assertIsArray($next, '审批必须推进到 review 节点');
    }

    // =================================================================
    // 3) 带合规 form_data 的审批：能过，且值进 result
    // =================================================================

    /**
     * `form_data` 合规 → 200，`result` 里能看到对应的 `f_*` 值
     *
     * 同时钉住「审批不写 `workflow_tasks.form_data`」：那一列是「已填的表单」，
     * 不是「本次审批的产出」；产出走 `result`。两者混用会让「表单里填了什么」永远被最后一次审批覆盖。
     */
    public function testApproveWithValidFormDataWritesValuesIntoResult(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('approve_ok', $this->demoLikeFormSchema()));

        [$code, $body] = $this->act('approve', $taskId, [
            'comment'   => '金额核对无误',
            'form_data' => $this->validPayload(['amount' => 1280]),
        ]);

        $this->assertSame(200, $code, '合规表单值必须通过，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $data = $body['data'] ?? [];
        $this->assertSame(1280, $data['result']['f_amount'] ?? null, '表单值必须按 f_ 前缀进 result');
        $this->assertSame('张三', $data['result']['f_applicant_name'] ?? null);
        $this->assertSame('差旅报销', $data['result']['f_reason'] ?? null);
        $this->assertSame('金额核对无误', $data['result']['tf_approval_comment'] ?? null, '意见与表单值共存');

        $validation = $data['form_validation'] ?? null;
        $this->assertIsArray($validation, '带了 form_data 就必须回报校验结论');
        $this->assertTrue($validation['validated'] ?? false, '合规数据必须标记 validated=true');
        $this->assertFalse($validation['skipped'] ?? true);
        $this->assertFalse($validation['needs_attention'] ?? true);

        // 库里的 result 同样是 JSON 字符串形态
        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame(1280, json_decode((string) $task['result'], true)['f_amount'] ?? null);

        // 审批**不**写 form_data 列
        $this->assertNull($task['form_data'], '审批动作不得把表单值写进 workflow_tasks.form_data（那是提交表单的语义）');
    }

    // =================================================================
    // 4) 空 form_data 等价于「没带」
    // =================================================================

    /**
     * `form_data: {}` → 不校验、不报错
     *
     * 依据：空数组遍历不出任何键，`result` 里什么都不会多出来 —— 没有可写入的东西，
     * 就没有可校验的对象。若把它当成「交了一份空表单」，一次手滑就会把正常审批打成 422。
     */
    public function testEmptyFormDataOnApproveIsNotValidated(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('approve_empty', $this->demoLikeFormSchema()));

        [$code, $body] = $this->act('approve', $taskId, [
            'comment'   => '同意',
            'form_data' => [],
        ]);

        $this->assertSame(200, $code, '空 form_data 必须放行，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $data = $body['data'] ?? [];
        $this->assertSame('completed', $data['status'] ?? null);
        $this->assertArrayNotHasKey('form_validation', $data, '空 form_data 视为「没带」，不应出现校验结论');
        $this->assertArrayNotHasKey('f_amount', (array) ($data['result'] ?? []), '空的 form_data 写不出任何 f_ 键');
    }

    // =================================================================
    // 5) output 不套表单规则
    // =================================================================

    /**
     * `output` 里的负数金额**不会**被拦 —— 它不是表单值，没有对应规则
     *
     * 这条钉的是「不要顺手给 output 编一套校验」：那样等于凭空发明契约，
     * 客户端只要不带 output 就会全场 422（审批意见本来就可以什么都没有）。
     */
    public function testOutputIsNotValidated(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('approve_output', $this->demoLikeFormSchema()));

        [$code, $body] = $this->act('approve', $taskId, [
            'comment' => '产出走 output',
            'output'  => ['amount' => -5, 'remark' => str_repeat('备', 201)],
        ]);

        $this->assertSame(200, $code, 'output 不受表单规则约束，必须放行，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $data = $body['data'] ?? [];
        $this->assertSame(-5, $data['result']['f_amount'] ?? null, 'output 的值原样进 result（引擎不区分表单字段与产出字段）');
        $this->assertArrayNotHasKey('form_validation', $data, '没带 form_data 就没有校验结论，与 output 无关');
    }

    // =================================================================
    // 6) 三个接口都要拦
    // =================================================================

    /**
     * `approve` / `complete` / `reject` 各来一次绕过尝试，全部 422
     *
     * 三个端点共用 `runAction()`，因此「拦得住一个」与「三个都拦得住」理论上是同一件事；
     * 但这条测试的存在意义是**防止有人把校验写回某个动作的分支里**（那样只会拦一个）。
     */
    public function testAllThreeActionsRejectInvalidFormData(): void
    {
        foreach (['approve', 'complete', 'reject'] as $action) {
            [$instanceId, $taskId] = $this->startInstanceWithForm(
                $this->insertForm('all_' . $action, $this->demoLikeFormSchema())
            );

            [$code, $body] = $this->act($action, $taskId, [
                'comment'   => '尝试绕过（' . $action . '）',
                'form_data' => ['applicant_name' => '张三', 'reason' => '差旅', 'amount' => -5],
            ]);

            $this->assertSame(
                422,
                $code,
                $action . ' 带违规表单值必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE)
            );
            $this->assertSame(
                '金额不能小于0',
                $body['data']['errors']['amount'] ?? null,
                $action . ' 的 422 必须定位到 amount 字段'
            );

            // 三个动作在 422 之后都必须「零变化」
            $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
            $this->assertIsArray($task);
            $this->assertSame('claimed', (string) $task['status'], $action . ' 校验失败后任务状态必须不变');
            $this->assertNull($task['result'], $action . ' 校验失败后 result 必须仍为空');
            $this->assertSame('running', $this->instanceStatus($instanceId), $action . ' 校验失败后实例必须仍是 running');
        }
    }

    // =================================================================
    // 7) 无表单节点：放行
    // =================================================================

    /**
     * 无表单节点（`review`）带任意 `form_data` → 放行，`reason = node_has_no_form`
     *
     * 先让 `apply`（有表单）正常通过，再对 `review`（无表单）带一组「在 apply 上必然被拦」的值：
     * 同一个值在 apply 被拦、在 review 放行，说明拦截是按**节点表单配置**来的，不是按请求体长相来的。
     */
    public function testNodeWithoutFormAcceptsArbitraryFormData(): void
    {
        [$instanceId, $applyTaskId] = $this->startInstanceWithForm(
            $this->insertForm('no_form_review', $this->demoLikeFormSchema())
        );

        [$firstCode] = $this->act('approve', $applyTaskId, [
            'comment'   => '提交申请',
            'form_data' => $this->validPayload(['amount' => 1280]),
        ]);
        $this->assertSame(200, $firstCode, '前置条件：apply 带合规表单值必须通过');

        $reviewTaskId = (int) Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('node_name', 'review')
            ->value('id');
        $this->assertGreaterThan(0, $reviewTaskId, '前置条件：apply 通过后必须生成 review 任务');

        [$code, $body] = $this->act('approve', $reviewTaskId, [
            'comment'   => '复核通过',
            'form_data' => ['amount' => -5, 'anything' => '没有规则可依也不报错'],
        ]);

        $this->assertSame(
            200,
            $code,
            '无表单节点必须放行（否则纯审批节点永远办不完），实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE)
        );

        $validation = $body['data']['form_validation'] ?? [];
        $this->assertSame('node_has_no_form', $validation['reason'] ?? null, '必须如实告知「节点没配表单」');
        $this->assertFalse($validation['validated'] ?? true, '没有规则可依时不得谎报 validated=true');
        $this->assertTrue($validation['skipped'] ?? false, '必须标记为跳过校验');
        $this->assertFalse($validation['needs_attention'] ?? true, '这是正常形态，不需要运维介入');

        $task = Db::table('workflow_tasks')->where('id', $reviewTaskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('completed', (string) $task['status'], '无表单节点带 form_data 也应照常完成');
    }

    // =================================================================
    // 8) 模板缺失：放行 + WARNING + needs_attention
    // =================================================================

    /**
     * 节点配了表单名、本租户却没有这张表单 → 审批**放行**，但必须有标记 + WARNING
     *
     * 口径与 `submitForm()` 完全一致：拦下来会让任务永远无法完成（配置错误变成业务死锁），
     * 但静默放过等于「脏数据无人知晓」，所以响应带标记、日志留痕。
     */
    public function testMissingFormTemplateOnApproveIsSkippedButFlagged(): void
    {
        $missingFormName = self::FORM_PREFIX . 'missing_' . uniqid();

        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . 'missing_' . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', $missingFormName, null)
        );
        [, $taskId] = $this->startAndTakeTask($definitionId);

        // 采集本请求写出的日志：`Http::end()` 结束时 `Log::save()` 会把缓冲清空，
        // 只有 `LogWrite` 事件能在 save() 那一刻拿到完整记录（与 submitForm 用例同法）
        $captured = [];
        $this->app()->event->listen(
            \think\event\LogWrite::class,
            static function (\think\event\LogWrite $event) use (&$captured): void {
                foreach ($event->log as $record) {
                    $captured[] = ['type' => $record->type, 'message' => $record->message];
                }
            }
        );

        [$code, $body] = $this->act('approve', $taskId, [
            'comment'   => '照常审批',
            'form_data' => ['any' => 'value'],
        ]);

        $this->assertSame(
            200,
            $code,
            '模板缺失时必须放行（拦了任务成死数据），实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE)
        );

        $validation = $body['data']['form_validation'] ?? [];
        $this->assertSame('form_not_found', $validation['reason'] ?? null, '必须如实给出原因');
        $this->assertSame($missingFormName, $validation['form_name'] ?? null, '必须回带配的是哪张表单，便于运维定位');
        $this->assertFalse($validation['validated'] ?? true, '跳过了校验就不能说校验过');
        $this->assertTrue($validation['skipped'] ?? false);
        $this->assertTrue($validation['needs_attention'] ?? false, '配置错误必须要求前端提示运维');
        $this->assertStringContainsString(
            $missingFormName,
            (string) ($validation['message'] ?? ''),
            '提示文案必须包含表单名，否则运维还得逐个节点翻定义体'
        );

        $warnings = array_values(array_filter(
            $captured,
            static fn (array $record): bool => $record['type'] === 'warning'
                && is_string($record['message'])
                && str_contains($record['message'], 'WORKFLOW_FORM_TEMPLATE_MISSING')
        ));

        $this->assertNotEmpty(
            $warnings,
            '模板缺失必须留 WARNING 日志（不静默），实际日志：' . json_encode($captured, JSON_UNESCAPED_UNICODE)
        );

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('completed', (string) $task['status'], '不拦 = 照常完成');
    }

    // =================================================================
    // 辅助：夹具
    // =================================================================

    /**
     * 与迁移 `20251230000001_seed_demo_lowcode_form_for_sample_definitions` 的演示表单同形
     *
     * `applicant_name`（require|max:50）/ `reason`（require|max:500）/
     * `amount`（number|egt:0|elt:1000000）/ `remark`（max:200）。
     * 刻意不复用真实表单行：用例必须可独立运行，清理时也不得碰真实数据。
     *
     * @return array<string, mixed>
     */
    private function demoLikeFormSchema(): array
    {
        return [
            'type'       => 'object',
            'title'      => '审批表单校验测试夹具',
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
     * 「全部合规」的表单值，用于只关心某一个字段的用例
     *
     * @param array<string, mixed> $overrides 覆盖字段
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => '张三',
            'reason'         => '差旅报销',
            'amount'         => 800,
            'remark'         => '合规备注',
        ], $overrides);
    }

    /**
     * 插入一张测试表单，返回其 name
     *
     * @param string               $suffix 名称后缀（拼在 `wf_form_approve_` 之后，便于按前缀清理）
     * @param array<string, mixed> $schema 表单 schema
     */
    private function insertForm(string $suffix, array $schema): string
    {
        $name = self::FORM_PREFIX . $suffix . '_' . uniqid();

        $now = date('Y-m-d H:i:s');

        // 直接插 `lowcode_forms`，不经过 FormSchemaManager：避免它的缓存/事件依赖
        Db::table('lowcode_forms')->insert([
            'tenant_id'  => self::TENANT_ID,
            'site_id'    => 0,
            'name'       => $name,
            'title'      => '审批表单校验夹具',
            'schema'     => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'layout'     => 'vertical',
            'status'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $name;
    }

    /**
     * 构造一张「apply 节点带表单指向」的可执行图
     *
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

        return $this->startAndTakeTask($definitionId);
    }

    /**
     * 用现成定义启动实例，返回 [实例ID, apply 任务ID]
     *
     * @return array{0: int, 1: int}
     */
    private function startAndTakeTask(int $definitionId): array
    {
        $response = $this->httpRequest('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'title'         => '审批表单校验实例',
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

        return [$instanceId, $taskId];
    }

    /**
     * 调审批类端点，返回 [HTTP 状态, 响应体数组]
     *
     * @param string               $action  approve|complete|reject
     * @param array<string, mixed> $payload 请求体
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function act(string $action, int $taskId, array $payload): array
    {
        $response = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/' . $action, $payload);

        $body = json_decode($response->getContent(), true);

        return [$response->getCode(), is_array($body) ? $body : []];
    }

    /**
     * 取任务行的关键列快照（用于「前后完全一致」的断言）
     *
     * @return array<string, mixed>
     */
    private function rowOf(int $taskId): array
    {
        $row = Db::table('workflow_tasks')
            ->field(['id', 'status', 'form_data', 'result', 'comment', 'updated_at'])
            ->where('id', $taskId)
            ->find();

        $this->assertIsArray($row, '任务行必须存在');

        return $row;
    }

    /**
     * 实例状态
     */
    private function instanceStatus(int $instanceId): string
    {
        return (string) Db::table('workflow_instances')->where('id', $instanceId)->value('status');
    }

    /**
     * 实例的事件类型列表
     *
     * @return string[]
     */
    private function eventTypes(int $instanceId): array
    {
        return array_map(
            static fn ($row): string => (string) $row['event_type'],
            Db::table('workflow_event_logs')->where('instance_id', $instanceId)->select()->toArray()
        );
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

        // 查询串与路径分开：路由按 pathinfo 匹配，query 只由控制器读 $request->get()
        $query = '';
        if (str_contains($uri, '?')) {
            [$uri, $query] = explode('?', $uri, 2);
        }

        $request->setPathinfo($uri);

        $headers = $headers === [] ? $this->authHeaders() : $headers;

        if ($method !== 'GET') {
            $headers['Content-Type'] = 'application/json';
            $request = $request->withHeader($headers);
            $request->withPost($data);
            $request->withInput(json_encode($data));
        } else {
            parse_str($query, $get);
            $request->withGet($get);
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

            $permissionIds = [
                $this->ensureWorkflowPermission('workflow_instance', 'read', self::PERMISSION_INSTANCE_READ),
                $this->ensureWorkflowPermission('workflow_instance', 'write', self::PERMISSION_INSTANCE_WRITE),
                $this->ensureWorkflowPermission('workflow_task', 'read', self::PERMISSION_TASK_READ),
                $this->ensureWorkflowPermission('workflow_task', 'write', self::PERMISSION_TASK_WRITE),
            ];

            Db::table('users')->insert([
                'id'         => self::USER_ID,
                'tenant_id'  => self::TENANT_ID,
                'username'   => 'wf_approve_form_user',
                'email'      => 'wf_approve_form_user@workflow-approve-form.test',
                'password'   => password_hash('password', PASSWORD_DEFAULT),
                'name'       => '审批表单校验用户',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('roles')->insert([
                'id'         => self::ROLE_ID,
                'tenant_id'  => self::TENANT_ID,
                'name'       => 'wf_approve_form_role',
                'slug'       => 'wf_approve_form_role',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id'    => self::USER_ID,
                'role_id'    => self::ROLE_ID,
                'created_at' => $now,
            ]);

            $rolePermissions = [];

            foreach ($permissionIds as $permissionId) {
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
}
