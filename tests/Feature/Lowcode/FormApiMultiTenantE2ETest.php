<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

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
    protected function setUp(): void
    {
        parent::setUp();

        // 确保每个用例开始前清理租户 B 专用的测试集合/表
        Db::execute('DROP TABLE IF EXISTS lc_test_api_data_t2');
        Db::execute('DELETE FROM lowcode_collections WHERE name = "test_api_data_t2"');
    }

    protected function tearDown(): void
    {
        // 清理本测试类创建的额外资源，避免影响其他用例
        Db::execute('DROP TABLE IF EXISTS lc_test_api_data_t2');
        Db::execute('DELETE FROM lowcode_collections WHERE name = "test_api_data_t2"');

        parent::tearDown();
    }

    private function headersForTenant(int $tenantId, int $userId = 1, int $siteId = 0): array
    {
        $token = AuthHelper::generateTestToken(userId: $userId, tenantId: $tenantId, siteId: $siteId);

        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-ID'   => (string) $tenantId,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
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
        $this->assertEquals(200, $responseB->getCode());

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
        $this->assertEquals(200, $response->getCode());

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
     * 集合名固定为 `test_api_data`：父类 setUp/tearDown 会 drop 物理表 `lc_test_api_data`
     * 并删掉该集合行，因此本方法在每个用例里都可以安全重建。
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
