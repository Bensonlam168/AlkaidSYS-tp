<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Infrastructure\Permission\Service\PermissionCacheService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use Tests\Traits\WorkflowFixtureIsolation;
use think\facade\Db;

/**
 * `GET /v1/workflow/users`（本租户用户目录，转办 / 委派「选人」下拉框的数据源）
 *
 * ## 为什么需要这份测试
 *
 * 这个端点是**纯读**接口，看起来「返回个列表」没什么可错的，但它同时踩在三条红线上：
 *
 * | 红线 | 断言方式 |
 * | ---- | -------- |
 * | **租户隔离** | 租户 2 的用户绝不能出现在租户 1 的结果里（反之亦然），`total` 也不能把他算进去 |
 * | **字段最小化** | 返回行只能是 `{id, name, username}`，且响应正文里不得出现 password / email / phone 任何一个键 |
 * | **鉴权** | 无 `workflow_task.read` 的用户必须拿到 2002 / 403，而不是一份用户名单 |
 *
 * 前两条属于「一次疏忽就长期泄露」的类型：字段最小化靠 `field([...])` 白名单，
 * 但白名单最容易被「顺手加个 email 方便显示」改掉，因此这里用**响应正文全文**做断言
 * （连嵌套在别的结构里的 `email` 也一并抓），而不只是检查顶层键。
 *
 * ## 分页与关键字为什么用 `wf_dirpick_` 前缀做锚
 *
 * 测试库里还有种子用户（`test_user` / `admin` …），「总数恰好是 2」这种断言必然不稳。
 * 因此凡是需要确定性的用例，都带 `keyword=wf_dirpick_` 把结果集收敛到本测试自己播的
 * 三个「被检索用户」（租户 1 两个、租户 2 一个），再断言 `total` / 分页切分 / 顺序。
 * 两个**操作人**用户故意不叫这个名字（`wf_dir_operator*`），否则它们会混进结果集、
 * 让「恰好 2 个」变成「恰好 4 个」—— 这条坑已经踩过一次，故在此写明。
 * 不带关键字的那条用例只用来做「字段最小化」与「每个租户都只拿到自己人」的扫描。
 *
 * @package Tests\Feature\Workflow
 */
class WorkflowUserDirectoryEndpointTest extends ThinkPHPTestCase
{
    use WorkflowFixtureIsolation;

    /** 主操作人所属租户（种子数据里的默认租户） */
    private const TENANT_ID = 1;

    /** 另一个租户（用于租户隔离断言） */
    private const OTHER_TENANT_ID = 2;

    /** 持有 `workflow_task.read` 的操作人（租户 1） */
    private const USER_ID = 6801;

    /** 同租户但**无任何角色**的用户（用于鉴权失败断言） */
    private const NO_PERM_USER_ID = 6802;

    /** 租户 1 的被检索用户 A（有真实姓名） */
    private const ALICE_ID = 6811;

    /** 租户 1 的被检索用户 B（`name` 为 NULL，用于验证回退 username） */
    private const BOB_ID = 6812;

    /** 租户 2 的操作人（必须有**自己租户**的用户 + 角色：JWT 里的 tenant_id 与用户所属租户不一致时 Auth 会直接判 2001） */
    private const OTHER_TENANT_USER_ID = 6803;

    /** 租户 2 的被检索用户（绝不能出现在租户 1 的结果里） */
    private const CAROL_ID = 6813;

    private const ROLE_ID = 6821;

    /** 租户 2 的角色（`roles.tenant_id` 是真实列，跨租户复用同一个角色行不符合模型） */
    private const OTHER_TENANT_ROLE_ID = 6822;

    /** 权限码对应的固定 id（实际 id 以 ensureWorkflowPermission() 返回值为准） */
    private const PERMISSION_TASK_READ = 6831;

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
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->resetRouteMiddlewareQueue();
        $this->purgeWorkflowFixtures();

        parent::tearDown();
    }

    // =================================================================
    // 1) 字段最小化
    // =================================================================

    /**
     * 返回行**只能**是 `{id, name, username}`，且正文里不得出现任何凭证 / 联系类字段
     *
     * 这是本端点最容易被「顺手加个 email 方便显示」破坏的地方，所以断言分三层：
     *   1. 每一行的键集合恰好是三个白名单字段；
     *   2. 响应正文全文（`json_encode` 后）不含 `password` / `email` / `phone` 这些**键名**；
     *   3. 正文里也不含夹具写进库的邮箱**取值**（键名改了但值还在，一样是泄露）。
     */
    public function testListReturnsOnlyIdNameUsernameAndNoSensitiveFields(): void
    {
        $response = $this->httpRequest('GET', 'v1/workflow/users', ['page' => 1, 'page_size' => 100]);
        $this->assertSame(200, $response->getCode(), '用户目录应可读，实际：' . $response->getContent());

        $raw  = (string) $response->getContent();
        $body = json_decode($raw, true);
        $list = $body['data']['list'] ?? null;

        $this->assertIsArray($list, '必须是分页结构 {list, total, page, page_size, total_pages}');
        $this->assertNotEmpty($list, '本租户至少有夹具用户，列表不得为空');

        foreach ($list as $index => $row) {
            $this->assertIsArray($row);
            $keys = array_keys($row);
            sort($keys);
            $this->assertSame(
                ['id', 'name', 'username'],
                $keys,
                "第 {$index} 行的字段集合必须恰好是 {id, name, username}；多一个字段就是多一个泄露面"
                . '（尤其不要返回 password / email / phone / tenant_id）'
            );
        }

        foreach (['password', 'email', 'phone', 'last_login_ip'] as $forbidden) {
            $this->assertStringNotContainsString(
                '"' . $forbidden . '"',
                $raw,
                "响应正文不得出现 `{$forbidden}` 键（即便是空值也不返回）"
            );
        }

        $this->assertStringNotContainsString(
            'wf_dirpick_alice@workflow-directory.test',
            $raw,
            '响应正文不得出现用户邮箱**取值** —— 键名改了但值还在，同样是泄露'
        );
    }

    // =================================================================
    // 2) 分页 + 关键字
    // =================================================================

    /**
     * 关键字收敛后的分页：`total` 与 `list` 必须自洽（不能是「先取全表再在 PHP 里切片」）
     */
    public function testPaginationIsAppliedInDatabase(): void
    {
        $first = $this->httpRequest('GET', 'v1/workflow/users', [
            'keyword'   => 'wf_dirpick_',
            'page'      => 1,
            'page_size' => 1,
        ]);
        $this->assertSame(200, $first->getCode(), '实际：' . $first->getContent());

        $body = json_decode($first->getContent(), true);
        $data = $body['data'] ?? [];

        // 租户 1 下带 `wf_dirpick_` 的恰好两个（alice / bob）
        $this->assertSame(2, (int) ($data['total'] ?? 0), 'total 必须是**过滤后**的总数，不是全表行数');
        $this->assertSame(1, (int) ($data['page'] ?? 0), 'page 必须回显请求页码');
        $this->assertSame(1, (int) ($data['page_size'] ?? 0), 'page_size 必须回显请求页大小');
        $this->assertSame(2, (int) ($data['total_pages'] ?? 0), 'total_pages 必须按 page_size 计算');

        $pageOne = array_map('intval', array_column($data['list'], 'id'));
        $this->assertSame([self::ALICE_ID], $pageOne, '第 1 页应只有 id 最小的那个（order id asc）');

        $second = $this->httpRequest('GET', 'v1/workflow/users', [
            'keyword'   => 'wf_dirpick_',
            'page'      => 2,
            'page_size' => 1,
        ]);
        $dataTwo = json_decode($second->getContent(), true)['data'] ?? [];

        $this->assertSame(2, (int) ($dataTwo['total'] ?? 0), '翻页不得改变 total');
        $this->assertSame([self::BOB_ID], array_map('intval', array_column($dataTwo['list'], 'id')), '第 2 页应是第二个用户');

        // 越界页：允许空 list，但 total 仍如实
        $third = $this->httpRequest('GET', 'v1/workflow/users', [
            'keyword'   => 'wf_dirpick_',
            'page'      => 9,
            'page_size' => 1,
        ]);
        $dataThree = json_decode($third->getContent(), true)['data'] ?? [];
        $this->assertSame([], $dataThree['list'] ?? null, '越界页必须返回空 list，而不是把第 1 页再来一遍');
        $this->assertSame(2, (int) ($dataThree['total'] ?? 0), '越界页的 total 仍必须是匹配总数');
    }

    /**
     * `keyword` 同时匹配 `name` 与 `username`
     */
    public function testKeywordMatchesNameAndUsername(): void
    {
        // 按 username 片段匹配（ASCII）
        $byUsername = $this->httpRequest('GET', 'v1/workflow/users', ['keyword' => 'dirpick_ali', 'page' => 1, 'page_size' => 100]);
        $this->assertSame(200, $byUsername->getCode(), '实际：' . $byUsername->getContent());
        $this->assertSame(
            [self::ALICE_ID],
            array_map('intval', array_column(json_decode($byUsername->getContent(), true)['data']['list'] ?? [], 'id')),
            'keyword 必须匹配 username'
        );

        // 按 name 片段匹配（中文）—— name 与 username 是两列，只匹配一列会漏人
        $byName = $this->httpRequest('GET', 'v1/workflow/users', ['keyword' => '爱丽', 'page' => 1, 'page_size' => 100]);
        $this->assertSame(200, $byName->getCode(), '实际：' . $byName->getContent());
        $this->assertSame(
            [self::ALICE_ID],
            array_map('intval', array_column(json_decode($byName->getContent(), true)['data']['list'] ?? [], 'id')),
            'keyword 必须匹配 name（中文姓名）'
        );

        // LIKE 通配符必须被转义：输入 `%` 不能变成「匹配所有人」
        $wildcard = $this->httpRequest('GET', 'v1/workflow/users', ['keyword' => '%', 'page' => 1, 'page_size' => 100]);
        $this->assertSame(200, $wildcard->getCode(), '实际：' . $wildcard->getContent());
        $wildcardData = json_decode($wildcard->getContent(), true)['data'] ?? [];
        $this->assertSame(0, (int) ($wildcardData['total'] ?? -1), '用户输入 `%` 必须被当成字面量转义，不得匹配全部用户');
    }

    /**
     * `name` 为 NULL 的用户，`name` 回退为 `username`（下拉框 label 必须稳定可读）
     */
    public function testNameFallsBackToUsername(): void
    {
        $response = $this->httpRequest('GET', 'v1/workflow/users', ['keyword' => 'wf_dirpick_bob', 'page' => 1, 'page_size' => 10]);
        $this->assertSame(200, $response->getCode(), '实际：' . $response->getContent());

        $list = json_decode($response->getContent(), true)['data']['list'] ?? [];
        $this->assertCount(1, $list);
        $this->assertSame('wf_dirpick_bob', $list[0]['name'] ?? null, 'name 为 NULL 时必须回退 username，不能返回 null/空串');
        $this->assertSame('wf_dirpick_bob', $list[0]['username'] ?? null);
    }

    // =================================================================
    // 3) 租户隔离
    // =================================================================

    /**
     * 租户 1 只看到租户 1 的人；租户 2 只看到租户 2 的人
     */
    public function testDirectoryIsTenantScoped(): void
    {
        // a) 租户 1：带前缀关键字，恰好 alice + bob
        $tenantOne = $this->httpRequest('GET', 'v1/workflow/users', ['keyword' => 'wf_dirpick_', 'page' => 1, 'page_size' => 100]);
        $this->assertSame(200, $tenantOne->getCode(), '实际：' . $tenantOne->getContent());
        $idsOne = array_map('intval', array_column(json_decode($tenantOne->getContent(), true)['data']['list'] ?? [], 'id'));

        $this->assertContains(self::ALICE_ID, $idsOne, '本租户用户必须出现');
        $this->assertContains(self::BOB_ID, $idsOne, '本租户用户必须出现');
        $this->assertNotContains(self::CAROL_ID, $idsOne, '⚠️ 别的租户的用户绝不能出现在本租户的用户目录里');

        // b) 租户 2：同一个关键字只能看到 carol
        $tenantTwo = $this->httpRequest(
            'GET',
            'v1/workflow/users',
            ['keyword' => 'wf_dirpick_', 'page' => 1, 'page_size' => 100],
            $this->authHeaders(self::OTHER_TENANT_USER_ID, self::OTHER_TENANT_ID)
        );
        $this->assertSame(200, $tenantTwo->getCode(), '实际：' . $tenantTwo->getContent());
        $idsTwo = array_map('intval', array_column(json_decode($tenantTwo->getContent(), true)['data']['list'] ?? [], 'id'));

        $this->assertSame([self::CAROL_ID], $idsTwo, '租户 2 只能看到租户 2 的用户');
        $this->assertNotContains(self::ALICE_ID, $idsTwo, '反向隔离：租户 1 的用户不得出现在租户 2 的结果里');
        $this->assertNotContains(self::BOB_ID, $idsTwo, '反向隔离：租户 1 的用户不得出现在租户 2 的结果里');
    }

    // =================================================================
    // 4) 鉴权
    // =================================================================

    /**
     * 无 `workflow_task.read` 的用户不得拿到用户名单
     *
     * 本端点挂的是 `workflow_task.read`（理由见 `route/workflow.php` 该组注释），
     * 因此「能看任务」与「能选人」是同一个权限 —— 这条用例钉住它真的被执行了，
     * 而不是「路由挂了权限码但中间件没生效」。
     */
    public function testRequiresWorkflowTaskReadPermission(): void
    {
        $response = $this->httpRequest(
            'GET',
            'v1/workflow/users',
            ['page' => 1, 'page_size' => 10],
            $this->authHeaders(self::NO_PERM_USER_ID, self::TENANT_ID)
        );

        $this->assertSame(403, $response->getCode(), '无权限必须 403，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertSame(2002, (int) ($body['code'] ?? 0), '权限拒绝的业务码必须是 2002');
        $this->assertArrayNotHasKey('list', $body['data'] ?? [], '被拒的响应里不得夹带任何用户数据');
    }

    /**
     * 匿名请求必须 401 / 2001（`Auth` + `Permission` 双重校验）
     */
    public function testAnonymousRequestIsRejected(): void
    {
        $response = $this->httpRequest('GET', 'v1/workflow/users', ['page' => 1], [
            'Accept' => 'application/json',
        ]);

        $this->assertSame(401, $response->getCode(), '匿名必须 401，实际：' . $response->getContent());

        $body = json_decode($response->getContent(), true);
        $this->assertSame(2001, (int) ($body['code'] ?? 0), '未认证的业务码必须是 2001');
    }

    // =================================================================
    // 辅助
    // =================================================================

    /**
     * 播种夹具：用户 / 角色 / 权限 / 关联
     */
    private function seedFixtures(): void
    {
        $this->seedWorkflowFixtures(function (): void {
            $now = date('Y-m-d H:i:s');

            // 权限按 slug 确保存在（可能由平台种子预建），必须用返回 id 写 role_permissions
            $permissionId = $this->ensureWorkflowPermission('workflow_task', 'read', self::PERMISSION_TASK_READ);

            // ⚠️ insertAll 要求**每一行的键集合完全一致**（它按第一行的键生成列清单），
            // 因此 `name` 即便为空也必须显式写出，不能只在部分行出现。
            Db::table('users')->insertAll([
                [
                    'id'         => self::USER_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_dir_operator',
                    'name'       => null,
                    'email'      => 'wf_dir_operator@workflow-directory.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    // 同租户但无角色 → 用作鉴权失败用例
                    'id'         => self::NO_PERM_USER_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_dir_nobody',
                    'name'       => null,
                    'email'      => 'wf_dir_nobody@workflow-directory.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => self::ALICE_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_dirpick_alice',
                    'name'       => '爱丽丝',
                    'email'      => 'wf_dirpick_alice@workflow-directory.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    // name 故意留 NULL：验证「回退 username」
                    'id'         => self::BOB_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'username'   => 'wf_dirpick_bob',
                    'name'       => null,
                    'email'      => 'wf_dirpick_bob@workflow-directory.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    // 另一个租户：任何租户 1 的结果里都不许出现
                    'id'         => self::CAROL_ID,
                    'tenant_id'  => self::OTHER_TENANT_ID,
                    'username'   => 'wf_dirpick_carol',
                    'name'       => '卡罗尔',
                    'email'      => 'wf_dirpick_carol@workflow-directory.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    // 租户 2 的操作人：**必须存在**。用「租户 1 的用户 + tenant_id=2 的 token」
                    // 会被 `Auth` 直接判为 2001（用户与 token 声明的租户不一致），
                    // 那样测出来的就不是「目录是否按租户过滤」，而是「Auth 是否生效」。
                    'id'         => self::OTHER_TENANT_USER_ID,
                    'tenant_id'  => self::OTHER_TENANT_ID,
                    'username'   => 'wf_dir_operator_t2',
                    'name'       => null,
                    'email'      => 'wf_dir_operator_t2@workflow-directory.test',
                    'password'   => password_hash('password', PASSWORD_DEFAULT),
                    'status'     => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('roles')->insertAll([
                [
                    'id'         => self::ROLE_ID,
                    'tenant_id'  => self::TENANT_ID,
                    'name'       => 'wf_dir_role',
                    'slug'       => 'wf_dir_role',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id'         => self::OTHER_TENANT_ROLE_ID,
                    'tenant_id'  => self::OTHER_TENANT_ID,
                    'name'       => 'wf_dir_role_t2',
                    'slug'       => 'wf_dir_role_t2',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            Db::table('user_roles')->insertAll([
                [
                    'user_id'    => self::USER_ID,
                    'role_id'    => self::ROLE_ID,
                    'created_at' => $now,
                ],
                [
                    'user_id'    => self::OTHER_TENANT_USER_ID,
                    'role_id'    => self::OTHER_TENANT_ROLE_ID,
                    'created_at' => $now,
                ],
            ]);

            Db::table('role_permissions')->insertAll([
                [
                    'role_id'       => self::ROLE_ID,
                    'permission_id' => $permissionId,
                    'created_at'    => $now,
                ],
                [
                    'role_id'       => self::OTHER_TENANT_ROLE_ID,
                    'permission_id' => $permissionId,
                    'created_at'    => $now,
                ],
            ]);
        });

        // 权限缓存必须清：其它用例可能缓存过该用户的空权限集合
        (new PermissionCacheService())->clearUserCache(self::USER_ID, self::TENANT_ID);
        (new PermissionCacheService())->clearUserCache(self::NO_PERM_USER_ID, self::TENANT_ID);
        (new PermissionCacheService())->clearUserCache(self::OTHER_TENANT_USER_ID, self::OTHER_TENANT_ID);
    }

    /**
     * 构造携带 JWT 的请求头（租户上下文来自 token 里的 tenant_id）
     *
     * @return array<string, string>
     */
    private function authHeaders(int $userId = self::USER_ID, int $tenantId = self::TENANT_ID): array
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
     * 简易 HTTP 调用（默认以 USER_ID 的 JWT 发起）
     */
    private function httpRequest(string $method, string $uri, array $data = [], array $headers = [])
    {
        // 第三个参数 true：容器缓存了上一次请求实例，必须强制新建，
        // 否则 app\Request 的实例级缓存（userGroups）会跨用例串味
        $request = $this->app()->make(\think\Request::class, [], true);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $headers = $headers === [] ? $this->authHeaders() : $headers;

        $request->withGet($data);
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

        $queue = (array) $ref->getValue($middleware);
        $queue['route'] = [];
        $queue['controller'] = [];

        $ref->setValue($middleware, $queue);
    }
}
