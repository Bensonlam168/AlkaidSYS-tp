<?php

declare(strict_types=1);

namespace app\command\plugin;

use Infrastructure\Plugin\PluginScanner;
use Infrastructure\Plugin\Repository\PluginPackageRepository;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;

/**
 * Plugin Scan Command | 扫描插件目录并写入插件包表
 */
class PluginScanCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugin:scan')
            ->setDescription('Scan addons/plugins for plugin.json and persist packages | 扫描插件目录并写入包清单')
            ->addOption('base-path', null, Option::VALUE_OPTIONAL, 'Base path for plugins | 插件目录', '');
    }

    protected function execute(Input $input, Output $output): int
    {
        $basePath = (string)$input->getOption('base-path');
        if ($basePath === '') {
            $basePath = Config::get('plugin.base_path');
        }

        if (!str_ends_with($basePath, DIRECTORY_SEPARATOR)) {
            $basePath .= DIRECTORY_SEPARATOR;
        }

        $scanner = new PluginScanner();
        $repo = new PluginPackageRepository();

        // 路径落库统一使用“相对项目根”的语义，便于跨环境一致
        $manifests = $scanner->scan($basePath, (string)\root_path());
        if (empty($manifests)) {
            $output->writeln("<comment>No plugin.json found under {$basePath}</comment>");
            return 0;
        }

        foreach ($manifests as $manifest) {
            try {
                $repo->upsert($manifest);
                $output->writeln(sprintf(
                    '<info>✓</info> %s (%s) => %s',
                    $manifest['plugin_key'],
                    $manifest['version'],
                    $manifest['manifest_path']
                ));
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ Persist failed: {$e->getMessage()}</error>");
                $output->writeln('<comment>请先执行迁移：php think migrate:run（或 docker exec -it alkaid-backend php think migrate:run）</comment>');
                return 1;
            }
        }

        $output->writeln('<info>Scan completed. Total: ' . count($manifests) . '</info>');
        return 0;
    }
}
