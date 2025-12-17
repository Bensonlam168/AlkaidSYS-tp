<?php

declare(strict_types=1);

namespace Tests\Integration\Lowcode;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * Lowcode CLI E2E Test | 低代码 CLI 端到端测试
 *
 * 通过实际执行 php think lowcode:* 命令，验证关键 CLI 流程可以在
 * 项目目录中正确运行，用于支撑 T-057/T-070/T-071 的测试矩阵扩展。
 *
 * @see T-057
 * @see T-070
 * @see T-071
 */
class LowcodeCliE2ETest extends ThinkPHPTestCase
{
    private const TEST_TENANT_ID = 99997;

    private CollectionManager $collectionManager;

    /**
     * @var array<string>
     */
    private array $createdCollections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectionManager = $this->app()->make(CollectionManager::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdCollections as $name) {
            try {
                // delete(name, dropTable, tenantId)
                $this->collectionManager->delete($name, true, self::TEST_TENANT_ID);
            } catch (\Throwable) {
                // Ignore cleanup errors in tests
            }
        }

        parent::tearDown();
    }

    /**
     * Execute a ThinkPHP CLI command within the project directory.
     *
     * @param string $args
     * @param int    $exitCode
     * @return string
     */
    private function runCli(string $args, int &$exitCode): string
    {
        $command = sprintf('%s think %s', escapeshellarg(PHP_BINARY), $args);

        $outputLines = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $outputLines, $exitCode);

        return implode("\n", $outputLines);
    }

    /**
     * E2E: create-model 命令可以成功创建 Collection 及元数据。
     *
     * @see T-057
     * @see T-070
     */
    public function testE2ECreateModelCommand(): void
    {
        $name = 'e2e_cli_' . time() . '_' . random_int(1000, 9999);
        $this->createdCollections[] = $name;

        $args = sprintf(
            'lowcode:create-model %s --fields=%s --tenant-id=%d --site-id=0 --force',
            escapeshellarg($name),
            escapeshellarg('title:string'),
            self::TEST_TENANT_ID
        );

        $exitCode = 0;
        $output = $this->runCli($args, $exitCode);

        $this->assertSame(0, $exitCode, "lowcode:create-model exit code should be 0. Output:\n" . $output);

        $row = Db::table('lowcode_collections')
            ->where('name', $name)
            ->where('tenant_id', self::TEST_TENANT_ID)
            ->find();

        $this->assertNotNull($row, 'Collection row should exist in lowcode_collections table after CLI execution');
    }

    /**
     * E2E: migration:diff --check 针对单个 Collection 时应无 schema diff。
     *
     * 为避免与全量 Schema 漂移检测 (T-072) 强耦合，这里只针对刚创建且
     * schema 一致的 Collection 做 check，验证命令行为与退出码。
     *
     * @see T-057
     * @see T-071
     */
    public function testCliMigrationDiffCheckForSingleCollection(): void
    {
        $name = 'e2e_cli_diff_' . time() . '_' . random_int(1000, 9999);
        $this->createdCollections[] = $name;

        // 先通过 CLI 创建一个 Collection，确保 DB 与 Schema 一致
        $createArgs = sprintf(
            'lowcode:create-model %s --fields=%s --tenant-id=%d --site-id=0 --force',
            escapeshellarg($name),
            escapeshellarg('title:string'),
            self::TEST_TENANT_ID
        );

        $createExitCode = 0;
        $createOutput = $this->runCli($createArgs, $createExitCode);
        $this->assertSame(0, $createExitCode, "prepare collection failed. Output:\n" . $createOutput);

        // 对单个 Collection 执行 migration:diff --check
        $diffArgs = sprintf('lowcode:migration:diff %s --check', escapeshellarg($name));

        $diffExitCode = 0;
        $diffOutput = $this->runCli($diffArgs, $diffExitCode);

        $this->assertSame(0, $diffExitCode, "lowcode:migration:diff --check exit code should be 0. Output:\n" . $diffOutput);
    }
}
