<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流实例历史记录的类型契约测试（C-7 回归）
 *
 * 背景：C-7（提交 7860fab）把 `$request->userId`（属性访问，恒 null）改为
 * `$request->userId()`（返回 `?int`）。而 `addHistoryEntry()` 的第 5 个形参声明为
 * `string $userId`，本文件 `declare(strict_types=1)` —— 已认证用户调用时 int 传入
 * string 形参会抛 TypeError，接口返回 500。
 *
 * 更糟的是状态在抛错之前已经写入，表现为「500 + 数据已变更」的半成品状态。
 *
 * 本测试走真实 HTTP 路由 + 真实 JWT + 真实 RBAC 权限链路 + **真实引擎/数据库**，验证：
 *   - 已认证用户启动实例不再 500；
 *   - 轨迹事件确实写入 `workflow_event_logs`，且操作人为当前用户；
 *   - `GET /history` 返回的轨迹结构与操作人字段同样正确（不只是库里对、接口里错）。
 *
 * ## 相对 Mock 版（改前）的差异与理由
 *
 * 1. `definition_id` 从占位字符串 `'wf_type_test'` 改为**真实定义主键**：
 *    实例控制器现在真的调引擎启动流程，引擎要求定义体是可执行的 LogicFlow `{nodes, edges}`，
 *    且 `workflow_instances.definition_id` 有外键。占位字符串在新实现下会被 422/400 拒绝。
 * 2. 历史从控制器进程内 `$history` 改为 `workflow_event_logs` 表；
 *    首条事件的 `event_type` 由 Mock 的大写 `'STARTED'` 变为引擎真值 `'instance_started'`
 *    （见 `ProcessInstanceHistoryService` 类注释里的事件词汇表）。
 *    「操作人不得退化为 system」这条断言原样保留，只是改为在 `user_id` 上断言。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowInstanceHistoryTypeTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;
    use \Tests\Traits\WorkflowExecutableFixture;

    /**
     * 真库夹具定义的名称前缀（清理时按前缀删除）
     */
    private const DEFINITION_PREFIX = 'c7_history_type_';

    /** 测试用户 ID（6401 段避开既有 61xx/62xx/63xx 占用） */
    private int $userId = 6401;

    /** 测试角色 ID */
    private int $roleId = 6411;

    /** 测试权限 ID（实例写 / 实例读） */
    private int $permissionId = 6421;
    private int $readPermissionId = 6422;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 userId()/tenantId() 等多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 载入工作流路由（与其他工作流 Feature 测试保持一致）
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其他测试类可能把 Auth / Permission 绑定为 no-op，
        // 容器会缓存首次解析结果，必须显式覆盖为真实实现。
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();

        // 先清掉上一轮可能残留的真库夹具（定义/实例/任务/事件）
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);

        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeWorkflowFixturesByNamePrefix(self::DEFINITION_PREFIX);
        $this->cleanupFixtures();

        parent::tearDown();
    }

    /**
     * 已认证用户启动实例：不得因 int → string 形参不兼容而 500
     */
    public function testStartInstanceWithAuthenticatedUserDoesNotTypeError(): void
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . 'no_type_error');

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/instances',
            ['definition_id' => $definitionId, 'title' => '类型契约测试实例'],
            $this->authHeaders()
        );

        $this->assertNotSame(
            500,
            $response->getCode(),
            '已认证用户启动实例不应 500（操作人 int 传入 string 形参 TypeError），实际响应：'
            . $response->getContent()
        );

        $this->assertSame(
            201,
            $response->getCode(),
            '启动实例应返回 201，实际响应：' . $response->getContent()
        );
    }

    /**
     * 历史记录必须真的写入，且 operator 指向当前用户（不能退化成 'system'）
     */
    public function testStartInstanceRecordsHistoryEntry(): void
    {
        $definitionId = $this->insertExecutableDefinition(self::DEFINITION_PREFIX . 'history');

        $response = $this->httpRequest(
            'POST',
            'v1/workflow/instances',
            ['definition_id' => $definitionId, 'title' => '历史记录测试实例'],
            $this->authHeaders()
        );

        $this->assertSame(201, $response->getCode(), '前置条件：实例应创建成功：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $instanceId = (int) ($body['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $instanceId, '响应中应包含实例 ID');

        // ① 库里的轨迹：首条必须是流程启动事件，操作人是当前用户
        $history = $this->readHistory($instanceId);
        $this->assertNotEmpty($history, '轨迹事件不应为空');
        $this->assertSame('instance_started', $history[0]['event_type'], '首条轨迹应为流程启动事件');
        $this->assertSame(
            $this->userId,
            (int) $history[0]['user_id'],
            '操作人应为当前登录用户（' . $this->userId . '），不得退化为 system'
        );
        $this->assertSame(1, (int) $history[0]['tenant_id'], '轨迹事件的租户应取自实例本身');

        // ② 接口返回的轨迹：结构字段与操作人同样正确（防止「库里对、接口里错」）
        $historyResponse = $this->httpRequest(
            'GET',
            'v1/workflow/instances/' . $instanceId . '/history',
            [],
            $this->authHeaders()
        );

        $this->assertSame(200, $historyResponse->getCode(), '轨迹接口应返回 200：' . $historyResponse->getContent());

        $historyBody = json_decode($historyResponse->getContent(), true);
        $this->assertIsArray($historyBody);
        $entries = $historyBody['data']['entries'] ?? [];
        $this->assertNotEmpty($entries, '轨迹接口应返回事件列表');
        $this->assertSame('instance_started', $entries[0]['event_type'], '接口返回的首条轨迹应为流程启动事件');
        $this->assertSame(
            $this->userId,
            (int) $entries[0]['user_id'],
            '接口返回的操作人应为当前登录用户'
        );
        $this->assertArrayHasKey('timestamp', $entries[0], '轨迹条目应带时间戳，保持旧结构兼容');
    }

    // =====================================================================
    // 辅助
    // =====================================================================

    /**
     * 读取实例的轨迹事件（真库：workflow_event_logs）
     *
     * @return array<int, array<string, mixed>>
     */
    private function readHistory(int $instanceId): array
    {
        return Db::table('workflow_event_logs')
            ->where('instance_id', $instanceId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
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
            // 说明：PermissionService 以 `resource:action` 拼接权限码，
            // 因此 resource=workflow_instance + action=write 才匹配中间件的 workflow_instance.write
            $this->permissionId = $this->ensureWorkflowPermission(
                'workflow_instance',
                'write',
                $this->permissionId,
                'C-7 回归测试权限'
            );
            $this->readPermissionId = $this->ensureWorkflowPermission(
                'workflow_instance',
                'read',
                $this->readPermissionId,
                'C-7 回归测试权限'
            );

            Db::table('users')->insert([
                'id' => $this->userId,
                'tenant_id' => 1,
                'username' => 'wf_instance_type_user',
                'email' => 'wf_instance_type_user@workflow-type.test',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('roles')->insert([
                'id' => $this->roleId,
                'tenant_id' => 1,
                'name' => 'wf_instance_type_role',
                'slug' => 'wf_instance_type_role',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Db::table('user_roles')->insert([
                'user_id' => $this->userId,
                'role_id' => $this->roleId,
                'created_at' => $now,
            ]);

            Db::table('role_permissions')->insertAll([
                [
                    'role_id' => $this->roleId,
                    'permission_id' => $this->permissionId,
                    'created_at' => $now,
                ],
                [
                    'role_id' => $this->roleId,
                    'permission_id' => $this->readPermissionId,
                    'created_at' => $now,
                ],
            ]);
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
     * 清除权限缓存（L1/L2），避免历史运行残留导致误判
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
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 第三个参数 true（newInstance）：容器缓存最近一次请求实例，必须强制新建，
        // 否则跨用例复用同一 Request 对象会串味。
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } else {
            $request->withPost($data);
            $request->withInput(json_encode($data));
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
