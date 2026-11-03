<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Language Pack Key Consistency Check Command | 语言包 Key 一致性检查命令
 *
 * Checks that all language pack files have consistent keys across locales.
 * 检查所有语言包文件在不同语言版本中的 key 是否一致。
 *
 * Usage | 用法:
 *   php think lang:check              # Check all language packs
 *   php think lang:check --base=zh-cn # Use zh-cn as base locale
 *   php think lang:check --fix        # Show fix suggestions
 *
 * Exit codes | 退出码:
 *   0 - All keys are consistent | 所有 key 一致
 *   1 - Inconsistencies found | 发现不一致
 *
 * @package app\command
 */
class LangCheckCommand extends Command
{
    /**
     * Default base locale | 默认基准语言
     */
    protected const DEFAULT_BASE_LOCALE = 'zh-cn';

    /**
     * Configure command | 配置命令
     */
    protected function configure(): void
    {
        $this->setName('lang:check')
            ->setDescription('Check language pack key consistency | 检查语言包 key 一致性')
            ->addOption('base', 'b', Option::VALUE_OPTIONAL, 'Base locale for comparison', self::DEFAULT_BASE_LOCALE)
            ->addOption('fix', 'f', Option::VALUE_NONE, 'Show fix suggestions');
    }

    /**
     * Execute command | 执行命令
     */
    protected function execute(Input $input, Output $output): int
    {
        $baseLocale = $input->getOption('base');
        $showFix = $input->getOption('fix');

        $output->writeln('<info>Checking language pack consistency...</info>');
        $output->writeln('<info>检查语言包一致性...</info>');
        $output->writeln('');

        $langPath = $this->app->getAppPath() . 'lang/';

        if (!is_dir($langPath)) {
            $output->writeln('<error>Language directory not found: app/lang/</error>');
            return 1;
        }

        // Get all locales | 获取所有语言
        $locales = $this->getLocales($langPath);

        if (empty($locales)) {
            $output->writeln('<comment>No language packs found | 未找到语言包</comment>');
            return 0;
        }

        $output->writeln(sprintf('Found %d locales: %s', count($locales), implode(', ', $locales)));
        $output->writeln(sprintf('Base locale: %s', $baseLocale));
        $output->writeln('');

        if (!in_array($baseLocale, $locales)) {
            $output->writeln(sprintf('<error>Base locale "%s" not found</error>', $baseLocale));
            return 1;
        }

        // Load all language files | 加载所有语言文件
        $langData = $this->loadAllLanguageFiles($langPath, $locales);

        // Compare keys | 比较 key
        $issues = $this->compareKeys($langData, $baseLocale);

        // Output results | 输出结果
        $this->outputResults($output, $issues, $showFix);

        if (!empty($issues['missing_files']) || !empty($issues['missing_keys']) || !empty($issues['extra_keys'])) {
            $output->writeln('');
            $output->writeln('<error>Language pack inconsistencies found!</error>');
            $output->writeln('<error>发现语言包不一致！</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>✓ All language packs are consistent | 所有语言包一致</info>');
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
     * Load all language files | 加载所有语言文件
     */
    protected function loadAllLanguageFiles(string $langPath, array $locales): array
    {
        $data = [];

        foreach ($locales as $locale) {
            $localePath = $langPath . $locale . '/';
            $files = glob($localePath . '*.php');

            foreach ($files as $file) {
                $filename = basename($file, '.php');
                $content = include $file;

                if (is_array($content)) {
                    $data[$locale][$filename] = $this->flattenKeys($content);
                }
            }
        }

        return $data;
    }

    /**
     * Flatten nested array keys | 扁平化嵌套数组 key
     */
    protected function flattenKeys(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix ? $prefix . '.' . $key : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenKeys($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Compare keys across locales | 比较不同语言的 key
     */
    protected function compareKeys(array $langData, string $baseLocale): array
    {
        $issues = [
            'missing_files' => [],
            'extra_files' => [],
            'missing_keys' => [],
            'extra_keys' => [],
        ];

        $baseFiles = array_keys($langData[$baseLocale] ?? []);

        foreach ($langData as $locale => $files) {
            if ($locale === $baseLocale) {
                continue;
            }

            $localeFiles = array_keys($files);

            // Check missing files | 检查缺失文件
            $missingFiles = array_diff($baseFiles, $localeFiles);
            foreach ($missingFiles as $file) {
                $issues['missing_files'][] = [
                    'locale' => $locale,
                    'file' => $file,
                ];
            }

            // Check extra files | 检查多余文件
            $extraFiles = array_diff($localeFiles, $baseFiles);
            foreach ($extraFiles as $file) {
                $issues['extra_files'][] = [
                    'locale' => $locale,
                    'file' => $file,
                ];
            }

            // Check keys in common files | 检查共同文件中的 key
            $commonFiles = array_intersect($baseFiles, $localeFiles);
            foreach ($commonFiles as $file) {
                $baseKeys = array_keys($langData[$baseLocale][$file] ?? []);
                $localeKeys = array_keys($files[$file] ?? []);

                // Missing keys | 缺失的 key
                $missingKeys = array_diff($baseKeys, $localeKeys);
                foreach ($missingKeys as $key) {
                    $issues['missing_keys'][] = [
                        'locale' => $locale,
                        'file' => $file,
                        'key' => $key,
                        'base_value' => $langData[$baseLocale][$file][$key] ?? '',
                    ];
                }

                // Extra keys | 多余的 key
                $extraKeys = array_diff($localeKeys, $baseKeys);
                foreach ($extraKeys as $key) {
                    $issues['extra_keys'][] = [
                        'locale' => $locale,
                        'file' => $file,
                        'key' => $key,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Output comparison results | 输出比较结果
     */
    protected function outputResults(Output $output, array $issues, bool $showFix): void
    {
        $totalIssues = 0;

        // Missing files | 缺失文件
        if (!empty($issues['missing_files'])) {
            $output->writeln('<comment>Missing files (缺失文件):</comment>');
            foreach ($issues['missing_files'] as $issue) {
                $output->writeln(sprintf('  - %s: %s.php', $issue['locale'], $issue['file']));
                $totalIssues++;
            }
            $output->writeln('');
        }

        // Extra files | 多余文件
        if (!empty($issues['extra_files'])) {
            $output->writeln('<comment>Extra files (多余文件):</comment>');
            foreach ($issues['extra_files'] as $issue) {
                $output->writeln(sprintf('  + %s: %s.php', $issue['locale'], $issue['file']));
                $totalIssues++;
            }
            $output->writeln('');
        }

        // Missing keys | 缺失 key
        if (!empty($issues['missing_keys'])) {
            $output->writeln('<comment>Missing keys (缺失 key):</comment>');
            foreach ($issues['missing_keys'] as $issue) {
                $output->writeln(sprintf(
                    '  - %s/%s.php: %s',
                    $issue['locale'],
                    $issue['file'],
                    $issue['key']
                ));
                if ($showFix) {
                    $output->writeln(sprintf(
                        '    <info>Suggestion: \'%s\' => \'TODO: %s\'</info>',
                        $issue['key'],
                        $issue['base_value']
                    ));
                }
                $totalIssues++;
            }
            $output->writeln('');
        }

        // Extra keys | 多余 key
        if (!empty($issues['extra_keys'])) {
            $output->writeln('<comment>Extra keys (多余 key):</comment>');
            foreach ($issues['extra_keys'] as $issue) {
                $output->writeln(sprintf(
                    '  + %s/%s.php: %s',
                    $issue['locale'],
                    $issue['file'],
                    $issue['key']
                ));
                $totalIssues++;
            }
            $output->writeln('');
        }

        if ($totalIssues === 0) {
            $output->writeln('<info>No issues found | 未发现问题</info>');
        } else {
            $output->writeln(sprintf('Total issues: %d | 问题总数: %d', $totalIssues, $totalIssues));
        }
    }
}
