<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Enum\RelationType;
use Domain\Lowcode\Collection\Interfaces\RelationshipInterface;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\RelationshipManager;
use Tests\ThinkPHPTestCase;

class RelationshipManagerTest extends ThinkPHPTestCase
{
    public function testAddRelationshipUsesTenantScopedCollectionsAndCache(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $relationshipRepo = $this->createMock(RelationshipRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $sourceCollection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getTableName', 'addRelationship'])
            ->getMock();
        $sourceCollection->method('getId')->willReturn(10);
        $sourceCollection->method('getTableName')->willReturn('lc_source');

        $targetCollection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getTableName'])
            ->getMock();
        $targetCollection->method('getTableName')->willReturn('lc_target');

        $tenantId = 3;

        $seenArgs = [];

        $collectionManager->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $name, ?int $tenant) use (
                &$seenArgs,
                $tenantId,
                $sourceCollection,
                $targetCollection
            ) {
                $seenArgs[] = [$name, $tenant];

                if ($name === 'posts') {
                    $this->assertSame($tenantId, $tenant);
                    return $sourceCollection;
                }

                if ($name === 'users') {
                    $this->assertSame($tenantId, $tenant);
                    return $targetCollection;
                }

                $this->fail("Unexpected CollectionManager::get call with name={$name}, tenant=" . var_export($tenant, true));
            });

        $relationship = $this->createMock(RelationshipInterface::class);
        $relationship->method('getName')->willReturn('author');
        $relationship->method('getType')->willReturn(RelationType::BELONGS_TO);
        $relationship->method('getTargetCollection')->willReturn('users');
        $relationship->method('getForeignKey')->willReturn('user_id');
        $relationship->method('getLocalKey')->willReturn('id');
        $relationship->method('getOptions')->willReturn([]);

        $relationshipRepo->expects($this->once())
            ->method('save')
            ->with($relationship, 10, $tenantId)
            ->willReturn(100);

        $collectionManager->expects($this->once())
            ->method('clearCache')
            ->with('posts', $tenantId);

        $manager = new RelationshipManager($schemaBuilder, $relationshipRepo, $collectionManager);
        $manager->addRelationship('posts', $relationship, $tenantId);

        $this->assertCount(2, $seenArgs);
    }

    public function testRemoveRelationshipUsesTenantScopedCollectionAndCache(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $relationshipRepo = $this->createMock(RelationshipRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $relationship = $this->createMock(RelationshipInterface::class);
        $relationship->method('getName')->willReturn('author');
        $relationship->method('getType')->willReturn(RelationType::BELONGS_TO);

        $collection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getRelationship'])
            ->getMock();
        $collection->method('getId')->willReturn(20);
        $collection->method('getRelationship')->with('author')->willReturn($relationship);

        $tenantId = 4;

        $collectionManager->expects($this->once())
            ->method('get')
            ->with('posts', $tenantId)
            ->willReturn($collection);

        $relationshipRepo->expects($this->once())
            ->method('delete')
            ->with(20, 'author', $tenantId)
            ->willReturn(true);

        $collectionManager->expects($this->once())
            ->method('clearCache')
            ->with('posts', $tenantId);

        $manager = new RelationshipManager($schemaBuilder, $relationshipRepo, $collectionManager);
        $manager->removeRelationship('posts', 'author', true, $tenantId);
    }

    public function testAddBelongsToManyRelationshipCreatesPivotTableWithIndexes(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $relationshipRepo = $this->createMock(RelationshipRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $sourceCollection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getTableName', 'addRelationship'])
            ->getMock();
        $sourceCollection->method('getId')->willReturn(10);
        $sourceCollection->method('getTableName')->willReturn('lc_posts');

        $targetCollection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getTableName'])
            ->getMock();
        $targetCollection->method('getTableName')->willReturn('lc_tags');

        $tenantId = 5;

        $collectionManager->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $name, ?int $tenant) use ($tenantId, $sourceCollection, $targetCollection) {
                if ($name === 'posts') {
                    $this->assertSame($tenantId, $tenant);
                    return $sourceCollection;
                }

                if ($name === 'tags') {
                    $this->assertSame($tenantId, $tenant);
                    return $targetCollection;
                }

                $this->fail('Unexpected CollectionManager::get call');
            });

        $relationship = $this->createMock(RelationshipInterface::class);
        $relationship->method('getName')->willReturn('tags');
        $relationship->method('getType')->willReturn(RelationType::BELONGS_TO_MANY);
        $relationship->method('getTargetCollection')->willReturn('tags');
        $relationship->method('getForeignKey')->willReturn('tag_id');
        $relationship->method('getLocalKey')->willReturn('post_id');
        $relationship->method('getOptions')->willReturn([]);

        $sourceCollection->expects($this->once())
            ->method('addRelationship')
            ->with('tags', $relationship);

        $relationshipRepo->expects($this->once())
            ->method('save')
            ->with($relationship, 10, $tenantId)
            ->willReturn(200);

        $collectionManager->expects($this->once())
            ->method('clearCache')
            ->with('posts', $tenantId);

        $expectedPivotTable = 'lc_pivot_posts_tags';

        $schemaBuilder->expects($this->once())
            ->method('hasTable')
            ->with($expectedPivotTable)
            ->willReturn(false);

        $schemaBuilder->expects($this->once())
            ->method('createTable')
            ->willReturnCallback(function (string $tableName, array $columns, array $indexes) use ($expectedPivotTable) {
                $this->assertSame($expectedPivotTable, $tableName);

                $this->assertArrayHasKey('post_id', $columns);
                $this->assertArrayHasKey('tag_id', $columns);

                $this->assertArrayHasKey('tenant_id', $columns);
                $this->assertArrayHasKey('site_id', $columns);
                $this->assertArrayHasKey('idx_tenant_site', $indexes);
                $this->assertArrayHasKey('idx_pivot_local', $indexes);
                $this->assertArrayHasKey('idx_pivot_foreign', $indexes);
                $this->assertArrayHasKey('idx_pivot_local_foreign', $indexes);
                $this->assertArrayHasKey('uk_pivot_local_foreign_tenant', $indexes);

                $this->assertSame(['tenant_id', 'site_id'], $indexes['idx_tenant_site']['columns']);
                $this->assertSame(['post_id'], $indexes['idx_pivot_local']['columns']);
                $this->assertSame(['tag_id'], $indexes['idx_pivot_foreign']['columns']);
                $this->assertSame(['tenant_id', 'site_id', 'post_id', 'tag_id'], $indexes['idx_pivot_local_foreign']['columns']);
                $this->assertSame(['tenant_id', 'site_id', 'post_id', 'tag_id'], $indexes['uk_pivot_local_foreign_tenant']['columns']);

                return true;
            });

        $manager = new RelationshipManager($schemaBuilder, $relationshipRepo, $collectionManager);
        $manager->addRelationship('posts', $relationship, $tenantId);
    }
}
