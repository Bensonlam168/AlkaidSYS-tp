<?php

declare(strict_types=1);

namespace Tests\Fixtures\RawSql;

use think\facade\Db;

/**
 * Sample class containing raw SQL usage for scanner tests.
 *
 * 用于 Raw SQL 扫描器单元测试的示例类，包含 Db::table 调用。
 */
final class RawSqlSample
{
    public function demo(): void
    {
        // This call is intentionally left as raw SQL usage for testing.
        Db::table('dummy_table')->where('tenant_id', 1)->select();
    }
}

