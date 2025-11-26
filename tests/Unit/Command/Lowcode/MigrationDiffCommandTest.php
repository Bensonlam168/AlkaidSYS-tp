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

