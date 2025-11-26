<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Generator;

use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Generator\ControllerGenerator;
use Tests\ThinkPHPTestCase;

/**
 * ControllerGenerator Test | ControllerGenerator测试
 *
 * Tests for the ControllerGenerator.
 * ControllerGenerator的测试。
 *
 * @package Tests\Unit\Infrastructure\Lowcode\Generator
 */
class ControllerGeneratorTest extends ThinkPHPTestCase
{
    protected ControllerGenerator $generator;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ControllerGenerator();
    }

    /**
     * Test get class name | 测试获取类名
     *
     * @return void
     */
    public function testGetClassName(): void
    {
        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('getClassName');
        $method->setAccessible(true);

        $this->assertEquals('ProductController', $method->invoke($this->generator, 'Product'));
        $this->assertEquals('ProductController', $method->invoke($this->generator, 'product'));
        $this->assertEquals('ProductItemController', $method->invoke($this->generator, 'product_item'));
        $this->assertEquals('MyProductController', $method->invoke($this->generator, 'my_product'));
    }

    /**
     * Test get fillable fields | 测试获取可填充字段
     *
     * @return void
     */
    public function testGetFillableFields(): void
    {
        $collection = new Collection('test_collection', [
            'title' => 'Test Collection',
        ]);

        $collection->addField(FieldFactory::create('string', 'name'));
        $collection->addField(FieldFactory::create('integer', 'age'));
        $collection->addField(FieldFactory::create('decimal', 'price'));

        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('getFillableFields');
        $method->setAccessible(true);

        $fillable = $method->invoke($this->generator, $collection);

        $this->assertStringContainsString("'name'", $fillable);
        $this->assertStringContainsString("'age'", $fillable);
        $this->assertStringContainsString("'price'", $fillable);
    }

    /**
     * Test generate code | 测试生成代码
     *
     * @return void
     */
    public function testGenerateCode(): void
    {
        $collection = new Collection('Product', [
            'title' => 'Product',
            'table_name' => 'lowcode_product',
        ]);

        $collection->addField(FieldFactory::create('string', 'name'));
        $collection->addField(FieldFactory::create('decimal', 'price'));

        $reflection = new \ReflectionClass($this->generator);
        $method = $reflection->getMethod('generateCode');
        $method->setAccessible(true);

        $code = $method->invoke(
            $this->generator,
            'ProductController',
            'app\\controller\\lowcode',
            'Product',
            'lowcode_product',
            $collection
        );

        $this->assertStringContainsString('class ProductController extends ApiController', $code);
        $this->assertStringContainsString('public function index(Request $request): Response', $code);
        $this->assertStringContainsString('public function read(Request $request, int $id): Response', $code);
        $this->assertStringContainsString('public function create(Request $request): Response', $code);
        $this->assertStringContainsString('public function update(Request $request, int $id): Response', $code);
        $this->assertStringContainsString('public function delete(Request $request, int $id): Response', $code);
        $this->assertStringContainsString('lowcode_product', $code);
    }

    /**
     * Clean up test | 清理测试
     *
     * @return void
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}

