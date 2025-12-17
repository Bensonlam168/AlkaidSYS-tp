<?php

declare(strict_types=1);

namespace tests\Unit\Lowcode\Field;

use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use PHPUnit\Framework\TestCase;

/**
 * Advanced field types test | 高级字段类型测试
 */
class AdvancedFieldTypesTest extends TestCase
{
    public function testCreateCascaderField(): void
    {
        $field = FieldFactory::create('cascader', 'region', [
            'nullable' => false,
            'title' => '地区',
        ]);

        $this->assertSame('cascader', $field->getType());
        $this->assertSame('region', $field->getName());
        $this->assertSame('JSON', $field->getDbType());
        $this->assertTrue($field->validate(['beijing', 'haidian']));
        $this->assertFalse($field->validate(['beijing', new \stdClass()]));
    }

    public function testCreateTreeSelectField(): void
    {
        $field = FieldFactory::create('tree-select', 'category', [
            'nullable' => true,
            'title' => '分类',
        ]);

        $this->assertSame('tree-select', $field->getType());
        $this->assertSame('category', $field->getName());
        $this->assertSame('JSON', $field->getDbType());
        $this->assertTrue($field->validate('c1'));
        $this->assertTrue($field->validate(['c1', 'c2']));
        $this->assertFalse($field->validate(['c1', ['nested']]));
    }
}

