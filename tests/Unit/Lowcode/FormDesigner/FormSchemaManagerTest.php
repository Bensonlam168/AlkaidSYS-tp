<?php

declare(strict_types=1);

namespace Tests\Unit\Lowcode\FormDesigner;

use Tests\ThinkPHPTestCase;
use Infrastructure\Lowcode\FormDesigner\Service\FormSchemaManager;
use Infrastructure\Lowcode\FormDesigner\Repository\FormSchemaRepository;
use think\facade\Cache;
use think\facade\Db;

/**
 * Form Schema Manager Test | FormSchemaManager测试
 *
 * Tests FormSchemaManager service.
 * 测试FormSchemaManager服务。
 *
 * @package Tests\Unit\Lowcode\FormDesigner
 */
class FormSchemaManagerTest extends ThinkPHPTestCase
{
    protected FormSchemaManager $manager;
    protected FormSchemaRepository $repository;
    protected int $testTenantId = 1;
    protected int $testSiteId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear database | 清空数据库
        Db::name('lowcode_forms')->where('tenant_id', $this->testTenantId)->delete();

        // Clear cache | 清空缓存
        Cache::clear();

        // Initialize components | 初始化组件
        $this->repository = new FormSchemaRepository();
        $this->manager = new FormSchemaManager($this->repository, null);
    }

    /**
     * Test create form schema | 测试创建表单Schema
     */
    public function testCreateFormSchema(): void
    {
        $formData = $this->getSampleFormData();

        $result = $this->manager->create($formData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('product_form', $result['name']);
        $this->assertEquals('商品表单', $result['title']);
        $this->assertEquals($this->testTenantId, $result['tenant_id']);
        $this->assertIsArray($result['schema']);
    }

    /**
     * Test get form schema by name | 测试按名称获取表单Schema
     */
    public function testGetFormSchemaByName(): void
    {
        // Create form | 创建表单
        $formData = $this->getSampleFormData();
        $created = $this->manager->create($formData);

        // Get by name | 按名称获取
        $result = $this->manager->get('product_form', $this->testTenantId, $this->testSiteId);

        $this->assertNotNull($result);
        $this->assertEquals($created['id'], $result['id']);
        $this->assertEquals('product_form', $result['name']);
    }

    /**
     * Test caching | 测试缓存
     */
    public function testCaching(): void
    {
        // Create form | 创建表单
        $formData = $this->getSampleFormData();
        $this->manager->create($formData);

        // First get (from DB) | 第一次获取（从数据库）
        $result1 = $this->manager->get('product_form', $this->testTenantId, $this->testSiteId);

        // Second get (should be from cache) | 第二次获取（应该从缓存）
        $result2 = $this->manager->get('product_form', $this->testTenantId, $this->testSiteId);

        $this->assertEquals($result1, $result2);

        // Verify cache key exists | 验证缓存key存在
        $cacheKey = 'lowcode:form:t1:s1:product_form';
        $this->assertNotFalse(Cache::get($cacheKey));
    }

    /**
     * Test update form schema | 测试更新表单Schema
     */
    public function testUpdateFormSchema(): void
    {
        // Create form | 创建表单
        $formData = $this->getSampleFormData();
        $created = $this->manager->create($formData);

        // Update | 更新
        $updateData = [
            'title' => '商品表单（更新）',
            'description' => '更新后的描述',
        ];

        $result = $this->manager->update($created['id'], $updateData, $this->testTenantId);

        $this->assertEquals('商品表单（更新）', $result['title']);
        $this->assertEquals('更新后的描述', $result['description']);
    }

    /**
     * Test delete form schema | 测试删除表单Schema
     */
    public function testDeleteFormSchema(): void
    {
        // Create form | 创建表单
        $formData = $this->getSampleFormData();
        $created = $this->manager->create($formData);

        // Delete | 删除
        $this->manager->delete($created['id'], $this->testTenantId);

        // Verify deleted | 验证已删除
        $result = $this->manager->getById($created['id'], $this->testTenantId);
        $this->assertNull($result);
    }

    /**
     * Test list forms | 测试列出表单
     */
    public function testListForms(): void
    {
        // Create multiple forms | 创建多个表单
        for ($i = 1; $i <= 3; $i++) {
            $formData = $this->getSampleFormData();
            $formData['name'] = "form_{$i}";
            $formData['title'] = "表单{$i}";
            $this->manager->create($formData);
        }

        // List | 列出
        $result = $this->manager->list($this->testTenantId);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('list', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['list']);
    }

    /**
     * Test duplicate form | 测试复制表单
     */
    public function testDuplicateForm(): void
    {
        // Create source form | 创建源表单
        $formData = $this->getSampleFormData();
        $created = $this->manager->create($formData);

        // Duplicate | 复制
        $duplicated = $this->manager->duplicate($created['id'], 'product_form_copy', $this->testTenantId);

        $this->assertNotEquals($created['id'], $duplicated['id']);
        $this->assertEquals('product_form_copy', $duplicated['name']);
        $this->assertEquals('商品表单 (副本)', $duplicated['title']);
        $this->assertEquals($created['schema'], $duplicated['schema']);
    }

    /**
     * Test schema validation | 测试Schema验证
     */
    public function testSchemaValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Schema type must be object');

        $formData = $this->getSampleFormData();
        $formData['schema']['type'] = 'string'; // Invalid type

        $this->manager->create($formData);
    }

    /**
     * Test required fields validation | 测试必需字段验证
     */
    public function testRequiredFieldsValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name is required');

        $formData = $this->getSampleFormData();
        unset($formData['name']);

        $this->manager->create($formData);
    }

    /**
     * Test tenant isolation | 测试租户隔离
     */
    public function testTenantIsolation(): void
    {
        // Create form for tenant 1 | 为租户1创建表单
        $formData1 = $this->getSampleFormData();
        $formData1['tenant_id'] = 1;
        $formData1['name'] = 'tenant1_form';
        $this->manager->create($formData1);

        // Create form for tenant 2 | 为租户2创建表单
        $formData2 = $this->getSampleFormData();
        $formData2['tenant_id'] = 2;
        $formData2['name'] = 'tenant2_form';
        $this->manager->create($formData2);

        // Verify tenant 1 can't see tenant 2's form | 验证租户1看不到租户2的表单
        $result = $this->manager->get('tenant2_form', 1);
        $this->assertNull($result);

        // Cleanup | 清理
        Db::name('lowcode_forms')->where('tenant_id', 2)->delete();
    }

    /**
     * Get sample form data | 获取示例表单数据
     *
     * @return array
     */
    protected function getSampleFormData(): array
    {
        return [
            'tenant_id' => $this->testTenantId,
            'site_id' => $this->testSiteId,
            'name' => 'product_form',
            'title' => '商品表单',
            'description' => '商品信息录入表单',
            'schema' => [
                'type' => 'object',
                'title' => '商品表单',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'title' => '商品名称',
                        'x-component' => 'Input',
                        'x-decorator' => 'FormItem',
                        'x-decorator-props' => [
                            'label' => '商品名称',
                            'required' => true,
                        ],
                    ],
                    'price' => [
                        'type' => 'number',
                        'title' => '商品价格',
                        'x-component' => 'InputNumber',
                        'x-decorator' => 'FormItem',
                        'x-decorator-props' => [
                            'label' => '商品价格',
                            'required' => true,
                        ],
                    ],
                ],
                'required' => ['name', 'price'],
            ],
            'collection_name' => null,
            'layout' => 'horizontal',
            'status' => 1,
        ];
    }

    protected function tearDown(): void
    {
        // Cleanup | 清理
        Db::name('lowcode_forms')->where('tenant_id', $this->testTenantId)->delete();
        Cache::clear();

        parent::tearDown();
    }
}
