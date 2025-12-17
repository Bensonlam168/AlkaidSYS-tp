<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Repository;

use Domain\Lowcode\Collection\Interfaces\RelationshipInterface;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 多租户仓储隔离测试
 */
class TenantAwareRepositoryTest extends ThinkPHPTestCase
{
    private int $tenantId;
    private int $collectionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = 2001;
        $name = 'test_mt_' . uniqid();

        // 准备一条租户隔离的 collection 元数据
        $this->collectionId = (int)Db::name('lowcode_collections')->insertGetId([
            'name' => $name,
            'table_name' => 'lc_' . $name,
            'schema' => json_encode(['title' => $name]),
            'tenant_id' => $this->tenantId,
            'site_id' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        // 清理测试数据，避免污染
        Db::name('lowcode_fields')->where('collection_id', $this->collectionId)->delete();
        Db::name('lowcode_relationships')->where('collection_id', $this->collectionId)->delete();
        Db::name('lowcode_collections')->where('id', $this->collectionId)->delete();

        parent::tearDown();
    }

    public function testFieldRepositoryRejectsCrossTenantAccess(): void
    {
        $repo = new FieldRepository();
        $field = FieldFactory::create('string', 'title');

        // 正确租户应成功写入
        $id = $repo->save($field, $this->collectionId, $this->tenantId);
        $this->assertGreaterThan(0, $id);

        // 错误租户触发隔离校验异常
        $this->expectException(\InvalidArgumentException::class);
        $repo->save($field, $this->collectionId, $this->tenantId + 1);
    }

    public function testRelationshipRepositoryRejectsCrossTenantAccess(): void
    {
        $repo = new RelationshipRepository();
        $relation = $this->createMock(RelationshipInterface::class);
        $relation->method('getName')->willReturn('owner');
        $relation->method('getType')->willReturn('belongsTo');
        $relation->method('getTargetCollection')->willReturn('user');
        $relation->method('getForeignKey')->willReturn('user_id');
        $relation->method('getLocalKey')->willReturn('id');
        $relation->method('getOptions')->willReturn([]);

        $id = $repo->save($relation, $this->collectionId, $this->tenantId);
        $this->assertGreaterThan(0, $id);

        $this->expectException(\InvalidArgumentException::class);
        $repo->save($relation, $this->collectionId, $this->tenantId + 1);
    }
}
