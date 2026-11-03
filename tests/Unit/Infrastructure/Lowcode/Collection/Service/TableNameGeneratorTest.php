<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lowcode\Collection\Service;

use Infrastructure\Lowcode\Collection\Service\TableNameGenerator;
use Tests\ThinkPHPTestCase;
use think\facade\Config;

/**
 * TableNameGenerator 表名策略测试
 */
class TableNameGeneratorTest extends ThinkPHPTestCase
{
    public function testSharedStrategyReturnsLcPrefix(): void
    {
        Config::set([
            'lowcode' => [
                'table_naming_strategy' => 'shared',
            ],
        ]);
        $gen = new TableNameGenerator();

        $this->assertSame('lc_user_profile', $gen->generate('UserProfile', 5, 1));
    }

    public function testTenantPrefixedStrategyUsesTenantId(): void
    {
        Config::set([
            'lowcode' => [
                'table_naming_strategy' => 'tenant_prefixed',
            ],
        ]);
        $gen = new TableNameGenerator();

        $this->assertSame('lc_t9_order', $gen->generate('Order', 9, 2));
        // 当 tenantId=0 时应回退共享表
        $this->assertSame('lc_order', $gen->generate('Order', 0, 2));
    }
}
