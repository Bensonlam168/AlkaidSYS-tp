<?php

declare(strict_types=1);

namespace Tests\Unit\Field;

use PHPUnit\Framework\TestCase;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Domain\Lowcode\Collection\Interfaces\FieldInterface;

/**
 * Field Type System Unit Tests | 字段类型系统单元测试
 */
class FieldTypeSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Lowcode FieldFactory has default types defined statically, no setup required.
    }

    /**
     * Test FieldTypeRegistry has default types | 测试字段类型注册表包含默认类型
     */
    public function testRegistryHasDefaultTypes()
    {
        $types = FieldFactory::getTypes();

        $this->assertContains('string', $types);
        $this->assertContains('integer', $types);
        $this->assertContains('boolean', $types);
        $this->assertContains('date', $types);
        $this->assertGreaterThanOrEqual(4, count($types));
    }

    /**
     * Test field creation via Lowcode FieldFactory | 测试通过 Lowcode 字段工厂创建字段
     */
    public function testFieldCreationViaFactory()
    {
        $field = FieldFactory::create('string', 'username', [
            'nullable' => false,
            'default' => 'guest'
        ]);

        $this->assertInstanceOf(FieldInterface::class, $field);
        $this->assertEquals('username', $field->getName());
        $this->assertEquals('string', $field->getType());
    }

    /**
     * Test string field validation | 测试字符串字段验证
     */
    public function testStringFieldValidation()
    {
        $field = FieldFactory::create('string', 'name', ['nullable' => false]);

        $this->assertTrue($field->validate('test'));
        $this->assertFalse($field->validate(123));
        $this->assertFalse($field->validate(null)); // not nullable
    }

    /**
     * Test string field nullable validation | 测试可空字符串字段验证
     */
    public function testStringFieldNullableValidation()
    {
        $field = FieldFactory::create('string', 'name', ['nullable' => true]);

        $this->assertTrue($field->validate('test'));
        $this->assertTrue($field->validate(null)); // nullable
    }

    /**
     * Test integer field validation | 测试整数字段验证
     */
    public function testIntegerFieldValidation()
    {
        $field = FieldFactory::create('integer', 'age');

        $this->assertTrue($field->validate(25));
        $this->assertTrue($field->validate('25')); // depending on Lowcode implementation this may be strict; adapt if needed
        $this->assertFalse($field->validate('abc'));
        $this->assertFalse($field->validate(25.5));
    }

    /**
     * Test boolean field validation | 测试布尔字段验证
     */
    public function testBooleanFieldValidation()
    {
        $field = FieldFactory::create('boolean', 'is_active');

        // Accept strict booleans and typical TINYINT(1) representations
        $this->assertTrue($field->validate(true));
        $this->assertTrue($field->validate(false));
        $this->assertTrue($field->validate(1));
        $this->assertTrue($field->validate(0));
        $this->assertTrue($field->validate('1'));
        $this->assertTrue($field->validate('0'));

        // Reject other values
        $this->assertFalse($field->validate('true'));
        $this->assertFalse($field->validate('yes'));
    }

    /**
     * Test date field validation | 测试日期字段验证
     */
    public function testDateFieldValidation()
    {
        $field = FieldFactory::create('date', 'birth_date');

        $this->assertTrue($field->validate('2024-01-15'));
        $this->assertFalse($field->validate('2024-13-01')); //  invalid month
        $this->assertFalse($field->validate('invalid'));
        $this->assertFalse($field->validate(123));
    }

    /**
     * Test field toArray method | 测试字段toArray方法
     */
    public function testFieldToArray()
    {
        $field = FieldFactory::create('string', 'email', [
            'nullable' => false,
            'default' => 'test@example.com'
        ]);

        $array = $field->toArray();

        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('nullable', $array);
        $this->assertArrayHasKey('default', $array);

        $this->assertEquals('email', $array['name']);
        $this->assertEquals('string', $array['type']);
        $this->assertFalse($array['nullable']);
        $this->assertEquals('test@example.com', $array['default']);
    }

    /**
     * Test registry throws exception for unknown type | 测试注册表对未知类型抛出异常
     */
    public function testRegistryThrowsExceptionForUnknownType()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field type: unknown');

        FieldFactory::create('unknown', 'test');
    }
}
