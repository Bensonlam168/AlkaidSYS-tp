<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Lowcode\Collection\Model;

use Domain\Lowcode\Collection\Model\Relationship;
use PHPUnit\Framework\TestCase;

/**
 * Relationship 模型构造与校验测试
 */
class RelationshipTest extends TestCase
{
    public function testDefaultForeignKeyAndToArray(): void
    {
        $rel = new Relationship(10, 'profile', [
            'type' => 'hasOne',
            'target_collection' => 'user_profiles',
            'local_key' => 'id',
            'options' => ['foo' => 'bar'],
            'id' => 99,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-02 00:00:00',
        ]);

        $this->assertSame(99, $rel->getId());
        $this->assertSame(10, $rel->getCollectionId());
        $this->assertSame('hasOne', $rel->getType());
        $this->assertSame('user_profiles', $rel->getTargetCollection());
        $this->assertSame('id', $rel->getLocalKey());
        $this->assertSame(['foo' => 'bar'], $rel->getOptions());
        $this->assertSame('2025-01-01 00:00:00', $rel->getCreatedAt());
        $this->assertSame('2025-01-02 00:00:00', $rel->getUpdatedAt());

        $rel->setOptions(['baz' => 1]);
        $this->assertSame(['baz' => 1], $rel->getOptions());

        $rel->setId(100);
        $this->assertSame(100, $rel->getId());

        $arr = $rel->toArray();

        $this->assertSame('profile', $arr['name']);
        $this->assertSame('user_profiles', $arr['target_collection']);
        // 未指定外键时自动生成 snake_case + _id
        $this->assertSame('user_profiles_id', $arr['foreign_key']);
    }

    public function testMissingTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Relationship(1, 'bad', [
            'target_collection' => 'users',
        ]);
    }

    public function testInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Relationship(1, 'bad', [
            'type' => 'invalid_type',
            'target_collection' => 'users',
        ]);
    }

    public function testMissingTargetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Relationship(1, 'bad', [
            'type' => 'hasMany',
        ]);
    }
}
