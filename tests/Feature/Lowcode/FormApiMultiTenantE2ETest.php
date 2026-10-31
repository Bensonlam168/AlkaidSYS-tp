<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Tests\Helpers\AuthHelper;
use think\facade\Db;

/**
 * Form API Multi-Tenant E2E Test | 表单 API 多租户 E2E 测试
 *
 * 在 HTTP 层验证不同租户之间的 Form & 数据完全隔离，以及伪造 tenant_id/site_id
 * 被忽略（仅使用请求上下文中的租户信息）。
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

    public function testInjectedTenantIdInPayloadIsIgnored(): void
    {
        $headersA = $this->headersForTenant(1, 1, 1);

        // 创建集合与表单
        $this->post('v1/lowcode/collections', [
            'name'  => 'test_api_data',
            'title' => 'Test Data',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ], $headersA);
        $this->post('v1/lowcode/forms', [
            'name'            => 'data_form_mt_payload',
            'title'           => 'Data Form MT Payload',
            'collection_name' => 'test_api_data',
            'schema'          => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'x-component' => 'Input'],
                ],
            ],
        ], $headersA);

        // 在 body 中伪造 tenant_id/site_id，应被忽略
        $response = $this->post('v1/lowcode/forms/data_form_mt_payload/data', [
            'name'      => 'Payload-Injection',
            'tenant_id' => 999,
            'site_id'   => 999,
        ], $headersA);
        $this->assertEquals(200, $response->getCode());
        $content = json_decode($response->getContent(), true);
        $id      = $content['data']['id'] ?? null;
        $this->assertNotEmpty($id);

        // 直接查物理表，验证实际 tenant_id/site_id 来自上下文
        $row = Db::table('lc_test_api_data')->where('id', $id)->find();
        $this->assertEquals(1, $row['tenant_id']);
        $this->assertEquals(1, $row['site_id']);
    }
}
