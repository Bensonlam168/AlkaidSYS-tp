<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Infrastructure\I18n\LanguageService;
use Tests\Helpers\AuthHelper;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 权限码不可变 + 新建码归属校验（缺陷 #1：权限定义改写 = 等价提权）
 *
 * ## 守住的缺陷
 *
 * `PUT /v1/admin/permissions/{id}` 原先直接接受请求里的 `slug` / `resource` / `action` 并落库，
 * 而鉴权认的是 `resource + action` 拼出的权限码（`resource:action`）。于是持有
 * `permission.write` 的人可以：
 *
 *   1. 把自己**已经持有**的那一行（例如 `role.write`）改成 `resource=tenant, action=write`；
 *   2. `role_permissions` 里的绑定一行都没变；
 *   3. 他从下一次请求起就持有 `tenant:write` —— **等价提权**。
 *
 * `permissions` 还是**全局目录**（无 `tenant_id`），一次改写影响**所有租户**。
 *
 * 修好后：`update()` 只接受 `name` / `description`；对码的改写尝试一律 422 且**不落库**。
 * 同时 `save()`（新建）要求「新码必须已在操作者权限集内」，否则 403 / 2002
 * —— 否则 `permission.write` 持有者可以往全局目录里塞任意码（污染所有租户的授权界面）。
 *
 * ## 为什么钉「未变更」的断言要查库
 *
 * 遮蔽/校验类缺陷最典型的假绿是"接口返回 4xx，但库里已经被改了"。因此每条拒绝用例
 * 都直接回查 `permissions` 行，断言三个字段**逐字节相同**。
 *
 * ## 夹具
 *
 * - 操作者：seeded admin（用户 1 / 角色 1，持有测试库全部 52 条权限行）；
 * - 被改写目标：`tmp_immutable_probe.read`（id 980001，测试库内自建、tearDown 删除）；
 * - 新建用例的目标码：`tmp_escalate.write`（**不存在**，且 admin 不持有）与
 *   `tenant:write` 的重复行（admin 持有该码 → 正面对照）。
 *
 * @package Tests\Feature\Admin
 */
class PermissionCodeImmutabilityTest extends ThinkPHPTestCase
{
    /**
     * 租户 / 操作者（seeded admin：持有全部权限，因此"能不能改"只取决于不可变规则本身）
     */
    private const TENANT_ID = 1;
    private const ADMIN_USER_ID = 1;

    /**
     * 被改写目标的固定 ID 与自然键
     */
    private const TARGET_PERMISSION_ID = 980001;
    private const TARGET_RESOURCE = 'tmp_immutable_probe';
    private const TARGET_ACTION = 'read';
    private const TARGET_SLUG = 'tmp_immutable_probe.read';

    /**
     * 新建用例用到的 slug（都要在 tearDown 里按 slug 兜底清理）
     */
    private const UNOWNED_SLUG = 'tmp_escalate.write';
    private const OWNED_CODE_DUPLICATE_SLUG = 'tmp_owned_duplicate.write';

    protected function setUp(): void
    {
        parent::setUp();

        // 让容器解析 think\Request 时拿到 app\Request（否则取不到 userId/tenantId 上下文）
        $this->app()->bind(\think\Request::class, \app\Request::class);

        // 注册 admin 路由（include_once：进程内只注册一次）
        include_once $this->app()->getRootPath() . 'route/admin.php';

        // 复位鉴权中间件实例，避免其它测试类把它们绑成 no-op 替身（容器会缓存首次解析结果）
        $this->app()->instance(\app\middleware\Auth::class, new \app\middleware\Auth());
        $this->app()->instance(\app\middleware\Permission::class, new \app\middleware\Permission());

        $this->resetRouteMiddlewareQueue();
        $this->cleanupFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        $this->resetRouteMiddlewareQueue();

        parent::tearDown();
    }

    // =====================================================================
    // 场景 1：改写 slug → 422，库不变
    // =====================================================================

    /**
     * 场景 1a：把 slug 改成一个**尚不存在**的标识 → 422 且一个字段都不落库
     *
     * 用不存在的 slug 是为了让失败原因唯一：若用已存在的 slug（如 `tenant.write`），
     * 旧实现会先被"slug 唯一性"拦成 422，看起来"也没改成"，其实只是撞了另一个校验。
     */
    public function testUpdateRejectsSlugRewrite(): void
    {
        $before = $this->targetRow();
        $this->assertSame(self::TARGET_SLUG, $before['slug'], '前置条件：夹具 slug 正确');
        $this->assertNull($this->rowBySlug('tmp_hijacked.read'), '前置条件：目标 slug 尚不存在');

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['slug' => 'tmp_hijacked.read']
        );

        $this->assertImmutableRejected($response, 'slug', 'tmp_hijacked.read');
        $this->assertSame($before, $this->targetRow(), '被拒绝的改写绝不能落库');
    }

    /**
     * 场景 1b：改成**已存在**的 slug（真实攻击形态：`role.write` → `tenant.write`）
     *
     * 旧实现在这里返回 `permission.slug_exists`（撞唯一性，属于"碰巧挡住"）；
     * 现在的拒绝理由必须是**不可变**本身 —— 这样即便将来唯一的约束变化，
     * 拒绝仍然成立，且文案能告诉调用方"要走迁移"。
     */
    public function testUpdateRejectsSlugRewriteToExistingSlugWithImmutableReason(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['slug' => 'tenant.write']
        );

        $this->assertImmutableRejected($response, 'slug', 'tenant.write');
        $this->assertSame($before, $this->targetRow(), '被拒绝的改写绝不能落库');
    }

    // =====================================================================
    // 场景 2：改写 resource / action → 422，库不变
    // =====================================================================

    /**
     * 场景 2a：只改 `resource` / `action`（正是"自己持有 role.write → 变成 tenant:write"的那一步）
     */
    public function testUpdateRejectsResourceAndActionRewrite(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['resource' => 'tenant', 'action' => 'write']
        );

        $this->assertImmutableRejected($response, 'resource', 'tenant');
        $this->assertSame($before, $this->targetRow(), '被拒绝的改写绝不能落库');
    }

    /**
     * 场景 2b：只改 `resource`（少带字段也不能"漏过"）
     */
    public function testUpdateRejectsResourceOnlyRewrite(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['resource' => 'tenant']
        );

        $this->assertImmutableRejected($response, 'resource', 'tenant');
        $this->assertSame($before, $this->targetRow(), '被拒绝的改写绝不能落库');
    }

    /**
     * 场景 2c：只改 `action`
     */
    public function testUpdateRejectsActionOnlyRewrite(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['action' => 'delete']
        );

        $this->assertImmutableRejected($response, 'action', 'delete');
        $this->assertSame($before, $this->targetRow(), '被拒绝的改写绝不能落库');
    }

    // =====================================================================
    // 场景 3：只改 name / description → 200，码不变
    // =====================================================================

    /**
     * 场景 3a：只改名称与描述 → 200，且 slug / resource / action 一字不差
     */
    public function testUpdateAllowsNameAndDescriptionOnly(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['name' => 'Renamed Probe', 'description' => 'Renamed by immutability test']
        );
        $body = $this->json($response);

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(0, $body['code'] ?? null, $this->diagnostics($response));
        $this->assertSame('Renamed Probe', $body['data']['name'] ?? null);

        $after = $this->targetRow();
        $this->assertSame('Renamed Probe', $after['name']);
        $this->assertSame('Renamed by immutability test', $after['description']);

        // 关键：码一字不改
        $this->assertSame($before['slug'], $after['slug'], 'slug 必须保持不变');
        $this->assertSame($before['resource'], $after['resource'], 'resource 必须保持不变');
        $this->assertSame($before['action'], $after['action'], 'action 必须保持不变');
    }

    /**
     * 场景 3b（顺带修掉的静默数据丢失）：只改名称时，**原描述不能被清空**
     *
     * 历史实现会构造一个 description 为 null 的新实体整行覆盖，
     * 于是"只改名字"顺手把描述抹掉。
     */
    public function testUpdateWithoutDescriptionKeepsExistingDescription(): void
    {
        $before = $this->targetRow();
        $this->assertSame('fixture permission for code immutability', $before['description']);

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['name' => 'Only Name Changed']
        );

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));

        $after = $this->targetRow();
        $this->assertSame('Only Name Changed', $after['name']);
        $this->assertSame(
            'fixture permission for code immutability',
            $after['description'],
            '请求没带 description 时必须沿用原值，而不是清空（历史实现的静默数据丢失）'
        );
    }

    // =====================================================================
    // 场景 4：原样回传（幂等友好）→ 200
    // =====================================================================

    /**
     * 场景 4：把当前值**原样**回传（前端编辑抽屉就是这么干的）→ 200 且库不变
     *
     * 若"带了这个字段"就一律 422，前端「只改描述」会被连带封死。
     */
    public function testUpdateAcceptsIdenticalCodeFields(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            [
                'name' => $before['name'],
                'slug' => $before['slug'],
                'resource' => $before['resource'],
                'action' => $before['action'],
                'description' => $before['description'],
            ]
        );

        $this->assertSame(200, $response->getCode(), $this->diagnostics($response));
        $this->assertSame($before, $this->targetRow(), '原样回传不应产生任何改动');
    }

    /**
     * 场景 4b：`resource` / `action` 传 `null` 或空串（前端清空输入框）也不能被当成"改码"
     *
     * 只有当目标行的 resource/action 本来就为空时才等价；这里夹具是有值的，
     * 所以"清空"确实是一次改写 → 仍然 422。本用例钉的是**判定口径**：
     * 用规范化后的字符串比较，而不是 `null !== ''` 这种类型比较。
     */
    public function testUpdateTreatsNullAsEmptyStringWhenComparing(): void
    {
        $before = $this->targetRow();

        $response = $this->request(
            'PUT',
            'v1/admin/permissions/' . self::TARGET_PERMISSION_ID,
            ['resource' => null]
        );

        $this->assertImmutableRejected($response, 'resource', '');
        $this->assertSame($before, $this->targetRow(), '被拒绝的改写绝不能落库');
    }

    // =====================================================================
    // 场景 5：新建的码必须已在操作者权限集内
    // =====================================================================

    /**
     * 场景 5a：新建一个 admin **不持有**的码 → 403 / 2002 且库里没有新行
     */
    public function testCreateRejectsCodeOperatorDoesNotHold(): void
    {
        $this->assertNull($this->rowBySlug(self::UNOWNED_SLUG), '前置条件：该 slug 尚不存在');

        $response = $this->request('POST', 'v1/admin/permissions', [
            'name' => 'Escalation Probe',
            'slug' => self::UNOWNED_SLUG,
            'resource' => 'tmp_escalate',
            'action' => 'write',
        ]);
        $body = $this->json($response);

        $lang = new LanguageService();

        $this->assertSame(403, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(2002, $body['code'] ?? null, $this->diagnostics($response));
        $this->assertSame(
            $lang->trans('permission.code_not_owned'),
            $body['message'] ?? null,
            '拒绝必须来自"码归属校验"，不能是中间件的通用「权限不足」' . $this->diagnostics($response)
        );
        $this->assertNotSame(
            $lang->trans('error.permission_denied'),
            $body['message'] ?? null,
            '403 不能来自中间件（否则用例会在操作者根本没有 permission.write 时假通过）'
        );

        $this->assertNull($this->rowBySlug(self::UNOWNED_SLUG), '被拒绝的创建绝不能落库');
    }

    /**
     * 场景 5b（正面对照）：admin 已持有 `tenant:write`，因此"再建一行同码"是允许的
     *
     * 这一条证明守卫是"该码是否属于你"，而不是"一律不许新建"。
     */
    public function testCreateAllowsCodeOperatorAlreadyHolds(): void
    {
        $this->assertNull($this->rowBySlug(self::OWNED_CODE_DUPLICATE_SLUG), '前置条件：该 slug 尚不存在');
        $this->assertTrue(
            $this->adminHolds('tenant:write'),
            '前置条件：seeded admin 必须持有 tenant:write（否则本用例测不到正面对照）'
        );

        $response = $this->request('POST', 'v1/admin/permissions', [
            'name' => 'Duplicate code probe',
            'slug' => self::OWNED_CODE_DUPLICATE_SLUG,
            'resource' => 'tenant',
            'action' => 'write',
        ]);

        $this->assertSame(201, $response->getCode(), $this->diagnostics($response));

        $row = $this->rowBySlug(self::OWNED_CODE_DUPLICATE_SLUG);
        $this->assertNotNull($row, '允许的创建必须真的落库');
        $this->assertSame('tenant', $row['resource']);
        $this->assertSame('write', $row['action']);
    }

    /**
     * 场景 5c：连权限码都解析不出来（slug 无点、resource/action 皆空）→ 422，不落库
     *
     * 这种行折算不出 `resource:action`，任何人都不可能"持有"它，属于纯脏数据。
     */
    public function testCreateRejectsRowWithoutDerivableCode(): void
    {
        $slug = 'tmpnocode';

        $response = $this->request('POST', 'v1/admin/permissions', [
            'name' => 'No code probe',
            'slug' => $slug,
        ]);

        $this->assertSame(422, $response->getCode(), $this->diagnostics($response));
        $this->assertNull($this->rowBySlug($slug), '无法折算权限码的行绝不能落库');
    }

    // =====================================================================
    // 辅助
    // =====================================================================

    /**
     * 建立夹具：一行可被改写的权限
     */
    private function seedFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        Db::table('permissions')->insert([
            'id' => self::TARGET_PERMISSION_ID,
            'name' => 'Tmp Immutable Probe',
            'slug' => self::TARGET_SLUG,
            'resource' => self::TARGET_RESOURCE,
            'action' => self::TARGET_ACTION,
            'description' => 'fixture permission for code immutability',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 清理夹具（按 ID + 自然键，保证零残留）
     */
    private function cleanupFixtures(): void
    {
        $slugs = [
            self::TARGET_SLUG,
            self::UNOWNED_SLUG,
            self::OWNED_CODE_DUPLICATE_SLUG,
            'tmpnocode',
            'tmp_hijacked.read',
        ];

        $ids = Db::table('permissions')->whereIn('slug', $slugs)->column('id');
        $ids = array_map('intval', $ids);

        if ($ids === []) {
            return;
        }

        Db::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Db::table('permissions')->whereIn('id', $ids)->delete();
    }

    /**
     * 取目标权限行的可比较快照（只取被本用例关心的列）
     *
     * @return array<string, mixed>
     */
    private function targetRow(): array
    {
        $row = Db::table('permissions')->where('id', self::TARGET_PERMISSION_ID)->find();
        $this->assertIsArray($row, '夹具行必须存在');

        return [
            'slug' => (string) $row['slug'],
            'resource' => $row['resource'] === null ? null : (string) $row['resource'],
            'action' => $row['action'] === null ? null : (string) $row['action'],
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
        ];
    }

    /**
     * 按 slug 取一行（不存在返回 null）
     *
     * @param string $slug 权限 slug
     * @return array<string, mixed>|null
     */
    private function rowBySlug(string $slug): ?array
    {
        $row = Db::table('permissions')->where('slug', $slug)->find();

        return is_array($row) ? $row : null;
    }

    /**
     * seeded admin 是否持有某个权限码（`resource:action`）
     *
     * 直接按「角色 → 权限行」折算，与 `PermissionService::getUserPermissions()` 同口径。
     *
     * @param string $code 权限码（`resource:action`）
     * @return bool
     */
    private function adminHolds(string $code): bool
    {
        [$resource, $action] = explode(':', $code, 2);

        $count = Db::table('role_permissions')
            ->alias('rp')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->join('user_roles ur', 'ur.role_id = rp.role_id')
            ->where('ur.user_id', self::ADMIN_USER_ID)
            ->where('p.resource', $resource)
            ->where('p.action', $action)
            ->count();

        return (int) $count > 0;
    }

    /**
     * 断言是一次「权限码不可修改」拒绝：HTTP 422 + 明确文案 + 字段级错误
     *
     * @param \think\Response $response 响应
     * @param string $field 期望出现在 data.errors 里的字段名
     * @param string $attemptedValue 期望**不**出现在 message 里的尝试值
     */
    private function assertImmutableRejected($response, string $field, string $attemptedValue): void
    {
        $body = $this->json($response);
        $lang = new LanguageService();

        $this->assertSame(422, $response->getCode(), $this->diagnostics($response));
        $this->assertSame(422, $body['code'] ?? null, $this->diagnostics($response));
        $this->assertSame(
            $lang->trans('permission.code_immutable'),
            $body['message'] ?? null,
            '文案必须明确「权限码不可修改，如需变更请走迁移」' . $this->diagnostics($response)
        );
        $this->assertArrayHasKey(
            $field,
            $body['data']['errors'] ?? [],
            sprintf('data.errors 必须指出是哪个字段被试图改写（%s）', $field) . $this->diagnostics($response)
        );

        // 提示里不要回显"你试图改成什么"（那会变成一个可用于探测的 oracle）
        if ($attemptedValue !== '') {
            $this->assertStringNotContainsString($attemptedValue, (string) ($body['message'] ?? ''));
        }
    }

    /**
     * 发起一次真实 JSON 请求（走完整「路由 → Auth → Permission 中间件 → 控制器」链路）
     *
     * @param string $method HTTP 方法
     * @param string $uri 路径（不含前导斜杠）
     * @param array<string, mixed> $data 请求体
     * @return \think\Response
     */
    private function request(string $method, string $uri, array $data = [])
    {
        $this->resetRouteMiddlewareQueue();

        $request = $this->app()->make(\think\Request::class);
        $request->setMethod($method);
        $request->setPathinfo($uri);

        $request = $request->withHeader([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Tenant-ID' => (string) self::TENANT_ID,
            'Authorization' => 'Bearer ' . AuthHelper::generateTestToken(
                self::ADMIN_USER_ID,
                self::TENANT_ID,
                0
            ),
        ]);

        if ($data !== []) {
            $request->withInput((string) json_encode($data));
        }

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

        $this->assertIsArray($decoded, '响应必须是 JSON：' . (string) $response->getContent());

        return $decoded;
    }

    /**
     * 失败信息里带上原始响应体（否则只能看到"422 != 200"，复现不了）
     *
     * @param \think\Response $response 响应
     * @return string
     */
    private function diagnostics($response): string
    {
        return "\n  原始响应：" . (string) $response->getContent();
    }

    /**
     * 清空路由/控制器中间件队列（保留全局中间件）
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
}
