<?php

declare(strict_types=1);

namespace Tests\Integration\Lowcode;

use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Tests\Support\CliProcess;
use Tests\Support\CliProcessResult;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * Lowcode CLI E2E Test | 低代码 CLI 端到端测试
 *
 * 通过实际执行 php think lowcode:* 命令，验证关键 CLI 流程可以在
 * 项目目录中正确运行，用于支撑 T-057/T-070/T-071 的测试矩阵扩展。
 *
 * 注意：子进程统一走 Tests\Support\CliProcess —— 它会用临时环境文件把子进程
 * 隔离到**测试库**。此前这里直接 exec()，子进程重新读 `.env` 连到开发库 alkaid_sys，
 * 实测把 369 张表的基线写成了 371 张（详见该助手类头部说明）。
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
     * 在「已隔离到测试库」的环境里执行一条 think 命令
     *
     * @param array<int, string> $args think 之后的参数（不含 php / think 本身）
     * @return CliProcessResult
     */
    private function runCli(array $args): CliProcessResult
    {
        return CliProcess::think($args);
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

        $result = $this->runCli([
            'lowcode:create-model',
            $name,
            '--fields=title:string',
            '--tenant-id=' . self::TEST_TENANT_ID,
            '--site-id=0',
            '--force',
        ]);

        $this->assertSame(
            0,
            $result->exitCode,
            "lowcode:create-model exit code should be 0. Output:\n" . $result->output
        );

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
        $createResult = $this->runCli([
            'lowcode:create-model',
            $name,
            '--fields=title:string',
            '--tenant-id=' . self::TEST_TENANT_ID,
            '--site-id=0',
            '--force',
        ]);
        $this->assertSame(
            0,
            $createResult->exitCode,
            "prepare collection failed. Output:\n" . $createResult->output
        );

        // 对单个 Collection 执行 migration:diff --check
        // 注意：diff 必须携带 tenant/site 维度，否则会默认 tenant_id=0 导致找不到集合
        $diffResult = $this->runCli([
            'lowcode:migration:diff',
            $name,
            '--check',
            '--tenant-id=' . self::TEST_TENANT_ID,
        ]);

        $this->assertSame(
            0,
            $diffResult->exitCode,
            "lowcode:migration:diff --check exit code should be 0. Output:\n" . $diffResult->output
        );
    }
}
