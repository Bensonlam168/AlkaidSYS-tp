<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Tests\ThinkPHPTestCase;
use Infrastructure\Schema\SchemaBuilder;

/**
 * SchemaBuilder Unit Tests | SchemaBuilder单元测试
 */
class SchemaBuilderTest extends ThinkPHPTestCase
{
    protected SchemaBuilder $schemaBuilder;
    protected string $testTable = 'test_schema_unit_table';

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaBuilder = new SchemaBuilder();
        
        // Clean up test table if exists | 清理测试表（如果存在）
        if ($this->schemaBuilder->hasTable($this->testTable)) {
            $this->schemaBuilder->dropTable($this->testTable);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test table | 清理测试表
        if ($this->schemaBuilder->hasTable($this->testTable)) {
            $this->schemaBuilder->dropTable($this->testTable);
        }
        
        parent::tearDown();
    }

    /**
     * Test table creation | 测试表创建
     */
    public function testCreateTable()
    {
        $result = $this->schemaBuilder->createTable(
            $this->testTable,
            [
                'id' => ['type' => 'INT', 'primary' => true, 'auto_increment' => true],
                'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            ],
            [],
            'InnoDB',
            'Test table'
        );

        $this->assertTrue($result);
        $this->assertTrue($this->schemaBuilder->hasTable($this->testTable));
    }

    /**
     * Test table existence check | 测试表存在性检查
     */
    public function testHasTable()
    {
        $this->assertFalse($this->schemaBuilder->hasTable($this->testTable));

        $this->schemaBuilder->createTable(
            $this->testTable,
            ['id' => ['type' => 'INT', 'primary' => true]]
        );

        $this->assertTrue($this->schemaBuilder->hasTable($this->testTable));
    }

    /**
     * Test add column | 测试添加列
     */
    public function testAddColumn()
    {
        // Create base table | 创建基础表
        $this->schemaBuilder->createTable(
            $this->testTable,
            ['id' => ['type' => 'INT', 'primary' => true]]
        );

        // Add column | 添加列
        $result = $this->schemaBuilder->addColumn(
            $this->testTable,
            'email',
            ['type' => 'VARCHAR', 'length' => 255, 'nullable' => true]
        );

        $this->assertTrue($result);
        $this->assertTrue($this->schemaBuilder->hasColumn($this->testTable, 'email'));
    }

    /**
     * Test drop column | 测试删除列
     */
    public function testDropColumn()
    {
        // Create table with column | 创建带列的表
        $this->schemaBuilder->createTable(
            $this->testTable,
            [
                'id' => ['type' => 'INT', 'primary' => true],
                'name' => ['type' => 'VARCHAR', 'length' => 255],
            ]
        );

        $this->assertTrue($this->schemaBuilder->hasColumn($this->testTable, 'name'));

        // Drop column | 删除列
        $result = $this->schemaBuilder->dropColumn($this->testTable, 'name');

        $this->assertTrue($result);
        $this->assertFalse($this->schemaBuilder->hasColumn($this->testTable, 'name'));
    }

    /**
     * Test drop table | 测试删除表
     */
    public function testDropTable()
    {
        $this->schemaBuilder->createTable(
            $this->testTable,
            ['id' => ['type' => 'INT', 'primary' => true]]
        );

        $this->assertTrue($this->schemaBuilder->hasTable($this->testTable));

        $result = $this->schemaBuilder->dropTable($this->testTable);

        $this->assertTrue($result);
        $this->assertFalse($this->schemaBuilder->hasTable($this->testTable));
    }

    /**
     * Test column existence check | 测试列存在性检查
     */
    public function testHasColumn()
    {
        $this->schemaBuilder->createTable(
            $this->testTable,
            [
                'id' => ['type' => 'INT', 'primary' => true],
                'name' => ['type' => 'VARCHAR', 'length' => 255],
            ]
        );

        $this->assertTrue($this->schemaBuilder->hasColumn($this->testTable, 'id'));
        $this->assertTrue($this->schemaBuilder->hasColumn($this->testTable, 'name'));
        $this->assertFalse($this->schemaBuilder->hasColumn($this->testTable, 'email'));
    }
}
