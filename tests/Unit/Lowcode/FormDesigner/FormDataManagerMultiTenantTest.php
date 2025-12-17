<?php

declare(strict_types=1);

namespace Tests\Unit\Lowcode\FormDesigner;

use Tests\ThinkPHPTestCase;
use Mockery;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use think\facade\Db;

/**
 * FormDataManager Multi-Tenant Isolation Test | FormDataManager 多租户隔离测试
 *
 * Tests comprehensive multi-tenant isolation for FormDataManager operations.
 * 测试 FormDataManager 操作的全面多租户隔离。
 *
 * @package tests\Unit\Lowcode\FormDesigner
 */
class FormDataManagerMultiTenantTest extends ThinkPHPTestCase
{
    protected FormDataManager $formDataManager;
    protected string $testTableName = 'lc_test_multitenant';

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Mock FormSchemaManager
        $mockSchemaManager = Mockery::mock(FormSchemaManager::class);
        $mockSchemaManager->shouldReceive('get')
            ->andReturn([
                'id' => 1,
                'name' => 'lc_test_multitenant',
                'collection_name' => 'lc_test_multitenant',
                'schema' => ['fields' => []], // Simplified schema
            ]);
        $this->app()->bind(FormSchemaManager::class, $mockSchemaManager);

        // 2. Mock CollectionManager
        // Create a mock Collection object
        $mockCollection = Mockery::mock(CollectionInterface::class);
        $mockCollection->shouldReceive('getTableName')->andReturn($this->testTableName);

        // Mock fields: name and value
        $mockField1 = Mockery::mock();
        $mockField1->shouldReceive('getName')->andReturn('name');
        $mockField2 = Mockery::mock();
        $mockField2->shouldReceive('getName')->andReturn('value');

        $mockCollection->shouldReceive('getFields')->andReturn([$mockField1, $mockField2]);

        $mockCollectionManager = Mockery::mock(CollectionManager::class);
        $mockCollectionManager->shouldReceive('get')->andReturn($mockCollection);
        $this->app()->bind(CollectionManager::class, $mockCollectionManager);

        // 3. Mock FormValidatorManager
        $mockValidator = Mockery::mock(FormValidatorManager::class);
        $mockValidator->shouldReceive('validate')->andReturn(true);
        $this->app()->bind(FormValidatorManager::class, $mockValidator);

        // 4. Create FormDataManager with mocks injected
        $this->formDataManager = $this->app()->make(FormDataManager::class);

        // Create test table | 创建测试表
        $this->createTestTable();
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        // Drop test table | 删除测试表
        Db::execute("DROP TABLE IF EXISTS {$this->testTableName}");

        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create test table with multi-tenant structure | 创建多租户结构的测试表
     */
    protected function createTestTable(): void
    {
        Db::execute("
            CREATE TABLE IF NOT EXISTS {$this->testTableName} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL DEFAULT 0,
                site_id INT NOT NULL DEFAULT 0,
                name VARCHAR(255) NOT NULL,
                value VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tenant_id_id (tenant_id, id),
                INDEX idx_tenant_site (tenant_id, site_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Seed test data for multiple tenants | 为多个租户填充测试数据
     */
    protected function seedTestData(): void
    {
        Db::table($this->testTableName)->insertAll([
            ['tenant_id' => 1, 'site_id' => 1, 'name' => 'item1_t1', 'value' => 'tenant1_value1'],
            ['tenant_id' => 1, 'site_id' => 1, 'name' => 'item2_t1', 'value' => 'tenant1_value2'],
            ['tenant_id' => 2, 'site_id' => 1, 'name' => 'item1_t2', 'value' => 'tenant2_value1'],
            ['tenant_id' => 2, 'site_id' => 1, 'name' => 'item2_t2', 'value' => 'tenant2_value2'],
            ['tenant_id' => 2, 'site_id' => 2, 'name' => 'item1_t2s2', 'value' => 'tenant2_site2_value1'],
        ]);
    }

    /**
     * Test: Different tenants can only see their own data | 测试：不同租户只能看到自己的数据
     */
    public function testTenantIsolationOnList(): void
    {
        // Tenant 1 can see 2 records | 租户 1 能看到 2 条记录
        // Tenant 1 can see 2 records | 租户 1 能看到 2 条记录
        $tenant1Data = $this->formDataManager->list($this->testTableName, 1, [], 1, 20, 1);
        $this->assertCount(2, $tenant1Data['list']);
        foreach ($tenant1Data['list'] as $item) {
            $this->assertEquals(1, $item['tenant_id']);
            $this->assertStringContainsString('t1', $item['name']);
        }

        // Tenant 2 site 1 can see 2 records | 租户 2 站点 1 能看到 2 条记录
        // Tenant 2 site 1 can see 2 records | 租户 2 站点 1 能看到 2 条记录
        $tenant2Site1Data = $this->formDataManager->list($this->testTableName, 2, [], 1, 20, 1);
        $this->assertCount(2, $tenant2Site1Data['list']);
        foreach ($tenant2Site1Data['list'] as $item) {
            $this->assertEquals(2, $item['tenant_id']);
            $this->assertEquals(1, $item['site_id']);
        }

        // Tenant 2 site 2 can see 1 record | 租户 2 站点 2 能看到 1 条记录
        // Tenant 2 site 2 can see 1 record | 租户 2 站点 2 能看到 1 条记录
        $tenant2Site2Data = $this->formDataManager->list($this->testTableName, 2, [], 1, 20, 2);
        $this->assertCount(1, $tenant2Site2Data['list']);
        $this->assertEquals('item1_t2s2', $tenant2Site2Data['list'][0]['name']);
    }

    /**
     * Test: Cross-tenant data access is blocked | 测试：跨租户数据访问被阻止
     */
    public function testCrossTenantAccessDenied(): void
    {
        // Create a record for tenant 1 | 为租户 1 创建记录
        $createdId = $this->formDataManager->save($this->testTableName, [
            'name' => 'tenant1_secret',
            'value' => 'confidential',
        ], 1, 1);

        // Try to read it as tenant 2 (should return null) | 尝试以租户 2 身份读取（应返回 null）
        $crossTenantData = $this->formDataManager->get($this->testTableName, $createdId, 2, 1);
        $this->assertNull($crossTenantData, 'Cross-tenant access should be blocked');

        // Verify tenant 1 can still read it | 验证租户 1 仍然可以读取
        // Verify tenant 1 can still read it | 验证租户 1 仍然可以读取
        $legitData = $this->formDataManager->get($this->testTableName, $createdId, 1, 1);
        $this->assertNotNull($legitData);
        $this->assertEquals('tenant1_secret', $legitData['name']);
    }

    /**
     * Test: Update operation respects tenant isolation | 测试：更新操作遵守租户隔离
     */
    public function testUpdateRespectsTenantIsolation(): void
    {
        // Get a tenant 1 record ID | 获取租户 1 的记录 ID
        $tenant1Records = Db::table($this->testTableName)
            ->where('tenant_id', 1)
            ->select();
        $tenant1Id = $tenant1Records[0]['id'];

        // Try to update it as tenant 2 (should fail silently or throw exception) | 尝试以租户 2 身份更新
        $updateResult = $this->formDataManager->save($this->testTableName, [
            'id' => $tenant1Id,
            'value' => 'hacked_value',
        ], 2, 1);

        // Verify the original value is unchanged | 验证原始值未改变
        $original = Db::table($this->testTableName)->where('id', $tenant1Id)->find();
        $this->assertEquals(1, $original['tenant_id']);
        $this->assertEquals('tenant1_value1', $original['value'], 'Cross-tenant update should not succeed');
    }

    /**
     * Test: Delete operation respects tenant isolation | 测试：删除操作遵守租户隔离
     */
    public function testDeleteRespectsTenantIsolation(): void
    {
        // Get a tenant 1 record ID | 获取租户 1 的记录 ID
        $tenant1Records = Db::table($this->testTableName)
            ->where('tenant_id', 1)
            ->select();
        $tenant1Id = $tenant1Records[0]['id'];

        // Try to delete it as tenant 2 | 尝试以租户 2 身份删除
        // Try to delete it as tenant 2 | 尝试以租户 2 身份删除
        $this->formDataManager->delete($this->testTableName, $tenant1Id, 2, 1);

        // Verify the record still exists | 验证记录仍然存在
        $stillExists = Db::table($this->testTableName)->where('id', $tenant1Id)->find();
        $this->assertNotNull($stillExists, 'Cross-tenant delete should not succeed');
        $this->assertEquals(1, $stillExists['tenant_id']);
    }

    /**
     * Test: Tenant context is injected on create | 测试：创建时注入租户上下文
     */
    public function testTenantContextInjectedOnCreate(): void
    {
        $newId = $this->formDataManager->save($this->testTableName, [
            'name' => 'new_item',
            'value' => 'new_value',
        ], 3, 2);

        $created = Db::table($this->testTableName)->where('id', $newId)->find();
        $this->assertEquals(3, $created['tenant_id'], 'tenant_id should be injected');
        $this->assertEquals(2, $created['site_id'], 'site_id should be injected');
    }

    /**
     * Test: Tenant context cannot be overridden in data payload | 测试：数据负载中的租户上下文不能被覆盖
     */
    public function testTenantContextCannotBeOverridden(): void
    {
        // Try to inject tenant_id in payload (should be ignored) | 尝试在负载中注入 tenant_id
        $newId = $this->formDataManager->save($this->testTableName, [
            'name' => 'malicious_item',
            'value' => 'value',
            'tenant_id' => 999,  // This should be ignored | 应该被忽略
            'site_id' => 999,    // This should be ignored | 应该被忽略
        ], 1, 1);

        $created = Db::table($this->testTableName)->where('id', $newId)->find();
        $this->assertEquals(1, $created['tenant_id'], 'Should use context tenant_id, not payload');
        $this->assertEquals(1, $created['site_id'], 'Should use context site_id, not payload');
    }
}
