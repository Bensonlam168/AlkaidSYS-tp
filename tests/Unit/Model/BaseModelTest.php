<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use app\model\BaseModel;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Tests\ThinkPHPTestCase;

/**
 * BaseModel Test | 基础模型测试
 *
 * Tests for BaseModel global scopes, CLI detection, and caching.
 * 测试 BaseModel 全局作用域、CLI 检测和缓存功能。
 *
 * 为什么要继承 `Tests\ThinkPHPTestCase`（原先直接继承 `PHPUnit\Framework\TestCase`）：
 * `BaseModel::areScopesEnabled()` 第一步就走 `getEnvValue()` → `env()` → 容器里的 `env` 实例。
 * 本类当初不 boot App，于是「容器里没有任何 env 绑定」时能跑（`env()` 抛错被外层的
 * CLI 分支绕过），但**顺序一旦变成「别的测试类先跑」**，容器可能停在
 * 「`new App()` 但尚未 `initialize()`」的状态 —— 此时 `env` 仍解析不到，
 * 直接抛 `class not exists: env`（随机顺序下偶发失败）。
 *
 * 继承基类后由 `setUpBeforeClass()` 统一建 App、绑定隔离版 Env（DB_* 钉在测试库），
 * 本类不再依赖「进程里没人碰过容器」这个隐含前提。
 *
 * 代价（实测，已确认可接受）：
 *   - 单跑 9 个用例 0.42s → 0.45~0.63s（同为 10MB 内存），差异在噪声量级；
 *   - **不会**真的查库：`setUpBeforeClass()` 只建 App、写配置、绑定隔离版 Env，
 *     连接是惰性的，本类全程没有任何 SQL（MySQL `Connections` 计数实测 +0）；
 *   - 新增的实质约束是「库名必须含 `_test`」的 fail-closed 断言 —— 若运行环境导出了
 *     `DB_NAME=alkaid_sys`，本类会**直接报错退出**而不是静默跑（这是基类刻意的行为）。
 */
#[CoversClass(\app\model\BaseModel::class)]
class BaseModelTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        // 父类（ThinkPHPTestCase）没有定义 setUp()，这里解析到 PHPUnit\Framework\TestCase::setUp()，
        // 保留该调用是为了与仓库内其他子类保持一致，避免将来基类补 setUp() 时漏调。
        parent::setUp();
        // Reset state before each test
        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();
    }

    protected function tearDown(): void
    {
        // Cleanup after each test
        BaseModel::clearColumnCache();
        BaseModel::resetScopesEnabled();
        // 必须调用：基类的 tearDown() 负责丢弃方法级的 Request/Response 实例，
        // 少了它会让本类把请求对象残留给下一个用例（随机顺序下的串味来源之一）。
        parent::tearDown();
    }

    public function testAreScopesEnabledReturnsFalseInCliEnvironment(): void
    {
        // Reset to re-evaluate
        BaseModel::resetScopesEnabled();

        // In CLI environment (phpunit runs in CLI), scopes should be disabled by default
        $result = BaseModel::areScopesEnabled();

        // PHPUnit runs in CLI, so scopes should be disabled
        $this->assertFalse($result);
    }

    public function testSetScopesEnabledOverridesDefaultBehavior(): void
    {
        // Force enable scopes
        BaseModel::setScopesEnabled(true);
        $this->assertTrue(BaseModel::areScopesEnabled());

        // Force disable scopes
        BaseModel::setScopesEnabled(false);
        $this->assertFalse(BaseModel::areScopesEnabled());
    }

    public function testResetScopesEnabledClearsOverride(): void
    {
        // Set and then reset
        BaseModel::setScopesEnabled(true);
        BaseModel::resetScopesEnabled();

        $reflection = new ReflectionClass(BaseModel::class);
        $property = $reflection->getProperty('scopesEnabled');
        $property->setAccessible(true);

        $this->assertNull($property->getValue());
    }

    public function testClearColumnCacheClearsAllEntries(): void
    {
        $reflection = new ReflectionClass(BaseModel::class);
        $property = $reflection->getProperty('columnCache');
        $property->setAccessible(true);

        // Set some cache entries
        $property->setValue(null, [
            'TestModel:tenant_id' => true,
            'TestModel:site_id' => false,
            'OtherModel:tenant_id' => true,
        ]);

        // Clear all
        BaseModel::clearColumnCache();

        $this->assertEmpty($property->getValue());
    }

    public function testClearColumnCacheClearsSpecificModel(): void
    {
        $reflection = new ReflectionClass(BaseModel::class);
        $property = $reflection->getProperty('columnCache');
        $property->setAccessible(true);

        // Set some cache entries
        $property->setValue(null, [
            'TestModel:tenant_id' => true,
            'TestModel:site_id' => false,
            'OtherModel:tenant_id' => true,
        ]);

        // Clear only TestModel entries
        BaseModel::clearColumnCache('TestModel');

        $cache = $property->getValue();
        $this->assertArrayNotHasKey('TestModel:tenant_id', $cache);
        $this->assertArrayNotHasKey('TestModel:site_id', $cache);
        $this->assertArrayHasKey('OtherModel:tenant_id', $cache);
    }

    public function testGetTenantContextReturnsNullOrInt(): void
    {
        // In test environment, request may not have tenantId method
        $result = BaseModel::getTenantContext();

        // Should return null or a valid integer, not throw exception
        $this->assertTrue($result === null || is_int($result));
    }

    public function testGetSiteContextReturnsNullOrInt(): void
    {
        // In test environment, request may not have siteId method
        $result = BaseModel::getSiteContext();

        // Should return null or a valid integer, not throw exception
        $this->assertTrue($result === null || is_int($result));
    }

    public function testScopesAreNotAppliedWhenDisabled(): void
    {
        // Disable scopes
        BaseModel::setScopesEnabled(false);

        // Scopes should be disabled
        $this->assertFalse(BaseModel::areScopesEnabled());
    }

    public function testScopesCanBeEnabledExplicitly(): void
    {
        // Force enable scopes (even in CLI)
        BaseModel::setScopesEnabled(true);

        $this->assertTrue(BaseModel::areScopesEnabled());
    }
}
