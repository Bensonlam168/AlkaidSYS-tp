<?php

declare(strict_types=1);

namespace Tests\Integration\Model;

use Tests\ThinkPHPTestCase;
use app\model\BaseModel;
use think\facade\Db;

/**
 * BaseModel Scope Integration Test | BaseModel 全局作用域集成测试
 *
 * 通过真实数据表验证 BaseModel 在多租户/多站点场景下的作用域行为：
 * - CLI 默认禁用作用域；
 * - 显式启用作用域时，按 tenant_id/site_id 自动过滤；
 * - 显式禁用作用域时，不附加任何租户/站点条件。
 */
final class BaseModelScopeIntegrationTest extends ThinkPHPTestCase
{
    private string $table = 'bm_scope_test';

    protected function setUp(): void
    {
        parent::setUp();

        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();

        $this->createTestTable();
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        Db::execute("DROP TABLE IF EXISTS {$this->table}");
        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();

        // 重置测试模型中的上下文
        TestTenantScopedModel::$fakeTenantId = null;
        TestTenantScopedModel::$fakeSiteId   = null;

        parent::tearDown();
    }

    private function createTestTable(): void
    {
        Db::execute(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL DEFAULT 0,
                site_id INT NOT NULL DEFAULT 0,
                value VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function seedTestData(): void
    {
        Db::table($this->table)->insertAll([
            ['tenant_id' => 1, 'site_id' => 2, 'value' => 't1s2'],
            ['tenant_id' => 1, 'site_id' => 3, 'value' => 't1s3'],
            ['tenant_id' => 2, 'site_id' => 2, 'value' => 't2s2'],
        ]);
    }

    /**
     * 启用作用域 + 设置租户/站点上下文时，应只返回匹配行。
     */
    public function testTenantAndSiteScopesFilterByContext(): void
    {
        BaseModel::setScopesEnabled(true);

        TestTenantScopedModel::$fakeTenantId = 1;
        TestTenantScopedModel::$fakeSiteId   = 2;

        $results = TestTenantScopedModel::select()->toArray();

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['tenant_id']);
        $this->assertEquals(2, $results[0]['site_id']);
        $this->assertEquals('t1s2', $results[0]['value']);
    }

    /**
     * 显式禁用作用域时，不应附加租户/站点过滤。
     */
    public function testNoFiltersWhenScopesDisabled(): void
    {
        BaseModel::setScopesEnabled(false);

        TestTenantScopedModel::$fakeTenantId = 1;
        TestTenantScopedModel::$fakeSiteId   = 2;

        $results = TestTenantScopedModel::select()->toArray();

        // 所有 3 行都应可见
        $this->assertCount(3, $results);
    }

    /**
     * CLI 环境下默认禁用作用域（phpunit 运行场景）。
     */
    public function testScopesDisabledByDefaultInCli(): void
    {
        BaseModel::resetScopesEnabled();

        TestTenantScopedModel::$fakeTenantId = 1;
        TestTenantScopedModel::$fakeSiteId   = 2;

        $results = TestTenantScopedModel::select()->toArray();

        $this->assertFalse(BaseModel::areScopesEnabled());
        $this->assertCount(3, $results);
    }
}

/**
 * 简单的多租户测试模型，启用 tenant/site 全局作用域。
 */
final class TestTenantScopedModel extends BaseModel
{
    protected $name        = 'bm_scope_test';
    protected $table       = 'bm_scope_test';
    protected $globalScope = ['tenant', 'site'];

    public static ?int $fakeTenantId = null;
    public static ?int $fakeSiteId   = null;

    public static function getTenantContext(): ?int
    {
        if (static::$fakeTenantId !== null) {
            return static::$fakeTenantId;
        }

        return parent::getTenantContext();
    }

    public static function getSiteContext(): ?int
    {
        if (static::$fakeSiteId !== null) {
            return static::$fakeSiteId;
        }

        return parent::getSiteContext();
    }
}

