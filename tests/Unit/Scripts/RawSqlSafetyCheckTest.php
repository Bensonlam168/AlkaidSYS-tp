<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

// Load the Raw SQL safety checker script functions | 加载 Raw SQL 安全检查脚本函数
require_once __DIR__ . '/../../../scripts/check-rawsql-safety.php';

/**
 * Raw SQL Safety Check Script Test | 原生 SQL 安全检查脚本测试
 *
 * Ensures that scanRawSqlUsage() can locate Db::table()/Db::name()/Db::query()
 * style raw SQL calls under a given directory.
 *
 * 确保 scanRawSqlUsage() 能够在指定目录下定位 Db::table()/Db::name()/Db::query()
 * 等原生 SQL 调用，为 CI 静态扫描提供基础保障。
 */
class RawSqlSafetyCheckTest extends TestCase
{
    public function testScannerFindsRawSqlInFixture(): void
    {
        $projectRoot = realpath(__DIR__ . '/../../..');
        $this->assertNotFalse($projectRoot, 'Project root path should be resolvable');

        $dirsToScan = ['tests/Fixtures/RawSql'];
        $patterns = ['Db::table('];

        $results = scanRawSqlUsage($projectRoot, $dirsToScan, $patterns);

        $this->assertNotEmpty($results, 'Scanner should find at least one raw SQL usage in fixture');

        $files = array_unique(array_column($results, 'file'));
        $this->assertContains('tests/Fixtures/RawSql/RawSqlSample.php', $files);
    }
}

