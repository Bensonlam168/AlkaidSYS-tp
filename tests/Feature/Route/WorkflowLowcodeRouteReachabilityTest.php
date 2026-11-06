<?php

declare(strict_types=1);

namespace Tests\Feature\Route;

use app\controller\workflow\WorkflowTriggerController;
use Infrastructure\I18n\LanguageService;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\FieldManager;
use Infrastructure\Lowcode\Collection\Service\RelationshipManager;
use Mockery;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;

/**
 * 工作流 / 低代码路由可达性测试（缺陷 #2：7 条路由被父级 `:id` 前缀匹配遮蔽）
 *
 * ## 守住的缺陷
 *
 * `config/route.php` 的 `route_complete_match = false` 让规则变成**前缀匹配**，
 * 于是「先注册的 `:id` / `:name`」会吃掉「后注册的 `:id/xxx`」——**不是 404，而是走错动作**：
 *
 * ```
 * DELETE /v1/lowcode/collections/<name>/fields/<field>       → 命中 CollectionController@delete  ← 会删掉整张集合
 * DELETE /v1/lowcode/collections/<name>/relationships/<rel>  → 命中 CollectionController@delete
 * GET    /v1/workflow/instances/<id>/history                 → 命中 WorkflowInstanceController@read
 * GET    /v1/workflow/instances/<id>/variables               → 命中 WorkflowInstanceController@read
 * GET    /v1/workflow/tasks/<id>/form                        → 命中 WorkflowTaskController@read
 * GET    /v1/workflow/triggers/<id>/logs                     → 命中 WorkflowTriggerController@read
 * GET    /v1/workflow/triggers/webhook/<key>                 → 命中 WorkflowTriggerController@read  ← webhook 回调 100% 失效
 * ```
 *
 * ## 两层验证（都不"只看 200"）
 *
 * 1. **路由层**（`testEveryWorkflowAndLowcodeRouteReachesItsOwnAction`）：用框架**自己的**
 *    匹配逻辑（`think\Route::check()`，与真实请求同一套代码）把每条规则的样例 URL 重新解析一遍，
 *    断言「命中的目标 == 这条规则本该命中的目标」。这一层能在**不发请求**的情况下定位到具体哪条规则被谁遮蔽。
 * 2. **行为层**（其余用例）：走完整链路「路由 → 全局中间件 → Auth → Permission → 控制器」，
 *    用**响应数据形状 / 副作用**判断到底进了哪个动作 —— 因为这些端点的成功文案都是
 *    `Success`（`ApiController::success()` 的默认值），只看 HTTP 200 与 message 分不出
 *    `read()` 还是 `history()`。
 *
 * ## 为什么可以放心用「响应形状」判定
 *
 * 三个控制器各动作返回的字段集合是**互斥**的：
 *
 * | 端点 | 正确动作返回 | 被遮蔽时会返回 |
 * | --- | --- | --- |
 * | `instances/<id>/history` | `{instance_id, entries, count}` | 实例对象（含 `definition_id`） |
 * | `instances/<id>/variables` | `{instance_id, variables}` | 实例对象（含 `definition_id`） |
 * | `tasks/<id>/form` | `{task_id, form_schema, form_data, form_rules}` | 任务对象（含 `status`、`history`） |
 * | `triggers/<id>/logs` | 分页结构 `{list, total, page, page_size, total_pages}` | 触发器对象（含 `webhook_key`） |
 *
 * ## 夹具形态（2026 更新：实例/任务已改为真库）
 *
 * `WorkflowInstanceController` / `WorkflowTaskController` 已从**进程内静态数组**（demo 实现）
 * 改为真库 + 真引擎，因此这里的夹具必须是真的：
 *   - 实例：插入一条可执行定义（LogicFlow `{nodes, edges}`）后走 `POST /instances` 真启动，
 *     拿到的是**真实实例主键**（不再是 `inst_xxx` 字符串）；
 *   - 任务：实例 → 任务行直插（见 `Tests\Traits\WorkflowExecutableFixture`），
 *     因为任务由引擎在推进时产生，本测试只需要「一条属于本租户的任务」用于路由判定。
 * `WorkflowTriggerController` 仍是 Mock 实现（下一个包才改），它的夹具保持原样。
 *
 * ## 低代码侧用 Mock 而不是真建表
 *
 * 「删字段不再删整张集合」的关键证据是**哪个 manager 被调用**：
 * 正确答案是 `FieldManager::removeField()`（且 `CollectionManager::delete()` **一次都不能被调用**）。
 * 用 Mockery 钉住这两个副作用，比真的建表 + DDL 更精确，也不会在测试库留下物理表。
 *
 * 非 Mock 依赖（真实链路）：Auth（JWT）+ Permission（`lowcode.delete` 等）中间件都会真实执行，
 * 用的是测试库 seeded admin（用户 1 / 角色 1，持有全部权限行）。
 *
 * @package Tests\Feature\Route
 */
class WorkflowLowcodeRouteReachabilityTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowExecutableFixture;

    /**
     * 只审计这两个前缀下的路由（与 `route/workflow.php` / `route/lowcode.php` 一一对应）
     */
    private const AUDIT_PREFIXES = ['/v1/workflow/', '/v1/lowcode/'];

    /**
     * 真库夹具定义的名称前缀（清理时按前缀删除）
     */
    private const DEFINITION_PREFIX = 'route_reachability_';

    /**
     * 审计到的规则数下限（防止"一条规则都没收集到 → 断言全部通过"这种假绿）
     */
    private const EXPECTED_MIN_RULES = 40;

    /**
     * 租户 / 操作者：seeded admin（角色 1 持有测试库全部 52 条权限）
     */
    private const TENANT_ID = 1;
    private const ADMIN_USER_ID = 1;

    /**
     * 低代码夹具名（只出现在 Mock 的期望参数里，不落库）
     */
    private const COLLECTION_NAME = 'probe_collection';
    private const FIELD_NAME = 'probe_field';
    private const RELATIONSHIP_NAME = 'probe_relationship';

    /**
     * 最近一次被派发的请求（用于断言"到底进了哪个控制器动作"）
     */
    private ?\think\Request $lastRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        // 让容器解析 think\Request 时拿到 app\Request（否则取不到 userId/tenantId 上下文）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册本次负责的两份路由（include_once：进程内只注册一次）
        foreach (['route/workflow.php', 'route/lowcode.php'] as $routeFile) {
            $path = $this->app()->getRootPath() . $routeFile;
            if (file_exists($path)) {
                include_once $path;
            }
        }

        // 复位鉴权中间件实例：其它测试类可能把它们绑成 no-op 替身（容器会缓存首次解析结果）
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
        $this->unbindLowcodeManagers();

        Mockery::close();

        parent::tearDown();
    }

    // =====================================================================
    // 路由层：每条规则都必须命中它自己注册的动作
    // =====================================================================

    /**
     * 静态审计：`v1/workflow/**` 与 `v1/lowcode/**` 的每条规则都要命中自己的动作
     *
     * 与真实请求共用 `think\Route::check()`，因此"优先级/前缀匹配"这类问题
     * 会在这里直接暴露，而不必逐条构造请求。
     */
    public function testEveryWorkflowAndLowcodeRouteReachesItsOwnAction(): void
    {
        $app = $this->app();
        $router = $app->route;

        // `Route::check()` 读的是注入的 request（真实请求里由 dispatch() 写入），这里反射注入
        $requestProperty = new \ReflectionProperty(\think\Route::class, 'request');
        $requestProperty->setAccessible(true);

        $shadowed = [];
        $audited = 0;

        foreach ($router->getRuleList() as $item) {
            $rule = (string) ($item['rule'] ?? '');
            if ($rule === '' || str_contains($rule, '<MISS>')) {
                continue;
            }

            $path = '/' . ltrim($rule, '/');
            if (!$this->isAuditedPath($path)) {
                continue;
            }

            // `Route::any()` 注册的规则 method 是 `*`；审计时用 GET 代表即可
            $method = strtoupper((string) ($item['method'] ?: 'GET'));
            if ($method === '*') {
                $method = 'GET';
            }

            $expected = $this->normalizeTarget($item['route']);

            // 样例 URL：路由变量按该规则自己的 pattern 生成（`:key` 是 `[a-f0-9]{32}`，
            // 兜底填 '1' 会让"webhook 规则"永远匹配不上，审计就假绿了）
            $sample = $this->sampleUrl($rule, (array) ($item['pattern'] ?? []));

            $request = $app->make(\think\Request::class);
            $request->setMethod($method);
            $request->setPathinfo($sample);
            $requestProperty->setValue($router, $request);

            // 与真实请求完全一致的匹配（第二参数 = config('route.route_complete_match') = false，
            // 规则自身的 complete_match 选项会覆盖它）
            $dispatch = $router->check(str_replace('/', '|', $sample), false);

            $audited++;

            if ($dispatch === false) {
                $shadowed[] = sprintf('%s %s -> 无规则命中（期望 %s）', $method, $path, $expected);
                continue;
            }

            $actual = $this->normalizeTarget($dispatch->getDispatch());

            if ($actual !== $expected) {
                $shadowed[] = sprintf('%s %s -> 实际命中 %s（期望 %s）', $method, $path, $actual, $expected);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::EXPECTED_MIN_RULES,
            $audited,
            sprintf('审计到的 workflow/lowcode 规则只有 %d 条，低于下限（说明路由没注册上，审计会假绿）', $audited)
        );

        $this->assertSame(
            [],
            $shadowed,
            "以下路由被更早注册的规则前缀匹配遮蔽（请求会静默走错动作）：\n" . implode("\n", $shadowed)
        );
    }

    /**
     * 带参数的路由规则必须显式声明 `completeMatch()`
     *
     * 上一条用例只能抓住"**当前**已被遮蔽"的情况；本用例防的是**未来**：
     * 新加一条 `:id/xxx` 却忘了 `completeMatch()` 时，行为可能恰好正确（注册顺序在父级之前），
     * 也可能在别人调整顺序后悄悄变成遮蔽。把"必须完整匹配"这条约定钉在规则本身上。
     */
    public function testEveryParameterisedRuleDeclaresCompleteMatch(): void
    {
        $missing = [];

        foreach ($this->app()->route->getRuleList() as $item) {
            $rule = (string) ($item['rule'] ?? '');
            if ($rule === '' || str_contains($rule, '<MISS>') || !str_contains($rule, '<')) {
                continue;
            }

            if (!$this->isAuditedPath('/' . ltrim($rule, '/'))) {
                continue;
            }

            $option = (array) ($item['option'] ?? []);
            if (empty($option['complete_match'])) {
                $missing[] = sprintf('%s %s', strtoupper((string) $item['method']), $rule);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "以下带参数的路由规则没有声明 ->completeMatch()（会被父级 :id 前缀匹配吃掉，或将来被吃掉）：\n"
            . implode("\n", $missing)
        );
    }

    // =====================================================================
    // 行为层：工作流 5 条路由
    // =====================================================================

    /**
     * `GET v1/workflow/instances/<id>/history` 必须进 history()，不是 read()
     */
    public function testInstanceHistoryRouteReachesHistoryAction(): void
    {
        $instanceId = $this->createWorkflowInstance();

        $response = $this->request('GET', 'v1/workflow/instances/' . $instanceId . '/history');
        $body = $this->json($response);
        $data = $body['data'] ?? [];

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            $this->sortedKeys(['instance_id', 'entries', 'count']),
            $this->sortedKeys($data),
            'history() 返回的是 {instance_id, entries, count}；若这里出现 definition_id/status，'
            . '说明命中的是 read()（路由仍被父级 :id 遮蔽）。' . $this->diagnostics($response)
        );
        $this->assertSame($instanceId, $data['instance_id'] ?? null);
        $this->assertGreaterThanOrEqual(
            1,
            (int) ($data['count'] ?? 0),
            '启动实例时引擎写入了 instance_started 事件，count 至少为 1'
        );
    }

    /**
     * `GET v1/workflow/instances/<id>/variables` 必须进 getVariables()，不是 read()
     */
    public function testInstanceVariablesRouteReachesGetVariablesAction(): void
    {
        $instanceId = $this->createWorkflowInstance(['probe_key' => 'probe_value']);

        $response = $this->request('GET', 'v1/workflow/instances/' . $instanceId . '/variables');
        $body = $this->json($response);
        $data = $body['data'] ?? [];

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            $this->sortedKeys(['instance_id', 'variables']),
            $this->sortedKeys($data),
            'getVariables() 返回的是 {instance_id, variables}；出现 definition_id/status 说明命中了 read()。'
            . $this->diagnostics($response)
        );
        // 只断言「请求里传的变量原样出现在实例变量里」：
        // 真引擎启动时会额外写入自己的变量（如 `auto_gen_title`），因此不能再要求整份相等 ——
        // 那会把「引擎的正常行为」误报成路由缺陷，而本用例要判定的是**进了哪个动作**。
        $this->assertIsArray($data['variables'] ?? null);
        $this->assertSame(
            'probe_value',
            $data['variables']['probe_key'] ?? null,
            '返回的必须是实例变量本身（read() 会把它藏在实例对象里，形状不同）'
            . $this->diagnostics($response)
        );
    }

    /**
     * `GET v1/workflow/tasks/<id>/form` 必须进 getForm()，不是 read()
     */
    public function testTaskFormRouteReachesGetFormAction(): void
    {
        $taskId = $this->seedTask();

        $response = $this->request('GET', 'v1/workflow/tasks/' . $taskId . '/form');
        $body = $this->json($response);
        $data = $body['data'] ?? [];

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            $this->sortedKeys(['form_data', 'form_rules', 'form_schema', 'task_id']),
            $this->sortedKeys($data),
            'getForm() 返回的是 {task_id, form_schema, form_data, form_rules}；出现 status/history 说明命中了 read()。'
            . $this->diagnostics($response)
        );
        $this->assertSame($taskId, $data['task_id'] ?? null);
    }

    /**
     * `GET v1/workflow/triggers/<id>/logs` 必须进 logs()，不是 read()
     */
    public function testTriggerLogsRouteReachesLogsAction(): void
    {
        $trigger = $this->createWorkflowTrigger();

        $response = $this->request('GET', 'v1/workflow/triggers/' . $trigger['id'] . '/logs');
        $body = $this->json($response);
        $data = $body['data'] ?? [];

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            $this->sortedKeys(['list', 'page', 'page_size', 'total', 'total_pages']),
            $this->sortedKeys($data),
            'logs() 返回的是分页结构 {list, total, page, page_size, total_pages}；'
            . '出现 webhook_key/type 说明命中了 read()。' . $this->diagnostics($response)
        );
        $this->assertGreaterThanOrEqual(1, (int) ($data['total'] ?? 0), '创建触发器时写过 CREATED 日志');
    }

    /**
     * `GET v1/workflow/triggers/webhook/<key>` 必须进 webhook()，且**不带 token** 也能调用
     *
     * 这条是本缺陷里危害最大的：修好之前它恒被 `GET triggers/:id` 吃掉，
     * 表现为「外部回调永远 404/ unauthorized」，即 webhook 100% 失效。
     */
    public function testWebhookRouteIsPublicAndReachesWebhookAction(): void
    {
        $trigger = $this->createWorkflowTrigger();
        $key = (string) ($trigger['webhook_key'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key, '触发器必须带 32 位十六进制 webhook_key');

        // 刻意不带 Authorization：webhook 是外部回调，靠 key（+ 可选 HMAC 签名/IP 白名单）鉴权
        // 方法用 POST：controller 的 `config['http_methods']` 默认只允许 POST（GET 会被它 405 掉，
        // 这也从另一个方向证明"请求真的进了 webhook()"，见下一条断言）。
        $response = $this->request(
            'POST',
            'v1/workflow/triggers/webhook/' . $key,
            ['hello' => 'world'],
            false
        );
        $body = $this->json($response);

        $lang = new LanguageService();

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            $lang->trans('workflow.webhook_processed'),
            $body['message'] ?? null,
            '成功文案必须是 webhook 专用的（read() 的文案是 Success）。' . $this->diagnostics($response)
        );
        $this->assertTrue((bool) ($body['data']['triggered'] ?? false), 'webhook 必须真的触发');
        $this->assertSame(
            ['hello' => 'world'],
            $body['data']['variables'] ?? null,
            'webhook 必须把请求体当作实例变量透传'
        );
    }

    /**
     * webhook 的 key 鉴权：错误的 key 必须 404，而不是"随便什么 key 都能触发"
     */
    public function testWebhookRejectsUnknownKey(): void
    {
        $this->createWorkflowTrigger();

        // 合法形态（32 位十六进制）但不存在的 key
        $response = $this->request('POST', 'v1/workflow/triggers/webhook/' . str_repeat('f', 32), [], false);
        $body = $this->json($response);
        $lang = new LanguageService();

        $this->assertSame(404, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            $lang->trans('workflow.webhook_not_found'),
            $body['message'] ?? null,
            $this->diagnostics($response)
        );
    }

    // =====================================================================
    // 行为层：低代码 2 条路由（关键证据是"没有删整张集合"）
    // =====================================================================

    /**
     * `DELETE v1/lowcode/collections/<name>/fields/<field>` 只能删字段，**绝不能删集合**
     *
     * 修好之前：请求会被 `DELETE collections/:name` 前缀匹配吃掉，执行的是
     * `CollectionManager::delete($name, dropTable: true)` —— 一次"删字段"把整张表删掉。
     */
    public function testCollectionFieldDeleteRouteDoesNotDeleteCollection(): void
    {
        $collectionManager = Mockery::mock(CollectionManager::class);
        $fieldManager = Mockery::mock(FieldManager::class);

        // 核心断言：整表删除一次都不许发生
        $collectionManager->shouldReceive('delete')->never();

        $fieldManager->shouldReceive('removeField')
            ->once()
            ->with(self::COLLECTION_NAME, self::FIELD_NAME, self::TENANT_ID, Mockery::any())
            ->andReturnNull();

        $this->app()->bind(CollectionManager::class, static fn () => $collectionManager);
        $this->app()->bind(FieldManager::class, static fn () => $fieldManager);

        $response = $this->request(
            'DELETE',
            'v1/lowcode/collections/' . self::COLLECTION_NAME . '/fields/' . self::FIELD_NAME
        );
        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            'Field removed successfully',
            $body['message'] ?? null,
            '命中的必须是 FieldController::delete()；文案若是 collection 相关，说明仍在删整张集合。'
            . $this->diagnostics($response)
        );
    }

    /**
     * `DELETE v1/lowcode/collections/<name>/relationships/<rel>` 只能删关系，**绝不能删集合**
     */
    public function testCollectionRelationshipDeleteRouteDoesNotDeleteCollection(): void
    {
        $collectionManager = Mockery::mock(CollectionManager::class);
        $relationshipManager = Mockery::mock(RelationshipManager::class);

        $collectionManager->shouldReceive('delete')->never();

        $relationshipManager->shouldReceive('removeRelationship')
            ->once()
            ->with(
                self::COLLECTION_NAME,
                self::RELATIONSHIP_NAME,
                Mockery::type('bool'),
                self::TENANT_ID,
                Mockery::any()
            )
            ->andReturnNull();

        $this->app()->bind(CollectionManager::class, static fn () => $collectionManager);
        $this->app()->bind(RelationshipManager::class, static fn () => $relationshipManager);

        $response = $this->request(
            'DELETE',
            'v1/lowcode/collections/' . self::COLLECTION_NAME . '/relationships/' . self::RELATIONSHIP_NAME
        );
        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(
            'Relationship removed successfully',
            $body['message'] ?? null,
            '命中的必须是 RelationshipController::delete()。' . $this->diagnostics($response)
        );
    }

    // =====================================================================
    // 辅助
    // =====================================================================

    /**
     * 发起一次真实 JSON 请求（走完整「路由 → 全局中间件 → Auth → Permission → 控制器」链路）
     *
     * @param string $method HTTP 方法
     * @param string $uri 路径（不含前导斜杠）
     * @param array<string, mixed> $data 请求体
     * @param bool $withToken 是否带 JWT（webhook 用例刻意不带）
     * @return \think\Response
     */
    private function request(string $method, string $uri, array $data = [], bool $withToken = true)
    {
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $request = $request->withHeader([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) self::TENANT_ID,
        ]);

        if ($data !== []) {
            $request->withInput((string) json_encode($data));
        }

        if ($withToken) {
            $request = $request->withHeader([
                'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                    self::ADMIN_USER_ID,
                    self::TENANT_ID,
                    0
                ),
            ]);
        }

        $this->lastRequest = $request;

        return $this->runHttp($request);
    }

    /**
     * 解析响应 JSON
     *
     * @param \think\Response $response 响应
     * @return array<string, mixed>
     */
    private function json($response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        $this->assertIsArray($decoded, '响应必须是 JSON：' . $this->diagnostics($response));

        return $decoded;
    }

    /**
     * 失败时把"实际命中的控制器动作 + 原始响应体"一起打出来
     *
     * 断言失败信息里必须能看到"到底进了哪个动作"，否则只能看到用例红，复现不了遮蔽。
     *
     * @param \think\Response $response 响应
     * @return string
     */
    private function diagnostics($response): string
    {
        return sprintf(
            "\n  实际命中：%s\n  原始响应：%s",
            $this->hitAction(),
            (string) $response->getContent()
        );
    }

    /**
     * 本次请求实际派发到的控制器动作（由 `ControllerDispatch::parseDispatch()` 写入 request）
     *
     * @return string 形如 `WorkflowInstanceController@history`
     */
    private function hitAction(): string
    {
        if ($this->lastRequest === null) {
            return '(未知：未发请求)';
        }

        $controller = (string) $this->lastRequest->controller();
        $action = (string) $this->lastRequest->action();

        // 控制器名可能是 `workflow.WorkflowInstanceController` 或带命名空间的全名，统一取短名
        $controller = class_basename(str_replace('.', '\\', $controller));

        return $controller . '@' . $action;
    }

    /**
     * 取出"形状"并排序（与字段顺序无关）
     *
     * 支持两种入参形态：
     *   - 关联数组（响应 data）：取**键名**；
     *   - 列表（期望值字面量）：直接取**值**。
     *
     * @param array $data 响应 data 或期望的键名列表
     * @return array<int, string>
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_is_list($data) ? array_values($data) : array_keys($data);
        $keys = array_map('strval', $keys);
        sort($keys);

        return $keys;
    }

    /**
     * 通过真实 API 创建一个工作流实例（真库 + 真引擎）
     *
     * 说明：实例控制器现在真的调引擎启动流程，`definition_id` 必须是**真实定义主键**
     * （实例表有外键、定义体必须是可执行 LogicFlow 图），占位字符串会被 422 拒绝。
     *
     * @param array<string, mixed> $variables 实例变量
     * @return int 真实实例主键
     */
    private function createWorkflowInstance(array $variables = []): int
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());

        $response = $this->request('POST', 'v1/workflow/instances', [
            'definition_id' => $definitionId,
            'title'         => '路由可达性探针实例',
            'variables'     => $variables,
        ]);
        $data = $this->json($response)['data'] ?? [];

        $this->assertSame(201, $response->getCode(), '前置条件：创建实例必须成功。' . $this->diagnostics($response));

        $instanceId = (int) ($data['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId, '前置条件：创建实例必须返回真实实例主键');

        return $instanceId;
    }

    /**
     * 通过真实 API 创建一个 webhook 类型触发器
     *
     * @return array<string, mixed> 触发器数据（含 id / webhook_key）
     */
    private function createWorkflowTrigger(): array
    {
        $response = $this->request('POST', 'v1/workflow/triggers', [
            'definition_id' => 'wf_reachability_probe',
            'name' => 'probe webhook trigger',
            'type' => WorkflowTriggerController::TYPE_WEBHOOK,
            'config' => [],
        ]);
        $data = $this->json($response)['data'] ?? [];

        $this->assertSame(201, $response->getCode(), '前置条件：创建触发器必须成功。' . $this->diagnostics($response));
        $this->assertNotEmpty($data['id'] ?? null, '前置条件：创建触发器必须返回 id');

        return $data;
    }

    /**
     * 播种一条待办任务（真库）
     *
     * `WorkflowTaskController` **没有**任何创建任务的端点（任务由引擎在推进时产生），
     * 因此这里直接插入「定义 → 实例 → 任务行」；用完由 `tearDown()` 按定义名前缀统一清理。
     *
     * @return int 任务主键
     */
    private function seedTask(): int
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . uniqid());
        $instanceId   = $this->seedWorkflowInstanceRow(self::TENANT_ID, $definitionId, self::ADMIN_USER_ID);

        return $this->seedWorkflowTaskRow(self::TENANT_ID, $instanceId, [
            'assignee'  => self::ADMIN_USER_ID,
            'status'    => 'claimed',
            'form_data' => ['probe' => true],
        ]);
    }

    /**
     * 清空仍为 Mock 实现的工作流控制器的进程内静态存储
     *
     * 实例/任务控制器已改为真库，它们的静态存储不存在了；这里只为
     * `WorkflowTriggerController`（下一个包才改）保留，`property_exists` 容错。
     */
    private function resetInMemoryStores(): void
    {
        $stores = [
            WorkflowTriggerController::class => ['triggers', 'triggerLogs'],
        ];

        foreach ($stores as $class => $properties) {
            foreach ($properties as $property) {
                if (!property_exists($class, $property)) {
                    continue;
                }

                $reflection = new \ReflectionProperty($class, $property);
                $reflection->setAccessible(true);
                $reflection->setValue(null, []);
            }
        }
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
     *
     * `think\Middleware::$queue` 是进程级累加、框架从不清空，
     * 不清理会让上一次请求的规则中间件影响下一次请求。
     */
    private function resetRouteMiddlewareQueue(): void
    {
        $middleware = $this->app()->middleware;

        $reflection = new \ReflectionProperty(\think\Middleware::class, 'queue');
        $reflection->setAccessible(true);

        $queue = (array) $reflection->getValue($middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $reflection->setValue($middleware, $queue);
    }

    /**
     * 解除低代码 manager 的容器绑定
     *
     * 容器会缓存解析结果，且 `bind` 是进程级的；不解除会把 Mock 泄漏给后续测试类。
     */
    private function unbindLowcodeManagers(): void
    {
        $app = $this->app();

        foreach ([CollectionManager::class, FieldManager::class, RelationshipManager::class] as $abstract) {
            // 1) 丢弃已解析实例
            $app->delete($abstract);

            // 2) 删除 bind 里的闭包（否则下一次 make() 还会拿到同一个 Mock）
            try {
                $reflection = new \ReflectionObject($app);
                if (!$reflection->hasProperty('bind')) {
                    continue;
                }

                $property = $reflection->getProperty('bind');
                $property->setAccessible(true);

                $bind = $property->getValue($app);
                if (is_array($bind) && array_key_exists($abstract, $bind)) {
                    unset($bind[$abstract]);
                    $property->setValue($app, $bind);
                }
            } catch (\Throwable $e) {
                // 反射失败不应影响用例结论（Mockery::close() 仍会校验期望）
            }
        }
    }

    /**
     * 是否属于本次审计的路径前缀
     *
     * @param string $path 以 `/` 开头的路径
     * @return bool
     */
    private function isAuditedPath(string $path): bool
    {
        foreach (self::AUDIT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按路由自身的 pattern 生成样例 URL
     *
     * @param string $rule 规则串（变量形如 `<id>` / `<key>`）
     * @param array<string, string> $pattern 变量规则
     * @return string
     */
    private function sampleUrl(string $rule, array $pattern): string
    {
        return (string) preg_replace_callback(
            '/<(\w+)\??>/',
            fn (array $matches): string => $this->sampleValue($pattern[$matches[1]] ?? null),
            $rule
        );
    }

    /**
     * 为某个变量挑一个能通过其 pattern 的样例值
     *
     * 候选值是本仓库 pattern 的并集：`\d+` / `[\w\-]+` / `[\w\.]+` 用 `1`，
     * `[a-f0-9]{32}`（webhook key）用 32 位十六进制。
     *
     * @param string|null $pattern 变量规则
     * @return string
     */
    private function sampleValue(?string $pattern): string
    {
        $pattern = $pattern ?? '[\w\.]+';

        foreach (['1', 'a1', str_repeat('a', 32)] as $candidate) {
            if (preg_match('~^(?:' . $pattern . ')$~u', $candidate) === 1) {
                return $candidate;
            }
        }

        return '1';
    }

    /**
     * 把路由目标规范成 `Controller@method`
     *
     * `Rule::parseRule()` 会把 `'a\b\C@method'` 拆成 `['a\b\C', 'method']`；
     * 闭包/`Controller/action` 形式也统一成同一形态。
     *
     * @param mixed $target 路由目标
     * @return string
     */
    private function normalizeTarget($target): string
    {
        if ($target instanceof \Closure) {
            return 'Closure';
        }

        if (is_array($target) && count($target) === 2) {
            return implode('@', $target);
        }

        if (is_string($target) && !str_contains($target, '@') && substr_count($target, '/') === 1) {
            // `index/hello` 这类两段式写法等价于 `index@hello`
            return str_replace('/', '@', $target);
        }

        return is_string($target) ? $target : (string) json_encode($target);
    }
}
