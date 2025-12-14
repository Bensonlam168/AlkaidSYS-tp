<?php

declare(strict_types=1);

namespace Tests\Unit\Lowcode\FormDesigner;

use Tests\ThinkPHPTestCase;
use Infrastructure\Lowcode\FormDesigner\Service\FormDataManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormValidatorManager;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Field\StringField;
use Infrastructure\Lowcode\Collection\Field\IntegerField;
use think\facade\Db;
use Mockery;

/**
 * Form Data Manager Test | 表单数据管理器测试
 *
 * Tests FormDataManager service.
 * 测试FormDataManager服务。
 *
 * @package Tests\Unit\Lowcode\FormDesigner
 */
class FormDataManagerTest extends ThinkPHPTestCase
{
    protected FormDataManager $manager;
    protected $schemaManager;
    protected $validatorManager;
    protected $collectionManager;

    protected string $testTableName = 'lowcode_test_product';

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies | Mock依赖
        $this->schemaManager = Mockery::mock(FormSchemaManager::class);
        $this->validatorManager = Mockery::mock(FormValidatorManager::class);
        $this->collectionManager = Mockery::mock(CollectionManager::class);

        $this->manager = new FormDataManager(
            $this->schemaManager,
            $this->validatorManager,
            $this->collectionManager
        );

        // Create test table | 创建测试表
        $this->createTestTable();
    }

    protected function tearDown(): void
    {
        // Drop test table | 删除测试表
        Db::execute("DROP TABLE IF EXISTS `{$this->testTableName}`");
        Mockery::close();
        parent::tearDown();
    }

    protected function createTestTable(): void
    {
        Db::execute("DROP TABLE IF EXISTS `{$this->testTableName}`");
        Db::execute("
            CREATE TABLE `{$this->testTableName}` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` bigint(20) unsigned NOT NULL DEFAULT 0,
                `site_id` bigint(20) unsigned NOT NULL DEFAULT 0,
                `name` varchar(255) NOT NULL,
                `price` int(10) NOT NULL,
                `created_at` datetime DEFAULT NULL,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_tenant_site` (`tenant_id`, `site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Test save new data | 测试保存新数据
     */
    public function testSaveNewData(): void
    {
        $formName = 'product_form';
        $data = ['name' => 'Test Product', 'price' => 100];
        $tenantId = 1;

        // Mock expectations | Mock预期
        $this->mockDependencies($formName, $tenantId);

        $id = $this->manager->save($formName, $data, $tenantId);

        $this->assertIsNumeric($id);

        // Verify DB | 验证数据库
        $saved = Db::table($this->testTableName)->find($id);
        $this->assertEquals('Test Product', $saved['name']);
        $this->assertEquals(100, $saved['price']);
        $this->assertNotNull($saved['created_at']);
    }

    /**
     * Test update data | 测试更新数据
     */
    public function testUpdateData(): void
    {
        // Insert initial data | 插入初始数据
        $id = Db::table($this->testTableName)->insertGetId([
            'tenant_id' => 1,
            'site_id' => 0,
            'name' => 'Old Name',
            'price' => 50,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $formName = 'product_form';
        $data = ['id' => $id, 'name' => 'New Name', 'price' => 200];
        $tenantId = 1;

        // Mock expectations | Mock预期
        $this->mockDependencies($formName, $tenantId);

        $resultId = $this->manager->save($formName, $data, $tenantId);

        $this->assertEquals($id, $resultId);

        // Verify DB | 验证数据库
        $saved = Db::table($this->testTableName)->find($id);
        $this->assertEquals('New Name', $saved['name']);
        $this->assertEquals(200, $saved['price']);
    }

    /**
     * Test get data | 测试获取数据
     */
    public function testGetData(): void
    {
        // Insert data | 插入数据
        $id = Db::table($this->testTableName)->insertGetId([
            'tenant_id' => 1,
            'site_id' => 0,
            'name' => 'Test Item',
            'price' => 99,
        ]);

        $formName = 'product_form';
        $tenantId = 1;

        $this->mockDependencies($formName, $tenantId);

        $result = $this->manager->get($formName, (int)$id, $tenantId);

        $this->assertNotNull($result);
        $this->assertEquals('Test Item', $result['name']);
    }

    /**
     * Test delete data | 测试删除数据
     */
    public function testDeleteData(): void
    {
        // Insert data | 插入数据
        $id = Db::table($this->testTableName)->insertGetId([
            'tenant_id' => 1,
            'site_id' => 0,
            'name' => 'To Delete',
            'price' => 10,
        ]);

        $formName = 'product_form';
        $tenantId = 1;

        $this->mockDependencies($formName, $tenantId);

        $result = $this->manager->delete($formName, (int)$id, $tenantId);

        $this->assertTrue($result);

        // Verify DB | 验证数据库
        $check = Db::table($this->testTableName)->find($id);
        $this->assertNull($check);
    }

    /**
     * Test list data | 测试列出数据
     */
    public function testListData(): void
    {
        // Insert multiple rows | 插入多行
        Db::table($this->testTableName)->insertAll([
            ['tenant_id' => 1, 'site_id' => 0, 'name' => 'Item 1', 'price' => 10],
            ['tenant_id' => 1, 'site_id' => 0, 'name' => 'Item 2', 'price' => 20],
            ['tenant_id' => 1, 'site_id' => 0, 'name' => 'Item 3', 'price' => 30],
        ]);

        $formName = 'product_form';
        $tenantId = 1;

        $this->mockDependencies($formName, $tenantId);

        // Test list all | 测试列出所有
        // 签名已调整：list(formName, tenantId, filters, page, pageSize, siteId)
        $result = $this->manager->list($formName, $tenantId, [], 1, 10);
        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['list']);

        // Test filter | 测试筛选
        $resultFilter = $this->manager->list($formName, $tenantId, ['price' => 20], 1, 10);
        $this->assertEquals(1, $resultFilter['total']);
        $this->assertEquals('Item 2', $resultFilter['list'][0]['name']);
    }

    /**
     * Test that list data is tenant scoped | 测试列表查询按租户隔离
     */
    public function testListDataIsTenantScoped(): void
    {
        // Insert data for two tenants | 为两个租户插入数据
        Db::table($this->testTableName)->insertAll([
            ['tenant_id' => 1, 'site_id' => 0, 'name' => 'Tenant1 Item', 'price' => 10],
            ['tenant_id' => 2, 'site_id' => 0, 'name' => 'Tenant2 Item', 'price' => 20],
        ]);

        $formName = 'product_form';
        $tenantId = 1;

        $this->mockDependencies($formName, $tenantId);

        // 签名已调整：list(formName, tenantId, filters, page, pageSize, siteId)
        $result = $this->manager->list($formName, $tenantId, [], 1, 10);

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['list']);
        $this->assertEquals(1, $result['list'][0]['tenant_id']);
        $this->assertEquals('Tenant1 Item', $result['list'][0]['name']);
    }

    /**
     * Test that get respects tenant isolation | 测试按ID获取时的租户隔离
     */
    public function testGetDataRespectsTenantIsolation(): void
    {
        // Insert data for two tenants | 为两个租户插入数据
        $idTenant1 = Db::table($this->testTableName)->insertGetId([
            'tenant_id' => 1,
            'site_id' => 0,
            'name' => 'Tenant1 Item',
            'price' => 10,
        ]);

        $idTenant2 = Db::table($this->testTableName)->insertGetId([
            'tenant_id' => 2,
            'site_id' => 0,
            'name' => 'Tenant2 Item',
            'price' => 20,
        ]);

        $formName = 'product_form';
        $tenantId = 1;

        $this->mockDependencies($formName, $tenantId);

        // Should get own-tenant record | 应能获取本租户记录
        $resultOwn = $this->manager->get($formName, (int) $idTenant1, $tenantId);
        $this->assertNotNull($resultOwn);
        $this->assertEquals(1, $resultOwn['tenant_id']);

        // Should not see other-tenant record | 不应看到其他租户记录
        $resultOther = $this->manager->get($formName, (int) $idTenant2, $tenantId);
        $this->assertNull($resultOther);
    }

    /**
     * Test validation failure | 测试验证失败
     */
    public function testValidationFailure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation failed');

        $formName = 'product_form';
        $data = ['name' => 'Invalid'];
        $tenantId = 1;

        $this->schemaManager->shouldReceive('get')
            ->with($formName, $tenantId, 0)
            ->andReturn([
                'schema' => [],
                'collection_name' => 'test_collection'
            ]);

        $this->validatorManager->shouldReceive('validate')
            ->andReturn('Name is required'); // Simulate failure

        $this->manager->save($formName, $data, $tenantId);
    }

    /**
     * Helper to mock dependencies | Mock依赖辅助方法
     */
    protected function mockDependencies(string $formName, int $tenantId): void
    {
        // Mock Schema Manager
        $this->schemaManager->shouldReceive('get')
            ->with($formName, $tenantId, 0)
            ->andReturn([
                'schema' => ['some' => 'schema'],
                'collection_name' => 'test_collection'
            ]);

        // Mock Validator Manager
        $this->validatorManager->shouldReceive('validate')
            ->andReturn(true);

        // Mock Collection Manager & Collection
        $collection = Mockery::mock(Collection::class);
        $collection->shouldReceive('getTableName')->andReturn($this->testTableName);

        // Mock Fields
        $field1 = new StringField('name');
        $field2 = new IntegerField('price');

        $collection->shouldReceive('getFields')->andReturn([$field1, $field2]);

        $this->collectionManager->shouldReceive('get')
            ->with('test_collection', $tenantId)
            ->andReturn($collection);
    }
}
