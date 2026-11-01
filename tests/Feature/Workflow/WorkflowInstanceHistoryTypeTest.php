<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use app\controller\workflow\WorkflowInstanceController;
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
 * 更糟的是状态在抛错之前已经写入（`save()` 先 `self::$instances[$instanceId] = $instance`
 * 再调 `addHistoryEntry()`），表现为「500 + 数据已变更」的半成品状态。
 *
 * 本测试走真实 HTTP 路由 + 真实 JWT + 真实 RBAC 权限链路，验证：
 *   - 已认证用户启动实例不再 500；
 *   - 历史记录确实写入，且 operator 为当前用户。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowInstanceHistoryTypeTest extends ThinkPHPTestCase
{
    use \Tests\Traits\WorkflowFixtureIsolation;

    /** 测试用户 ID（6401 段避开既有 61xx/62xx/63xx 占用） */
    private int $userId = 6401;

    /** 测试角色 ID */
    private int $roleId = 6411;

    /** 测试权限 ID */
    private int $permissionId = 6421;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->resetInMemoryStores();
        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->cleanupFixtures();

        parent::tearDown();
    }

    /**
     * 已认证用户启动实例：不得因 int → string 形参不兼容而 500
     */
    public function testStartInstanceWithAuthenticatedUserDoesNotTypeError(): void
    {
        $response = $this->httpRequest(
            'POST',
            'v1/workflow/instances',
            ['definition_id' => 'wf_type_test', 'title' => '类型契约测试实例'],
            $this->authHeaders()
        );

        $this->assertNotSame(
            500,
            $response->getCode(),
            '已认证用户启动实例不应 500（addHistoryEntry 的 int 传入 string 形参 TypeError），实际响应：'
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
        $response = $this->httpRequest(
            'POST',
            'v1/workflow/instances',
            ['definition_id' => 'wf_type_history', 'title' => '历史记录测试实例'],
            $this->authHeaders()
        );

        $this->assertSame(201, $response->getCode(), '前置条件：实例应创建成功：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $instanceId = $body['data']['id'] ?? null;
        $this->assertNotEmpty($instanceId, '响应中应包含实例 ID');

        $history = $this->readHistory();
        $this->assertArrayHasKey($instanceId, $history, '应存在该实例的历史记录');
        $this->assertNotEmpty($history[$instanceId], '历史记录不应为空');

        $entry = $history[$instanceId][0];
        $this->assertSame('STARTED', $entry['event_type'], '首条历史应为 STARTED');
        $this->assertSame(
            (string) $this->userId,
            (string) $entry['user_id'],
            '操作人应为当前登录用户（' . $this->userId . '），不得退化为 system'
        );
    }

    /**
     * 读取控制器进程内历史记录（通过反射）
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function readHistory(): array
    {
        $ref = new \ReflectionProperty(WorkflowInstanceController::class, 'history');
        $ref->setAccessible(true);

        return (array) $ref->getValue();
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

            Db::table('role_permissions')->insert([
                'role_id' => $this->roleId,
                'permission_id' => $this->permissionId,
                'created_at' => $now,
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

    /**
     * 清空实例控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        foreach (['instances', 'history'] as $property) {
            if (!property_exists(WorkflowInstanceController::class, $property)) {
                continue;
            }

            $ref = new \ReflectionProperty(WorkflowInstanceController::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }
    }
}
