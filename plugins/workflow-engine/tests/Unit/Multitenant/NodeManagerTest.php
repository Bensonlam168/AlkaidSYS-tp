<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Tests\Unit\Multitenant;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Alkaid\Workflow\Engine\Multitenant\TenantContext;
use Alkaid\Workflow\Engine\Multitenant\NodeManager;
use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;

/**
 * NodeManager Unit Tests | 节点管理器单元测试
 */
class NodeManagerTest extends TestCase
{
    private NodeManager $nodeManager;

    protected function setUp(): void
    {
        TenantContext::clearAll();
        TenantContext::setIsolationEnforced(true);
        $this->nodeManager = new NodeManager();
    }

    protected function tearDown(): void
    {
        TenantContext::clearAll();
        TenantContext::setIsolationEnforced(true);
    }

    #[Test]
    public function testSaveAndGetNodeConfig(): void
    {
        TenantContext::setTenantId(100);

        $config = [
            'type' => 'approval',
            'approvers' => ['user-1', 'user-2'],
            'timeout' => 3600,
        ];

        $result = $this->nodeManager->saveNodeConfig(100, 'node-1', $config);
        $this->assertTrue($result);

        $retrieved = $this->nodeManager->getNodeConfig(100, 'node-1');
        $this->assertNotNull($retrieved);
        $this->assertEquals('approval', $retrieved['type']);
        $this->assertEquals(['user-1', 'user-2'], $retrieved['approvers']);
        $this->assertEquals(100, $retrieved['tenant_id']);
    }

    #[Test]
    public function testGetNodeConfigReturnsNullForMissing(): void
    {
        TenantContext::setTenantId(100);

        $result = $this->nodeManager->getNodeConfig(100, 'non-existent');
        $this->assertNull($result);
    }

    #[Test]
    public function testHasNodeConfig(): void
    {
        TenantContext::setTenantId(100);

        $this->assertFalse($this->nodeManager->hasNodeConfig(100, 'node-1'));

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'test']);

        $this->assertTrue($this->nodeManager->hasNodeConfig(100, 'node-1'));
    }

    #[Test]
    public function testDeleteNodeConfig(): void
    {
        TenantContext::setTenantId(100);

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'test']);
        $this->assertTrue($this->nodeManager->hasNodeConfig(100, 'node-1'));

        $result = $this->nodeManager->deleteNodeConfig(100, 'node-1');
        $this->assertTrue($result);
        $this->assertFalse($this->nodeManager->hasNodeConfig(100, 'node-1'));
    }

    #[Test]
    public function testDeleteNodeConfigReturnsFalseForMissing(): void
    {
        TenantContext::setTenantId(100);

        $result = $this->nodeManager->deleteNodeConfig(100, 'non-existent');
        $this->assertFalse($result);
    }

    #[Test]
    public function testGetNodeConfigsForTenant(): void
    {
        TenantContext::setTenantId(100);

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'approval']);
        $this->nodeManager->saveNodeConfig(100, 'node-2', ['type' => 'condition']);
        $this->nodeManager->saveNodeConfig(100, 'node-3', ['type' => 'http']);

        $configs = $this->nodeManager->getNodeConfigsForTenant(100);

        $this->assertCount(3, $configs);
        $this->assertArrayHasKey('node-1', $configs);
        $this->assertArrayHasKey('node-2', $configs);
        $this->assertArrayHasKey('node-3', $configs);
    }

    #[Test]
    public function testTenantIsolation(): void
    {
        TenantContext::setIsolationEnforced(false);

        // Save configs for different tenants
        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'tenant-100']);
        $this->nodeManager->saveNodeConfig(200, 'node-1', ['type' => 'tenant-200']);

        TenantContext::setIsolationEnforced(true);

        // Should only see tenant 100's config when context is set to 100
        TenantContext::setTenantId(100);
        $configs = $this->nodeManager->getNodeConfigsForTenant(100);
        $this->assertEquals('tenant-100', $configs['node-1']['type']);
    }

    #[Test]
    public function testCrossTenantAccessThrows(): void
    {
        TenantContext::setTenantId(100);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Cross-tenant access denied');

        // Try to access tenant 200's config while context is 100
        $this->nodeManager->getNodeConfig(200, 'node-1');
    }

    #[Test]
    public function testCrossTenantSaveThrows(): void
    {
        TenantContext::setTenantId(100);

        $this->expectException(TenantIsolationException::class);

        // Try to save config for tenant 200 while context is 100
        $this->nodeManager->saveNodeConfig(200, 'node-1', ['type' => 'test']);
    }

    #[Test]
    public function testGetNodeMetadata(): void
    {
        TenantContext::setTenantId(100);

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'test']);

        $metadata = $this->nodeManager->getNodeMetadata(100, 'node-1');

        $this->assertNotNull($metadata);
        $this->assertEquals(1, $metadata['version']);
        $this->assertArrayHasKey('created_at', $metadata);
    }

    #[Test]
    public function testMetadataVersionIncrementsOnUpdate(): void
    {
        TenantContext::setTenantId(100);

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'v1']);
        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'v2']);
        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'v3']);

        $metadata = $this->nodeManager->getNodeMetadata(100, 'node-1');

        $this->assertEquals(3, $metadata['version']);
    }

    #[Test]
    public function testCopyNodeConfigToTenant(): void
    {
        TenantContext::setIsolationEnforced(false);

        $this->nodeManager->saveNodeConfig(100, 'node-1', [
            'type' => 'approval',
            'timeout' => 3600,
        ]);

        $result = $this->nodeManager->copyNodeConfigToTenant(100, 200, 'node-1');
        $this->assertTrue($result);

        $copied = $this->nodeManager->getNodeConfig(200, 'node-1');
        $this->assertNotNull($copied);
        $this->assertEquals('approval', $copied['type']);
        $this->assertEquals(200, $copied['tenant_id']);
    }

    #[Test]
    public function testCopyNodeConfigReturnsFalseForMissing(): void
    {
        $result = $this->nodeManager->copyNodeConfigToTenant(100, 200, 'non-existent');
        $this->assertFalse($result);
    }

    #[Test]
    public function testCloneAllConfigs(): void
    {
        TenantContext::setIsolationEnforced(false);

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'approval']);
        $this->nodeManager->saveNodeConfig(100, 'node-2', ['type' => 'condition']);
        $this->nodeManager->saveNodeConfig(100, 'node-3', ['type' => 'http']);

        $count = $this->nodeManager->cloneAllConfigs(100, 200);

        $this->assertEquals(3, $count);

        $configs = $this->nodeManager->getNodeConfigsForTenant(200);
        $this->assertCount(3, $configs);
    }

    #[Test]
    public function testConfigUpdatedAtIsSet(): void
    {
        TenantContext::setTenantId(100);

        $this->nodeManager->saveNodeConfig(100, 'node-1', ['type' => 'test']);

        $config = $this->nodeManager->getNodeConfig(100, 'node-1');

        $this->assertArrayHasKey('updated_at', $config);
        $this->assertNotNull($config['updated_at']);
    }

    #[Test]
    public function testAccessWithoutIsolationEnforced(): void
    {
        TenantContext::setIsolationEnforced(false);
        TenantContext::setTenantId(100);

        // Save config for tenant 200
        $this->nodeManager->saveNodeConfig(200, 'node-1', ['type' => 'test']);

        // Should be able to access without exception
        $config = $this->nodeManager->getNodeConfig(200, 'node-1');
        $this->assertNotNull($config);
    }

    #[Test]
    public function testMultipleTenantsIsolated(): void
    {
        TenantContext::setIsolationEnforced(false);

        // Create configs for multiple tenants
        $this->nodeManager->saveNodeConfig(100, 'node-shared', ['value' => 'tenant-100']);
        $this->nodeManager->saveNodeConfig(200, 'node-shared', ['value' => 'tenant-200']);
        $this->nodeManager->saveNodeConfig(300, 'node-shared', ['value' => 'tenant-300']);

        TenantContext::setIsolationEnforced(true);

        // Each tenant should only see their own config
        TenantContext::setTenantId(100);
        $configs100 = $this->nodeManager->getNodeConfigsForTenant(100);
        $this->assertEquals('tenant-100', $configs100['node-shared']['value']);

        TenantContext::setTenantId(200);
        $configs200 = $this->nodeManager->getNodeConfigsForTenant(200);
        $this->assertEquals('tenant-200', $configs200['value'] ?? $configs200['node-shared']['value']);
    }
}
