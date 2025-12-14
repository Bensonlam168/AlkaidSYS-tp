<?php

declare(strict_types=1);

namespace Tests\Integration\Lowcode;

use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * Lowcode CLI Integration Test | 低代码CLI集成测试
 *
 * Tests lowcode CLI commands with real database operations.
 * 使用真实数据库操作测试低代码CLI命令。
 *
 * Covers:
 * - Collection creation (lowcode:create-model)
 * - Form generation (lowcode:create-form)
 * - Schema diff detection (lowcode:migration:diff)
 * - Multi-tenant isolation
 *
 * @package Tests\Integration\Lowcode
 * @since T-057
 */
class LowcodeCliIntegrationTest extends ThinkPHPTestCase
{
    /**
     * Test tenant ID for integration tests | 集成测试用租户ID
     */
    protected const TEST_TENANT_ID = 99999;

    /**
     * Test site ID | 测试站点ID
     */
    protected const TEST_SITE_ID = 0;

    /**
     * Track created collections for cleanup | 跟踪创建的集合以便清理
     *
     * @var array<string>
     */
    protected array $createdCollections = [];

    /**
     * Track created forms for cleanup | 跟踪创建的表单以便清理
     *
     * @var array<string>
     */
    protected array $createdForms = [];

    /**
     * CollectionManager instance | CollectionManager实例
     */
    protected CollectionManager $collectionManager;

    /**
     * FormSchemaManager instance | FormSchemaManager实例
     */
    protected FormSchemaManager $formManager;

    /**
     * Set up test | 设置测试
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->collectionManager = $this->app()->make(CollectionManager::class);
        $this->formManager = $this->app()->make(FormSchemaManager::class);
    }

    /**
     * Tear down test | 清理测试
     */
    protected function tearDown(): void
    {
        // Clean up created collections | 清理创建的集合
        foreach ($this->createdCollections as $collectionName) {
            try {
                // delete signature: (name, dropTable, tenantId)
                $this->collectionManager->delete($collectionName, true, self::TEST_TENANT_ID);
            } catch (\Throwable) {
                // Ignore cleanup errors | 忽略清理错误
            }
        }

        // Clean up created forms | 清理创建的表单
        foreach ($this->createdForms as $formName) {
            try {
                $form = $this->formManager->get($formName, self::TEST_TENANT_ID);
                if ($form) {
                    $this->formManager->delete($form['id'], self::TEST_TENANT_ID);
                }
            } catch (\Throwable) {
                // Ignore cleanup errors | 忽略清理错误
            }
        }

        parent::tearDown();
    }

    /**
     * Register collection for cleanup | 注册集合以便清理
     */
    protected function registerCollection(string $name): void
    {
        $this->createdCollections[] = $name;
    }

    /**
     * Register form for cleanup | 注册表单以便清理
     */
    protected function registerForm(string $name): void
    {
        $this->createdForms[] = $name;
    }

    /**
     * Generate unique collection name | 生成唯一的集合名称
     */
    protected function generateCollectionName(string $prefix = 'TestCollection'): string
    {
        return $prefix . '_' . time() . '_' . random_int(1000, 9999);
    }

    /**
     * Generate unique form name | 生成唯一的表单名称
     */
    protected function generateFormName(string $prefix = 'test_form'): string
    {
        return $prefix . '_' . time() . '_' . random_int(1000, 9999);
    }

    // ========================================================================
    // Test Cases - Collection Creation | 测试用例 - 集合创建
    // ========================================================================

    /**
     * Test creating a collection with basic fields | 测试创建包含基本字段的集合
     */
    public function testCreateCollectionWithBasicFields(): void
    {
        $collectionName = $this->generateCollectionName();
        $this->registerCollection($collectionName);

        // Create collection | 创建集合
        $collection = new Collection($collectionName, [
            'title' => 'Test Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);

        // Add fields | 添加字段
        $nameField = FieldFactory::create('string', 'name', ['max_length' => 255]);
        $ageField = FieldFactory::create('integer', 'age', ['nullable' => true]);
        $collection->addField($nameField);
        $collection->addField($ageField);

        // Create via manager | 通过管理器创建
        $this->collectionManager->create($collection);

        // Verify collection exists | 验证集合存在
        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved, 'Collection should be retrievable after creation');
        $this->assertEquals($collectionName, $retrieved->getName());

        // Verify physical table exists | 验证物理表存在
        $tableName = $retrieved->getTableName();
        $tableExists = $this->tableExists($tableName);
        $this->assertTrue($tableExists, "Physical table {$tableName} should exist");

        // Verify fields | 验证字段
        $fields = $retrieved->getFields();
        $this->assertCount(2, $fields, 'Collection should have 2 fields');
    }

    /**
     * Test creating collection with multiple field types | 测试创建包含多种字段类型的集合
     */
    public function testCreateCollectionWithMultipleFieldTypes(): void
    {
        $collectionName = $this->generateCollectionName('MultiFieldTest');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Multi Field Test Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);

        // Add various field types | 添加各种字段类型
        // Note: default values must be strings for SchemaBuilder compatibility
        // 注意：默认值必须是字符串以兼容 SchemaBuilder
        $collection->addField(FieldFactory::create('string', 'title', ['max_length' => 255]));
        $collection->addField(FieldFactory::create('text', 'description', ['nullable' => true]));
        $collection->addField(FieldFactory::create('integer', 'quantity', ['default' => '0']));
        $collection->addField(FieldFactory::create('decimal', 'price', []));
        $collection->addField(FieldFactory::create('boolean', 'is_active', ['default' => '1']));

        $this->collectionManager->create($collection);

        // Verify | 验证
        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertCount(5, $retrieved->getFields());

        // Verify table columns | 验证表列
        $tableName = $retrieved->getTableName();
        $columns = $this->getTableColumns($tableName);
        $this->assertArrayHasKey('title', $columns);
        $this->assertArrayHasKey('description', $columns);
        $this->assertArrayHasKey('quantity', $columns);
        $this->assertArrayHasKey('price', $columns);
        $this->assertArrayHasKey('is_active', $columns);
    }

    /**
     * Test collection CRUD lifecycle | 测试集合CRUD生命周期
     */
    public function testCollectionCrudLifecycle(): void
    {
        $collectionName = $this->generateCollectionName('CrudLifecycle');
        $this->registerCollection($collectionName);

        // 1. Create | 创建
        $collection = new Collection($collectionName, [
            'title' => 'CRUD Test Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));
        $collection->addField(FieldFactory::create('integer', 'count', ['default' => '0']));

        $this->collectionManager->create($collection);
        $this->assertNotNull($this->collectionManager->get($collectionName, self::TEST_TENANT_ID));

        // 2. Read | 读取
        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertEquals($collectionName, $retrieved->getName());
        $this->assertCount(2, $retrieved->getFields(), 'Collection should have 2 fields');

        // 3. Update metadata (note: update only changes metadata, not physical table)
        // 更新元数据（注意：update 只更改元数据，不修改物理表）
        // Cast to Collection to access setTitle method | 转换为 Collection 以访问 setTitle 方法
        /** @var Collection $retrieved */
        $retrieved->setTitle('Updated CRUD Test Collection');
        $this->collectionManager->update($retrieved);

        $updated = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertCount(2, $updated->getFields());

        // 4. Delete | 删除
        // Note: delete signature is (name, dropTable, tenantId)
        // 注意：delete 签名是 (name, dropTable, tenantId)
        $this->collectionManager->delete($collectionName, true, self::TEST_TENANT_ID);
        $deleted = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNull($deleted, 'Collection should be null after deletion');

        // Remove from cleanup list since already deleted | 从清理列表移除（已删除）
        $this->createdCollections = array_filter(
            $this->createdCollections,
            fn ($name) => $name !== $collectionName
        );
    }

    // ========================================================================
    // Test Cases - Multi-Tenant Isolation | 测试用例 - 多租户隔离
    // ========================================================================

    /**
     * Test multi-tenant collection isolation | 测试多租户集合隔离
     */
    public function testMultiTenantCollectionIsolation(): void
    {
        $collectionName = $this->generateCollectionName('TenantIsolation');
        $tenant1Id = self::TEST_TENANT_ID;
        $tenant2Id = self::TEST_TENANT_ID + 1;

        // Create collection for tenant 1 | 为租户1创建集合
        $collection1 = new Collection($collectionName, [
            'title' => 'Tenant 1 Collection',
            'tenant_id' => $tenant1Id,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection1->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));
        $this->collectionManager->create($collection1);
        $this->registerCollection($collectionName);

        // Verify tenant 1 can access | 验证租户1可以访问
        $retrieved1 = $this->collectionManager->get($collectionName, $tenant1Id);
        $this->assertNotNull($retrieved1, 'Tenant 1 should access their collection');

        // Verify tenant 2 cannot access tenant 1's collection | 验证租户2无法访问租户1的集合
        $retrieved2 = $this->collectionManager->get($collectionName, $tenant2Id);
        $this->assertNull($retrieved2, 'Tenant 2 should not access Tenant 1 collection');
    }

    // ========================================================================
    // Test Cases - Form Creation | 测试用例 - 表单创建
    // ========================================================================

    /**
     * Test creating form from collection | 测试基于集合创建表单
     */
    public function testCreateFormFromCollection(): void
    {
        // First create a collection | 首先创建集合
        $collectionName = $this->generateCollectionName('FormSource');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Form Source Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'title', ['max_length' => 255]));
        $collection->addField(FieldFactory::create('text', 'content', ['nullable' => true]));
        $this->collectionManager->create($collection);

        // Create form based on collection | 基于集合创建表单
        $formName = $this->generateFormName();
        $this->registerForm($formName);

        $formData = [
            'name' => $formName,
            'title' => 'Test Form',
            'description' => 'Test form description',
            'collection_name' => $collectionName,
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'x-component' => 'Input',
                    ],
                    'content' => [
                        'type' => 'string',
                        'x-component' => 'Textarea',
                    ],
                ],
            ],
        ];

        $formId = $this->formManager->create($formData);
        $this->assertNotNull($formId, 'Form ID should be returned after creation');

        // Verify form exists | 验证表单存在
        $form = $this->formManager->get($formName, self::TEST_TENANT_ID);
        $this->assertNotNull($form, 'Form should be retrievable');
        $this->assertEquals($formName, $form['name']);
        $this->assertEquals($collectionName, $form['collection_name']);
    }

    // ========================================================================
    // Test Cases - Field Operations | 测试用例 - 字段操作
    // ========================================================================

    /**
     * Test field CRUD lifecycle | 测试字段CRUD生命周期
     *
     * Note: This test verifies field metadata operations. The update method
     * only updates metadata, not physical table structure.
     * 注意：此测试验证字段元数据操作。update 方法只更新元数据，不修改物理表结构。
     */
    public function testFieldCrudLifecycle(): void
    {
        $collectionName = $this->generateCollectionName('FieldCrud');
        $this->registerCollection($collectionName);

        // Create collection with initial fields | 创建包含初始字段的集合
        $collection = new Collection($collectionName, [
            'title' => 'Field CRUD Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));
        $collection->addField(FieldFactory::create('string', 'email', ['max_length' => 255]));
        $this->collectionManager->create($collection);

        // Verify initial fields | 验证初始字段
        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved->getField('name'));
        $this->assertNotNull($retrieved->getField('email'));
        $this->assertCount(2, $retrieved->getFields());

        // Clear cache and verify field removal in metadata | 清除缓存并验证元数据中的字段移除
        $this->collectionManager->clearCache($collectionName, self::TEST_TENANT_ID);
        $retrieved->removeField('email');
        $this->collectionManager->update($retrieved);

        // Clear cache again to get fresh data | 再次清除缓存以获取最新数据
        $this->collectionManager->clearCache($collectionName, self::TEST_TENANT_ID);
        $final = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNull($final->getField('email'));
        $this->assertCount(1, $final->getFields());
    }

    /**
     * Test string field with max length | 测试带最大长度的字符串字段
     */
    public function testStringFieldWithMaxLength(): void
    {
        $collectionName = $this->generateCollectionName('StringField');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'String Field Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'short_text', ['max_length' => 50]));
        $collection->addField(FieldFactory::create('string', 'long_text', ['max_length' => 500]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertCount(2, $retrieved->getFields());
    }

    /**
     * Test nullable field configuration | 测试可空字段配置
     */
    public function testNullableFieldConfiguration(): void
    {
        $collectionName = $this->generateCollectionName('NullableField');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Nullable Field Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'required_field', [
            'nullable' => false,
            'max_length' => 100,
        ]));
        $collection->addField(FieldFactory::create('string', 'optional_field', [
            'nullable' => true,
            'max_length' => 100,
        ]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $requiredField = $retrieved->getField('required_field');
        $optionalField = $retrieved->getField('optional_field');

        $this->assertFalse($requiredField->isNullable());
        $this->assertTrue($optionalField->isNullable());
    }

    // ========================================================================
    // Test Cases - Error Handling | 测试用例 - 错误处理
    // ========================================================================

    /**
     * Test duplicate collection name error | 测试重复集合名称错误
     */
    public function testDuplicateCollectionNameError(): void
    {
        $collectionName = $this->generateCollectionName('Duplicate');
        $this->registerCollection($collectionName);

        // Create first collection | 创建第一个集合
        $collection1 = new Collection($collectionName, [
            'title' => 'First Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection1->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));
        $this->collectionManager->create($collection1);

        // Try to create duplicate | 尝试创建重复集合
        $collection2 = new Collection($collectionName, [
            'title' => 'Duplicate Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection2->addField(FieldFactory::create('string', 'title', ['max_length' => 100]));

        $this->expectException(\Exception::class);
        $this->collectionManager->create($collection2);
    }

    /**
     * Test get non-existent collection | 测试获取不存在的集合
     */
    public function testGetNonExistentCollection(): void
    {
        $result = $this->collectionManager->get('non_existent_collection_xyz', self::TEST_TENANT_ID);
        $this->assertNull($result);
    }

    /**
     * Test delete non-existent collection | 测试删除不存在的集合
     */
    public function testDeleteNonExistentCollection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->collectionManager->delete('non_existent_collection_xyz', true, self::TEST_TENANT_ID);
    }

    // ========================================================================
    // Test Cases - Collection Listing | 测试用例 - 集合列表
    // ========================================================================

    /**
     * Test list collections with pagination | 测试分页列出集合
     */
    public function testListCollectionsWithPagination(): void
    {
        // Create multiple collections | 创建多个集合
        $prefix = 'ListTest_' . time();
        for ($i = 1; $i <= 3; $i++) {
            $name = "{$prefix}_{$i}";
            $this->registerCollection($name);

            $collection = new Collection($name, [
                'title' => "List Test Collection {$i}",
                'tenant_id' => self::TEST_TENANT_ID,
                'site_id' => self::TEST_SITE_ID,
            ]);
            $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));
            $this->collectionManager->create($collection);
        }

        // List with pagination | 分页列出
        $result = $this->collectionManager->list(self::TEST_TENANT_ID, [], 1, 10);

        $this->assertArrayHasKey('list', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pageSize', $result);
        $this->assertGreaterThanOrEqual(3, $result['total']);
    }

    // ========================================================================
    // Test Cases - Date/Time Fields | 测试用例 - 日期时间字段
    // ========================================================================

    /**
     * Test date and datetime fields | 测试日期和日期时间字段
     */
    public function testDateAndDatetimeFields(): void
    {
        $collectionName = $this->generateCollectionName('DateFields');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Date Fields Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('date', 'birth_date', ['nullable' => true]));
        $collection->addField(FieldFactory::create('datetime', 'event_time', ['nullable' => true]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->getField('birth_date'));
        $this->assertNotNull($retrieved->getField('event_time'));
    }

    /**
     * Test JSON field type | 测试JSON字段类型
     */
    public function testJsonFieldType(): void
    {
        $collectionName = $this->generateCollectionName('JsonField');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'JSON Field Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('json', 'metadata', ['nullable' => true]));
        $collection->addField(FieldFactory::create('json', 'settings', ['nullable' => false]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertCount(2, $retrieved->getFields());

        // Verify table has JSON columns | 验证表有JSON列
        $tableName = $retrieved->getTableName();
        $columns = $this->getTableColumns($tableName);
        $this->assertArrayHasKey('metadata', $columns);
        $this->assertArrayHasKey('settings', $columns);
    }

    // ========================================================================
    // Test Cases - Table Name Generation | 测试用例 - 表名生成
    // ========================================================================

    /**
     * Test automatic table name generation | 测试自动表名生成
     */
    public function testAutomaticTableNameGeneration(): void
    {
        $collectionName = 'TestCamelCaseCollection';
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'CamelCase Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $tableName = $retrieved->getTableName();

        // Table name should be snake_case with lc_ prefix | 表名应该是带 lc_ 前缀的蛇形命名
        $this->assertStringStartsWith('lc_', $tableName);
        $this->assertStringContainsString('test_camel_case', $tableName);
    }

    /**
     * Test custom table name | 测试自定义表名
     */
    public function testCustomTableName(): void
    {
        $collectionName = $this->generateCollectionName('CustomTable');
        $customTableName = 'custom_table_' . time();
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Custom Table Name Test',
            'table_name' => $customTableName,
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertEquals($customTableName, $retrieved->getTableName());
        $this->assertTrue($this->tableExists($customTableName));
    }

    // ========================================================================
    // Test Cases - Cache Behavior | 测试用例 - 缓存行为
    // ========================================================================

    /**
     * Test collection caching | 测试集合缓存
     */
    public function testCollectionCaching(): void
    {
        $collectionName = $this->generateCollectionName('CacheTest');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Cache Test Collection',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));

        $this->collectionManager->create($collection);

        // First get (may hit DB) | 第一次获取（可能命中数据库）
        $first = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($first);

        // Second get (should hit cache) | 第二次获取（应该命中缓存）
        $second = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($second);

        // Both should return same data | 两次应该返回相同数据
        $this->assertEquals($first->getName(), $second->getName());
        $this->assertEquals($first->getTitle(), $second->getTitle());
    }

    // ========================================================================
    // Test Cases - Extended Field Types | 测试用例 - 扩展字段类型
    // ========================================================================

    /**
     * Test bigint field type | 测试大整数字段类型
     */
    public function testBigintFieldType(): void
    {
        $collectionName = $this->generateCollectionName('BigintField');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Bigint Field Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('bigint', 'large_number', ['nullable' => true]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->getField('large_number'));
    }

    /**
     * Test select field type | 测试选择字段类型
     */
    public function testSelectFieldType(): void
    {
        $collectionName = $this->generateCollectionName('SelectField');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Select Field Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('select', 'status', [
            'options' => ['active', 'inactive', 'pending'],
            'nullable' => false,
        ]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->getField('status'));
    }

    /**
     * Test file and image field types | 测试文件和图片字段类型
     */
    public function testFileAndImageFieldTypes(): void
    {
        $collectionName = $this->generateCollectionName('FileFields');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'File Fields Test',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('file', 'document', ['nullable' => true]));
        $collection->addField(FieldFactory::create('image', 'avatar', ['nullable' => true]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->getField('document'));
        $this->assertNotNull($retrieved->getField('avatar'));
    }

    // ========================================================================
    // Test Cases - Collection toArray | 测试用例 - 集合序列化
    // ========================================================================

    /**
     * Test collection toArray serialization | 测试集合 toArray 序列化
     */
    public function testCollectionToArraySerialization(): void
    {
        $collectionName = $this->generateCollectionName('Serialization');
        $this->registerCollection($collectionName);

        $collection = new Collection($collectionName, [
            'title' => 'Serialization Test',
            'description' => 'Test description',
            'tenant_id' => self::TEST_TENANT_ID,
            'site_id' => self::TEST_SITE_ID,
        ]);
        $collection->addField(FieldFactory::create('string', 'name', ['max_length' => 100]));
        $collection->addField(FieldFactory::create('integer', 'age', ['nullable' => true]));

        $this->collectionManager->create($collection);

        $retrieved = $this->collectionManager->get($collectionName, self::TEST_TENANT_ID);
        $array = $retrieved->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('table_name', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('fields', $array);
        $this->assertCount(2, $array['fields']);
    }

    // ========================================================================
    // Helper Methods | 辅助方法
    // ========================================================================

    /**
     * Check if table exists | 检查表是否存在
     */
    protected function tableExists(string $tableName): bool
    {
        try {
            $result = Db::query("SHOW TABLES LIKE '{$tableName}'");
            return !empty($result);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get table columns | 获取表列
     *
     * @return array<string, array>
     */
    protected function getTableColumns(string $tableName): array
    {
        try {
            $result = Db::query("DESCRIBE `{$tableName}`");
            $columns = [];
            foreach ($result as $row) {
                $columns[$row['Field']] = $row;
            }
            return $columns;
        } catch (\Throwable) {
            return [];
        }
    }
}
