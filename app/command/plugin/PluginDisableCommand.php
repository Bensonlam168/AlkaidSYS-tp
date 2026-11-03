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
 * Plugin Disable Command | 禁用插件命令
 */
class PluginDisableCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugin:disable')
            ->setDescription('Disable a plugin | 禁用插件')
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
            $manager->disable($key, $tenantId, $siteId);
            $output->writeln("<info>✓ Plugin disabled: {$key} (tenant={$tenantId}, site={$siteId})</info>");
            return 0;
        } catch (\Throwable $e) {
            $output->writeln("<error>✗ Disable failed: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
