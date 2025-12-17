<?php

declare(strict_types=1);

namespace Infrastructure\Plugin\Repository;

use think\facade\Db;

/**
 * 插件安装状态仓储 | 记录租户/站点维度的安装与启用状态
 */
class PluginInstallationRepository
{
    protected string $table = 'alkaid_plugin_installations';

    /**
    * 获取安装记录
    */
    public function find(int $tenantId, int $siteId, string $pluginKey): ?array
    {
        $row = Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->where('plugin_key', $pluginKey)
            ->find();

        if (!$row) {
            return null;
        }

        $row['config'] = is_string($row['config']) ? json_decode($row['config'], true) : ($row['config'] ?? null);
        return $row;
    }

    /**
     * 安装（不存在则创建，存在则更新版本与状态）
     */
    public function install(int $tenantId, int $siteId, string $pluginKey, string $version): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->find($tenantId, $siteId, $pluginKey);

        if ($exists) {
            Db::name($this->table)
                ->where('tenant_id', $tenantId)
                ->where('site_id', $siteId)
                ->where('plugin_key', $pluginKey)
                ->update([
                    'status' => 'installed',
                    'installed_version' => $version,
                    'installed_at' => $now,
                    'updated_at' => $now,
                ]);
            return;
        }

        Db::name($this->table)->insert([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'plugin_key' => $pluginKey,
            'installed_version' => $version,
            'status' => 'installed',
            'installed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 启用插件
     */
    public function enable(int $tenantId, int $siteId, string $pluginKey, string $version): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->find($tenantId, $siteId, $pluginKey);
        if (!$exists) {
            $this->install($tenantId, $siteId, $pluginKey, $version);
            $exists = $this->find($tenantId, $siteId, $pluginKey);
        }

        Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->where('plugin_key', $pluginKey)
            ->update([
                'status' => 'enabled',
                'enabled_at' => $now,
                'installed_version' => $version,
                'updated_at' => $now,
            ]);
    }

    /**
     * 禁用插件
     */
    public function disable(int $tenantId, int $siteId, string $pluginKey): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->where('plugin_key', $pluginKey)
            ->update([
                'status' => 'disabled',
                'updated_at' => $now,
            ]);
    }

    /**
     * 卸载插件
     */
    public function uninstall(int $tenantId, int $siteId, string $pluginKey): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name($this->table)
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->where('plugin_key', $pluginKey)
            ->update([
                'status' => 'uninstalled',
                'uninstalled_at' => $now,
                'updated_at' => $now,
            ]);
    }
}

