<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Service;

use Domain\Lowcode\Collection\Interfaces\FieldInterface;
use Domain\Schema\Interfaces\SchemaBuilderInterface;
use Infrastructure\Lowcode\Collection\Repository\FieldRepository;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Infrastructure\Lowcode\Collection\Service\FieldManager;
use Tests\ThinkPHPTestCase;

class FieldManagerTest extends ThinkPHPTestCase
{
    public function testAddFieldPassesTenantIdToCollectionManagerAndClearsTenantScopedCache(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $field = $this->createMock(FieldInterface::class);
        $field->method('getName')->willReturn('foo');
        $field->method('getDbColumn')->willReturn(['type' => 'string']);

        $collection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getTableName', 'addField', 'getId'])
            ->getMock();
        $collection->method('getTableName')->willReturn('lc_test');
        $collection->method('getId')->willReturn(123);

        $tenantId = 5;

        $collectionManager->expects($this->once())
            ->method('get')
            ->with('test_collection', $tenantId)
            ->willReturn($collection);

        $schemaBuilder->expects($this->once())
            ->method('addColumn')
            ->with('lc_test', 'foo', ['type' => 'string']);

        $fieldRepo->expects($this->once())
            ->method('save')
            ->with($field, 123, $tenantId);

        $collectionManager->expects($this->once())
            ->method('clearCache')
            ->with('test_collection', $tenantId);

        $manager = new FieldManager($schemaBuilder, $fieldRepo, $collectionManager);
        $manager->addField('test_collection', $field, $tenantId);
    }

    public function testRemoveFieldUsesTenantScopedCacheAndCollectionLookup(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $collection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getTableName', 'removeField', 'getId'])
            ->getMock();
        $collection->method('getTableName')->willReturn('lc_test_remove');
        $collection->method('getId')->willReturn(456);

        $tenantId = 7;

        $collectionManager->expects($this->once())
            ->method('get')
            ->with('test_collection', $tenantId)
            ->willReturn($collection);

        $schemaBuilder->expects($this->once())
            ->method('dropColumn')
            ->with('lc_test_remove', 'bar');

        $fieldRepo->expects($this->once())
            ->method('delete')
            ->with(456, 'bar', $tenantId);

        $collectionManager->expects($this->once())
            ->method('clearCache')
            ->with('test_collection', $tenantId);

        $manager = new FieldManager($schemaBuilder, $fieldRepo, $collectionManager);
        $manager->removeField('test_collection', 'bar', $tenantId);
    }

    public function testUpdateFieldUpdatesMetadataAndClearsTenantCache(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $field = $this->createMock(FieldInterface::class);
        $field->method('getName')->willReturn('email');

        $collection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getField', 'getId'])
            ->getMock();
        $collection->method('getId')->willReturn(999);
        $collection->method('getField')->with('email')->willReturn($field);

        $tenantId = 8;

        $collectionManager->expects($this->once())
            ->method('get')
            ->with('test_collection', $tenantId)
            ->willReturn($collection);

        $fieldRepo->expects($this->once())
            ->method('save')
            ->with($field, 999, $tenantId)
            ->willReturn(1);

        $collectionManager->expects($this->once())
            ->method('clearCache')
            ->with('test_collection', $tenantId);

        $manager = new FieldManager($schemaBuilder, $fieldRepo, $collectionManager);
        $manager->updateField('test_collection', 'email', ['title' => 'Email'], $tenantId);
    }

    public function testUpdateFieldThrowsWhenCollectionNotFound(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $tenantId = 8;
        $collectionManager->expects($this->once())
            ->method('get')
            ->with('missing_collection', $tenantId)
            ->willReturn(null);

        $manager = new FieldManager($schemaBuilder, $fieldRepo, $collectionManager);

        $this->expectException(\InvalidArgumentException::class);
        $manager->updateField('missing_collection', 'email', [], $tenantId);
    }

    public function testUpdateFieldThrowsWhenFieldNotFound(): void
    {
        $schemaBuilder = $this->createMock(SchemaBuilderInterface::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $collectionManager = $this->createMock(CollectionManager::class);

        $collection = $this->getMockBuilder('Domain\\Lowcode\\Collection\\Model\\Collection')
            ->disableOriginalConstructor()
            ->onlyMethods(['getField'])
            ->getMock();
        $collection->method('getField')->with('missing')->willReturn(null);

        $tenantId = 8;
        $collectionManager->expects($this->once())
            ->method('get')
            ->with('test_collection', $tenantId)
            ->willReturn($collection);

        $manager = new FieldManager($schemaBuilder, $fieldRepo, $collectionManager);

        $this->expectException(\InvalidArgumentException::class);
        $manager->updateField('test_collection', 'missing', [], $tenantId);
    }
}
