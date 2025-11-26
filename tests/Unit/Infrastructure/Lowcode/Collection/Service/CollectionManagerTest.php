<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\CollectionRepository;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Repository\RelationshipRepository;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Tests\ThinkPHPTestCase;
use think\facade\Cache;

class CollectionManagerTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function testGetUsesTenantScopedCacheKey(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $repo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $relRepo = $this->createMock(RelationshipRepository::class);

        $manager = new CollectionManager($schemaBuilder, $repo, $fieldRepo, $relRepo);

        $collection = $this->createMock(CollectionInterface::class);
        $collection->method('getId')->willReturn(1);
        $collection->method('getName')->willReturn('test');
        $fieldRepo->method('findByCollectionId')->willReturn([]);
        $relRepo->method('findByCollectionId')->willReturn([]);

        $repo->expects($this->once())
            ->method('findByName')
            ->with('test', 1)
            ->willReturn($collection);

        $result1 = $manager->get('test', 1);
        $this->assertInstanceOf(CollectionInterface::class, $result1);
        $this->assertSame('test', $result1->getName());

        // 
        $this->assertTrue(Cache::has('lowcode:collection:1:test'));

        $repo->expects($this->never())->method('findByName');
        $result2 = $manager->get('test', 1);
        $this->assertInstanceOf(CollectionInterface::class, $result2);
        $this->assertSame('test', $result2->getName());
    }

    public function testGetUsesDifferentCachePerTenant(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $repo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $relRepo = $this->createMock(RelationshipRepository::class);

        $manager = new CollectionManager($schemaBuilder, $repo, $fieldRepo, $relRepo);

        $tenant1Collection = $this->createMock(CollectionInterface::class);
        $tenant1Collection->method('getName')->willReturn('test_multi');

        $tenant2Collection = $this->createMock(CollectionInterface::class);
        $tenant2Collection->method('getName')->willReturn('test_multi');

        Cache::set('lowcode:collection:1:test_multi', $tenant1Collection, 3600);
        Cache::set('lowcode:collection:2:test_multi', $tenant2Collection, 3600);

        $repo->expects($this->never())->method('findByName');

        $resultTenant1 = $manager->get('test_multi', 1);
        $resultTenant2 = $manager->get('test_multi', 2);

        $this->assertSame('test_multi', $resultTenant1->getName());
        $this->assertSame('test_multi', $resultTenant2->getName());
        $this->assertNotSame($resultTenant1, $resultTenant2);
    }

    public function testListPassesTenantToRepository(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $repo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $relRepo = $this->createMock(RelationshipRepository::class);

        $manager = new CollectionManager($schemaBuilder, $repo, $fieldRepo, $relRepo);

        $filters = ['name' => 'foo'];
        $expected = ['list' => [], 'total' => 0, 'page' => 2, 'pageSize' => 15];

        $repo->expects($this->once())
            ->method('list')
            ->with($filters, 2, 15, 5)
            ->willReturn($expected);

        $result = $manager->list(5, $filters, 2, 15);

        $this->assertSame($expected, $result);
    }

    public function testClearCacheRemovesOnlySpecifiedTenantKey(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $repo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $relRepo = $this->createMock(RelationshipRepository::class);

        $manager = new CollectionManager($schemaBuilder, $repo, $fieldRepo, $relRepo);

        Cache::set('lowcode:collection:0:test_clear', 'system', 3600);
        Cache::set('lowcode:collection:1:test_clear', 'tenant1', 3600);

        $manager->clearCache('test_clear', 0);

        $this->assertFalse(Cache::has('lowcode:collection:0:test_clear'));
        $this->assertTrue(Cache::has('lowcode:collection:1:test_clear'));

        //  tenantId=null  0
        Cache::set('lowcode:collection:0:test_clear_default', 'system_default', 3600);
        $manager->clearCache('test_clear_default');
        $this->assertFalse(Cache::has('lowcode:collection:0:test_clear_default'));
    }

    public function testBuildCacheKeyFormat(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $repo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $relRepo = $this->createMock(RelationshipRepository::class);

        $manager = new CollectionManager($schemaBuilder, $repo, $fieldRepo, $relRepo);

        $refMethod = new \ReflectionMethod(CollectionManager::class, 'buildCacheKey');
        $refMethod->setAccessible(true);

        $key = $refMethod->invoke($manager, 'example', 42);

        $this->assertSame('lowcode:collection:42:example', $key);
    }

    public function testBuildColumnsAndIndexesIncludeTenantAndSite(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $repo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $relRepo = $this->createMock(RelationshipRepository::class);

        $manager = new CollectionManager($schemaBuilder, $repo, $fieldRepo, $relRepo);

        $refBuildColumns = new \ReflectionMethod(CollectionManager::class, 'buildColumns');
        $refBuildColumns->setAccessible(true);
        $columns = $refBuildColumns->invoke($manager, []);

        $this->assertArrayHasKey('tenant_id', $columns);
        $this->assertArrayHasKey('site_id', $columns);
        // Use assertEquals for loose comparison (string '0' vs int 0)
        $this->assertEquals(0, $columns['tenant_id']['default']);
        $this->assertEquals(0, $columns['site_id']['default']);

        $refBuildIndexes = new \ReflectionMethod(CollectionManager::class, 'buildIndexes');
        $refBuildIndexes->setAccessible(true);
        $indexes = $refBuildIndexes->invoke($manager);

        $this->assertArrayHasKey('idx_tenant_id_id', $indexes);
        $this->assertSame(['tenant_id', 'id'], $indexes['idx_tenant_id_id']['columns']);
    }
}
