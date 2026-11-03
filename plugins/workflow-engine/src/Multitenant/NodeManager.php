<?php

declare(strict_types=1);

namespace Alkaid\Workflow\Engine\Multitenant;

use Alkaid\Workflow\Engine\Multitenant\Exception\TenantIsolationException;

/**
 * Node Manager | 多租户节点管理器
 *
 * Manages node configurations per tenant with proper isolation.
 * 按租户管理节点配置，并提供适当的隔离。
 *
 * @package Alkaid\Workflow\Engine\Multitenant
 */
class NodeManager
{
    /**
     * Node configurations storage (keyed by tenant_id:node_id)
     *
     * @var array<string, array>
     */
    private array $nodeConfigs = [];

    /**
     * Node metadata storage
     *
     * @var array<string, array>
     */
    private array $nodeMetadata = [];

    /**
     * Get node configuration | 获取节点配置
     *
     * @param int $tenantId Tenant ID
     * @param string $nodeId Node ID
     * @return array|null Node configuration or null if not found
     * @throws TenantIsolationException If tenant mismatch
     */
    public function getNodeConfig(int $tenantId, string $nodeId): ?array
    {
        $this->validateTenantAccess($tenantId);

        $key = $this->makeKey($tenantId, $nodeId);
        return $this->nodeConfigs[$key] ?? null;
    }

    /**
     * Save node configuration | 保存节点配置
     *
     * @param int $tenantId Tenant ID
     * @param string $nodeId Node ID
     * @param array $config Node configuration
     * @return bool Success status
     * @throws TenantIsolationException If tenant mismatch
     */
    public function saveNodeConfig(int $tenantId, string $nodeId, array $config): bool
    {
        $this->validateTenantAccess($tenantId);

        $key = $this->makeKey($tenantId, $nodeId);

        $this->nodeConfigs[$key] = array_merge($config, [
            'tenant_id' => $tenantId,
            'node_id' => $nodeId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!isset($this->nodeMetadata[$key])) {
            $this->nodeMetadata[$key] = [
                'created_at' => date('Y-m-d H:i:s'),
                'version' => 1,
            ];
        } else {
            $this->nodeMetadata[$key]['version']++;
        }

        return true;
    }

    /**
     * Delete node configuration | 删除节点配置
     *
     * @param int $tenantId Tenant ID
     * @param string $nodeId Node ID
     * @return bool Success status
     * @throws TenantIsolationException If tenant mismatch
     */
    public function deleteNodeConfig(int $tenantId, string $nodeId): bool
    {
        $this->validateTenantAccess($tenantId);

        $key = $this->makeKey($tenantId, $nodeId);

        if (isset($this->nodeConfigs[$key])) {
            unset($this->nodeConfigs[$key]);
            unset($this->nodeMetadata[$key]);
            return true;
        }

        return false;
    }

    /**
     * Check if node configuration exists | 检查节点配置是否存在
     *
     * @param int $tenantId Tenant ID
     * @param string $nodeId Node ID
     * @return bool
     */
    public function hasNodeConfig(int $tenantId, string $nodeId): bool
    {
        $key = $this->makeKey($tenantId, $nodeId);
        return isset($this->nodeConfigs[$key]);
    }

    /**
     * Get all node configurations for tenant | 获取租户的所有节点配置
     *
     * @param int $tenantId Tenant ID
     * @return array<string, array>
     * @throws TenantIsolationException If tenant mismatch
     */
    public function getNodeConfigsForTenant(int $tenantId): array
    {
        $this->validateTenantAccess($tenantId);

        $prefix = "{$tenantId}:";
        $configs = [];

        foreach ($this->nodeConfigs as $key => $config) {
            if (str_starts_with($key, $prefix)) {
                $nodeId = substr($key, strlen($prefix));
                $configs[$nodeId] = $config;
            }
        }

        return $configs;
    }

    /**
     * Get node metadata | 获取节点元数据
     *
     * @param int $tenantId Tenant ID
     * @param string $nodeId Node ID
     * @return array|null
     */
    public function getNodeMetadata(int $tenantId, string $nodeId): ?array
    {
        $key = $this->makeKey($tenantId, $nodeId);
        return $this->nodeMetadata[$key] ?? null;
    }

    /**
     * Copy node configuration to another tenant | 复制节点配置到另一个租户
     *
     * @param int $sourceTenantId Source tenant ID
     * @param int $targetTenantId Target tenant ID
     * @param string $nodeId Node ID
     * @return bool Success status
     */
    public function copyNodeConfigToTenant(int $sourceTenantId, int $targetTenantId, string $nodeId): bool
    {
        // This operation bypasses normal tenant isolation
        return TenantIsolationGuard::bypass(function () use ($sourceTenantId, $targetTenantId, $nodeId) {
            $sourceConfig = $this->getNodeConfig($sourceTenantId, $nodeId);

            if ($sourceConfig === null) {
                return false;
            }

            // Remove source tenant info
            unset($sourceConfig['tenant_id']);
            unset($sourceConfig['node_id']);
            unset($sourceConfig['updated_at']);

            return $this->saveNodeConfig($targetTenantId, $nodeId, $sourceConfig);
        });
    }

    /**
     * Clone all node configurations from one tenant to another | 克隆所有节点配置
     *
     * @param int $sourceTenantId Source tenant ID
     * @param int $targetTenantId Target tenant ID
     * @return int Number of configurations cloned
     */
    public function cloneAllConfigs(int $sourceTenantId, int $targetTenantId): int
    {
        return TenantIsolationGuard::bypass(function () use ($sourceTenantId, $targetTenantId) {
            $sourceConfigs = $this->getNodeConfigsForTenant($sourceTenantId);
            $count = 0;

            foreach ($sourceConfigs as $nodeId => $config) {
                unset($config['tenant_id']);
                unset($config['node_id']);
                unset($config['updated_at']);

                if ($this->saveNodeConfig($targetTenantId, $nodeId, $config)) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Validate tenant access | 验证租户访问权限
     *
     * @param int $requestedTenantId Requested tenant ID
     * @return void
     * @throws TenantIsolationException If access denied
     */
    private function validateTenantAccess(int $requestedTenantId): void
    {
        if (!TenantContext::isIsolationEnforced()) {
            return;
        }

        $currentTenantId = TenantContext::getTenantId();

        if ($currentTenantId !== null && $currentTenantId !== $requestedTenantId) {
            throw TenantIsolationException::crossTenantAccess(
                $currentTenantId,
                $requestedTenantId,
                'NodeConfig',
                null
            );
        }
    }

    /**
     * Make storage key | 生成存储键
     *
     * @param int $tenantId Tenant ID
     * @param string $nodeId Node ID
     * @return string
     */
    private function makeKey(int $tenantId, string $nodeId): string
    {
        return "{$tenantId}:{$nodeId}";
    }
}
