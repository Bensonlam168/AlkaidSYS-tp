<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Tests\Unit\Multitenant;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;

/**
 * TenantContext Unit Tests | 租户上下文单元测试
 */
class TenantContextTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::clearAll();
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();
    }

    #[Test]
    public function testSetGetTenantId(): void
    {
        $this->assertNull(TenantContext::getTenantId());

        TenantContext::setTenantId(100);
        $this->assertEquals(100, TenantContext::getTenantId());
    }

    #[Test]
    public function testHasTenantId(): void
    {
        $this->assertFalse(TenantContext::hasTenantId());

        TenantContext::setTenantId(100);
        $this->assertTrue(TenantContext::hasTenantId());
    }

    #[Test]
    public function testGetTenantIdRequired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID is required');

        TenantContext::getTenantId(required: true);
    }

    #[Test]
    public function testSetGetUserId(): void
    {
        $this->assertNull(TenantContext::getUserId());

        TenantContext::setUserId(200);
        $this->assertEquals(200, TenantContext::getUserId());
    }

    #[Test]
    public function testSetGetCustomValue(): void
    {
        $this->assertNull(TenantContext::get('custom_key'));

        TenantContext::set('custom_key', 'custom_value');
        $this->assertEquals('custom_value', TenantContext::get('custom_key'));
    }

    #[Test]
    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', TenantContext::get('missing', 'default'));
    }

    #[Test]
    public function testInitialize(): void
    {
        TenantContext::initialize(100, 200, ['extra' => 'data']);

        $this->assertEquals(100, TenantContext::getTenantId());
        $this->assertEquals(200, TenantContext::getUserId());
        $this->assertEquals('data', TenantContext::get('extra'));
        $this->assertNotNull(TenantContext::get('initialized_at'));
    }

    #[Test]
    public function testClear(): void
    {
        TenantContext::setTenantId(100);
        TenantContext::setUserId(200);
        TenantContext::set('custom', 'value');

        TenantContext::clear();

        $this->assertNull(TenantContext::getTenantId());
        $this->assertNull(TenantContext::getUserId());
        $this->assertNull(TenantContext::get('custom'));
    }

    #[Test]
    public function testClearAll(): void
    {
        TenantContext::setTenantId(100);

        TenantContext::clearAll();

        $this->assertNull(TenantContext::getTenantId());
    }

    #[Test]
    public function testRunWithTenant(): void
    {
        TenantContext::setTenantId(100);

        $result = TenantContext::runWithTenant(200, function () {
            return TenantContext::getTenantId();
        });

        $this->assertEquals(200, $result);
        $this->assertEquals(100, TenantContext::getTenantId()); // Restored
    }

    #[Test]
    public function testRunWithTenantRestoresOnException(): void
    {
        TenantContext::setTenantId(100);

        try {
            TenantContext::runWithTenant(200, function () {
                throw new \Exception('Test exception');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals(100, TenantContext::getTenantId());
    }

    #[Test]
    public function testAll(): void
    {
        TenantContext::initialize(100, 200, ['foo' => 'bar']);

        $all = TenantContext::all();

        $this->assertEquals(100, $all['tenant_id']);
        $this->assertEquals(200, $all['user_id']);
        $this->assertEquals('bar', $all['foo']);
    }

    #[Test]
    public function testTenantConfig(): void
    {
        TenantContext::setTenantConfig(100, [
            'max_workflows' => 50,
            'features' => ['approval', 'automation'],
        ]);

        TenantContext::setTenantId(100);

        $config = TenantContext::getTenantConfig();
        $this->assertEquals(50, $config['max_workflows']);

        $features = TenantContext::getTenantConfigValue('features');
        $this->assertEquals(['approval', 'automation'], $features);
    }

    #[Test]
    public function testGetTenantConfigValueWithDefault(): void
    {
        TenantContext::setTenantId(100);

        $value = TenantContext::getTenantConfigValue('missing', 'default');
        $this->assertEquals('default', $value);
    }

    #[Test]
    public function testIsolationEnforced(): void
    {
        $this->assertTrue(TenantContext::isIsolationEnforced());

        TenantContext::setIsolationEnforced(false);
        $this->assertFalse(TenantContext::isIsolationEnforced());

        TenantContext::setIsolationEnforced(true);
        $this->assertTrue(TenantContext::isIsolationEnforced());
    }

    #[Test]
    public function testGetDebugInfo(): void
    {
        TenantContext::setTenantId(100);
        TenantContext::setUserId(200);

        $debugInfo = TenantContext::getDebugInfo();

        $this->assertEquals(100, $debugInfo['tenant_id']);
        $this->assertEquals(200, $debugInfo['user_id']);
        $this->assertArrayHasKey('coroutine_id', $debugInfo);
        $this->assertArrayHasKey('context_count', $debugInfo);
        $this->assertArrayHasKey('isolation_enforced', $debugInfo);
    }

    #[Test]
    public function testMultipleTenantSwitches(): void
    {
        TenantContext::setTenantId(100);
        $this->assertEquals(100, TenantContext::getTenantId());

        TenantContext::setTenantId(200);
        $this->assertEquals(200, TenantContext::getTenantId());

        TenantContext::setTenantId(300);
        $this->assertEquals(300, TenantContext::getTenantId());
    }

    #[Test]
    public function testInitializeOverwrites(): void
    {
        TenantContext::initialize(100, 200, ['key1' => 'value1']);
        TenantContext::initialize(300, 400, ['key2' => 'value2']);

        $this->assertEquals(300, TenantContext::getTenantId());
        $this->assertEquals(400, TenantContext::getUserId());
        $this->assertNull(TenantContext::get('key1')); // Overwritten
        $this->assertEquals('value2', TenantContext::get('key2'));
    }

    #[Test]
    public function testRunWithTenantAndUser(): void
    {
        TenantContext::initialize(100, 200);

        $result = TenantContext::runWithTenant(300, function () {
            return [
                'tenant' => TenantContext::getTenantId(),
                'user' => TenantContext::getUserId(),
            ];
        }, 400);

        $this->assertEquals(300, $result['tenant']);
        $this->assertEquals(400, $result['user']);

        // Verify restoration
        $this->assertEquals(100, TenantContext::getTenantId());
        $this->assertEquals(200, TenantContext::getUserId());
    }

    #[Test]
    public function testGetTenantConfigForSpecificTenant(): void
    {
        TenantContext::setTenantConfig(100, ['plan' => 'basic']);
        TenantContext::setTenantConfig(200, ['plan' => 'premium']);

        // Get config for specific tenant, not current
        $config = TenantContext::getTenantConfig(200);
        $this->assertEquals('premium', $config['plan']);
    }
}
