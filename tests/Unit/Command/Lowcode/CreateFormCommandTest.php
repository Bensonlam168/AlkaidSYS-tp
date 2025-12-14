<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Lowcode;

use app\command\lowcode\CreateFormCommand;
use Tests\ThinkPHPTestCase;

/**
 * CreateFormCommand Test | CreateFormCommand测试
 *
 * Tests for the lowcode:create-form command.
 * lowcode:create-form命令的测试。
 *
 * @package Tests\Unit\Command\Lowcode
 */
class CreateFormCommandTest extends ThinkPHPTestCase
{
    protected CreateFormCommand $command;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new CreateFormCommand();
    }

    /**
     * Test command configuration | 测试命令配置
     *
     * @return void
     */
    public function testCommandConfiguration(): void
    {
        $this->assertEquals('lowcode:create-form', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
    }

    /**
     * Test validate form name | 测试验证表单名称
     *
     * @return void
     */
    public function testIsValidFormName(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('isValidFormName');
        $method->setAccessible(true);

        // Valid names | 有效名称
        $this->assertTrue($method->invoke($this->command, 'product_form'));
        $this->assertTrue($method->invoke($this->command, 'ProductForm'));
        $this->assertTrue($method->invoke($this->command, '_form'));

        // Invalid names | 无效名称
        $this->assertFalse($method->invoke($this->command, '123form'));
        $this->assertFalse($method->invoke($this->command, 'form-name'));
        $this->assertFalse($method->invoke($this->command, 'form name'));
        $this->assertFalse($method->invoke($this->command, ''));
    }

    /**
     * Test map field type to schema type | 测试字段类型映射到Schema类型
     *
     * @return void
     */
    public function testMapFieldTypeToSchemaType(): void
    {
        $reflection = new \ReflectionClass($this->command);
        $method = $reflection->getMethod('mapFieldTypeToSchemaType');
        $method->setAccessible(true);

        $this->assertEquals('string', $method->invoke($this->command, 'string'));
        $this->assertEquals('string', $method->invoke($this->command, 'text'));
        $this->assertEquals('integer', $method->invoke($this->command, 'integer'));
        $this->assertEquals('integer', $method->invoke($this->command, 'bigint'));
        $this->assertEquals('number', $method->invoke($this->command, 'decimal'));
        $this->assertEquals('boolean', $method->invoke($this->command, 'boolean'));
        $this->assertEquals('string', $method->invoke($this->command, 'date'));
        $this->assertEquals('string', $method->invoke($this->command, 'datetime'));
        $this->assertEquals('object', $method->invoke($this->command, 'json'));
        $this->assertEquals('string', $method->invoke($this->command, 'select'));
        $this->assertEquals('array', $method->invoke($this->command, 'checkbox'));
        $this->assertEquals('string', $method->invoke($this->command, 'file'));
        $this->assertEquals('string', $method->invoke($this->command, 'image'));

        // Unknown type should default to string | 未知类型应默认为string
        $this->assertEquals('string', $method->invoke($this->command, 'unknown_type'));
    }
}
