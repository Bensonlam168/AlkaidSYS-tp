<?php

declare(strict_types=1);

namespace Tests\E2E;

use Domain\Lowcode\Collection\Model\Collection;
use Infrastructure\Lowcode\Collection\Service\CollectionManager;
use Tests\ThinkPHPTestCase;

/**
 * Multi-Tenant Isolation E2E Test | 多租户隔离端到端测试
 *
 * Tests that data is properly isolated between tenants.
 * 测试数据在不同租户之间的隔离性。
 *
 * @package Tests\E2E
 */
class MultiTenantIsolationTest extends ThinkPHPTestCase
{
    /**
     * Test tenant IDs | 测试租户 ID
     */
    protected const TENANT_A = 88881;
    protected const TENANT_B = 88882;

    /**
     * Collection manager instance | 集合管理器实例
     */
    protected CollectionManager $collectionManager;

    /**
     * Track created collections for cleanup | 跟踪创建的集合以便清理
     *
     * @var array<string>
     */
    protected array $createdCollections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectionManager = $this->app()->make(CollectionManager::class);
    }

    protected function tearDown(): void
    {
        // Clean up test collections | 清理测试集合
        foreach ($this->createdCollections as $item) {
            try {
                $this->collectionManager->delete($item['name'], true, $item['tenant_id']);
            } catch (\Throwable) {
                // Ignore if not exists
            }
        }
        parent::tearDown();
    }

    /**
     * Register collection for cleanup | 注册集合以便清理
     */
    protected function registerCollection(string $name, int $tenantId): void
    {
        $this->createdCollections[] = ['name' => $name, 'tenant_id' => $tenantId];
    }

    /**
     * Create a test collection | 创建测试集合
     */
    protected function createTestCollection(string $name, string $label, int $tenantId): void
    {
        $collection = new Collection($name, [
            'title' => $label,
            'tenant_id' => $tenantId,
            'site_id' => 0,
        ]);
        $this->collectionManager->create($collection);
        $this->registerCollection($name, $tenantId);
    }

    /**
     * E2E Test 1: Tenant A cannot access Tenant B's collection
     * E2E 测试 1：租户 A 无法访问租户 B 的集合
     */
    public function testTenantACannotAccessTenantBCollection(): void
    {
        // Create collection for Tenant B | 为租户 B 创建集合
        $this->createTestCollection('e2e_tenant_b_data', 'Tenant B Data', self::TENANT_B);

        // Try to access from Tenant A | 尝试从租户 A 访问
        $result = $this->collectionManager->get('e2e_tenant_b_data', self::TENANT_A);

        // Should not find the collection | 不应该找到该集合
        $this->assertNull($result, 'Tenant A should not be able to access Tenant B collection');
    }

    /**
     * E2E Test 2: Each tenant has isolated collections
     * E2E 测试 2：每个租户拥有隔离的集合
     *
     * Note: Current implementation uses shared table names (lc_xxx),
     * so we test with different collection names per tenant.
     * 注意：当前实现使用共享表名（lc_xxx），因此我们为每个租户使用不同的集合名称进行测试。
     */
    public function testEachTenantHasIsolatedCollections(): void
    {
        // Create different collections for each tenant | 为每个租户创建不同的集合
        $this->createTestCollection('e2e_tenant_a_col', 'Tenant A Collection', self::TENANT_A);
        $this->createTestCollection('e2e_tenant_b_col', 'Tenant B Collection', self::TENANT_B);

        // Verify Tenant A can only see their collection | 验证租户 A 只能看到自己的集合
        $fetchedAFromA = $this->collectionManager->get('e2e_tenant_a_col', self::TENANT_A);
        $fetchedBFromA = $this->collectionManager->get('e2e_tenant_b_col', self::TENANT_A);

        $this->assertNotNull($fetchedAFromA, 'Tenant A should see their own collection');
        $this->assertNull($fetchedBFromA, 'Tenant A should not see Tenant B collection');

        // Verify Tenant B can only see their collection | 验证租户 B 只能看到自己的集合
        $fetchedAFromB = $this->collectionManager->get('e2e_tenant_a_col', self::TENANT_B);
        $fetchedBFromB = $this->collectionManager->get('e2e_tenant_b_col', self::TENANT_B);

        $this->assertNull($fetchedAFromB, 'Tenant B should not see Tenant A collection');
        $this->assertNotNull($fetchedBFromB, 'Tenant B should see their own collection');
    }

    /**
     * E2E Test 3: Tenant switch maintains data isolation
     * E2E 测试 3：租户切换保持数据隔离
     */
    public function testTenantSwitchMaintainsIsolation(): void
    {
        // Create collection for Tenant A | 为租户 A 创建集合
        $this->createTestCollection('e2e_tenant_a_data', 'Tenant A Only', self::TENANT_A);

        // List collections for Tenant A | 列出租户 A 的集合
        // list(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20)
        $listA = $this->collectionManager->list(self::TENANT_A, [], 1, 100);
        $namesA = array_map(fn ($c) => $c->getName(), $listA['list']);
        $this->assertContains('e2e_tenant_a_data', $namesA);

        // "Switch" to Tenant B and list | "切换"到租户 B 并列出
        $listB = $this->collectionManager->list(self::TENANT_B, [], 1, 100);
        $namesB = array_map(fn ($c) => $c->getName(), $listB['list']);
        $this->assertNotContains('e2e_tenant_a_data', $namesB);

        // "Switch" back to Tenant A | "切换"回租户 A
        $listA2 = $this->collectionManager->list(self::TENANT_A, [], 1, 100);
        $namesA2 = array_map(fn ($c) => $c->getName(), $listA2['list']);
        $this->assertContains('e2e_tenant_a_data', $namesA2);
    }
}
