<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Multitenant;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\TenantIsolationGuard;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;
use Alkaid\Workflow\Engine\Multitenant\Interfaces\TenantAwareInterface;
use Alkaid\Workflow\Engine\Multitenant\Traits\TenantAwareTrait;

/**
 * Test tenant-aware entity
 */
class TestTenantEntity implements TenantAwareInterface
{
    use TenantAwareTrait;

    public function __construct(?int $tenantId = null)
    {
        $this->tenantId = $tenantId;
    }
}

/**
 * TenantIsolationGuard Unit Tests | 租户隔离守卫单元测试
 */
class TenantIsolationGuardTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::clearAll();
        TenantContext::setIsolationEnforced(true);
        TenantIsolationGuard::clearAccessLog();
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();
        TenantContext::setIsolationEnforced(true);
    }

    #[Test]
    public function testValidatePassesForSameTenant(): void
    {
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(100);

        // Should not throw
        TenantIsolationGuard::validate($entity, 'TestEntity');
        $this->assertTrue(true);
    }

    #[Test]
    public function testValidatePassesForArrayWithSameTenant(): void
    {
        TenantContext::setTenantId(100);
        $data = ['tenant_id' => 100, 'name' => 'test'];

        // Should not throw
        TenantIsolationGuard::validate($data, 'TestData');
        $this->assertTrue(true);
    }

    #[Test]
    public function testValidateThrowsForDifferentTenant(): void
    {
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(200);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Cross-tenant access denied');

        TenantIsolationGuard::validate($entity, 'TestEntity');
    }

    #[Test]
    public function testValidatePassesWhenNoContextSet(): void
    {
        // No tenant context set
        $entity = new TestTenantEntity(100);

        // Should not throw - no context means no restriction
        TenantIsolationGuard::validate($entity, 'TestEntity');
        $this->assertTrue(true);
    }

    #[Test]
    public function testValidatePassesWhenEntityHasNoTenant(): void
    {
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(null);

        // Should not throw - entity without tenant_id is allowed
        TenantIsolationGuard::validate($entity, 'TestEntity');
        $this->assertTrue(true);
    }

    #[Test]
    public function testValidatePassesWhenIsolationDisabled(): void
    {
        TenantContext::setIsolationEnforced(false);
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(200);

        // Should not throw when isolation is disabled
        TenantIsolationGuard::validate($entity, 'TestEntity');
        $this->assertTrue(true);
    }

    #[Test]
    public function testCheckReturnsTrueForSameTenant(): void
    {
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(100);

        $this->assertTrue(TenantIsolationGuard::check($entity));
    }

    #[Test]
    public function testCheckReturnsFalseForDifferentTenant(): void
    {
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(200);

        $this->assertFalse(TenantIsolationGuard::check($entity));
    }

    #[Test]
    public function testCheckReturnsTrueForArrayWithSameTenant(): void
    {
        TenantContext::setTenantId(100);
        $data = ['tenant_id' => 100];

        $this->assertTrue(TenantIsolationGuard::check($data));
    }

    #[Test]
    public function testRequireTenantContext(): void
    {
        TenantContext::setTenantId(100);

        $tenantId = TenantIsolationGuard::requireTenantContext('test operation');
        $this->assertEquals(100, $tenantId);
    }

    #[Test]
    public function testRequireTenantContextThrowsWhenMissing(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant context required');

        TenantIsolationGuard::requireTenantContext('test operation');
    }

    #[Test]
    public function testValidateAll(): void
    {
        TenantContext::setTenantId(100);
        $entities = [
            new TestTenantEntity(100),
            new TestTenantEntity(100),
            ['tenant_id' => 100],
        ];

        // Should not throw
        TenantIsolationGuard::validateAll($entities, 'TestEntities');
        $this->assertTrue(true);
    }

    #[Test]
    public function testValidateAllThrowsOnFirstMismatch(): void
    {
        TenantContext::setTenantId(100);
        $entities = [
            new TestTenantEntity(100),
            new TestTenantEntity(200), // This should trigger exception
            new TestTenantEntity(100),
        ];

        $this->expectException(TenantIsolationException::class);

        TenantIsolationGuard::validateAll($entities, 'TestEntities');
    }

    #[Test]
    public function testFilter(): void
    {
        TenantContext::setTenantId(100);
        $entities = [
            'a' => new TestTenantEntity(100),
            'b' => new TestTenantEntity(200),
            'c' => new TestTenantEntity(100),
            'd' => new TestTenantEntity(null), // No tenant, should be included
        ];

        $filtered = TenantIsolationGuard::filter($entities);

        $this->assertCount(3, $filtered);
        $this->assertArrayHasKey('a', $filtered);
        $this->assertArrayHasKey('c', $filtered);
        $this->assertArrayHasKey('d', $filtered);
        $this->assertArrayNotHasKey('b', $filtered);
    }

    #[Test]
    public function testFilterReturnsAllWhenNoContext(): void
    {
        $entities = [
            new TestTenantEntity(100),
            new TestTenantEntity(200),
        ];

        $filtered = TenantIsolationGuard::filter($entities);

        $this->assertCount(2, $filtered);
    }

    #[Test]
    public function testEnsureBelongsTo(): void
    {
        $entity = new TestTenantEntity(100);

        // Should not throw
        TenantIsolationGuard::ensureBelongsTo($entity, 100, 'TestEntity');
        $this->assertTrue(true);
    }

    #[Test]
    public function testEnsureBelongsToThrowsOnMismatch(): void
    {
        $entity = new TestTenantEntity(200);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Tenant mismatch');

        TenantIsolationGuard::ensureBelongsTo($entity, 100, 'TestEntity');
    }

    #[Test]
    public function testBypass(): void
    {
        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(200);

        $result = TenantIsolationGuard::bypass(function () use ($entity) {
            // This would normally throw
            TenantIsolationGuard::validate($entity, 'TestEntity');
            return 'success';
        });

        $this->assertEquals('success', $result);
        // Verify isolation is re-enabled
        $this->assertTrue(TenantContext::isIsolationEnforced());
    }

    #[Test]
    public function testBypassRestoresOnException(): void
    {
        try {
            TenantIsolationGuard::bypass(function () {
                throw new \Exception('Test exception');
            });
        } catch (\Exception $e) {
            // Expected
        }

        // Verify isolation is re-enabled
        $this->assertTrue(TenantContext::isIsolationEnforced());
    }

    #[Test]
    public function testAsTenant(): void
    {
        TenantContext::setTenantId(100);

        $result = TenantIsolationGuard::asTenant(200, function () {
            return TenantContext::getTenantId();
        });

        $this->assertEquals(200, $result);
        $this->assertEquals(100, TenantContext::getTenantId()); // Restored
    }

    #[Test]
    public function testOnViolation(): void
    {
        $violationData = null;

        TenantIsolationGuard::onViolation(function ($current, $target, $resource) use (&$violationData) {
            $violationData = compact('current', 'target', 'resource');
        });

        TenantContext::setTenantId(100);
        $entity = new TestTenantEntity(200);

        try {
            TenantIsolationGuard::validate($entity, 'TestEntity', 'entity-123');
        } catch (TenantIsolationException $e) {
            // Expected
        }

        $this->assertNotNull($violationData);
        $this->assertEquals(100, $violationData['current']);
        $this->assertEquals(200, $violationData['target']);
        $this->assertEquals('TestEntity:entity-123', $violationData['resource']);
    }

    #[Test]
    public function testAccessLog(): void
    {
        TenantContext::setTenantId(100);

        // Valid access
        TenantIsolationGuard::validate(['tenant_id' => 100], 'Test');

        $log = TenantIsolationGuard::getAccessLog();
        $this->assertCount(1, $log);
        $this->assertTrue($log[0]['allowed']);
    }

    #[Test]
    public function testViolationStats(): void
    {
        TenantContext::setTenantId(100);

        // Valid access
        TenantIsolationGuard::validate(['tenant_id' => 100], 'Test1');
        TenantIsolationGuard::validate(['tenant_id' => 100], 'Test2');

        // Invalid access
        try {
            TenantIsolationGuard::validate(['tenant_id' => 200], 'Test3');
        } catch (TenantIsolationException $e) {
            // Expected
        }

        $stats = TenantIsolationGuard::getViolationStats();

        $this->assertEquals(3, $stats['total_accesses']);
        $this->assertEquals(2, $stats['allowed']);
        $this->assertEquals(1, $stats['violations']);
        $this->assertEqualsWithDelta(0.333, $stats['violation_rate'], 0.01);
    }

    #[Test]
    public function testClearAccessLog(): void
    {
        TenantContext::setTenantId(100);
        TenantIsolationGuard::validate(['tenant_id' => 100], 'Test');

        $this->assertNotEmpty(TenantIsolationGuard::getAccessLog());

        TenantIsolationGuard::clearAccessLog();

        $this->assertEmpty(TenantIsolationGuard::getAccessLog());
    }

    #[Test]
    public function testMaxLogEntries(): void
    {
        TenantIsolationGuard::setMaxLogEntries(3);
        TenantContext::setTenantId(100);

        for ($i = 0; $i < 5; $i++) {
            TenantIsolationGuard::validate(['tenant_id' => 100], "Test{$i}");
        }

        $log = TenantIsolationGuard::getAccessLog();
        $this->assertCount(3, $log);

        // Restore default
        TenantIsolationGuard::setMaxLogEntries(1000);
    }
}
