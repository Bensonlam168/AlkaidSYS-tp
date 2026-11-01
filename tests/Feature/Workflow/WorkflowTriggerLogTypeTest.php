<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use app\controller\workflow\WorkflowTriggerController;
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
 * 覆盖两个调用点（其余 update / test 走同一个 helper，签名修复后同样受益）：
 *   - `save()`    → `:320`
 *   - `enable()`  → `:745`（setTriggerStatus）
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

    /** 测试权限 ID */
    private int $permissionId = 6521;

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
     * 创建触发器：不得因 int → string 形参不兼容而 500
     */
    public function testCreateTriggerWithAuthenticatedUserDoesNotTypeError(): void
    {
        $response = $this->httpRequest(
            'POST',
            'v1/workflow/triggers',
            [
                'definition_id' => 'wf_trigger_type_test',
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
        $create = $this->httpRequest(
            'POST',
            'v1/workflow/triggers',
            [
                'definition_id' => 'wf_trigger_enable_test',
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

        // 日志必须真的写入，且 user_id 为当前用户
        $logs = $this->readTriggerLogs();
        $this->assertArrayHasKey($triggerId, $logs, '应存在该触发器的日志');
        $events = array_column($logs[$triggerId], 'event');
        $this->assertContains('CREATED', $events, '创建应留下 CREATED 日志');
        $this->assertContains('ENABLED', $events, '启用应留下 ENABLED 日志');

        foreach ($logs[$triggerId] as $entry) {
            $this->assertSame(
                (string) $this->userId,
                (string) $entry['user_id'],
                '日志的操作人应为当前登录用户（' . $this->userId . '），不得退化为 system'
            );
        }
    }

    /**
     * 读取控制器进程内触发器日志（通过反射）
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function readTriggerLogs(): array
    {
        $ref = new \ReflectionProperty(WorkflowTriggerController::class, 'triggerLogs');
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

            Db::table('permissions')->insert([
                'id' => $this->permissionId,
                'name' => 'workflow_trigger.write',
                'slug' => 'workflow_trigger.write',
                'resource' => 'workflow_trigger',
                'action' => 'write',
                'description' => 'C-7 回归测试权限（触发器）',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

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
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
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
     * 清空触发器控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        foreach (['triggers', 'triggerLogs'] as $property) {
            if (!property_exists(WorkflowTriggerController::class, $property)) {
                continue;
            }

            $ref = new \ReflectionProperty(WorkflowTriggerController::class, $property);
            $ref->setAccessible(true);
            $ref->setValue(null, []);
        }
    }
}
