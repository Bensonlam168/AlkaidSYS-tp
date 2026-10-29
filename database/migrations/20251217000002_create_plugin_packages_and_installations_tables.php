<?php

use think\migration\Migrator;

/**
 * 创建插件包表与插件安装状态表（T-035 扩展）
 *
 * 目标：
 * - alkaid_plugin_packages：记录本地可用插件包（来自 addons/plugins/{plugin_key}/plugin.json 扫描）
 * - alkaid_plugin_installations：记录租户/站点维度的安装与启用状态
 *
 * 说明：
 * - 插件市场（Marketplace）相关表不在本迁移范围；
 * - 本地插件包以 plugin_key 作为唯一标识，安装状态表以 (tenant_id, site_id, plugin_key) 唯一。
 */
class CreatePluginPackagesAndInstallationsTables extends Migrator
{
    public function change(): void
    {
        // 1) 插件包表：扫描结果落库
        if (!$this->hasTable('alkaid_plugin_packages')) {
            $table = $this->table('alkaid_plugin_packages', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '本地插件包表（扫描插件目录生成）',
            ]);

            $table
                ->addColumn('id', 'biginteger', [
                    'identity' => true,
                    'signed' => false,
                    'comment' => '主键',
                ])
                ->addColumn('plugin_key', 'string', [
                    'limit' => 64,
                    'null' => false,
                    'comment' => '插件唯一标识（key）',
                ])
                ->addColumn('name', 'string', [
                    'limit' => 128,
                    'null' => false,
                    'default' => '',
                    'comment' => '插件名称（展示）',
                ])
                ->addColumn('version', 'string', [
                    'limit' => 32,
                    'null' => false,
                    'default' => '0.0.0',
                    'comment' => '插件版本',
                ])
                ->addColumn('category', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'comment' => '插件分类（universal/app-specific 等）',
                ])
                ->addColumn('app_key', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'comment' => '依赖应用 key（应用专属插件可选）',
                ])
                ->addColumn('main_class', 'string', [
                    'limit' => 255,
                    'null' => false,
                    'comment' => '插件主类（PHP 类名）',
                ])
                ->addColumn('base_path', 'string', [
                    'limit' => 500,
                    'null' => false,
                    'comment' => '插件目录路径（相对项目根）',
                ])
                ->addColumn('manifest_path', 'string', [
                    'limit' => 500,
                    'null' => false,
                    'comment' => '清单文件路径（相对项目根）',
                ])
                ->addColumn('manifest_hash', 'string', [
                    'limit' => 64,
                    'null' => true,
                    'comment' => '清单文件哈希（sha256）',
                ])
                ->addColumn('manifest', 'json', [
                    'null' => false,
                    'comment' => '完整 plugin.json（JSON）',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                ])
                ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['plugin_key'], [
                    'unique' => true,
                    'name' => 'uk_plugin_key',
                ])
                ->addIndex(['category'], ['name' => 'idx_category'])
                ->addIndex(['app_key'], ['name' => 'idx_app_key'])
                ->create();
        }

        // 2) 插件安装状态表：租户/站点维度的启停
        if (!$this->hasTable('alkaid_plugin_installations')) {
            $table = $this->table('alkaid_plugin_installations', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
                'comment' => '插件安装与启用状态表（租户/站点维度）',
            ]);

            $table
                ->addColumn('id', 'biginteger', [
                    'identity' => true,
                    'signed' => false,
                    'comment' => '主键',
                ])
                ->addColumn('tenant_id', 'biginteger', [
                    'signed' => false,
                    'default' => 0,
                    'comment' => '租户ID（0 = 系统模板）',
                ])
                ->addColumn('site_id', 'biginteger', [
                    'signed' => false,
                    'default' => 0,
                    'comment' => '站点ID（0 = 默认站点）',
                ])
                ->addColumn('plugin_key', 'string', [
                    'limit' => 64,
                    'null' => false,
                    'comment' => '插件唯一标识（key）',
                ])
                ->addColumn('installed_version', 'string', [
                    'limit' => 32,
                    'null' => false,
                    'default' => '0.0.0',
                    'comment' => '安装版本（来自插件包版本）',
                ])
                ->addColumn('status', 'enum', [
                    'values' => ['installed', 'enabled', 'disabled', 'uninstalled'],
                    'default' => 'uninstalled',
                    'comment' => '状态：installed/enabled/disabled/uninstalled',
                ])
                ->addColumn('config', 'json', [
                    'null' => true,
                    'comment' => '插件配置（JSON）',
                ])
                ->addColumn('installed_at', 'timestamp', [
                    'null' => true,
                    'comment' => '安装时间',
                ])
                ->addColumn('enabled_at', 'timestamp', [
                    'null' => true,
                    'comment' => '启用时间',
                ])
                ->addColumn('uninstalled_at', 'timestamp', [
                    'null' => true,
                    'comment' => '卸载时间',
                ])
                ->addColumn('created_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                ])
                ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP',
                    'update' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['tenant_id', 'site_id', 'plugin_key'], [
                    'unique' => true,
                    'name' => 'uk_tenant_site_plugin',
                ])
                ->addIndex(['tenant_id'], ['name' => 'idx_tenant_id'])
                ->addIndex(['plugin_key'], ['name' => 'idx_plugin_key'])
                ->addIndex(['status'], ['name' => 'idx_status'])
                ->create();
        }
    }
}
