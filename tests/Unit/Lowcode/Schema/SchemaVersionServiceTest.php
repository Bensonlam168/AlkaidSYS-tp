<?php

declare(strict_types=1);

namespace tests\Unit\Lowcode\Schema;

use Infrastructure\Lowcode\Schema\SchemaVersionService;
use PHPUnit\Framework\TestCase;

/**
 * SchemaVersionService unit test | Schema 版本服务单测
 */
class SchemaVersionServiceTest extends TestCase
{
    public function testComputeHashIsStableForAssociativeKeyOrder(): void
    {
        $service = new SchemaVersionService();

        $schemaA = [
            'name' => 'Product',
            'fields' => [
                ['name' => 'price', 'type' => 'decimal'],
                ['name' => 'name', 'type' => 'string'],
            ],
            'options' => [
                'title' => '商品',
                'description' => '商品数据模型',
            ],
        ];

        $schemaB = [
            'options' => [
                'description' => '商品数据模型',
                'title' => '商品',
            ],
            'fields' => [
                ['type' => 'decimal', 'name' => 'price'],
                ['type' => 'string', 'name' => 'name'],
            ],
            'name' => 'Product',
        ];

        $this->assertSame($service->computeHash($schemaA), $service->computeHash($schemaB));
    }

    public function testComputeHashPreservesListOrder(): void
    {
        $service = new SchemaVersionService();

        $schemaA = ['fields' => [['name' => 'a'], ['name' => 'b']]];
        $schemaB = ['fields' => [['name' => 'b'], ['name' => 'a']]];

        $this->assertNotSame($service->computeHash($schemaA), $service->computeHash($schemaB));
    }
}

