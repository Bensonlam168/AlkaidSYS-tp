<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use app\model\BaseModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * BaseModel Test | 基础模型测试
 *
 * Tests for BaseModel global scopes, CLI detection, and caching.
 * 测试 BaseModel 全局作用域、CLI 检测和缓存功能。
 *
 * @covers \app\model\BaseModel
 */
class BaseModelTest extends TestCase
{
    protected function setUp(): void
    {
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
