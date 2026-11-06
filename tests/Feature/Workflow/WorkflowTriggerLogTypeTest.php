<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流触发器日志的类型契约测试（C-7 回归）
 *
 * 背景：C-7（提交 7860fab）把 `$request->userId`（属性访问，恒 null）改为
 * `$request->userId()`（返回 `?int`）。`addTriggerLog()` 的第 3 个形参声明为
 * `string $userId`，本文件 `declare(strict_types=1)` —— 已认证用户调用时
 * int 传入 string 形参会抛 TypeError，接口 500。
 *
 * 覆盖两个调用点（其余 update / test / disable 走同一个 helper，签名修复后同样受益）：
 *   - `save()`    → 创建时写 `trigger_created` 日志
 *   - `enable()`  → `setTriggerStatus()` 写 `trigger_enabled` 日志
 *
 * 2026 变更（Mock → 真库 + 真引擎）：
 *   控制器不再持有 `static $triggerLogs` 内存数组，日志落 `workflow_event_logs`
 *   （`instance_id` 为 NULL 表示「管理类事件」，`event_data.trigger_id` 承载归属）。
 *   因此本用例的「日志真的写入了」不再靠反射读内存，而是：
 *     ① 调 `GET v1/workflow/triggers/{id}/logs` 走真实端点契约；
 *     ② 再直接查库核对 `user_id` 列，证明操作人是真的落库、不是响应层拼出来的。
 *   断言语义（CREATED / ENABLED 都存在、操作人必须是当前登录用户、不得退化为 system）
 *   逐条保留，未削弱。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTriggerLogTypeTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /** 测试用户 ID（6501 段避开既有 61xx/62xx/63xx/64xx 占用） */
    private int $userId = 6501;

    /** 测试角色 ID */
    private int $roleId = 6511;

    /** 测试权限 ID（写） */
    private int $permissionId = 6521;

    /** 测试权限 ID（读，logs 端点要求 workflow_trigger.read） */
    private int $readPermissionId = 6522;

    /** 定义名统一前缀（tearDown 按前缀回收，避免脏数据） */
    private const DEFINITION_PREFIX = 'wf_trigger_logtype_def_';

    protected function setUp(): void
    {
        parent::setUp();

        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例（其他测试可能绑定成 no-op，容器会缓存首次解析）
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->purgeSeededWorkflowRows();
        $this->resetRouteMiddlewareQueue();
        $this->cleanupFixtures();

        parent::tearDown();
    }

    /**
     * 创建触发器：不得因 int → string 形参不兼容而 500
     */
    public function testCreateTriggerWithAuthenticatedUserDoesNotTypeError(): void
    {
        $definitionId = $this->seedDefinition();

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/triggers',
            [
                'definition_id' => $definitionId,
                'name' => '类型契约测试触发器',
                'type' => 'manual',
            ],
            $this->authHeaders()
        );

        $this->assertNotSame(
            500,
            $response->getCode(),
            '已认证用户创建触发器不应 500（addTriggerLog 的 int 传入 string 形参 TypeError），实际响应：'
            . $response->getContent()
        );

        $this->assertSame(
            201,
            $response->getCode(),
            '创建触发器应返回 201，实际响应：' . $response->getContent()
        );
    }

    /**
     * 启用触发器：走 setTriggerStatus，同样会写触发器日志
     */
    public function testEnableTriggerWithAuthenticatedUserDoesNotTypeError(): void
    {
        $definitionId = $this->seedDefinition();

        $create = $this->httpRequest(
            'POST',
            'v1/workflow/triggers',
            [
                'definition_id' => $definitionId,
                'name' => '启用路径测试触发器',
                'type' => 'manual',
            ],
            $this->authHeaders()
        );

        $this->assertSame(201, $create->getCode(), '前置条件：触发器应创建成功：' . $create->getContent());

        $body = json_decode($create->getContent(), true);
        $this->assertIsArray($body);
        $triggerId = $body['data']['id'] ?? null;
        $this->assertNotEmpty($triggerId, '响应中应包含触发器 ID');
        $triggerId = (int) $triggerId;

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/triggers/' . $triggerId . '/enable',
            [],
            $this->authHeaders()
        );

        $this->assertNotSame(
            500,
            $response->getCode(),
            '启用触发器不应 500（setTriggerStatus 同样调用 addTriggerLog），实际响应：'
            . $response->getContent()
        );

        // ① 走真实日志端点：事件词汇与归属都对
        $logs = $this->readTriggerLogsViaEndpoint($triggerId);
        $this->assertNotSame([], $logs, '应存在该触发器的日志（logs 端点返回空）');

        $events = array_column($logs, 'event');
        $this->assertContains('CREATED', $events, '创建应留下 CREATED 日志，实际：' . json_encode($events));
        $this->assertContains('ENABLED', $events, '启用应留下 ENABLED 日志，实际：' . json_encode($events));

        foreach ($logs as $entry) {
            $this->assertSame(
                (string) $this->userId,
                (string) $entry['user_id'],
                '日志的操作人应为当前登录用户（' . $this->userId . '），不得退化为 system'
            );
        }

        // ② 再直接查库核对：证明 user_id 是真落库的列值，而不是呈现层拼出来的
        $dbUserIds = Db::table('workflow_event_logs')
            ->where('tenant_id', 1)
            ->whereIn('event_type', ['trigger_created', 'trigger_enabled'])
            ->whereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.trigger_id')) AS UNSIGNED) = ?", [$triggerId])
            ->column('user_id');

        $this->assertCount(2, $dbUserIds, '库里应有 2 条管理类日志（created + enabled），实际：' . json_encode($dbUserIds));

        foreach ($dbUserIds as $dbUserId) {
            $this->assertSame(
                $this->userId,
                (int) $dbUserId,
                'workflow_event_logs.user_id 必须是真实登录用户，不得为 NULL / 0'
            );
        }
    }

    /**
     * 经真实端点读触发器日志
     *
     * @return array<int, array<string, mixed>>
     */
    private function readTriggerLogsViaEndpoint(int $triggerId): array
    {
        $response = $this->httpRequest(
            'GET',
            'v1/workflow/triggers/' . $triggerId . '/logs',
            [],
            $this->authHeaders()
        );

        $this->assertSame(
            200,
            $response->getCode(),
            'logs 端点应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        return (array) ($body['data']['list'] ?? []);
    }

    /**
     * 插入一条真实定义（触发器必须挂在同租户的真实定义上，外键才成立）
     */
    private function seedDefinition(): int
    {
        $name = self::DEFINITION_PREFIX . uniqid();
        $now  = date('Y-m-d H:i:s');

        return (int) Db::table('workflow_definitions')->insertGetId([
            'tenant_id'    => 1,
            'name'         => $name,
            'display_name' => '触发器日志类型测试定义',
            'description'  => 'C-7 回归夹具（用例结束后按前缀删除）',
            'type'         => 'approval',
            'version'      => 1,
            'status'       => 'draft',
            'is_active'    => 0,
            'nodes_config' => json_encode([
                'name'  => $name,
                'type'  => 'approval',
                'nodes' => [
                    ['id' => 'start', 'type' => 'ingenious:start', 'x' => 200, 'y' => 160,
                        'text' => ['value' => '开始'], 'properties' => ['_alkaid_node_class' => 'StartNode', '_alkaid_node_type' => 'start']],
                    ['id' => 'end', 'type' => 'ingenious:end', 'x' => 420, 'y' => 160,
                        'text' => ['value' => '结束'], 'properties' => ['_alkaid_node_class' => 'EndNode', '_alkaid_node_type' => 'end']],
                ],
                'edges' => [
                    ['id' => 'flow_start_end', 'type' => 'polyline', 'sourceNodeId' => 'start',
                        'targetNodeId' => 'end', 'text' => ['value' => ''], 'properties' => ['_alkaid_type' => 'sequence']],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at'   => $now,
            'updated_at'   => $now,
            'created_by'   => $this->userId,
            'updated_by'   => $this->userId,
        ]);
    }

    /**
     * 回收本用例写入的业务行（严格按外键顺序：event_logs → triggers → definitions）
     *
     * 说明：trait 的 `purgeWorkflowFixtures()` 只清 users / roles / permissions，
     * 不认识工作流业务表，因此业务夹具必须自己收尾。
     */
    private function purgeSeededWorkflowRows(): void
    {
        $definitionIds = Db::table('workflow_definitions')
            ->where('name', 'like', self::DEFINITION_PREFIX . '%')
            ->column('id');

        if ($definitionIds === []) {
            return;
        }

        $triggerIds = Db::table('workflow_triggers')->whereIn('definition_id', $definitionIds)->column('id');

        if ($triggerIds !== []) {
            Db::table('workflow_event_logs')
                ->whereRaw(
                    "CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '\$.trigger_id')) AS UNSIGNED) IN ("
                    . implode(',', array_map('intval', $triggerIds)) . ')'
                )
                ->delete();

            Db::table('workflow_triggers')->whereIn('id', $triggerIds)->delete();
        }

        Db::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();
    }

    /**
     * 播种用户 / 角色 / 权限与关联（清理与幂等性见 WorkflowFixtureIsolation）
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 权限改为「按 slug 确保存在」（R51）：seeder 可能已预建同 slug 行（id 1024..1033），
            // 此时复用其 id；未预建才按本用例的固定 id 插入。必须用返回值回写属性。
            $this->permissionId = $this->ensureWorkflowPermission(
                'workflow_trigger',
                'write',
                $this->permissionId,
                'C-7 回归测试权限（触发器写）'
            );

            // logs 端点要求 workflow_trigger.read：不授予会得到 403 而不是日志
            $this->readPermissionId = $this->ensureWorkflowPermission(
                'workflow_trigger',
                'read',
                $this->readPermissionId,
                'C-7 回归测试权限（触发器读）'
            );

            Db::table('users')->insert([
                'id' => $this->userId,
                'tenant_id' => 1,
                'username' => 'wf_trigger_type_user',
                'email' => 'wf_trigger_type_user@workflow-type.test',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('roles')->insert([
                'id' => $this->roleId,
                'tenant_id' => 1,
                'name' => 'wf_trigger_type_role',
                'slug' => 'wf_trigger_type_role',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id' => $this->userId,
                'role_id' => $this->roleId,
                'created_at' => $now,
            ]);

            foreach ([$this->permissionId, $this->readPermissionId] as $permissionId) {
                Db::table('role_permissions')->insert([
                    'role_id' => $this->roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                ]);
            }
        });
    }

    /**
     * 清理工作流测试夹具（含其他测试类留下的同 slug 残留）
     */
    private function cleanupFixtures(): void
    {
        $this->purgeWorkflowFixtures();
    }

    /**
     * 清除权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        (new PermissionService())->clearUserCache($this->userId);
    }

    /**
     * 构造携带 JWT 的请求头
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $this->userId,
                tenantId: 1,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ];
    }

    /**
     * 简易 HTTP 调用辅助（与既有工作流 Feature 测试保持一致）
     *
     * 每次请求前清空路由/控制器中间件队列：ThinkPHP 的中间件队列在同一进程内跨请求累积，
     * 上一次请求的 Auth / Permission 会残留在队列里，导致后续请求的鉴权结论失真。
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } else {
            $request->withPost($data);
            $request->withInput((string) json_encode($data));
        }

        return $this->runHttp($request->withHeader($headers));
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
