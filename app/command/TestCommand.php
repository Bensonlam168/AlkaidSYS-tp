<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Unified Test Entry Point Command | 统一测试入口命令
 *
 * Wraps PHPUnit execution with parameter passthrough support.
 * 封装 PHPUnit 执行，支持参数透传。
 *
 * Usage examples | 使用示例:
 *   php think test                           # Run all tests | 运行所有测试
 *   php think test --testsuite=Unit          # Run Unit tests only | 仅运行单元测试
 *   php think test --testsuite=Feature       # Run Feature tests only | 仅运行功能测试
 *   php think test --filter=testMethodName   # Filter by method name | 按方法名过滤
 *   php think test --coverage-html=coverage  # Generate HTML coverage | 生成 HTML 覆盖率报告
 *   php think test -c phpunit.performance.xml # Use custom config | 使用自定义配置
 *
 * @package app\command
 * @since T-056
 */
class TestCommand extends Command
{
    /**
     * PHPUnit executable path | PHPUnit 可执行文件路径
     */
    private const PHPUNIT_BIN = './vendor/bin/phpunit';

    /**
     * Default configuration file | 默认配置文件
     */
    private const DEFAULT_CONFIG = 'phpunit.xml';

    /**
     * Configure command | 配置命令
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('test')
            ->setDescription('Run PHPUnit tests | 运行 PHPUnit 测试')
            ->addOption(
                'testsuite',
                's',
                Option::VALUE_OPTIONAL,
                'Test suite name (Unit/Feature/Performance) | 测试套件名称',
                null
            )
            ->addOption(
                'filter',
                'f',
                Option::VALUE_OPTIONAL,
                'Filter tests by method/class name | 按方法/类名过滤测试',
                null
            )
            ->addOption(
                'coverage-html',
                null,
                Option::VALUE_OPTIONAL,
                'Generate HTML coverage report to directory | 生成 HTML 覆盖率报告到指定目录',
                null
            )
            ->addOption(
                'coverage-text',
                null,
                Option::VALUE_NONE,
                'Output text coverage report | 输出文本覆盖率报告'
            )
            ->addOption(
                'configuration',
                'c',
                Option::VALUE_OPTIONAL,
                'PHPUnit configuration file | PHPUnit 配置文件',
                self::DEFAULT_CONFIG
            )
            ->addOption(
                'stop-on-failure',
                null,
                Option::VALUE_NONE,
                'Stop on first failure | 首次失败时停止'
            )
            ->addOption(
                'stop-on-error',
                null,
                Option::VALUE_NONE,
                'Stop on first error | 首次错误时停止'
            )
            ->addOption(
                'phpunit-verbose',
                null,
                Option::VALUE_NONE,
                'PHPUnit verbose output | PHPUnit 详细输出'
            )
            ->addOption(
                'phpunit-debug',
                null,
                Option::VALUE_NONE,
                'PHPUnit debug mode | PHPUnit 调试模式'
            )
            ->addOption(
                'list-suites',
                null,
                Option::VALUE_NONE,
                'List available test suites | 列出可用的测试套件'
            )
            ->addOption(
                'passthru',
                'p',
                Option::VALUE_OPTIONAL,
                'Additional PHPUnit arguments | 额外的 PHPUnit 参数',
                null
            );
    }

    /**
     * Execute command | 执行命令
     *
     * @param Input $input Input instance | 输入实例
     * @param Output $output Output instance | 输出实例
     * @return int Exit code (0 = success, non-zero = failure) | 退出码
     */
    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('<info>AlkaidSYS Test Runner</info>');
        $output->writeln('');

        // Validate PHPUnit exists | 验证 PHPUnit 存在
        if (!file_exists(self::PHPUNIT_BIN)) {
            $output->writeln('<error>PHPUnit not found. Run "composer install" first.</error>');
            $output->writeln('<error>未找到 PHPUnit，请先运行 "composer install"。</error>');
            return 1;
        }

        // Build command arguments | 构建命令参数
        $args = $this->buildArguments($input);

        // Build full command | 构建完整命令
        $command = self::PHPUNIT_BIN . ' ' . implode(' ', $args);

        $output->writeln("<comment>Executing | 执行: {$command}</comment>");
        $output->writeln('');

        // Execute PHPUnit with passthru to preserve colors and exit code
        // 使用 passthru 执行 PHPUnit 以保留颜色和退出码
        passthru($command, $exitCode);

        $output->writeln('');
        if ($exitCode === 0) {
            $output->writeln('<info>All tests passed! | 所有测试通过！</info>');
        } else {
            $output->writeln('<error>Tests failed with exit code: ' . $exitCode . '</error>');
            $output->writeln('<error>测试失败，退出码: ' . $exitCode . '</error>');
        }

        return $exitCode;
    }

    /**
     * Build PHPUnit command arguments | 构建 PHPUnit 命令参数
     *
     * @param Input $input Input instance | 输入实例
     * @return array<string> Command arguments | 命令参数数组
     */
    private function buildArguments(Input $input): array
    {
        $args = [];

        // Configuration file | 配置文件
        $config = $input->getOption('configuration');
        if ($config && file_exists($config)) {
            $args[] = '--configuration';
            $args[] = escapeshellarg($config);
        }

        // Test suite | 测试套件
        if ($testsuite = $input->getOption('testsuite')) {
            $args[] = '--testsuite';
            $args[] = escapeshellarg($testsuite);
        }

        // Filter | 过滤
        if ($filter = $input->getOption('filter')) {
            $args[] = '--filter';
            $args[] = escapeshellarg($filter);
        }

        // Coverage HTML | HTML 覆盖率
        if ($coverageHtml = $input->getOption('coverage-html')) {
            $args[] = '--coverage-html';
            $args[] = escapeshellarg($coverageHtml);
        }

        // Coverage text | 文本覆盖率
        if ($input->getOption('coverage-text')) {
            $args[] = '--coverage-text';
        }

        // Stop on failure | 失败时停止
        if ($input->getOption('stop-on-failure')) {
            $args[] = '--stop-on-failure';
        }

        // Stop on error | 错误时停止
        if ($input->getOption('stop-on-error')) {
            $args[] = '--stop-on-error';
        }

        // Verbose | 详细输出
        if ($input->getOption('phpunit-verbose')) {
            $args[] = '--verbose';
        }

        // Debug | 调试模式
        if ($input->getOption('phpunit-debug')) {
            $args[] = '--debug';
        }

        // List suites | 列出套件
        if ($input->getOption('list-suites')) {
            $args[] = '--list-suites';
        }

        // Passthru additional arguments | 透传额外参数
        if ($passthru = $input->getOption('passthru')) {
            $args[] = $passthru;
        }

        // Always use colors | 始终使用颜色
        $args[] = '--colors=always';

        return $args;
    }
}
