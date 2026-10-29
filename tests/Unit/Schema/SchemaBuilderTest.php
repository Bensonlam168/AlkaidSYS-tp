<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Tests\ThinkPHPTestCase;
use Infrastructure\Schema\SchemaBuilder;
use think\facade\Env;

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

    /**
     * Test idempotent createTable/addColumn/dropColumn | 测试幂等建表/加列/删列
     */
    public function testIdempotentOperations(): void
    {
        // createTable 幂等
        $this->schemaBuilder->createTable(
            $this->testTable,
            ['id' => ['type' => 'INT', 'primary' => true]]
        );
        $this->assertTrue($this->schemaBuilder->createTable(
            $this->testTable,
            ['id' => ['type' => 'INT', 'primary' => true]]
        ));

        // addColumn 幂等
        $this->assertTrue($this->schemaBuilder->addColumn(
            $this->testTable,
            'flag',
            ['type' => 'TINYINT', 'default' => 0]
        ));
        $this->assertTrue($this->schemaBuilder->addColumn(
            $this->testTable,
            'flag',
            ['type' => 'TINYINT', 'default' => 0]
        ));

        // dropColumn 幂等
        $this->assertTrue($this->schemaBuilder->dropColumn($this->testTable, 'flag'));
        $this->assertTrue($this->schemaBuilder->dropColumn($this->testTable, 'flag'));
    }

    /**
     * 覆盖 assertCanRunDDL 的禁止分支（生产环境）
     */
    public function testAssertCanRunDDLBlockedInProduction(): void
    {
        $envBak = Env::get('APP_ENV');
        $allowBak = Env::get('ALLOW_RUNTIME_DDL');

        Env::set('APP_ENV', 'production');
        Env::set('ALLOW_RUNTIME_DDL', false);

        $ref = new \ReflectionClass($this->schemaBuilder);
        $method = $ref->getMethod('assertCanRunDDL');
        $method->setAccessible(true);

        try {
            $method->invoke($this->schemaBuilder, 'create_table', 'blocked_table');
            $this->fail('生产环境下应阻止运行时 DDL');
        } catch (\think\exception\HttpResponseException $e) {
            $this->assertEquals(403, $e->getResponse()->getCode());
        } finally {
            // 还原环境变量，避免影响其他用例
            Env::set('APP_ENV', $envBak);
            Env::set('ALLOW_RUNTIME_DDL', $allowBak);
        }
    }

    /**
     * 构造包含 unsigned/on_update 的列定义，覆盖更多分支。
     */
    public function testBuildColumnDefinitionCoversUnsignedAndOnUpdate(): void
    {
        $ref = new \ReflectionClass($this->schemaBuilder);
        $method = $ref->getMethod('buildColumnDefinition');
        $method->setAccessible(true);

        $primary = null;
        $args = [
            'updated_at',
            [
                'type' => 'TIMESTAMP',
                'nullable' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'on_update' => 'CURRENT_TIMESTAMP',
                'unsigned' => true,
                'comment' => '更新时间',
            ],
            &$primary,
        ];
        $sql = $method->invokeArgs($this->schemaBuilder, $args);

        $this->assertStringContainsString('TIMESTAMP', $sql);
        $this->assertStringContainsString('UNSIGNED', $sql);
        $this->assertStringContainsString('ON UPDATE CURRENT_TIMESTAMP', $sql);
        $this->assertNull($primary);
    }
}
