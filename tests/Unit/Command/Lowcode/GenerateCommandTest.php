<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Lowcode;

use app\command\lowcode\GenerateCommand;
use Tests\ThinkPHPTestCase;

/**
 * GenerateCommand Test | GenerateCommand测试
 *
 * Tests for the lowcode:generate command.
 * lowcode:generate命令的测试。
 *
 * @package Tests\Unit\Command\Lowcode
 */
class GenerateCommandTest extends ThinkPHPTestCase
{
    protected GenerateCommand $command;

    /**
     * Set up test | 设置测试
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new GenerateCommand();
    }

    /**
     * Test command configuration | 测试命令配置
     *
     * @return void
     */
    public function testCommandConfiguration(): void
    {
        $this->assertEquals('lowcode:generate', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
    }
}

