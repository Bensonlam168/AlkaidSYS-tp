<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Lowcode\Collection\Model;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Domain\Lowcode\Collection\Model\Collection;
use Domain\Lowcode\Collection\Model\Relationship;
use PHPUnit\Framework\TestCase;

/**
 * Collection 模型行为测试（含 fromArray/toArray、多租户属性、字段/关系容器）
 */
class CollectionTest extends TestCase
{
    public function testConstructToArrayAndFromArrayCoversOptionsDecode(): void
    {
        $collection = new Collection('UserProfile', [
            'id' => 1,
            'title' => '用户资料',
            'description' => 'desc',
            'tenant_id' => 10,
            'site_id' => 2,
            // 不传 table_name 时走默认生成 lc_user_profile
        ]);

        $this->assertSame(1, $collection->getId());
        $this->assertSame('UserProfile', $collection->getName());
        $this->assertSame('lc_user_profile', $collection->getTableName());
        $this->assertSame('用户资料', $collection->getTitle());
        $this->assertSame('desc', $collection->getDescription());
        $this->assertSame(10, $collection->getTenantId());
        $this->assertSame(2, $collection->getSiteId());

        $collection->setId(2)
            ->setTitle('标题2')
            ->setDescription('desc2')
            ->setTableName('lc_custom')
            ->setOptions(['a' => 1]);

        $field = new class ('email') implements FieldInterface {
            public function __construct(private string $name)
            {
            }
            public function getName(): string
            {
                return $this->name;
            }
            public function getType(): string
            {
                return 'string';
            }
            public function getDbType(): string
            {
                return 'VARCHAR(255)';
            }
            public function getDbColumn(): array
            {
                return ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true];
            }
            public function getTitle(): string
            {
                return 'Email';
            }
            public function isNullable(): bool
            {
                return true;
            }
            public function getDefault()
            {
                return null;
            }
            public function getOptions(): array
            {
                return [];
            }
            public function validate($value): bool
            {
                return is_string($value);
            }
            public function toArray(): array
            {
                return ['name' => $this->name, 'type' => 'string'];
            }
        };

        $relationship = new Relationship(2, 'profile', [
            'type' => 'hasOne',
            'target_collection' => 'profiles',
        ]);

        $collection->addField($field);
        $collection->addRelationship('profile', $relationship);

        $this->assertSame($field, $collection->getField('email'));
        $this->assertNull($collection->getField('missing'));
        $collection->removeField('email');
        $this->assertNull($collection->getField('email'));

        $this->assertSame($relationship, $collection->getRelationship('profile'));
        $this->assertNull($collection->getRelationship('missing'));

        $arr = $collection->toArray();
        $this->assertSame(2, $arr['id']);
        $this->assertSame('lc_custom', $arr['table_name']);
        $this->assertSame('标题2', $arr['title']);
        $this->assertSame(['a' => 1], $arr['options']);
        $this->assertIsArray($arr['fields']);
        $this->assertIsArray($arr['relationships']);

        // fromArray：options 支持字符串 JSON（覆盖 json_decode 分支）
        $collection->fromArray([
            'options' => json_encode(['x' => 1], JSON_THROW_ON_ERROR),
            'tenant_id' => 99,
            'site_id' => 0,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-02 00:00:00',
        ]);

        $this->assertSame(['x' => 1], $collection->getOptions());
        $this->assertSame(99, $collection->getTenantId());
        $this->assertSame(0, $collection->getSiteId());
        $this->assertSame('2025-01-01 00:00:00', $collection->getCreatedAt());
        $this->assertSame('2025-01-02 00:00:00', $collection->getUpdatedAt());
    }
}
