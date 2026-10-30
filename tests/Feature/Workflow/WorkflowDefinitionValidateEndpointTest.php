<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流定义「校验」端点 HTTP 级集成测试（独立评审问题 4）
 *
 * 背景：
 *   控制器曾把动作方法命名为 `validate()`，与父类 `think\Controller`（项目 `app\BaseController`）
 *   的数据验证方法同名，PHP 要求覆写签名兼容，导致：
 *     1. 签名被放宽成 `validate(mixed $id, mixed $request = null, array $message = [], bool $batch = false)`，
 *        客户端只要传 `?message=x` 就会触发
 *        `TypeError: Argument #3 ($message) must be of type array, string given` → 500；
 *     2. 方法体内调用了不存在的 `ValidationResult::getInfo()`（正确方法名 `getInfos()`）→ Fatal error → 500。
 *
 * 修复方式：动作方法改名为 `validateAction(string $id, Request $request): Response`，
 * 路由 URL 保持不变（`POST v1/workflow/definitions/:id/validate`），并改用 `getInfos()`。
 *
 * 本测试通过真实路由 + 真实 JWT 认证 + 真实权限中间件链路验证：
 *   - 不存在的定义 → 404（不是 500）；
 *   - 客户端可控的 `?message=x` 查询参数 → 不再 500；
 *   - 存在的定义 → 200 且返回校验结果（证明 `getInfos()` 生效）。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowDefinitionValidateEndpointTest extends ThinkPHPTestCase
{
    /**
     * 测试用户 ID（租户 1）
     * 使用高位 ID，避免与既有测试数据或种子数据冲突
     */
    private int $userId = 991001;

    /**
     * 测试角色 ID
     */
    private int $roleId = 991001;

    /**
     * 工作流定义读权限 ID（validate 路由只要求 read 权限）
     */
    private int $readPermissionId = 991101;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 tenantId()/userId()/siteId() 多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载工作流路由（含 Auth + Permission 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其他测试类可能把 Auth / Permission 绑定为 no-op，
        // 而 think\Container::make() 会缓存首次解析结果，导致本用例拿不到真实租户上下文
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        // 清空路由/控制器中间件队列（ThinkPHP 的中间件队列在同一进程内跨请求累积）
        $this->resetRouteMiddlewareQueue();

        // 清空工作流控制器的进程内静态存储，避免用例间互相污染
        $this->resetInMemoryStores();

        // 授予测试用户工作流定义读权限（走真实 RBAC 校验链路）
        $this->seedPermissions();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->cleanupPermissions();
        $this->clearPermissionCache();

        parent::tearDown();
    }

    /**
     * 用例 1：对不存在的定义调用 validate → 404（不是 500）
     */
    public function testValidateNonexistentDefinitionReturns404(): void
    {
        $response = $this->postValidate('definition-not-exists-999');

        $this->assertSame(
            404,
            $response->getCode(),
            '对不存在的定义调用 validate 应返回 404，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(404, $body['code']);
    }

    /**
     * 用例 2：客户端传入 `?message=x` → 不得触发 TypeError 500
     */
    public function testValidateWithClientControlledMessageQueryDoesNotReturn500(): void
    {
        $definitionId = $this->seedDefinition();

        $response = $this->postValidate($definitionId, ['message' => 'x']);

        $this->assertNotSame(
            500,
            $response->getCode(),
            '客户端可控参数 message 不应导致 500（原为 TypeError），实际响应：' . $response->getContent()
        );
        $this->assertSame(
            200,
            $response->getCode(),
            'message 查询参数应被忽略，接口仍应正常返回 200，实际响应：' . $response->getContent()
        );
    }

    /**
     * 用例 3：对存在的定义调用 validate → 200 且返回校验结果（证明 getInfos() 生效）
     */
    public function testValidateReturnsValidationResult(): void
    {
        $definitionId = $this->seedDefinition();

        $response = $this->postValidate($definitionId);

        $this->assertSame(
            200,
            $response->getCode(),
            'validate 接口应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code']);
        $this->assertArrayHasKey('data', $body);

        $data = $body['data'];
        $this->assertIsArray($data);
        $this->assertArrayHasKey('valid', $data, '响应应包含 valid 字段，实际：' . $response->getContent());
        $this->assertIsBool($data['valid']);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('warnings', $data);
        // info 字段来自 ValidationResult::getInfos()，方法名写错会直接 Fatal error
        $this->assertArrayHasKey('info', $data);
        $this->assertIsArray($data['info']);
    }

    /**
     * 向控制器进程内静态存储注入一条工作流定义，并返回其 ID
     *
     * 说明：本用例聚焦 validate 端点，创建定义链路已由 WorkflowTenantIsolationTest 覆盖，
     * 这里直接构造定义以降低耦合（无需 write 权限与创建接口前置条件）。
     */
    private function seedDefinition(int $tenantId = 1): string
    {
        $builder    = WorkflowBuilder::create('validate_endpoint_wf_' . uniqid(), ['tenant_id' => $tenantId]);
        $definition = $builder->getDefinition();

        $ref = new \ReflectionProperty(
            \app\controller\workflow\WorkflowDefinitionController::class,
            'definitions'
        );
        $ref->setAccessible(true);

        $definitions = (array) $ref->getValue();
        $definitions[$definition->getId()] = $definition;
        $ref->setValue(null, $definitions);

        return $definition->getId();
    }

    /**
     * 发起 POST v1/workflow/definitions/{id}/validate 请求
     *
     * @param string $definitionId 定义 ID
     * @param array $query 查询参数（模拟客户端可控参数）
     */
    private function postValidate(string $definitionId, array $query = [])
    {
        // 强制新建实例：容器默认返回共享 Request，复用会把上一个用例的查询参数
        // （如 message=x）带入本用例，导致结论失真
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod('POST');
        $request->setPathinfo('v1/workflow/definitions/' . $definitionId . '/validate');

        // 始终覆盖查询参数，避免残留
        $request->withGet($query);

        // 注意：withHeader 是整体替换而非合并，Authorization 与 Accept 必须一起传入
        $request = $request->withHeader([
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $this->userId,
                tenantId: 1,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ]);

        return $this->runHttp($request);
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
        $queue['route']      = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }

    /**
     * 清空工作流控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        $stores = [
            \app\controller\workflow\WorkflowDefinitionController::class => ['definitions'],
            \app\controller\workflow\WorkflowInstanceController::class => ['instances', 'history'],
            \app\controller\workflow\WorkflowTaskController::class => ['tasks', 'taskHistory'],
            \app\controller\workflow\WorkflowTriggerController::class => ['triggers', 'triggerLogs'],
        ];

        foreach ($stores as $class => $properties) {
            foreach ($properties as $property) {
                if (!property_exists($class, $property)) {
                    continue;
                }

                $ref = new \ReflectionProperty($class, $property);
                $ref->setAccessible(true);
                $ref->setValue(null, []);
            }
        }
    }

    /**
     * 授予测试用户工作流定义读权限（幂等：先清理残留再插入）
     */
    private function seedPermissions(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->cleanupPermissions();

        // 说明：PermissionService 以 `resource:action` 拼接权限码，
        // 因此 resource=workflow + action=definition:read 才能匹配中间件的 workflow.definition.read
        Db::table('permissions')->insert([
            'id'          => $this->readPermissionId,
            'name'        => 'workflow.definition.read',
            'slug'        => 'workflow.definition.read',
            'resource'    => 'workflow',
            'action'      => 'definition:read',
            'description' => 'workflow validate endpoint test permission',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        Db::table('users')->insert([
            'id'         => $this->userId,
            'tenant_id'  => 1,
            'username'   => 'workflow_validate_user',
            'email'      => 'workflow_validate_user@workflow-validate.test',
            'password'   => password_hash('password', PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::table('roles')->insert([
            'id'         => $this->roleId,
            'tenant_id'  => 1,
            'name'       => 'workflow_validate_role',
            'slug'       => 'workflow_validate_role',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Db::table('user_roles')->insert([
            'user_id'    => $this->userId,
            'role_id'    => $this->roleId,
            'created_at' => $now,
        ]);

        Db::table('role_permissions')->insert([
            'role_id'       => $this->roleId,
            'permission_id' => $this->readPermissionId,
            'created_at'    => $now,
        ]);
    }

    /**
     * 清理本用例植入的权限数据
     */
    private function cleanupPermissions(): void
    {
        Db::table('role_permissions')->where('role_id', $this->roleId)->delete();
        Db::table('user_roles')->where('user_id', $this->userId)->delete();
        Db::table('roles')->where('id', $this->roleId)->delete();
        Db::table('users')->where('id', $this->userId)->delete();
        Db::table('permissions')->where('id', $this->readPermissionId)->delete();
    }

    /**
     * 清除测试用户的权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        $permissionService = new PermissionService();
        $permissionService->clearUserCache($this->userId);
    }
}
