<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Infrastructure\Permission\Service\PermissionCacheService;
use Tests\Helpers\AuthHelper;
use think\facade\Db;

/**
 * Form API Multi-Tenant E2E Test | 表单 API 多租户 E2E 测试
 *
 * 在 HTTP 层验证不同租户之间的 Form & 数据完全隔离，以及请求体里伪造
 * `tenant_id` / `site_id` 会被**拒绝**（HTTP 403 + 业务码 2002）而不是被静默忽略。
 *
 * 为什么是「拒绝」而不是「忽略」：实现侧
 * `Infrastructure\Lowcode\FormDesigner\Service\FormDataManager::save()`
 * 在写库前显式比对上下文租户/站点，不一致即抛 `ForbiddenException`（→ 2002 / 403）。
 * 静默忽略会让调用方误以为「我成功写进了自己指定租户的数据」，把越权尝试隐藏成一次成功。
 */
final class FormApiMultiTenantE2ETest extends FormApiTest
{
    /**
     * 租户 B 夹具用户的固定 ID（ER-04）
     *
     * 取 48xx 段：避开 seeder 权限 1001..1033、工作流夹具 6000-6999、既有用例的 99xxxx 与 >=10000。
     */
    private const TENANT_B_USER_ID = 4821;

    /**
     * 租户 B 夹具角色 ID
     */
    private const TENANT_B_ROLE_ID = 4822;

    /**
     * 租户 B 夹具用户邮箱（自然键清理用）
     */
    private const TENANT_B_USER_EMAIL = 'er04-mt-tenant-b@er04-session.test';

    /**
     * 租户 B 夹具角色 slug（自然键清理用）
     */
    private const TENANT_B_ROLE_SLUG = 'er04_mt_role_b';

    /**
     * 低代码接口需要的最小权限集合
     */
    private const LOWCODE_PERMISSION_SLUGS = ['lowcode.read', 'lowcode.write', 'lowcode.delete'];

    protected function setUp(): void
    {
        parent::setUp();

        // 确保每个用例开始前清理租户 B 专用的测试集合/表
        // 复用父类的 purgeCollection()：drop 表 + 删元数据 + 清 CollectionManager/ORM 两级缓存
        $this->purgeCollection('test_api_data_t2', 'lc_test_api_data_t2');
    }

    protected function tearDown(): void
    {
        // 清理本测试类创建的额外资源，避免影响其他用例
        $this->purgeCollection('test_api_data_t2', 'lc_test_api_data_t2');

        // ER-04：租户 B 夹具用户一并清掉（按 ID + 自然键双条件，幂等）
        $this->purgeTenantBFixtures();

        parent::tearDown();
    }

    /**
     * 构造指定租户的请求头
     *
     * ## ER-04 之后为什么要「按租户取真实用户」
     *
     * 历史实现固定用 `userId = 1` 给任意租户签 token，即「租户 1 的用户去访问租户 2」。
     * `app\middleware\Auth` 现在会回查「该用户是否**仍存在于令牌声明的租户**内」，
     * 这种「用户与租户不匹配」的令牌会被正确拒绝（401），
     * 因为它在语义上就是一次租户伪造 —— 与审计报告 ER-04 要求的
     * 「头与 Token 冲突应按明确策略拒绝，不能检查一个租户、访问另一个租户」一致。
     *
     * 因此租户 B 的请求必须用**租户 B 自己的用户**：这里按需创建/复用夹具用户。
     * 用例要验证的是「跨租户数据隔离」，不是「跨租户令牌可用」，改夹具不影响其断言含义。
     *
     * @param int $tenantId 目标租户
     * @param int $userId 用户 ID（租户 1 沿用历史的 1 号种子用户）
     * @param int $siteId 站点 ID
     * @return array 请求头
     */
    private function headersForTenant(int $tenantId, int $userId = 1, int $siteId = 0): array
    {
        if ($tenantId !== 1) {
            $userId = $this->ensureTenantUser($tenantId);
        }

        $token = AuthHelper::generateTestToken(userId: $userId, tenantId: $tenantId, siteId: $siteId);

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-ID'   => (string) $tenantId,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * 确保「租户 B」下存在一个可用的夹具用户，返回其 ID
     *
     * 幂等：先查后插；已存在（上一次异常中断留下的）就直接复用。
     * 同时授予 `lowcode.read/write/delete` 三项权限 —— 本类验证的是跨租户**数据隔离**，
     * 若夹具用户没有低代码权限，请求会先在 Permission 中间件被 403 拦下，
     * 断言就测不到隔离逻辑本身。
     *
     * @param int $tenantId 目标租户（测试库中已由 seeder 建好 id=2 的租户）
     * @return int 该租户下的用户 ID
     */
    private function ensureTenantUser(int $tenantId): int
    {
        $existing = Db::name('users')
            ->where('tenant_id', $tenantId)
            ->where('email', self::TENANT_B_USER_EMAIL)
            ->value('id');

        if ($existing === null || $existing === false) {
            $now = date('Y-m-d H:i:s');

            Db::name('users')->insert([
                'id' => self::TENANT_B_USER_ID,
                'tenant_id' => $tenantId,
                'username' => 'er04_mt_tenant_b',
                'email' => self::TENANT_B_USER_EMAIL,
                'password' => password_hash('secret123', PASSWORD_DEFAULT),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $userId = (int) (Db::name('users')
            ->where('tenant_id', $tenantId)
            ->where('email', self::TENANT_B_USER_EMAIL)
            ->value('id'));

        $this->grantLowcodePermissions($userId, $tenantId);

        // 清掉该用户的权限缓存（L1 60s / L2 300s，进程外的 File 缓存）：
        // 历史运行可能已把「无权限」写进缓存，不清的话重新授权后仍会 403。
        (new PermissionCacheService())->clearUserCache($userId, $tenantId);

        return $userId;
    }

    /**
     * 为租户 B 夹具用户建角色并授予低代码权限（幂等）
     *
     * 权限按 slug 查真实 ID（不写死 1021..1023），角色必须是**同租户**的，
     * 否则 `UserRepository::assignRole()` 的跨租户守卫会拒绝。
     *
     * @param int $userId 夹具用户 ID
     * @param int $tenantId 夹具租户 ID
     */
    private function grantLowcodePermissions(int $userId, int $tenantId): void
    {
        $permissionIds = Db::name('permissions')
            ->whereIn('slug', self::LOWCODE_PERMISSION_SLUGS)
            ->column('id');

        if (empty($permissionIds)) {
            // 测试库缺少低代码权限时不做静默跳过：直接失败，避免用例退化成「403 也算过」
            $this->fail('测试库缺少 lowcode.read/write/delete 权限，无法为租户 B 夹具用户授权');
        }

        $now = date('Y-m-d H:i:s');

        $existingRoleId = Db::name('roles')
            ->where('tenant_id', $tenantId)
            ->where('slug', self::TENANT_B_ROLE_SLUG)
            ->value('id');

        if ($existingRoleId === null || $existingRoleId === false) {
            Db::name('roles')->insert([
                'id' => self::TENANT_B_ROLE_ID,
                'tenant_id' => $tenantId,
                'name' => 'ER-04 Tenant B Role',
                'slug' => self::TENANT_B_ROLE_SLUG,
                'description' => 'lowcode permissions for cross-tenant isolation tests',
                'is_system' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $existingRoleId = self::TENANT_B_ROLE_ID;
        }

        $roleId = (int) $existingRoleId;

        // 幂等重建授权：先删后插，避免重复行与历史残留
        Db::name('role_permissions')->where('role_id', $roleId)->delete();
        Db::name('user_roles')->where('user_id', $userId)->delete();

        $rows = [];
        foreach ($permissionIds as $permissionId) {
            $rows[] = [
                'role_id' => $roleId,
                'permission_id' => (int) $permissionId,
                'created_at' => $now,
            ];
        }
        Db::name('role_permissions')->insertAll($rows);

        Db::name('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);
    }

    /**
     * 清理租户 B 夹具（ID + 自然键双条件，幂等）
     */
    private function purgeTenantBFixtures(): void
    {
        $roleIds = Db::name('roles')
            ->where(function ($query): void {
                $query->where('id', self::TENANT_B_ROLE_ID)
                    ->whereOr('slug', self::TENANT_B_ROLE_SLUG);
            })
            ->column('id');

        $userIds = Db::name('users')
            ->where(function ($query): void {
                $query->where('id', self::TENANT_B_USER_ID)
                    ->whereOr('email', self::TENANT_B_USER_EMAIL);
            })
            ->column('id');

        if (!empty($roleIds)) {
            Db::name('role_permissions')->whereIn('role_id', $roleIds)->delete();
            Db::name('user_roles')->whereIn('role_id', $roleIds)->delete();
            Db::name('roles')->whereIn('id', $roleIds)->delete();
        }

        if (!empty($userIds)) {
            Db::name('user_roles')->whereIn('user_id', $userIds)->delete();
            Db::name('users')->whereIn('id', $userIds)->delete();
        }
    }

    public function testTenantBCannotAccessTenantAFormData(): void
    {
        // 1. 租户 A 创建 Collection + Form + Data
        $headersA = $this->headersForTenant(1);

        $this->post('v1/lowcode/collections', [
            'name'  => 'test_api_data',
            'title' => 'Test Data',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ], $headersA);

        $this->post('v1/lowcode/forms', [
            'name'            => 'data_form_mt',
            'title'           => 'Data Form MT',
            'collection_name' => 'test_api_data',
            'schema' => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                ],
            ],
        ], $headersA);

        $response = $this->post('v1/lowcode/forms/data_form_mt/data', [
            'name' => 'TenantA-Record',
        ], $headersA);
        $this->assertEquals(200, $response->getCode());
        $contentA = json_decode($response->getContent(), true);
        $id       = $contentA['data']['id'] ?? null;
        $this->assertNotEmpty($id);

        // 1.b 租户 B 创建自己的 Collection + 同名 Form，使用独立物理表
        //    为避免触发 lowcode_collections.uk_table_name 约束，B 使用 table_name = lc_test_api_data_t2
        $headersB = $this->headersForTenant(2);

        $responseB = $this->post('v1/lowcode/collections', [
            'name'  => 'test_api_data_t2',
            'title' => 'Test Data Tenant2',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ], $headersB);
        $this->assertEquals(200, $responseB->getCode(), '租户 B 创建集合失败：' . $responseB->getContent());

        $responseB = $this->post('v1/lowcode/forms', [
            'name'            => 'data_form_mt',
            'title'           => 'Data Form MT',
            'collection_name' => 'test_api_data_t2',
            'schema'          => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                ],
            ],
        ], $headersB);
        $this->assertEquals(200, $responseB->getCode());

        // 2. 租户 B 尝试访问同一 ID 的数据，应看不到租户 A 的记录
        $get = $this->get("v1/lowcode/forms/data_form_mt/data/{$id}", $headersB);
        $this->assertEquals(404, $get->getCode());

        // 2.b 租户 B 尝试更新该 ID，实际行为以当前实现为准（可能返回 4xx），但不会影响租户 A 的记录
        $update = $this->put("v1/lowcode/forms/data_form_mt/data/{$id}", [
            'name' => 'Hacked',
        ], $headersB);

        $getAAfterUpdate = $this->get("v1/lowcode/forms/data_form_mt/data/{$id}", $headersA);
        $this->assertEquals(200, $getAAfterUpdate->getCode());
        $dataAAfterUpdate = json_decode($getAAfterUpdate->getContent(), true);
        $this->assertEquals('TenantA-Record', $dataAAfterUpdate['data']['name']);

        // 2.c 租户 B 尝试删除该 ID，应返回 404 且不会删除租户 A 的记录
        $delete = $this->delete("v1/lowcode/forms/data_form_mt/data/{$id}", [], $headersB);
        $this->assertEquals(404, $delete->getCode());

        // 3. 租户 A 仍然可以访问该数据
        $getA = $this->get("v1/lowcode/forms/data_form_mt/data/{$id}", $headersA);
        $this->assertEquals(200, $getA->getCode());
        $dataA = json_decode($getA->getContent(), true);
        $this->assertEquals('TenantA-Record', $dataA['data']['name']);
    }

    public function testSameFormNameIsolatedBetweenTenants(): void
    {
        $headersA = $this->headersForTenant(1);
        $headersB = $this->headersForTenant(2);

        // 租户 A: 创建 Collection + Form + Data
        $response = $this->post('v1/lowcode/collections', [
            'name'  => 'test_api_data',
            'title' => 'Test Data',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ], $headersA);
        $this->assertEquals(200, $response->getCode());

        $response = $this->post('v1/lowcode/forms', [
            'name'            => 'data_form_mt_iso',
            'title'           => 'Data Form MT ISO',
            'collection_name' => 'test_api_data',
            'schema'          => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                ],
            ],
        ], $headersA);
        $this->assertEquals(200, $response->getCode());

        $response = $this->post('v1/lowcode/forms/data_form_mt_iso/data', [
            'name' => 'TenantA-Only',
        ], $headersA);
        $this->assertEquals(200, $response->getCode());

        // 租户 B: 同名 Form + 自己的数据
        $response = $this->post('v1/lowcode/collections', [
            'name'  => 'test_api_data_t2',
            'title' => 'Test Data',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ], $headersB);
        $this->assertEquals(200, $response->getCode(), '租户 B 创建集合失败：' . $response->getContent());

        $response = $this->post('v1/lowcode/forms', [
            'name'            => 'data_form_mt_iso',
            'title'           => 'Data Form MT ISO',
            'collection_name' => 'test_api_data_t2',
            'schema'          => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                ],
            ],
        ], $headersB);
        $this->assertEquals(200, $response->getCode());

        $response = $this->post('v1/lowcode/forms/data_form_mt_iso/data', [
            'name' => 'TenantB-Only',
        ], $headersB);
        $this->assertEquals(200, $response->getCode());

        // 各自列表只包含自己的数据
        $listA = $this->get('v1/lowcode/forms/data_form_mt_iso/data', $headersA);
        $this->assertEquals(200, $listA->getCode());
        $dataA = json_decode($listA->getContent(), true);
        $this->assertCount(1, $dataA['data']['list']);
        $this->assertEquals('TenantA-Only', $dataA['data']['list'][0]['name']);

        $listB = $this->get('v1/lowcode/forms/data_form_mt_iso/data', $headersB);
        $this->assertEquals(200, $listB->getCode());
        $dataB = json_decode($listB->getContent(), true);
        $this->assertCount(1, $dataB['data']['list']);
        $this->assertEquals('TenantB-Only', $dataB['data']['list'][0]['name']);
    }

    public function testInjectedTenantIdInPayloadIsRejected(): void
    {
        // 上下文固定为「租户 1 / 站点 1」（JWT 与 X-Tenant-ID 一致）
        $headersA = $this->headersForTenant(1, 1, 1);
        $this->prepareCollectionAndForm($headersA, 'data_form_mt_payload');

        // 0. 阳性对照：同一路由、同一表单，不带伪造字段 → 可以正常写入。
        //    没有这一步，「403 且没落库」也可能只是表不存在 / 路由没注册 / 校验失败
        //    这类与「拒绝伪造」无关的原因，断言就失去了判别力。
        $legit = $this->post('v1/lowcode/forms/data_form_mt_payload/data', [
            'name' => 'Legit-Record',
        ], $headersA);
        $this->assertEquals(200, $legit->getCode(), '阳性对照失败：合法请求应能写入');
        $this->assertSame(
            1,
            (int) Db::table('lc_test_api_data')->where('name', 'Legit-Record')->count(),
            '阳性对照失败：合法请求应真的写进物理表'
        );
        // 合法请求落库时，tenant_id / site_id 必须来自**请求上下文**（原用例的核心断言，保留）
        $legitRow = Db::table('lc_test_api_data')->where('name', 'Legit-Record')->find();
        $this->assertSame(1, (int) $legitRow['tenant_id'], '落库行的 tenant_id 应取自请求上下文');
        $this->assertSame(1, (int) $legitRow['site_id'], '落库行的 site_id 应取自请求上下文');

        // 1. 在 body 里伪造 tenant_id（上下文是 1，这里给 999）→ 必须被拒绝
        $response = $this->post('v1/lowcode/forms/data_form_mt_payload/data', [
            'name'      => 'Tenant-Injection',
            'tenant_id' => 999,
        ], $headersA);

        // 2. HTTP 状态码与业务码：403 + 2002
        $this->assertEquals(403, $response->getCode(), '伪造 tenant_id 必须以 HTTP 403 拒绝');
        $content = json_decode($response->getContent(), true);
        $this->assertSame(2002, $content['code'], '业务码必须是 2002（FORBIDDEN）');
        $this->assertStringContainsString('租户', (string) $content['message'], '错误信息应指向租户维度');

        // 3. 「拒绝」必须包含「没落库」——只断言状态码挡不住「先写库再报错」这种实现
        $this->assertSame(
            0,
            (int) Db::table('lc_test_api_data')->where('name', 'Tenant-Injection')->count(),
            '被拒绝的伪造数据不得写入物理表'
        );
        // 物理表里只应有阳性对照那一条：伪造请求不能换个 tenant_id/site_id 偷偷插进去
        $this->assertSame(
            1,
            (int) Db::table('lc_test_api_data')->count(),
            '物理表应只有阳性对照那 1 行，被拒绝的请求不得留下任何行'
        );
    }

    /**
     * 伪造 site_id 必须被拒绝 | Injected site_id must be rejected
     *
     * 与 tenant_id 伪造对称：`FormDataManager::save()` 的两道守卫是并列的，
     * 只覆盖 tenant 会让 site 维度越权（例如把数据塞进同租户的另一个站点）无人看守。
     */
    public function testInjectedSiteIdInPayloadIsRejected(): void
    {
        $headersA = $this->headersForTenant(1, 1, 1);
        $this->prepareCollectionAndForm($headersA, 'data_form_mt_payload');

        // 伪造 site_id（上下文站点是 1，这里给 999），tenant_id 保持上下文值
        $response = $this->post('v1/lowcode/forms/data_form_mt_payload/data', [
            'name'      => 'Site-Injection',
            'tenant_id' => 1,
            'site_id'   => 999,
        ], $headersA);

        $this->assertEquals(403, $response->getCode(), '伪造 site_id 必须以 HTTP 403 拒绝');
        $content = json_decode($response->getContent(), true);
        $this->assertSame(2002, $content['code'], '业务码必须是 2002（FORBIDDEN）');
        $this->assertStringContainsString('站点', (string) $content['message'], '错误信息应指向站点维度');

        // 未落库：既没有落在伪造的站点，也没有落在真实站点
        $this->assertSame(
            0,
            (int) Db::table('lc_test_api_data')->where('name', 'Site-Injection')->count(),
            '被拒绝的伪造数据不得写入物理表'
        );
        $this->assertSame(
            0,
            (int) Db::table('lc_test_api_data')->count(),
            '被拒绝的请求不得在物理表留下任何行'
        );
    }

    /**
     * 建好集合与表单，供上面的伪造用例复用 | Create collection + form for the spoofing cases
     *
     * 集合名固定为 `test_api_data`：父类 setUp/tearDown 会通过 `purgeCollection()`
     * 把物理表 `lc_test_api_data`、`lowcode_collections` 里的集合行，以及
     * CollectionManager 缓存与 think-orm 的进程内字段缓存一并清掉，
     * 因此本方法在每个用例里都可以安全重建（不会拿到上一轮的旧字段集）。
     */
    private function prepareCollectionAndForm(array $headers, string $formName): void
    {
        $response = $this->post('v1/lowcode/collections', [
            'name'  => 'test_api_data',
            'title' => 'Test Data',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ], $headers);
        $this->assertEquals(200, $response->getCode(), '准备阶段失败：集合未创建成功');

        $response = $this->post('v1/lowcode/forms', [
            'name'            => $formName,
            'title'           => 'Data Form MT Payload',
            'collection_name' => 'test_api_data',
            'schema'          => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                ],
            ],
        ], $headers);
        $this->assertEquals(200, $response->getCode(), '准备阶段失败：表单未创建成功');
    }
}
