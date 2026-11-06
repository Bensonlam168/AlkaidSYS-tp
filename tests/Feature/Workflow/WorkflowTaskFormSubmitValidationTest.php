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
 * `POST /v1/workflow/tasks/{id}/form` 的**后端表单校验**端到端测试
 *
 * ## 守住的缺陷
 *
 * 改造前 `WorkflowTaskService::submitForm()` 只判断「`form_data` 是不是非空数组」，
 * **一个字段规则都不跑**：负数金额、超长文本、必填留空都能直接落库。
 * 后果是 AGENTS.md 约定的「后端是唯一权威」并不存在 ——
 * 前端按 `GET /v1/lowcode/forms/{name}?with_validator=1` 的 rules 做的那套校验，
 * 只是「前端本地兜底」，绕过后端（curl / 脚本 / 老前端）就没有任何拦截。
 *
 * 现在提交表单会：
 *   1. 沿 {@see \Infrastructure\Workflow\Support\WorkflowTaskFormResolver} 解出
 *      `form_rules` / `form_messages`（与 `GET /tasks/{id}/form`、
 *      `GET /v1/lowcode/forms/{name}?with_validator=1` **同源**）；
 *   2. 用同一个生成器（`FormValidatorGenerator::buildFromDefinition()`）执行这份 rules；
 *   3. **先校验 → 再落库 → 再推进**（顺序见 `runAction()` 第 4 步）。
 *
 * ## 逐条钉住的语义
 *
 * | 用例 | 断言的核心语义 |
 * | ---- | -------------- |
 * | `testValidFormDataIsPersistedAndProcessAdvances` | 合法数据 → 200、`form_data` 落库、任务完成、推进到下一节点、`validated=true` |
 * | `testMissingRequiredFieldIsRejectedWithReadableFieldError` | 漏 `reason` → 422，`data.errors.reason` 用的是 `form_messages` 的文案 |
 * | `testMultipleViolationsAreReportedTogether` | 多字段同时不合规 → 一次全部报出（批量模式，前端可标多个红字） |
 * | `testNegativeAmountIsRejected` | `amount = -5`（rules `number\|egt:0\|elt:1000000`）→ 422 定位到 `amount` |
 * | `testAmountAboveMaximumIsRejected` | `amount = 2000000` → 422 定位到 `amount` |
 * | `testAmountWithinRangeIsAccepted` | `amount = 1280` → 通过（证明上一条不是「凡 amount 就拦」） |
 * | `testOverlongRemarkIsRejected` | `remark` 超 200 字 → 422 定位到 `remark` |
 * | `testFailedValidationLeavesNoDirtyData` | 422 之后 `workflow_tasks.form_data` / `status` / `updated_at` **完全没变**，且没有留痕事件 |
 * | `testNodeWithoutFormIsSubmittedWithoutValidation` | `node_has_no_form` → 不校验、照常落库（纯审批节点的正常形态） |
 * | `testMissingFormTemplateIsSkippedButFlagged` | `form_not_found` → **不拦**、响应带标记、日志有 WARNING |
 * | `testBackendEnforcesExactlyTheRulesReturnedToFrontend` | 用 `GET /tasks/{id}/form` 返回的 `form_rules` 反推一个违规值，后端确实按它拦截 |
 *
 * ## 为什么每条用例都读库、并且刻意区分 422 的字段名
 *
 * 「返回了 422」不能证明是**表单规则**拦下来的（状态冲突、越权也都是 4xx）。
 * 因此每条都用 `data.errors` 的**字段名 + 文案**定位到具体规则，
 * 关键的正向对照（`amount = 1280` 必须通过）则用来证明「拦的不是整个请求」。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTaskFormSubmitValidationTest extends ThinkPHPTestCase
{
    use WorkflowFixtureIsolation;
    use WorkflowExecutableFixture;

    /** 实例、任务、表单所属租户 */
    private const TENANT_ID = 1;

    /** 主操作人（租户 1；必须是任务的可办理人，见 assertActor 的开放任务口径） */
    private const USER_ID = 6901;

    /** 夹具角色 */
    private const ROLE_ID = 6911;

    /** 权限码对应的固定 id（实际 id 以 ensureWorkflowPermission() 返回值为准） */
    private const PERMISSION_INSTANCE_READ = 6921;
    private const PERMISSION_INSTANCE_WRITE = 6922;
    private const PERMISSION_TASK_READ = 6923;
    private const PERMISSION_TASK_WRITE = 6924;
    private const PERMISSION_LOWCODE_READ = 6925;

    /** 测试定义的名称前缀（清理时按前缀删除） */
    private const DEFINITION_PREFIX = 'form_submit_';

    /** 测试表单的名称前缀（清理时按前缀删除，绝不碰真实表单） */
    private const FORM_PREFIX = 'wf_form_submit_';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 工作流路由 + 低代码表单路由（后者用于「前后端规则同源」对照）
        foreach (['route/workflow.php', 'route/lowcode.php'] as $routeFile) {
            $path = $this->app()->getRootPath() . $routeFile;
            if (file_exists($path)) {
                include_once $path;
            }
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
    // 1) 正向：合法数据落库 + 推进
    // =================================================================

    /**
     * 合法表单 → 200、`form_data` 落库、任务完成、流程推进到下一节点
     */
    public function testValidFormDataIsPersistedAndProcessAdvances(): void
    {
        [$instanceId, $taskId] = $this->startInstanceWithForm($this->insertForm('valid', $this->demoLikeFormSchema()));

        [$code, $body] = $this->submit($taskId, [
            'applicant_name' => '张三',
            'reason'         => '差旅报销',
            'amount'         => 800,
            'remark'         => '高铁票两张',
        ]);

        $this->assertSame(200, $code, '合法表单必须提交成功，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $data = $body['data'] ?? [];
        $this->assertSame($taskId, $data['task_id'] ?? null);
        $this->assertSame('completed', $data['status'] ?? null, '提交后任务必须完成');

        // 校验结论必须回报（前端据此知道「后端真的校验过」）
        $validation = $data['form_validation'] ?? null;
        $this->assertIsArray($validation, '响应必须带 form_validation 标记');
        $this->assertTrue($validation['validated'] ?? false, '合法数据必须标记 validated=true');
        $this->assertFalse($validation['skipped'] ?? true, '合法数据不得标记 skipped');
        $this->assertFalse($validation['needs_attention'] ?? true, '合法数据不需要运维介入');

        // 落库事实
        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('completed', (string) $task['status']);

        $formData = json_decode((string) $task['form_data'], true);
        $this->assertSame(800, $formData['amount'] ?? null, '数值字段必须保持数值落库');
        $this->assertSame('差旅报销', $formData['reason'] ?? null);
        $this->assertSame('高铁票两张', $formData['remark'] ?? null);

        // 流程真的推进了：下一节点（review）产生待办
        $next = Db::table('workflow_tasks')
            ->where('instance_id', $instanceId)
            ->where('status', 'pending')
            ->find();
        $this->assertIsArray($next, '提交表单必须推进流程');
        $this->assertSame('review', (string) $next['node_name']);
    }

    // =================================================================
    // 2) 必填缺失
    // =================================================================

    /**
     * 漏必填项 `reason` → 422，且错误定位到 `reason` 字段、文案来自 `form_messages`
     */
    public function testMissingRequiredFieldIsRejectedWithReadableFieldError(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('missing', $this->demoLikeFormSchema()));

        [$code, $body] = $this->submit($taskId, [
            'applicant_name' => '张三',
            // 刻意漏掉 required 里的 reason
            'amount'         => 800,
        ]);

        $this->assertSame(422, $code, '漏必填项必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame(422, (int) ($body['code'] ?? 0), '业务码与 HTTP 状态一致（ApiController::validationError() 的口径）');

        $errors = $body['data']['errors'] ?? [];
        $this->assertArrayHasKey('reason', $errors, '字段级错误必须能定位到 reason，实际：' . json_encode($errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame(
            '申请事由不能为空',
            $errors['reason'],
            '文案必须取自 form_messages（也就是表单设计器里配的 title），不是 require 这种原始规则串'
        );
        $this->assertArrayNotHasKey('amount', $errors, '合规字段不得被报错（amount=800 在 0..1000000 内）');
    }

    /**
     * 多个字段同时不合规 → **一次全部报出来**（批量模式）
     *
     * 为什么这条重要：只报第一条会让用户「改完金额再提交、又发现备注超长」来回试错；
     * 前端要把红字标在多个输入框上，就必须一次拿到全部字段错误。
     */
    public function testMultipleViolationsAreReportedTogether(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('multi', $this->demoLikeFormSchema()));

        [$code, $body] = $this->submit($taskId, [
            'applicant_name' => '张三',
            // reason 缺失（required）
            'amount'         => 2000000,          // 超上限 elt:1000000
            'remark'         => str_repeat('备', 201), // 超长 max:200
        ]);

        $this->assertSame(422, $code, '多字段不合规必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $errors = $body['data']['errors'] ?? [];
        $fields = array_keys($errors);
        sort($fields);

        $this->assertSame(['amount', 'reason', 'remark'], $fields, '三个不合规字段必须一次全部报出，实际：' . json_encode($errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame('申请事由不能为空', $errors['reason']);
        $this->assertSame('金额不能大于1000000', $errors['amount']);
        $this->assertSame('备注长度不能超过200个字符', $errors['remark']);
    }

    // =================================================================
    // 3) 类型 / 范围
    // =================================================================

    /**
     * `amount = -5`：rules 是 `number|egt:0|elt:1000000` → egt 失败
     */
    public function testNegativeAmountIsRejected(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('negative', $this->demoLikeFormSchema()));

        [$code, $body] = $this->submit($taskId, $this->validPayload(['amount' => -5]));

        $this->assertSame(422, $code, '负数金额必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $errors = $body['data']['errors'] ?? [];
        $this->assertSame('金额不能小于0', $errors['amount'] ?? null, '错误必须定位到 amount 并说明范围');
    }

    /**
     * `amount = 2000000`：超出 `elt:1000000`
     */
    public function testAmountAboveMaximumIsRejected(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('above_max', $this->demoLikeFormSchema()));

        [$code, $body] = $this->submit($taskId, $this->validPayload(['amount' => 2000000]));

        $this->assertSame(422, $code, '超上限金额必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $errors = $body['data']['errors'] ?? [];
        $this->assertSame('金额不能大于1000000', $errors['amount'] ?? null);
    }

    /**
     * `amount = 1280`：范围内的正常值必须**通过**（证明上面两条不是「凡 amount 就拦」）
     */
    public function testAmountWithinRangeIsAccepted(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('in_range', $this->demoLikeFormSchema()));

        [$code, $body] = $this->submit($taskId, $this->validPayload(['amount' => 1280]));

        $this->assertSame(200, $code, '1280 在 0..1000000 内必须通过，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame(1280, json_decode((string) $task['form_data'], true)['amount'] ?? null);
    }

    // =================================================================
    // 4) 长度
    // =================================================================

    /**
     * `remark` 超过 200 字 → 422（rules 为 `max:200`）
     */
    public function testOverlongRemarkIsRejected(): void
    {
        [, $taskId] = $this->startInstanceWithForm($this->insertForm('too_long', $this->demoLikeFormSchema()));

        $remark = str_repeat('备', 201);

        [$code, $body] = $this->submit($taskId, $this->validPayload(['remark' => $remark]));

        $this->assertSame(422, $code, '超长备注必须 422，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $errors = $body['data']['errors'] ?? [];
        $this->assertSame('备注长度不能超过200个字符', $errors['remark'] ?? null);
    }

    // =================================================================
    // 5) 校验失败 → 库里没有脏数据
    // =================================================================

    /**
     * 422 之后任务行必须**一个字节都没变**：`form_data` / `status` / `updated_at` 全等，
     * 且没有 `task_form_submitted` 留痕、没有产生新任务
     *
     * 这是「先校验、再落库」的直接证据：改造前 `form_data` 在推进之前就会被写进去，
     * 于是「用户看到 422」与「库里已有值」同时成立 —— 那正是这条测试要钉死的形态。
     */
    public function testFailedValidationLeavesNoDirtyData(): void
    {
        [$instanceId, $taskId] = $this->startInstanceWithForm($this->insertForm('dirty', $this->demoLikeFormSchema()));

        // 前置：先造一个「用户之前存过的草稿」形态，断言它不被覆盖
        Db::table('workflow_tasks')->where('id', $taskId)->update([
            'form_data'  => json_encode(['applicant_name' => '张三', 'reason' => '草稿'], JSON_UNESCAPED_UNICODE),
            'updated_at' => '2025-01-01 00:00:00',
        ]);

        $before = $this->rowOf($taskId);

        [$code, $body] = $this->submit($taskId, [
            'applicant_name' => '张三',
            'reason'         => '差旅报销',
            'amount'         => -5,          // 唯一违规点：负数金额
        ]);

        $this->assertSame(422, $code, '负数金额必须 422');

        $after = $this->rowOf($taskId);

        $this->assertSame($before['status'], $after['status'], '422 后任务状态必须不变');
        $this->assertSame($before['form_data'], $after['form_data'], '422 后 form_data 必须保持原样（不得先写后校验）');
        $this->assertSame($before['updated_at'], $after['updated_at'], '422 后 updated_at 必须不变（说明这一行根本没被写过）');

        // 没有推进：实例下不应该出现第二个任务
        $this->assertSame(
            1,
            Db::table('workflow_tasks')->where('instance_id', $instanceId)->count(),
            '422 之后不得推进流程（不该产生新任务）'
        );

        // 没有留痕：task_form_submitted 只能是「真的提交成功」时才写
        $this->assertNotContains(
            'task_form_submitted',
            $this->eventTypes($instanceId),
            '校验失败不得写 task_form_submitted 事件'
        );
    }

    // =================================================================
    // 6) 无表单节点：不校验、照常落库
    // =================================================================

    /**
     * `node_has_no_form`：节点没配表单（纯审批节点的正常形态）→ 不校验，提交照常成功
     */
    public function testNodeWithoutFormIsSubmittedWithoutValidation(): void
    {
        // approvalGraph() 的节点 properties 里没有 form
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . 'no_form_' . uniqid());
        $taskId       = $this->startAndTakeTask($definitionId);

        [$code, $body] = $this->submit($taskId, ['anything' => '没有规则可依也不报错', 'amount' => -5]);

        $this->assertSame(200, $code, '无表单节点必须放行，实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $validation = $body['data']['form_validation'] ?? [];
        $this->assertSame('node_has_no_form', $validation['reason'] ?? null, '必须如实告知「节点没配表单」');
        $this->assertFalse($validation['validated'] ?? true, '没有规则可依时不得谎报 validated=true');
        $this->assertTrue($validation['skipped'] ?? false, '必须标记为跳过校验');
        $this->assertFalse($validation['needs_attention'] ?? true, '这是正常形态，不需要运维介入');

        $task = Db::table('workflow_tasks')->where('id', $taskId)->find();
        $this->assertIsArray($task);
        $this->assertSame('completed', (string) $task['status'], '无表单节点提交后照常完成任务');
        $this->assertSame(
            '没有规则可依也不报错',
            json_decode((string) $task['form_data'], true)['anything'] ?? null,
            '无表单节点提交的内容照常落库'
        );
    }

    // =================================================================
    // 7) 模板缺失：不拦，但必须有标记 + WARNING
    // =================================================================

    /**
     * `form_not_found`：节点配了表单名、本租户却没有这张表单
     *
     * 期望：**不拦**（拦了任务永远无法完成 = 把配置错误变成业务死锁），
     * 但响应必须带 `needs_attention=true` 的标记，日志必须有 WARNING。
     */
    public function testMissingFormTemplateIsSkippedButFlagged(): void
    {
        $missingFormName = self::FORM_PREFIX . 'missing_' . uniqid();

        $definitionId = $this->insertExecutableDefinition(
            self::DEFINITION_PREFIX . 'missing_form_' . uniqid(),
            self::TENANT_ID,
            $this->approvalGraphWithForm('placeholder', $missingFormName, null)
        );
        $taskId = $this->startAndTakeTask($definitionId);

        // 采集本请求写出的日志。为什么不能直接 `Log::getLog()`：
        // `Http::end()` 会在请求结束时 `Log::save()`，而 `Channel::save()` 收尾会把缓冲清空
        // （`$this->log = []`），于是测试里读到的永远是空数组。
        // `LogWrite` 事件正是在 save() 的那一刻触发，能拿到这一批完整记录。
        $captured = [];
        $this->app()->event->listen(
            \think\event\LogWrite::class,
            static function (\think\event\LogWrite $event) use (&$captured): void {
                foreach ($event->log as $record) {
                    $captured[] = ['type' => $record->type, 'message' => $record->message];
                }
            }
        );

        [$code, $body] = $this->submit($taskId, ['any' => 'value']);

        $this->assertSame(200, $code, '模板缺失时必须放行（否则任务成死数据），实际响应：' . json_encode($body, JSON_UNESCAPED_UNICODE));

        $validation = $body['data']['form_validation'] ?? [];
        $this->assertSame('form_not_found', $validation['reason'] ?? null, '必须如实给出原因');
        $this->assertSame($missingFormName, $validation['form_name'] ?? null, '必须回带配的是哪张表单，便于运维定位');
        $this->assertFalse($validation['validated'] ?? true, '跳过了校验就不能说校验过');
        $this->assertTrue($validation['skipped'] ?? false);
        $this->assertTrue($validation['needs_attention'] ?? false, '配置错误必须要求前端提示运维');
        $this->assertNotEmpty($validation['message'] ?? null, 'needs_attention 时必须给一句人话');
        $this->assertStringContainsString(
            $missingFormName,
            (string) $validation['message'],
            '提示文案必须包含表单名，否则运维还得逐个节点翻定义体'
        );

        // 日志：WARNING 且带标记与定位字段
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
    // 8) 前端拿到的 rules == 后端执行的 rules
    // =================================================================

    /**
     * 用 `GET /v1/workflow/tasks/{id}/form` 返回的 `form_rules` **反推**一个违规值，
     * 后端必须按它拦截 —— 这就是「前端拿到的规则」与「后端执行的规则」是同一份的证据
     *
     * 另外对照 `GET /v1/lowcode/forms/{name}?with_validator=1&with_parsed=1` 的
     * `data.validator.rules`：三者必须逐字相同（同一套 `FormValidatorManager`）。
     */
    public function testBackendEnforcesExactlyTheRulesReturnedToFrontend(): void
    {
        $formName = $this->insertForm('same_rules', $this->demoLikeFormSchema());
        [, $taskId] = $this->startInstanceWithForm($formName);

        // 1）工作流端点返回给前端的规则
        $formResponse = $this->httpRequest('GET', 'v1/workflow/tasks/' . $taskId . '/form');
        $this->assertSame(200, $formResponse->getCode(), '读取任务表单应成功');
        $formData = json_decode($formResponse->getContent(), true)['data'] ?? [];
        $formRules = $formData['form_rules'] ?? [];

        $this->assertSame('number|egt:0|elt:1000000', $formRules['amount'] ?? null);
        $this->assertSame('require|max:500', $formRules['reason'] ?? null);
        $this->assertSame('max:200', $formRules['remark'] ?? null);

        // 2）低代码表单端点返回的规则（AGENTS.md 指定的「前端复用后端校验」入口）
        $lowcodeResponse = $this->httpRequest(
            'GET',
            'v1/lowcode/forms/' . $formName . '?with_validator=1&with_parsed=1'
        );
        $this->assertSame(200, $lowcodeResponse->getCode(), '读取低代码表单应成功，实际：' . $lowcodeResponse->getContent());
        $lowcode = json_decode($lowcodeResponse->getContent(), true)['data'] ?? [];

        $this->assertSame(
            $lowcode['validator']['rules'] ?? [],
            $formRules,
            '工作流端点与低代码端点的 rules 必须逐字相同（同一套生成器，不是两份映射）'
        );
        $this->assertSame(
            $lowcode['validator']['messages'] ?? [],
            $formData['form_messages'] ?? [],
            'messages 同理：前端拿到的文案就是后端报错用的文案'
        );

        // 3）按**返回的规则**反推违规值：`elt:1000000` → 1000001 必须被拦
        preg_match('/elt:(\d+)/', (string) $formRules['amount'], $matches);
        $this->assertNotEmpty($matches, '前置条件：amount 规则里应有 elt 上界');
        $derivedViolation = (int) $matches[1] + 1;

        [$code, $body] = $this->submit($taskId, $this->validPayload(['amount' => $derivedViolation]));

        $this->assertSame(
            422,
            $code,
            '按返回的 rules 反推出的违规值必须被后端拦下（规则同源），实际响应：'
            . json_encode($body, JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame(
            '金额不能大于1000000',
            $body['data']['errors']['amount'] ?? null,
            '后端报错用的正是 form_messages 里那条文案'
        );
    }

    // =================================================================
    // 辅助：夹具
    // =================================================================

    /**
     * 与迁移 `20251230000001_seed_demo_lowcode_form_for_sample_definitions` 的演示表单同形
     *
     * 字段与规则一一对应 AGENTS.md 里提到的那张 demo 表单：
     * `applicant_name`（require|max:50）/ `reason`（require|max:500）/
     * `amount`（number|egt:0|elt:1000000）/ `remark`（max:200）。
     * 刻意**不**复用迁移里的真实表单行：用例必须可独立运行、且清理时不得碰真实数据。
     *
     * @return array<string, mixed>
     */
    private function demoLikeFormSchema(): array
    {
        return [
            'type'       => 'object',
            'title'      => '工作流表单提交校验测试夹具',
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
     * 「全部合规」的提交体，用于只关心某一个字段的用例
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
     * @param string               $suffix 名称后缀（拼在 `wf_form_submit_` 之后，便于按前缀清理）
     * @param array<string, mixed> $schema 表单 schema
     */
    private function insertForm(string $suffix, array $schema): string
    {
        $name = self::FORM_PREFIX . $suffix . '_' . uniqid();

        $now = date('Y-m-d H:i:s');

        // 直接插 `lowcode_forms`，不经过 FormSchemaManager：避免它的缓存/事件依赖，
        // 与 WorkflowTaskFormResolutionTest 的夹具口径保持一致
        Db::table('lowcode_forms')->insert([
            'tenant_id'  => self::TENANT_ID,
            'site_id'    => 0,
            'name'       => $name,
            'title'      => '工作流表单提交校验夹具',
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

        $taskId     = $this->startAndTakeTask($definitionId);
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
            'title'         => '表单提交校验实例',
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
     * 提交表单，返回 [HTTP 状态, 响应体数组]
     *
     * @param array<string, mixed> $formData
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function submit(int $taskId, array $formData): array
    {
        $response = $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/form', [
            'form_data' => $formData,
            'comment'   => '表单提交校验用例',
        ]);

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
            ->field(['id', 'status', 'form_data', 'comment', 'updated_at'])
            ->where('id', $taskId)
            ->find();

        $this->assertIsArray($row, '任务行必须存在');

        return $row;
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
                // 「前后端规则同源」用例需要读低代码表单详情
                $this->ensureWorkflowPermission('lowcode', 'read', self::PERMISSION_LOWCODE_READ),
            ];

            Db::table('users')->insert([
                'id'         => self::USER_ID,
                'tenant_id'  => self::TENANT_ID,
                'username'   => 'wf_form_submit_user',
                'email'      => 'wf_form_submit_user@workflow-form-submit.test',
                'password'   => password_hash('password', PASSWORD_DEFAULT),
                'name'       => '表单提交校验用户',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('roles')->insert([
                'id'         => self::ROLE_ID,
                'tenant_id'  => self::TENANT_ID,
                'name'       => 'wf_form_submit_role',
                'slug'       => 'wf_form_submit_role',
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
