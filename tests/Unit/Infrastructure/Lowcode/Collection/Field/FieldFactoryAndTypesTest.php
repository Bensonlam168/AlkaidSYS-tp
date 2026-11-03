<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Field;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ThinkPHPTestCase;

/**
 * FieldFactory 及内置字段类型行为测试
 *
 * 覆盖所有注册类型的实例化、validate 与 DB 映射，确保高级字段不依赖 Legacy。
 */
class FieldFactoryAndTypesTest extends ThinkPHPTestCase
{
    #[DataProvider('fieldTypeProvider')]
    public function testCreateAndValidateBuiltInTypes(string $type, array $options, mixed $validValue, mixed $invalidValue): void
    {
        $field = FieldFactory::create($type, 'f_' . $type, $options);

        // 基本校验：DB 定义应存在 type 字段
        $dbColumn = $field->getDbColumn();
        $this->assertArrayHasKey('type', $dbColumn, "{$type} 应提供物理列类型定义");

        // validate 正例/反例覆盖
        $this->assertTrue($field->validate($validValue), "{$type} 应接受有效值");
        if ($invalidValue !== '__skip__') {
            $this->assertFalse($field->validate($invalidValue), "{$type} 应拒绝无效值");
        }
    }

    public function testCreateUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FieldFactory::create('unknown_type', 'foo');
    }

    public function testRegisterRejectsNonFieldInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FieldFactory::register('bad', self::class);
    }

    public function testRegisterCustomFieldType(): void
    {
        FieldFactory::register('custom_stub', CustomStubField::class);

        $this->assertTrue(FieldFactory::hasType('custom_stub'));
        $this->assertContains('custom_stub', FieldFactory::getTypes());

        $field = FieldFactory::create('custom_stub', 'stub_name', ['title' => 'stub']);
        $this->assertInstanceOf(CustomStubField::class, $field);
        $this->assertTrue($field->validate('ok'));
    }

    public function testCheckboxAcceptsJsonStringAndRejectsInvalidJson(): void
    {
        $field = FieldFactory::create('checkbox', 'flags', [
            'enum' => ['x', 'y'],
        ]);

        $this->assertTrue($field->validate('["x"]'), 'checkbox 应支持 JSON 字符串输入');
        $this->assertFalse($field->validate('["x"'), 'checkbox 应拒绝非法 JSON 字符串');
    }

    public function testTimestampDbColumnOutputsDefaultAndOnUpdate(): void
    {
        $field = FieldFactory::create('timestamp', 'updated_at', [
            'default_current' => true,
            'on_update_current' => true,
        ]);

        $column = $field->getDbColumn();
        $this->assertSame('TIMESTAMP', $column['type']);
        $this->assertSame('CURRENT_TIMESTAMP', $column['default']);
        $this->assertSame('CURRENT_TIMESTAMP', $column['on_update']);
    }

    public function testImageValidateCoversExistingFileBranch(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img_');
        $fakeImage = $tmp . '.jpg';
        @rename($tmp, $fakeImage);
        file_put_contents($fakeImage, 'not a real image');

        try {
            $field = FieldFactory::create('image', 'avatar', []);
            $this->assertFalse($field->validate($fakeImage), '非真实图片文件应校验失败');
        } finally {
            @unlink($fakeImage);
        }
    }

    public static function fieldTypeProvider(): array
    {
        return [
            // 基础类型
            ['string', [], 'foo', ['bar' => 'baz']],
            ['text', [], 'foo', 123],
            ['integer', [], 10, 'bad'],
            ['decimal', ['precision' => 10, 'scale' => 2], 10.5, 'bad'],
            ['boolean', [], true, 'not-bool'],
            ['date', [], '2024-01-01', 'invalid'],
            ['datetime', [], '2024-01-01 10:00:00', 'invalid'],
            ['json', [], ['k' => 'v'], 'not-json'],
            // 扩展/高级类型
            ['bigint', [], 100000, 'bad'],
            ['timestamp', [], '2024-01-01 10:00:00', 'invalid'],
            ['file', [], 'file_id', ['bad']],
            ['image', [], 'avatar.jpg', 'avatar.txt'],
            ['select', ['enum' => ['a', 'b']], 'a', 'z'],
            ['radio', ['enum' => ['a', 'b']], 'b', 'z'],
            ['checkbox', ['enum' => ['x', 'y']], ['x', 'y'], ['x', 'z']],
            ['enum', ['enum' => ['a', 'b']], 'a', 'c'],
            ['cascader', [], ['1', '2'], ['x', ['y']]],
            ['tree-select', [], ['1', '2'], ['x', ['y']]],
        ];
    }
}

/**
 * 自定义字段桩类，用于验证 register 行为。
 */
class CustomStubField implements FieldInterface
{
    public function __construct(private string $name, private array $options = [])
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return 'custom_stub';
    }

    public function getTitle(): string
    {
        return $this->options['title'] ?? 'custom';
    }

    public function getDbType(): string
    {
        return 'VARCHAR(255)';
    }

    public function getDbColumn(): array
    {
        return [
            'type' => 'VARCHAR',
            'length' => 255,
            'nullable' => true,
        ];
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function isNullable(): bool
    {
        return true;
    }

    public function validate($value): bool
    {
        return is_string($value);
    }

    public function getDefault(): mixed
    {
        return null;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'db_type' => $this->getDbType(),
            'options' => $this->options,
        ];
    }
}
