<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Alkaid\Workflow\Engine\Definition\WorkflowBuilder;
use app\controller\workflow\WorkflowDefinitionController;
use app\controller\workflow\WorkflowInstanceController;
use app\controller\workflow\WorkflowTaskController;
use app\controller\workflow\WorkflowTriggerController;
use Infrastructure\Permission\Service\PermissionService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 工作流「租户归属缺失即放行」收敛测试（缺陷 R13）
 *
 * 背景：
 *   四处工作流控制器对「详情/修改/删除」等按 ID 访问的端点，租户校验写成：
 *     - 定义：`$tenantId !== null && $definition->getTenantId() !== null && getTenantId() !== $tenantId`
 *     - 任务/实例/触发器：`$tenantId !== null && isset($x['tenant_id']) && $x['tenant_id'] !== $tenantId`
 *   PHP 的 `isset($x['tenant_id'])` 对 **null 值返回 false**，因此 tenant_id 为 null 的记录会
 *   整段跳过校验（fail-open）→ 任意租户都能读到、改到、删掉它。`$tenantId !== null` 也是死条件
 *   （`app\Request::tenantId(): int` 恒 >= 1）。
 *
 * 本测试通过真实 HTTP 路由 + 真实 JWT/RBAC 链路钉住收敛后的语义：
 *   1. tenant_id 为 null 的记录，对**任何**租户的详情/动作端点都必须拒绝（403/404，绝不能 200）；
 *   2. 同租户记录仍然 200（防止把校验改死）；
 *   3. 越权调用不得产生副作用（定义未被删/归档、任务未被认领、实例未被取消、触发器未被禁用）；
 *   4. 当前租户上下文为非正数（伪造 tenant_id = 0 的 token）时，**任何**记录都不可访问
 *      （否则 `(int) null === 0` 会把「缺失归属」误判为「归属正确」，见用例 14）。
 *
 * 注意：
 *   - 工作流数据存放在控制器进程内 `protected static array`（demo 实现），本测试用反射播种，
 *     并在每个用例前后清空，避免用例间互相污染；
 *   - 「tenant_id 为 null 的记录」在 DB 中不可能存在（各表 tenant_id 均 NOT NULL），
 *     这里刻意播种，是为了把「缺失归属 → 放行」的失效开放分支钉死成回归断言。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowTenantOwnershipTest extends ThinkPHPTestCase
{
    /**
     * 测试用户 ID（租户 1 / 租户 2）
     *
     * 选用 67xx 段，避开既有用例占用的 61xx/62xx/63xx/64xx/65xx/66xx 子段
     * 以及以高位 ID 批量清理的测试。
     */
    private int $tenant1UserId = 6701;
    private int $tenant2UserId = 6702;

    /**
     * 测试角色 ID（租户 1 / 租户 2）
     */
    private int $tenant1RoleId = 6711;
    private int $tenant2RoleId = 6712;

    /**
     * 工作流权限 ID（读 / 写或删）
     *
     * PermissionService 以 `resource:action` 拼接权限码，
     * 例如 resource=workflow + action=definition:read → workflow.definition.read。
     */
    private int $definitionReadPermissionId = 6721;
    private int $definitionDeletePermissionId = 6722;
    private int $definitionWritePermissionId = 6723;
    private int $taskReadPermissionId = 6724;
    private int $taskWritePermissionId = 6725;
    private int $instanceReadPermissionId = 6726;
    private int $instanceWritePermissionId = 6727;
    private int $triggerReadPermissionId = 6728;
    private int $triggerWritePermissionId = 6729;

    protected function setUp(): void
    {
        parent::setUp();

        // 绑定自定义 Request，确保 userId()/tenantId()/userGroups() 等多租户方法可用
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 加载工作流路由（含 Auth + Permission 中间件）
        $routeFile = $this->app()->getRootPath() . 'route/workflow.php';
        if (file_exists($routeFile)) {
            include_once $routeFile;
        }

        // 复位鉴权中间件实例：其他测试类可能把 Auth / Permission 绑定为 no-op，
        // 容器会缓存首次解析结果，必须显式覆盖为真实实现
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        // 清空路由/控制器中间件队列（中间件队列在同进程内跨请求累积）
        $this->resetRouteMiddlewareQueue();

        // 清空控制器进程内静态存储，避免用例间互相污染
        $this->resetInMemoryStores();

        $this->seedFixtures();
        $this->clearPermissionCache();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->resetInMemoryStores();
        $this->cleanupFixtures();
        $this->clearPermissionCache();

        parent::tearDown();
    }

    // =====================================================================
    // 工作流定义（WorkflowDefinitionController）
    // =====================================================================

    /**
     * 用例 1（RED）：tenant_id 为 null 的定义，任何租户读取详情都必须被拒绝
     *
     * 现状缺陷：`$definition->getTenantId() !== null && ...` 让缺失归属的定义直接放行 → 200。
     */
    public function testDefinitionDetailIsDeniedForRecordWithoutTenantId(): void
    {
        $definitionId = $this->seedDefinition(null);

        $this->assertReadDeniedForBothTenants(
            'v1/workflow/definitions/' . $definitionId,
            'tenant_id 为 null 的工作流定义'
        );
    }

    /**
     * 用例 2：同租户定义详情必须 200（防止收敛把正常路径改死）
     */
    public function testDefinitionDetailIsAllowedForOwnTenantRecord(): void
    {
        $definitionId = $this->seedDefinition(1);

        $response = $this->httpRequest(
            'GET',
            'v1/workflow/definitions/' . $definitionId,
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '同租户读取定义详情应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '同租户读取定义详情的业务码应为 0，实际响应：' . $response->getContent());
        $this->assertSame($definitionId, $body['data']['id'], '返回的应是请求的那条定义');
        $this->assertSame(1, $body['data']['tenant_id'], '返回定义的 tenant_id 应为 1');
    }

    /**
     * 用例 3（RED）：tenant_id 为 null 的定义，validate / archive / delete 都必须被拒绝且无副作用
     *
     * 覆盖 `validateAction()` / `archive()` / `delete()` 三处同样的失效开放分支。
     */
    public function testDefinitionActionEndpointsAreDeniedForRecordWithoutTenantId(): void
    {
        $definitionId = $this->seedDefinition(null);
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        $this->assertDenied(
            $this->httpRequest('POST', 'v1/workflow/definitions/' . $definitionId . '/validate', [], $headers),
            'tenant_id 为 null 的定义，validate 必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('POST', 'v1/workflow/definitions/' . $definitionId . '/archive', [], $headers),
            'tenant_id 为 null 的定义，archive 必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('DELETE', 'v1/workflow/definitions/' . $definitionId, [], $headers),
            'tenant_id 为 null 的定义，delete 必须被拒绝（不得 200）'
        );

        // 副作用断言：定义必须仍在，且状态未被 archive 改写
        $definition = $this->getStatic(WorkflowDefinitionController::class, 'definitions', $definitionId);
        $this->assertNotNull($definition, '越权 delete 被拒绝后，定义不应被删除');
        $this->assertNotSame(
            'archived',
            $definition->getStatus(),
            '越权 archive 被拒绝后，定义状态不应被改写为 archived'
        );

        // 防止修过头：同租户定义仍可正常删除
        $ownDefinitionId = $this->seedDefinition(1);
        $ownResponse = $this->httpRequest('DELETE', 'v1/workflow/definitions/' . $ownDefinitionId, [], $headers);
        $this->assertSame(
            200,
            $ownResponse->getCode(),
            '同租户删除自己的定义应返回 200，实际响应：' . $ownResponse->getContent()
        );
    }

    // =====================================================================
    // 工作流任务（WorkflowTaskController）
    // =====================================================================

    /**
     * 用例 4（RED）：tenant_id 为 null 的任务，任何租户读取详情都必须被拒绝
     */
    public function testTaskDetailIsDeniedForRecordWithoutTenantId(): void
    {
        $taskId = 'task_r13_orphan_' . uniqid();
        $this->seedTask($taskId, null);

        $this->assertReadDeniedForBothTenants(
            'v1/workflow/tasks/' . $taskId,
            'tenant_id 为 null 的工作流任务'
        );
    }

    /**
     * 用例 5：同租户任务详情必须 200（防止收敛把正常路径改死）
     */
    public function testTaskDetailIsAllowedForOwnTenantRecord(): void
    {
        $taskId = 'task_r13_own_' . uniqid();
        $this->seedTask($taskId, 1);

        $response = $this->httpRequest(
            'GET',
            'v1/workflow/tasks/' . $taskId,
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '同租户读取任务详情应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '同租户读取任务详情的业务码应为 0，实际响应：' . $response->getContent());
        $this->assertSame($taskId, $body['data']['id'], '返回的应是请求的那条任务');
    }

    /**
     * 用例 6（RED）：tenant_id 为 null 的任务，form / claim / approve 都必须被拒绝且无副作用
     *
     * 覆盖 `getForm()`、`claim()` 与 `checkTaskAccess()`（approve 走它）三处失效开放分支。
     */
    public function testTaskActionEndpointsAreDeniedForRecordWithoutTenantId(): void
    {
        $taskId = 'task_r13_action_' . uniqid();
        $this->seedTask($taskId, null);
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/tasks/' . $taskId . '/form', [], $headers),
            'tenant_id 为 null 的任务，读取表单必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/claim', [], $headers),
            'tenant_id 为 null 的任务，claim 必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('POST', 'v1/workflow/tasks/' . $taskId . '/approve', ['comment' => '越权审批'], $headers),
            'tenant_id 为 null 的任务，approve 必须被拒绝（不得 200）'
        );

        // 副作用断言：任务未被认领、状态仍为 pending
        $task = $this->getStatic(WorkflowTaskController::class, 'tasks', $taskId);
        $this->assertNotNull($task, '任务应仍存在于控制器静态存储中');
        $this->assertNull($task['assignee'], '越权 claim 被拒绝后，任务 assignee 不应被改写');
        $this->assertSame(
            WorkflowTaskController::STATUS_PENDING,
            $task['status'],
            '越权 approve 被拒绝后，任务状态应仍为 pending'
        );

        // 防止修过头：同租户任务仍可正常认领
        $ownTaskId = 'task_r13_action_own_' . uniqid();
        $this->seedTask($ownTaskId, 1);
        $ownResponse = $this->httpRequest('POST', 'v1/workflow/tasks/' . $ownTaskId . '/claim', [], $headers);
        $this->assertSame(
            200,
            $ownResponse->getCode(),
            '同租户认领自己的任务应返回 200，实际响应：' . $ownResponse->getContent()
        );
    }

    /**
     * 用例 7（RED）：待办列表不得把「缺失 tenant_id」的任务显示给任何租户
     *
     * 覆盖 `pending()` 内 array_filter 的同一处失效开放分支（本文件唯一在闭包内调用
     * helper 的位置）：两条任务都指派给当前用户，唯一差别是 tenant_id；缺失归属的那条
     * 在修复前会出现在待办列表中（`isset()` 对 null 返回 false → 跳过整个校验）。
     */
    public function testPendingListExcludesTasksWithoutTenantId(): void
    {
        $ownTaskId = 'task_r13_pending_own_' . uniqid();
        $orphanTaskId = 'task_r13_pending_orphan_' . uniqid();

        $this->seedTask($ownTaskId, 1, $this->tenant1UserId);
        $this->seedTask($orphanTaskId, null, $this->tenant1UserId);

        $response = $this->httpRequest(
            'GET',
            'v1/workflow/tasks/pending',
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '待办列表接口应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '待办列表的业务码应为 0，实际响应：' . $response->getContent());

        $ids = array_column($body['data']['list'], 'id');
        $this->assertContains($ownTaskId, $ids, '待办列表应包含本租户指派给当前用户的任务（正常路径）');
        $this->assertNotContains(
            $orphanTaskId,
            $ids,
            '缺少 tenant_id 的任务不得出现在任何租户的待办列表中（R13：isset() 对 null 返回 false 导致校验被跳过）'
        );
    }

    // =====================================================================
    // 工作流实例（WorkflowInstanceController）
    // =====================================================================
    /**
     * 用例 8（RED）：tenant_id 为 null 的实例，任何租户读取详情都必须被拒绝
     */
    public function testInstanceDetailIsDeniedForRecordWithoutTenantId(): void
    {
        $instanceId = 'inst_r13_orphan_' . uniqid();
        $this->seedInstance($instanceId, null);

        $this->assertReadDeniedForBothTenants(
            'v1/workflow/instances/' . $instanceId,
            'tenant_id 为 null 的工作流实例'
        );
    }

    /**
     * 用例 9：同租户实例详情必须 200（防止收敛把正常路径改死）
     */
    public function testInstanceDetailIsAllowedForOwnTenantRecord(): void
    {
        $instanceId = 'inst_r13_own_' . uniqid();
        $this->seedInstance($instanceId, 1);

        $response = $this->httpRequest(
            'GET',
            'v1/workflow/instances/' . $instanceId,
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '同租户读取实例详情应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '同租户读取实例详情的业务码应为 0，实际响应：' . $response->getContent());
        $this->assertSame($instanceId, $body['data']['id'], '返回的应是请求的那条实例');
    }

    /**
     * 用例 10（RED）：tenant_id 为 null 的实例，cancel / history / variables 都必须被拒绝且无副作用
     */
    public function testInstanceActionEndpointsAreDeniedForRecordWithoutTenantId(): void
    {
        $instanceId = 'inst_r13_action_' . uniqid();
        $this->seedInstance($instanceId, null);
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        $this->assertDenied(
            $this->httpRequest('POST', 'v1/workflow/instances/' . $instanceId . '/cancel', ['reason' => '越权取消'], $headers),
            'tenant_id 为 null 的实例，cancel 必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/instances/' . $instanceId . '/history', [], $headers),
            'tenant_id 为 null 的实例，history 必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/instances/' . $instanceId . '/variables', [], $headers),
            'tenant_id 为 null 的实例，variables 必须被拒绝（不得 200）'
        );

        // 副作用断言：实例状态未被取消
        $instance = $this->getStatic(WorkflowInstanceController::class, 'instances', $instanceId);
        $this->assertNotNull($instance, '实例应仍存在于控制器静态存储中');
        $this->assertSame(
            WorkflowInstanceController::STATUS_RUNNING,
            $instance['status'],
            '越权 cancel 被拒绝后，实例状态应仍为 running'
        );

        // 防止修过头：同租户实例仍可正常取消
        $ownInstanceId = 'inst_r13_action_own_' . uniqid();
        $this->seedInstance($ownInstanceId, 1);
        $ownResponse = $this->httpRequest(
            'POST',
            'v1/workflow/instances/' . $ownInstanceId . '/cancel',
            ['reason' => '正常取消'],
            $headers
        );
        $this->assertSame(
            200,
            $ownResponse->getCode(),
            '同租户取消自己的实例应返回 200，实际响应：' . $ownResponse->getContent()
        );
    }

    // =====================================================================
    // 工作流触发器（WorkflowTriggerController）
    // =====================================================================

    /**
     * 用例 11（RED）：tenant_id 为 null 的触发器，任何租户读取详情都必须被拒绝
     */
    public function testTriggerDetailIsDeniedForRecordWithoutTenantId(): void
    {
        $triggerId = 'trig_r13_orphan_' . uniqid();
        $this->seedTrigger($triggerId, null);

        $this->assertReadDeniedForBothTenants(
            'v1/workflow/triggers/' . $triggerId,
            'tenant_id 为 null 的工作流触发器'
        );
    }

    /**
     * 用例 12：同租户触发器详情必须 200（防止收敛把正常路径改死）
     */
    public function testTriggerDetailIsAllowedForOwnTenantRecord(): void
    {
        $triggerId = 'trig_r13_own_' . uniqid();
        $this->seedTrigger($triggerId, 1);

        $response = $this->httpRequest(
            'GET',
            'v1/workflow/triggers/' . $triggerId,
            [],
            $this->authHeaders($this->tenant1UserId, 1)
        );

        $this->assertSame(
            200,
            $response->getCode(),
            '同租户读取触发器详情应返回 200，实际响应：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(0, $body['code'], '同租户读取触发器详情的业务码应为 0，实际响应：' . $response->getContent());
        $this->assertSame($triggerId, $body['data']['id'], '返回的应是请求的那条触发器');
    }

    /**
     * 用例 13（RED）：tenant_id 为 null 的触发器，disable / logs 都必须被拒绝且无副作用
     */
    public function testTriggerActionEndpointsAreDeniedForRecordWithoutTenantId(): void
    {
        $triggerId = 'trig_r13_action_' . uniqid();
        $this->seedTrigger($triggerId, null);
        $headers = $this->authHeaders($this->tenant1UserId, 1);

        $this->assertDenied(
            $this->httpRequest('POST', 'v1/workflow/triggers/' . $triggerId . '/disable', [], $headers),
            'tenant_id 为 null 的触发器，disable 必须被拒绝（不得 200）'
        );

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/triggers/' . $triggerId . '/logs', [], $headers),
            'tenant_id 为 null 的触发器，logs 必须被拒绝（不得 200）'
        );

        // 副作用断言：触发器状态未被改写
        $trigger = $this->getStatic(WorkflowTriggerController::class, 'triggers', $triggerId);
        $this->assertNotNull($trigger, '触发器应仍存在于控制器静态存储中');
        $this->assertSame(
            WorkflowTriggerController::STATUS_ENABLED,
            $trigger['status'],
            '越权 disable 被拒绝后，触发器状态应仍为 enabled'
        );

        // 防止修过头：同租户触发器仍可正常禁用
        $ownTriggerId = 'trig_r13_action_own_' . uniqid();
        $this->seedTrigger($ownTriggerId, 1);
        $ownResponse = $this->httpRequest('POST', 'v1/workflow/triggers/' . $ownTriggerId . '/disable', [], $headers);
        $this->assertSame(
            200,
            $ownResponse->getCode(),
            '同租户禁用自己的触发器应返回 200，实际响应：' . $ownResponse->getContent()
        );
    }

    // =====================================================================
    // 租户上下文无效（当前租户为 0）防护
    // =====================================================================

    /**
     * 用例 14（RED）：当前租户上下文为 0 时，任何工作流记录都不得可访问
     *
     * 触发路径（真实可复现）：用应用密钥签发一个 `tenant_id = 0` 的 access token，且不带
     * `X-Tenant-ID` 头 —— `TenantIdentify` 的 `<= 0` 拒绝只作用于**请求头**，token 侧不拦，
     * `Auth` 中间件会把 0 写进 Request，于是 `Request::tenantId()` 返回 0。
     *
     * 此时若归属判断只写「`(int) $recordTenantId === $currentTenantId`」，则
     * `(int) null === 0` 会让「缺失租户归属」的记录被误判为「归属正确」→ 仍然 fail-open。
     * 因此当前租户为非正数时必须一律拒绝：
     *   - tenant_id = null 的记录 → 403（不能因 `(int) null === 0` 而放行）；
     *   - tenant_id = 1 的记录 → 同样 403（不允许「只挡 null 记录」的半吊子修法）。
     */
    public function testZeroTenantContextIsRejectedForBothOrphanAndOwnedRecords(): void
    {
        // 数组形态（Task）：tenant_id = null 与 tenant_id = 1 各一条
        $orphanTaskId = 'task_r13_zero_orphan_' . uniqid();
        $ownedTaskId = 'task_r13_zero_owned_' . uniqid();
        $this->seedTask($orphanTaskId, null);
        $this->seedTask($ownedTaskId, 1);

        // 对象形态（Definition）：同样播种一条缺失归属的定义，证明守卫对所有调用形态生效
        $orphanDefinitionId = $this->seedDefinition(null);

        $headers = $this->authHeaders($this->tenant1UserId, 0);

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/tasks/' . $orphanTaskId, [], $headers),
            '当前租户为 0 时，tenant_id 为 null 的任务必须被拒绝（(int) null === 0 会被误判为归属正确）'
        );

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/tasks/' . $ownedTaskId, [], $headers),
            '当前租户为 0 时，tenant_id = 1 的任务必须同样被拒绝（不允许只挡 null 记录的半吊子修法）'
        );

        $this->assertDenied(
            $this->httpRequest('GET', 'v1/workflow/definitions/' . $orphanDefinitionId, [], $headers),
            '当前租户为 0 时，tenant_id 为 null 的定义必须被拒绝（对象形态调用同样受守卫保护）'
        );
    }

    // =====================================================================
    // 辅助方法
    // =====================================================================

    /**
     * 断言详情端点对「租户 1 / 租户 2」两个上下文都被拒绝（403/404，绝不是 200）
     *
     * @param string $uri       请求 URI
     * @param string $resourceLabel 资源描述（用于断言消息）
     */
    private function assertReadDeniedForBothTenants(string $uri, string $resourceLabel): void
    {
        foreach ([1 => $this->tenant1UserId, 2 => $this->tenant2UserId] as $tenantId => $userId) {
            $response = $this->httpRequest('GET', $uri, [], $this->authHeaders($userId, $tenantId));

            $this->assertDenied(
                $response,
                sprintf('%s，租户 %d 读取详情必须被拒绝（缺失归属即放行是 R13 缺陷）', $resourceLabel, $tenantId)
            );
        }
    }

    /**
     * 断言响应为「拒绝访问」：HTTP 403/404，且业务码与 HTTP 状态一致
     *
     * @param mixed $response 响应对象
     * @param string $message 断言消息
     */
    private function assertDenied($response, string $message): void
    {
        $httpCode = $response->getCode();

        $this->assertContains(
            $httpCode,
            [403, 404],
            $message . '；实际 HTTP 状态：' . $httpCode . '，响应体：' . $response->getContent()
        );

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body, $message . '；响应体不是合法 JSON：' . $response->getContent());
        $this->assertSame(
            $httpCode,
            $body['code'] ?? null,
            $message . '；业务码应与 HTTP 状态一致（403 = 控制器 forbidden()），实际响应：' . $response->getContent()
        );
    }

    /**
     * 播种一条工作流定义（tenant_id 允许为 null，用于钉住失效开放分支），返回其 ID
     */
    private function seedDefinition(?int $tenantId): string
    {
        $builder = WorkflowBuilder::create('r13_wf_' . uniqid(), ['tenant_id' => $tenantId]);
        $definition = $builder->getDefinition();

        $this->putStatic(WorkflowDefinitionController::class, 'definitions', $definition->getId(), $definition);

        return $definition->getId();
    }

    /**
     * 播种一条工作流任务
     *
     * @param string   $taskId   任务 ID
     * @param int|null $tenantId 归属租户（null = 缺失归属）
     * @param int|null $assignee 受理人（用于待办列表用例）
     */
    private function seedTask(string $taskId, ?int $tenantId, ?int $assignee = null): void
    {
        $now = (new \DateTimeImmutable())->format('c');

        $this->putStatic(WorkflowTaskController::class, 'tasks', $taskId, [
            'id' => $taskId,
            'tenant_id' => $tenantId,
            'instance_id' => 'inst_' . $taskId,
            'definition_id' => 'def_' . $taskId,
            'name' => 'R13 租户归属测试任务',
            'type' => WorkflowTaskController::TYPE_APPROVAL,
            'status' => WorkflowTaskController::STATUS_PENDING,
            'assignee' => $assignee,
            'delegated_to' => null,
            'candidate_users' => [],
            'candidate_groups' => [],
            'form_data' => [],
            'due_date' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 播种一条工作流实例（状态 running，便于验证 cancel 的副作用）
     */
    private function seedInstance(string $instanceId, ?int $tenantId): void
    {
        $now = (new \DateTimeImmutable())->format('c');

        $this->putStatic(WorkflowInstanceController::class, 'instances', $instanceId, [
            'id' => $instanceId,
            'definition_id' => 'def_' . $instanceId,
            'definition_version' => 1,
            'status' => WorkflowInstanceController::STATUS_RUNNING,
            'current_node_id' => 'node_start',
            'variables' => [],
            'context' => [],
            'trigger_type' => 'manual',
            'trigger_data' => [],
            'started_by' => $this->tenant1UserId,
            'started_at' => $now,
            'completed_at' => null,
            'tenant_id' => $tenantId,
            'error_message' => null,
        ]);
    }

    /**
     * 播种一条工作流触发器（状态 enabled，便于验证 disable 的副作用）
     */
    private function seedTrigger(string $triggerId, ?int $tenantId): void
    {
        $now = (new \DateTimeImmutable())->format('c');

        $this->putStatic(WorkflowTriggerController::class, 'triggers', $triggerId, [
            'id' => $triggerId,
            'definition_id' => 'def_' . $triggerId,
            'name' => 'R13 租户归属测试触发器',
            'description' => '',
            'type' => WorkflowTriggerController::TYPE_MANUAL,
            'config' => [],
            'status' => WorkflowTriggerController::STATUS_ENABLED,
            'webhook_key' => null,
            'webhook_url' => null,
            'execution_count' => 0,
            'last_execution_at' => null,
            'last_execution_status' => null,
            'created_by' => (string) $this->tenant1UserId,
            'created_at' => $now,
            'updated_at' => $now,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * 向控制器进程内静态存储写入一条记录
     */
    private function putStatic(string $class, string $property, string $key, mixed $value): void
    {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);

        $rows = (array) $ref->getValue();
        $rows[$key] = $value;
        $ref->setValue(null, $rows);
    }

    /**
     * 从控制器进程内静态存储读取一条记录
     */
    private function getStatic(string $class, string $property, string $key): mixed
    {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);

        $rows = (array) $ref->getValue();

        return $rows[$key] ?? null;
    }

    /**
     * 构造携带 JWT 的请求头（租户上下文来自 token 中的 tenant_id）
     */
    private function authHeaders(int $userId, int $tenantId): array
    {
        return [
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                userId: $userId,
                tenantId: $tenantId,
                siteId: 0
            ),
            'Accept' => 'application/json',
        ];
    }

    /**
     * 简易 HTTP 调用辅助（与既有 Feature 测试保持一致）
     *
     * 第三个参数 true（newInstance）：容器把最近一次 HTTP 请求实例缓存在
     * instances['app\Request'] 下，必须强制新建，否则不同用例会复用同一请求对象。
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        if ($method === 'GET') {
            $request->withGet($data);
        } elseif ($method === 'POST') {
            $request->withPost($data);
            $request->withInput(json_encode($data));
        }

        $request = $request->withHeader($headers);

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

        // `think\Middleware::$queue` 是**实例**属性（非 static），必须传入对象，
        // 否则 PHP 8 会抛 "Argument #1 ($object) must be provided for instance properties"。
        $queue = (array) $ref->getValue($middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }

    /**
     * 清空四个工作流控制器的进程内静态存储
     */
    private function resetInMemoryStores(): void
    {
        $stores = [
            WorkflowDefinitionController::class => ['definitions'],
            WorkflowInstanceController::class => ['instances', 'history'],
            WorkflowTaskController::class => ['tasks', 'taskHistory'],
            WorkflowTriggerController::class => ['triggers', 'triggerLogs'],
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
     * 播种测试夹具（用户 / 角色 / 权限 / 关联）
     *
     * 采用「先清理残留 → 再插入」的幂等策略，避免上一次异常中断留下的同 ID 记录撞唯一索引。
     */
    private function seedFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->cleanupFixtures();

        // 权限：slug 唯一，resource/action 拼接成中间件校验的权限码
        Db::table('permissions')->insertAll([
            [
                'id' => $this->definitionReadPermissionId,
                'name' => 'workflow.definition.read',
                'slug' => 'workflow.definition.read',
                'resource' => 'workflow',
                'action' => 'definition:read',
                'description' => 'R13 工作流租户归属测试权限（定义读）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->definitionDeletePermissionId,
                'name' => 'workflow.definition.delete',
                'slug' => 'workflow.definition.delete',
                'resource' => 'workflow',
                'action' => 'definition:delete',
                'description' => 'R13 工作流租户归属测试权限（定义删）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->definitionWritePermissionId,
                'name' => 'workflow.definition.write',
                'slug' => 'workflow.definition.write',
                'resource' => 'workflow',
                'action' => 'definition:write',
                'description' => 'R13 工作流租户归属测试权限（定义写）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->taskReadPermissionId,
                'name' => 'workflow.task.read',
                'slug' => 'workflow.task.read',
                'resource' => 'workflow',
                'action' => 'task:read',
                'description' => 'R13 工作流租户归属测试权限（任务读）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->taskWritePermissionId,
                'name' => 'workflow.task.write',
                'slug' => 'workflow.task.write',
                'resource' => 'workflow',
                'action' => 'task:write',
                'description' => 'R13 工作流租户归属测试权限（任务写）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->instanceReadPermissionId,
                'name' => 'workflow.instance.read',
                'slug' => 'workflow.instance.read',
                'resource' => 'workflow',
                'action' => 'instance:read',
                'description' => 'R13 工作流租户归属测试权限（实例读）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->instanceWritePermissionId,
                'name' => 'workflow.instance.write',
                'slug' => 'workflow.instance.write',
                'resource' => 'workflow',
                'action' => 'instance:write',
                'description' => 'R13 工作流租户归属测试权限（实例写）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->triggerReadPermissionId,
                'name' => 'workflow.trigger.read',
                'slug' => 'workflow.trigger.read',
                'resource' => 'workflow',
                'action' => 'trigger:read',
                'description' => 'R13 工作流租户归属测试权限（触发器读）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->triggerWritePermissionId,
                'name' => 'workflow.trigger.write',
                'slug' => 'workflow.trigger.write',
                'resource' => 'workflow',
                'action' => 'trigger:write',
                'description' => 'R13 工作流租户归属测试权限（触发器写）',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // 两个租户各一个用户
        Db::table('users')->insertAll([
            [
                'id' => $this->tenant1UserId,
                'tenant_id' => 1,
                'username' => 'wf_r13_tenant1',
                'email' => 'wf_r13_tenant1@workflow-ownership.test',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->tenant2UserId,
                'tenant_id' => 2,
                'username' => 'wf_r13_tenant2',
                'email' => 'wf_r13_tenant2@workflow-ownership.test',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // 两个租户各一个角色
        Db::table('roles')->insertAll([
            [
                'id' => $this->tenant1RoleId,
                'tenant_id' => 1,
                'name' => 'wf_r13_role_1',
                'slug' => 'wf_r13_role_1',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $this->tenant2RoleId,
                'tenant_id' => 2,
                'name' => 'wf_r13_role_2',
                'slug' => 'wf_r13_role_2',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Db::table('user_roles')->insertAll([
            ['user_id' => $this->tenant1UserId, 'role_id' => $this->tenant1RoleId, 'created_at' => $now],
            ['user_id' => $this->tenant2UserId, 'role_id' => $this->tenant2RoleId, 'created_at' => $now],
        ]);

        // 两个角色都授予全部工作流权限，确保 403 来自控制器的租户归属判断（而不是权限中间件的 2002）
        $permissionIds = [
            $this->definitionReadPermissionId,
            $this->definitionDeletePermissionId,
            $this->definitionWritePermissionId,
            $this->taskReadPermissionId,
            $this->taskWritePermissionId,
            $this->instanceReadPermissionId,
            $this->instanceWritePermissionId,
            $this->triggerReadPermissionId,
            $this->triggerWritePermissionId,
        ];

        $rolePermissions = [];
        foreach ([$this->tenant1RoleId, $this->tenant2RoleId] as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rolePermissions[] = [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                ];
            }
        }
        Db::table('role_permissions')->insertAll($rolePermissions);
    }

    /**
     * 清理本用例植入的夹具数据
     */
    private function cleanupFixtures(): void
    {
        $userIds = [$this->tenant1UserId, $this->tenant2UserId];
        $roleIds = [$this->tenant1RoleId, $this->tenant2RoleId];
        $permissionIds = [
            $this->definitionReadPermissionId,
            $this->definitionDeletePermissionId,
            $this->definitionWritePermissionId,
            $this->taskReadPermissionId,
            $this->taskWritePermissionId,
            $this->instanceReadPermissionId,
            $this->instanceWritePermissionId,
            $this->triggerReadPermissionId,
            $this->triggerWritePermissionId,
        ];

        // 先清缓存再删用户，避免残留缓存导致下一次运行误判
        $this->clearPermissionCache();

        Db::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        Db::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Db::table('user_roles')->whereIn('user_id', $userIds)->delete();
        Db::table('roles')->whereIn('id', $roleIds)->delete();
        Db::table('users')->whereIn('id', $userIds)->delete();
        Db::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    /**
     * 清除测试用户的权限缓存（L1/L2）
     */
    private function clearPermissionCache(): void
    {
        $permissionService = new PermissionService();

        foreach ([$this->tenant1UserId, $this->tenant2UserId] as $userId) {
            $permissionService->clearUserCache($userId);
        }
    }
}
