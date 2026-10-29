<?php

declare(strict_types=1);

namespace Tests\Integration\Lowcode;

use Domain\Lowcode\Collection\Model\Relationship;
use Infrastructure\Lowcode\Collection\Field\FieldFactory;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use Infrastructure\Tenant\TenantAwareDb;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 字段/关系幂等与回滚安全集成测试
 *
 * 覆盖 T-048 验收标准：字段/关系操作幂等且回滚不破坏已有数据，多租户隔离。
 */
class FieldAndRelationshipIdempotencyTest extends ThinkPHPTestCase
{
    private array $collectionIds = [];

    protected function tearDown(): void
    {
        // 清理本用例创建的元数据，避免唯一索引污染其他用例
        foreach ($this->collectionIds as $collectionId) {
            Db::table('lowcode_fields')->where('collection_id', $collectionId)->delete();
            Db::table('lowcode_relationships')->where('collection_id', $collectionId)->delete();
            Db::table('lowcode_collections')->where('id', $collectionId)->delete();
        }
        parent::tearDown();
    }

    public function testFieldSaveIsIdempotentAndRollbackSafe(): void
    {
        $tenantId = 1201;
        $collectionId = $this->createCollectionForTenant($tenantId);

        $repo = new FieldRepository(new TenantAwareDb());

        // 首次保存字段
        $field = FieldFactory::create('string', 'title', [
            'title' => '初始标题',
            'nullable' => false,
            'length' => 120,
        ]);
        $firstId = $repo->save($field, $collectionId, $tenantId);

        // 重复保存（更新）同名字段，验证返回 ID 不变且只保留一条记录
        $updatedField = FieldFactory::create('string', 'title', [
            'title' => '更新标题',
            'nullable' => false,
            'length' => 200,
        ]);
        $secondId = $repo->save($updatedField, $collectionId, $tenantId);

        $this->assertSame($firstId, $secondId, '幂等更新应复用同一条字段记录');
        $this->assertSame(
            1,
            Db::table('lowcode_fields')
                ->where('collection_id', $collectionId)
                ->where('tenant_id', $tenantId)
                ->count()
        );
        $this->assertSame(
            '更新标题',
            Db::table('lowcode_fields')->where('id', $secondId)->value('title'),
            '字段更新后标题应被覆盖'
        );

        // 删除后再保存，验证回滚/重建不破坏数据一致性
        $this->assertTrue(
            $repo->delete($collectionId, 'title', $tenantId),
            '删除应返回 true'
        );
        $this->assertSame(
            0,
            Db::table('lowcode_fields')
                ->where('collection_id', $collectionId)
                ->where('tenant_id', $tenantId)
                ->count(),
            '删除后应无残留字段记录'
        );

        $repo->save($updatedField, $collectionId, $tenantId);
        $this->assertSame(
            1,
            Db::table('lowcode_fields')
                ->where('collection_id', $collectionId)
                ->where('tenant_id', $tenantId)
                ->count(),
            '重建字段后记录数应恢复为 1'
        );
    }

    public function testRelationshipSaveIsIdempotentAndRollbackSafe(): void
    {
        $tenantId = 1202;
        $collectionId = $this->createCollectionForTenant($tenantId);

        $repo = new RelationshipRepository(new TenantAwareDb());

        // 首次保存关系
        $relation = new Relationship($collectionId, 'orders', [
            'type' => 'hasMany',
            'target_collection' => 'orders',
            'foreign_key' => 'user_id',
            'local_key' => 'id',
        ]);
        $firstId = $repo->save($relation, $collectionId, $tenantId);

        // 重复保存同名关系，更新外键配置，验证记录复用与幂等
        $updatedRelation = new Relationship($collectionId, 'orders', [
            'type' => 'hasMany',
            'target_collection' => 'orders',
            'foreign_key' => 'owner_id',
            'local_key' => 'id',
        ]);
        $secondId = $repo->save($updatedRelation, $collectionId, $tenantId);

        $this->assertSame($firstId, $secondId, '幂等更新应复用同一条关系记录');
        $this->assertSame(
            1,
            Db::table('lowcode_relationships')
                ->where('collection_id', $collectionId)
                ->where('tenant_id', $tenantId)
                ->count()
        );
        $this->assertSame(
            'owner_id',
            Db::table('lowcode_relationships')->where('id', $secondId)->value('foreign_key'),
            '关系更新后外键应被覆盖'
        );

        // 删除后再保存，验证回滚/重建不破坏数据一致性
        $this->assertTrue(
            $repo->delete($collectionId, 'orders', $tenantId),
            '删除应返回 true'
        );
        $this->assertSame(
            0,
            Db::table('lowcode_relationships')
                ->where('collection_id', $collectionId)
                ->where('tenant_id', $tenantId)
                ->count(),
            '删除后应无残留关系记录'
        );

        $repo->save($updatedRelation, $collectionId, $tenantId);
        $this->assertSame(
            1,
            Db::table('lowcode_relationships')
                ->where('collection_id', $collectionId)
                ->where('tenant_id', $tenantId)
                ->count(),
            '重建关系后记录数应恢复为 1'
        );
    }

    /**
     * 创建仅用于测试的集合元数据，并记录待清理 ID。
     */
    private function createCollectionForTenant(int $tenantId): int
    {
        $collectionName = 'idem_' . uniqid();
        $tableName = 'lc_' . uniqid();
        $schema = json_encode(['name' => $collectionName], JSON_THROW_ON_ERROR);
        $schemaHash = hash('sha256', $schema);

        $collectionId = Db::table('lowcode_collections')->insertGetId([
            'name' => $collectionName,
            'table_name' => $tableName,
            'schema' => $schema,
            'tenant_id' => $tenantId,
            'site_id' => 0,
            'schema_version' => 1,
            'schema_hash' => $schemaHash,
        ]);

        $this->collectionIds[] = $collectionId;

        return $collectionId;
    }
}
