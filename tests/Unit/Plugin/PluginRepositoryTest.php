<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use Infrastructure\Plugin\Repository\PluginInstallationRepository;
use Infrastructure\Plugin\Repository\PluginPackageRepository;
use Tests\ThinkPHPTestCase;
use think\facade\Db;

/**
 * 插件仓储单元测试
 */
class PluginRepositoryTest extends ThinkPHPTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        Db::name('alkaid_plugin_installations')->where('plugin_key', 'demo_plugin')->delete();
        Db::name('alkaid_plugin_packages')->where('plugin_key', 'demo_plugin')->delete();
        parent::tearDown();
    }

    public function testPackageUpsertAndFind(): void
    {
        $repo = new PluginPackageRepository();
        $repo->upsert([
            'plugin_key' => 'demo_plugin',
            'name' => 'Demo 插件',
            'version' => '1.0.0',
            'category' => 'universal',
            'app_key' => null,
            'main_class' => 'plugin\\demo_plugin\\Plugin',
            'base_path' => 'addons/plugins/demo_plugin',
            'manifest_path' => 'addons/plugins/demo_plugin/plugin.json',
            'manifest_hash' => 'hash',
            'manifest' => ['key' => 'demo_plugin', 'version' => '1.0.0'],
        ]);

        $row = $repo->findByKey('demo_plugin');
        $this->assertNotNull($row);
        $this->assertSame('1.0.0', $row['version']);
        $this->assertSame('plugin\\demo_plugin\\Plugin', $row['main_class']);
    }

    public function testInstallationLifecycle(): void
    {
        $repo = new PluginInstallationRepository();

        $repo->install(0, 0, 'demo_plugin', '1.0.0');
        $row = $repo->find(0, 0, 'demo_plugin');
        $this->assertSame('installed', $row['status']);

        $repo->enable(0, 0, 'demo_plugin', '1.0.0');
        $row = $repo->find(0, 0, 'demo_plugin');
        $this->assertSame('enabled', $row['status']);

        $repo->disable(0, 0, 'demo_plugin');
        $row = $repo->find(0, 0, 'demo_plugin');
        $this->assertSame('disabled', $row['status']);

        $repo->uninstall(0, 0, 'demo_plugin');
        $row = $repo->find(0, 0, 'demo_plugin');
        $this->assertSame('uninstalled', $row['status']);
    }

    /**
     * 确保测试所需的表存在（若迁移未执行）
     */
    protected function ensureTables(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS alkaid_plugin_packages (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                plugin_key varchar(64) NOT NULL UNIQUE,
                name varchar(128) NOT NULL,
                version varchar(32) NOT NULL,
                category varchar(64) NULL,
                app_key varchar(64) NULL,
                main_class varchar(255) NOT NULL,
                base_path varchar(500) NOT NULL,
                manifest_path varchar(500) NOT NULL,
                manifest_hash varchar(64) NULL,
                manifest json NOT NULL,
                created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        Db::execute(
            "CREATE TABLE IF NOT EXISTS alkaid_plugin_installations (
                id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
                tenant_id bigint unsigned NOT NULL DEFAULT 0,
                site_id bigint unsigned NOT NULL DEFAULT 0,
                plugin_key varchar(64) NOT NULL,
                installed_version varchar(32) NOT NULL,
                status enum('installed','enabled','disabled','uninstalled') NOT NULL DEFAULT 'uninstalled',
                config json NULL,
                installed_at timestamp NULL,
                enabled_at timestamp NULL,
                uninstalled_at timestamp NULL,
                created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_tenant_site_plugin (tenant_id, site_id, plugin_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
