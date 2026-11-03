<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Language Pack Sync Command | 语言包同步命令
 *
 * Synchronizes missing keys from base locale to other locales.
 * 将基准语言包中缺失的 key 同步到其他语言包。
 *
 * Usage | 用法:
 *   php think lang:sync              # Sync missing keys (dry-run)
 *   php think lang:sync --write      # Actually write changes
 *   php think lang:sync --base=zh-cn # Use zh-cn as base locale
 *
 * Exit codes | 退出码:
 *   0 - Sync completed successfully | 同步成功
 *   1 - Error occurred | 发生错误
 *
 * @package app\command
 */
class LangSyncCommand extends Command
{
    protected const DEFAULT_BASE_LOCALE = 'zh-cn';

    /**
     * Configure command | 配置命令
     */
    protected function configure(): void
    {
        $this->setName('lang:sync')
            ->setDescription('Sync missing language pack keys | 同步缺失的语言包 key')
            ->addOption('base', 'b', Option::VALUE_OPTIONAL, 'Base locale', self::DEFAULT_BASE_LOCALE)
            ->addOption('write', 'w', Option::VALUE_NONE, 'Write changes to files');
    }

    /**
     * Execute command | 执行命令
     */
    protected function execute(Input $input, Output $output): int
    {
        $baseLocale = $input->getOption('base');
        $writeMode = $input->getOption('write');

        $output->writeln('<info>Language Pack Sync | 语言包同步</info>');
        $output->writeln(sprintf('Base locale: <comment>%s</comment>', $baseLocale));
        $output->writeln(sprintf('Mode: <comment>%s</comment>', $writeMode ? 'WRITE' : 'DRY-RUN'));
        $output->writeln('');

        $langPath = $this->app->getAppPath() . 'lang/';

        if (!is_dir($langPath)) {
            $output->writeln('<error>Language directory not found: app/lang/</error>');
            return 1;
        }

        // Get all locales | 获取所有语言
        $locales = $this->getLocales($langPath);

        if (!in_array($baseLocale, $locales)) {
            $output->writeln(sprintf('<error>Base locale "%s" not found</error>', $baseLocale));
            return 1;
        }

        // Load base locale files | 加载基准语言文件
        $baseFiles = $this->loadLocaleFiles($langPath, $baseLocale);
        $totalSynced = 0;

        foreach ($locales as $locale) {
            if ($locale === $baseLocale) {
                continue;
            }

            $output->writeln(sprintf('<comment>Processing locale: %s</comment>', $locale));
            $localeFiles = $this->loadLocaleFiles($langPath, $locale);

            foreach ($baseFiles as $filename => $baseContent) {
                $targetFile = $langPath . $locale . '/' . $filename . '.php';
                $targetContent = $localeFiles[$filename] ?? [];

                $missing = $this->findMissingKeys($baseContent, $targetContent);

                if (empty($missing)) {
                    continue;
                }

                $output->writeln(sprintf('  %s.php: %d missing keys', $filename, count($missing)));

                foreach ($missing as $key => $value) {
                    $output->writeln(sprintf('    + %s', $key));
                    $totalSynced++;
                }

                if ($writeMode) {
                    $merged = $this->mergeKeys($targetContent, $missing);
                    $this->writeFile($targetFile, $merged);
                    $output->writeln(sprintf('    <info>Written to %s</info>', $targetFile));
                }
            }

            // Check for missing files | 检查缺失的文件
            foreach ($baseFiles as $filename => $baseContent) {
                if (!isset($localeFiles[$filename])) {
                    $targetFile = $langPath . $locale . '/' . $filename . '.php';
                    $output->writeln(sprintf('  <comment>Missing file: %s.php</comment>', $filename));

                    if ($writeMode) {
                        $placeholder = $this->createPlaceholder($baseContent);
                        $this->writeFile($targetFile, $placeholder);
                        $output->writeln(sprintf('    <info>Created %s</info>', $targetFile));
                    }

                    $totalSynced += count($baseContent);
                }
            }
        }

        $output->writeln('');
        if ($totalSynced > 0) {
            $output->writeln(sprintf('<comment>Total keys to sync: %d</comment>', $totalSynced));
            if (!$writeMode) {
                $output->writeln('<info>Run with --write to apply changes</info>');
            } else {
                $output->writeln('<info>✓ Sync completed</info>');
            }
        } else {
            $output->writeln('<info>✓ All locales are in sync | 所有语言包已同步</info>');
        }

        return 0;
    }

    /**
     * Get all locale directories | 获取所有语言目录
     */
    protected function getLocales(string $langPath): array
    {
        $locales = [];
        $dirs = glob($langPath . '*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $locales[] = basename($dir);
        }
        return $locales;
    }

    /**
     * Load all files for a locale | 加载语言的所有文件
     */
    protected function loadLocaleFiles(string $langPath, string $locale): array
    {
        $files = [];
        $localePath = $langPath . $locale . '/';
        $phpFiles = glob($localePath . '*.php');

        foreach ($phpFiles as $file) {
            $filename = basename($file, '.php');
            $content = include $file;
            if (is_array($content)) {
                $files[$filename] = $content;
            }
        }
        return $files;
    }


    /**
     * Find missing keys | 查找缺失的 key
     */
    protected function findMissingKeys(array $base, array $target): array
    {
        $missing = [];
        foreach ($base as $key => $value) {
            if (!array_key_exists($key, $target)) {
                $missing[$key] = 'TODO: ' . (is_string($value) ? $value : json_encode($value));
            }
        }
        return $missing;
    }

    /**
     * Merge keys into target | 合并 key 到目标
     */
    protected function mergeKeys(array $target, array $missing): array
    {
        return array_merge($target, $missing);
    }

    /**
     * Create placeholder content | 创建占位内容
     */
    protected function createPlaceholder(array $base): array
    {
        $placeholder = [];
        foreach ($base as $key => $value) {
            $placeholder[$key] = 'TODO: ' . (is_string($value) ? $value : json_encode($value));
        }
        return $placeholder;
    }

    /**
     * Write array to PHP file | 写入数组到 PHP 文件
     */
    protected function writeFile(string $path, array $content): void
    {
        $export = var_export($content, true);
        $export = preg_replace('/^(  )+/m', '$0$0', $export);
        $export = preg_replace("/array \\(\n/", "[\n", $export);
        $export = preg_replace('/\\)$/', ']', $export);
        $export = preg_replace("/=> \n\\s+\\[/", '=> [', $export);

        $php = "<?php\n\ndeclare(strict_types=1);\n\n";
        $php .= "// Auto-generated by lang:sync command\n";
        $php .= "// 由 lang:sync 命令自动生成\n\n";
        $php .= "return {$export};\n";

        file_put_contents($path, $php);
    }
}
