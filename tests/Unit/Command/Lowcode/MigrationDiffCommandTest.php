<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Lowcode;

use app\command\lowcode\MigrationDiffCommand;
use Tests\ThinkPHPTestCase;

/**
 * MigrationDiffCommand Test | MigrationDiffCommand测试
 *
 * Tests for the lowcode:migration:diff command.
 * lowcode:migration:diff命令的测试。
 *
 * @package Tests\Unit\Command\Lowcode
 */
class MigrationDiffCommandTest extends ThinkPHPTestCase
{
    protected MigrationDiffCommand $command;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new MigrationDiffCommand();
    }

    public function testBuildSummaryCountsChanges(): void
    {
        $ref = new \ReflectionClass($this->command);
        $method = $ref->getMethod('buildSummary');
        $method->setAccessible(true);

        $diffs = [
            [
                'collection' => 'Product',
                'changes' => [
                    ['type' => 'add_column', 'table' => 'lc_product', 'message' => '新增列'],
                    ['type' => 'modify_index', 'table' => 'lc_product', 'message' => '索引调整'],
                ],
            ],
            [
                'collection' => 'Order',
                'changes' => [],
            ],
        ];

        $summary = $method->invoke($this->command, $diffs);

        $this->assertSame(1, $summary['drift_collections']);
        $this->assertSame(1, $summary['changes']['add_column']);
        $this->assertSame(1, $summary['changes']['modify_index']);
    }

    public function testRenderSqlForChangeAddsAlterStatement(): void
    {
        $ref = new \ReflectionClass($this->command);
        $method = $ref->getMethod('renderSqlForChange');
        $method->setAccessible(true);

        $sql = $method->invoke($this->command, [
            'type' => 'add_column',
            'table' => 'lc_demo',
            'column' => 'title',
            'field_type' => 'varchar(255)',
            'message' => '测试新增列',
        ]);

        $this->assertStringContainsString('ALTER TABLE `lc_demo` ADD COLUMN', $sql);
        $this->assertStringContainsString('`title`', $sql);
        $this->assertStringContainsString('-- 测试新增列', $sql);
    }

    /**
     * Test command configuration | 测试命令配置
     *
     * @return void
     */
    public function testCommandConfiguration(): void
    {
        $this->assertEquals('lowcode:migration:diff', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
    }
}
