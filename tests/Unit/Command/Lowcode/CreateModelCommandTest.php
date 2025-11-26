<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Lowcode;

use app\command\lowcode\CreateModelCommand;
use Tests\ThinkPHPTestCase;

/**
 * CreateModelCommand Test | CreateModelCommand测试
 *
 * Tests for the lowcode:create-model command.
 * lowcode:create-model命令的测试。
 *
 * @package Tests\Unit\Command\Lowcode
 */
class CreateModelCommandTest extends ThinkPHPTestCase
{
    protected CreateModelCommand $command;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new CreateModelCommand();
    }

    /**
     * Test command configuration | 测试命令配置
     *
     * @return void
     */
    public function testCommandConfiguration(): void
    {
        $this->assertEquals('lowcode:create-model', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
    }

    /**
     * Test parse fields method | 测试解析字段方法
     *
     * @return void
     */
    public function testParseFields(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('parseFields');
        $method->setAccessible(true);

        $fieldsStr = 'name:string,age:integer,price:decimal';
        $fields = $method->invoke($this->command, $fieldsStr);

        $this->assertCount(3, $fields);
        $this->assertEquals('name', $fields[0]['name']);
        $this->assertEquals('string', $fields[0]['type']);
        $this->assertEquals('age', $fields[1]['name']);
        $this->assertEquals('integer', $fields[1]['type']);
        $this->assertEquals('price', $fields[2]['name']);
        $this->assertEquals('decimal', $fields[2]['type']);
    }

    /**
     * Test validate field type | 测试验证字段类型
     *
     * @return void
     */
    public function testIsValidFieldType(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('isValidFieldType');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->command, 'string'));
        $this->assertTrue($method->invoke($this->command, 'integer'));
        $this->assertTrue($method->invoke($this->command, 'decimal'));
        $this->assertTrue($method->invoke($this->command, 'boolean'));
        $this->assertTrue($method->invoke($this->command, 'date'));
        $this->assertTrue($method->invoke($this->command, 'datetime'));
        $this->assertTrue($method->invoke($this->command, 'json'));
        $this->assertTrue($method->invoke($this->command, 'select'));

        $this->assertFalse($method->invoke($this->command, 'invalid_type'));
        $this->assertFalse($method->invoke($this->command, ''));
    }

    /**
     * Test validate collection name | 测试验证Collection名称
     *
     * @return void
     */
    public function testIsValidCollectionName(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('isValidCollectionName');
        $method->setAccessible(true);

        // Valid names | 有效名称
        $this->assertTrue($method->invoke($this->command, 'Product'));
        $this->assertTrue($method->invoke($this->command, 'product'));
        $this->assertTrue($method->invoke($this->command, 'product_item'));
        $this->assertTrue($method->invoke($this->command, '_product'));

        // Invalid names | 无效名称
        $this->assertFalse($method->invoke($this->command, '123product'));
        $this->assertFalse($method->invoke($this->command, 'product-item'));
        $this->assertFalse($method->invoke($this->command, 'product item'));
        $this->assertFalse($method->invoke($this->command, ''));
    }

    /**
     * Test get default field options | 测试获取默认字段选项
     *
     * @return void
     */
    public function testGetDefaultFieldOptions(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('getDefaultFieldOptions');
        $method->setAccessible(true);

        $stringOptions = $method->invoke($this->command, 'string');
        $this->assertArrayHasKey('nullable', $stringOptions);
        $this->assertArrayHasKey('max_length', $stringOptions);
        $this->assertEquals(255, $stringOptions['max_length']);

        $integerOptions = $method->invoke($this->command, 'integer');
        $this->assertArrayHasKey('nullable', $integerOptions);

        $decimalOptions = $method->invoke($this->command, 'decimal');
        $this->assertArrayHasKey('precision', $decimalOptions);
        $this->assertArrayHasKey('scale', $decimalOptions);
        $this->assertEquals(10, $decimalOptions['precision']);
        $this->assertEquals(2, $decimalOptions['scale']);
    }
}

