<?php

declare(strict_types=1);

namespace Tests\Unit\Lowcode\Collection;

use Infrastructure\Lowcode\Collection\Service\TableNameGenerator;
use Tests\ThinkPHPTestCase;
use think\facade\Config;

/**
 * 表名生成策略单测
 */
class TableNameGeneratorTest extends ThinkPHPTestCase
{
    private mixed $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfig = Config::get('lowcode');
    }

    protected function tearDown(): void
    {
        if ($this->originalConfig !== null) {
            Config::set(['lowcode' => $this->originalConfig]);
        }
        parent::tearDown();
    }

    public function testSharedStrategyGeneratesLcPrefix(): void
    {
        Config::set(['lowcode' => ['table_naming_strategy' => 'shared']]);
        $generator = new TableNameGenerator();

        $this->assertSame('lc_product', $generator->generate('Product', 0));
        $this->assertSame('lc_user_profile', $generator->generate('UserProfile', 0));
    }

    public function testTenantPrefixedStrategyAddsTenantId(): void
    {
        Config::set(['lowcode' => ['table_naming_strategy' => 'tenant_prefixed']]);
        $generator = new TableNameGenerator();

        $this->assertSame('lc_t5_order', $generator->generate('Order', 5));
        // 租户ID为0时仍走共享表策略，避免旧表名破坏
        $this->assertSame('lc_order', $generator->generate('Order', 0));
    }
}
