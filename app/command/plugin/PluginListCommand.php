<?php

declare(strict_types=1);

namespace app\command\plugin;

use Infrastructure\Plugin\PluginManager;
use Infrastructure\Plugin\Repository\PluginInstallationRepository;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Plugin List Command | 插件列表命令
 *
 * 用于查看当前配置/加载的插件（T-035）。
 */
class PluginListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugin:list')
            ->setDescription('List all loaded plugins | 列出已加载插件')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID', '0')
            ->addOption('site-id', null, Option::VALUE_OPTIONAL, 'Site ID (default: 0) | 站点ID', '0');
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var PluginManager $manager */
        $manager = app()->make(PluginManager::class);
        $installationRepo = new PluginInstallationRepository();
        $tenantId = (int)$input->getOption('tenant-id');
        $siteId = (int)$input->getOption('site-id');
        $plugins = $manager->all();

        if ($plugins === []) {
            $output->writeln('<comment>No plugins loaded. Configure in config/plugin.php</comment>');
            return 0;
        }

        $output->writeln(sprintf('<info>Loaded Plugins | 已加载插件 (tenant=%d, site=%d)</info>', $tenantId, $siteId));
        $installationRepoAvailable = true;
        foreach ($plugins as $key => $plugin) {
            $install = null;
            if ($installationRepoAvailable) {
                try {
                    $install = $installationRepo->find($tenantId, $siteId, $key);
                } catch (\Throwable $e) {
                    $installationRepoAvailable = false;
                    $output->writeln('<comment>插件安装状态表不可用，将仅展示运行时状态；请先执行迁移：php think migrate:run</comment>');
                }
            }
            $status = $install['status'] ?? ($plugin->isEnabled() ? 'enabled' : ($plugin->isInstalled() ? 'installed' : 'uninstalled'));
            $version = $install['installed_version'] ?? ($plugin->getManifest()['version'] ?? 'n/a');
            $output->writeln(sprintf(
                '- %s | version=%s | status=%s | installed=%s enabled=%s',
                $key,
                $version,
                $status,
                $plugin->isInstalled() ? 'yes' : 'no',
                $plugin->isEnabled() ? 'yes' : 'no'
            ));
        }

        return 0;
    }
}
