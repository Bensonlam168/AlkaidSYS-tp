<?php

declare(strict_types=1);

namespace app\command\plugin;

use Infrastructure\Plugin\PluginManager;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * Plugin Enable Command | 启用插件命令
 */
class PluginEnableCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugin:enable')
            ->setDescription('Enable a plugin | 启用插件')
            ->addArgument('key', Argument::REQUIRED, 'Plugin key | 插件 key')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID (default: 0) | 租户ID', '0')
            ->addOption('site-id', null, Option::VALUE_OPTIONAL, 'Site ID (default: 0) | 站点ID', '0');
    }

    protected function execute(Input $input, Output $output): int
    {
        $key = (string)$input->getArgument('key');
        $tenantId = (int)$input->getOption('tenant-id');
        $siteId = (int)$input->getOption('site-id');
        /** @var PluginManager $manager */
        $manager = app()->make(PluginManager::class);

        try {
            $manager->enable($key, $tenantId, $siteId);
            $output->writeln("<info>✓ Plugin enabled: {$key} (tenant={$tenantId}, site={$siteId})</info>");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>✗ Enable failed: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
