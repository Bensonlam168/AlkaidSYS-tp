<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Environment Variable Completeness Check Command | 环境变量完整性检查命令
 *
 * Checks that all required environment variables are set and have valid values.
 * 检查所有必需的环境变量是否已设置且值有效。
 *
 * Usage | 用法:
 *   php think env:check              # Check environment variables
 *   php think env:check --strict     # Fail on warnings too
 *   php think env:check --generate   # Generate documentation template
 *
 * Exit codes | 退出码:
 *   0 - All required variables are set | 所有必需变量已设置
 *   1 - Required variables missing | 缺少必需变量
 *
 * @package app\command
 */
class EnvCheckCommand extends Command
{
    /**
     * Environment variable definitions | 环境变量定义
     *
     * Format: [
     *   'name' => [
     *     'required' => bool,      // 是否必需
     *     'type' => 'string|int|bool|array', // 类型
     *     'default' => mixed,      // 默认值
     *     'description' => string, // 描述
     *     'category' => string,    // 分类
     *     'production_required' => bool, // 生产环境必需
     *   ]
     * ]
     */
    protected array $envDefinitions = [];

    /**
     * Configure command | 配置命令
     */
    protected function configure(): void
    {
        $this->setName('env:check')
            ->setDescription('Check environment variable completeness | 检查环境变量完整性')
            ->addOption('strict', 's', Option::VALUE_NONE, 'Fail on warnings too | 警告也视为失败')
            ->addOption('generate', 'g', Option::VALUE_NONE, 'Generate documentation template | 生成文档模板');
    }

    /**
     * Execute command | 执行命令
     */
    protected function execute(Input $input, Output $output): int
    {
        $this->loadEnvDefinitions();

        $strict = $input->getOption('strict');
        $generate = $input->getOption('generate');

        if ($generate) {
            return $this->generateDocumentation($output);
        }

        $output->writeln('<info>Checking environment variables...</info>');
        $output->writeln('<info>检查环境变量...</info>');
        $output->writeln('');

        $appEnv = strtolower((string) env('APP_ENV', 'local'));
        $isProduction = in_array($appEnv, ['production', 'prod', 'stage', 'staging'], true);

        $output->writeln(sprintf('Current environment: <comment>%s</comment>', $appEnv));
        $output->writeln('');

        $errors = [];
        $warnings = [];
        $categories = $this->groupByCategory();

        foreach ($categories as $category => $vars) {
            $output->writeln(sprintf('<comment>== %s ==</comment>', $category));

            foreach ($vars as $name => $definition) {
                $result = $this->checkVariable($name, $definition, $isProduction);

                if ($result['status'] === 'error') {
                    $errors[] = $result;
                    $output->writeln(sprintf('  <error>✗</error> %s: %s', $name, $result['message']));
                } elseif ($result['status'] === 'warning') {
                    $warnings[] = $result;
                    $output->writeln(sprintf('  <comment>⚠</comment> %s: %s', $name, $result['message']));
                } else {
                    $output->writeln(sprintf('  <info>✓</info> %s', $name));
                }
            }

            $output->writeln('');
        }

        // Summary | 汇总
        $this->outputSummary($output, $errors, $warnings);

        if (!empty($errors)) {
            $output->writeln('<error>Environment check failed! 环境检查失败！</error>');
            return 1;
        }

        if ($strict && !empty($warnings)) {
            $output->writeln('<error>Environment check failed (strict mode)! 环境检查失败（严格模式）！</error>');
            return 1;
        }

        $output->writeln('<info>✓ Environment check passed | 环境检查通过</info>');
        return 0;
    }

    /**
     * Load environment variable definitions | 加载环境变量定义
     */
    protected function loadEnvDefinitions(): void
    {
        $this->envDefinitions = [
            // Application | 应用
            'APP_ENV' => [
                'required' => true, 'type' => 'string', 'default' => 'local',
                'description' => 'Application environment | 应用环境',
                'category' => 'Application',
                'valid_values' => ['local', 'dev', 'development', 'testing', 'staging', 'stage', 'production', 'prod'],
            ],
            'APP_DEBUG' => [
                'required' => true, 'type' => 'bool', 'default' => true,
                'description' => 'Debug mode | 调试模式',
                'category' => 'Application',
                'production_warning' => 'Should be false in production | 生产环境应为 false',
            ],
            'DEFAULT_LANG' => [
                'required' => false, 'type' => 'string', 'default' => 'zh-cn',
                'description' => 'Default language | 默认语言',
                'category' => 'Application',
            ],
        ];

        // Add more definitions | 添加更多定义
        $this->addDatabaseDefinitions();
        $this->addJwtDefinitions();
        $this->addRedisDefinitions();
        $this->addCasbinDefinitions();
        $this->addRateLimitDefinitions();
        $this->addCorsDefinitions();
    }

    /**
     * Add database definitions | 添加数据库定义
     */
    protected function addDatabaseDefinitions(): void
    {
        $dbVars = [
            'DB_TYPE' => ['required' => false, 'default' => 'mysql'],
            'DB_DRIVER' => ['required' => false, 'default' => 'mysql'],
            'DB_HOST' => ['required' => true, 'default' => 'mysql'],
            'DB_PORT' => ['required' => false, 'default' => 3306, 'type' => 'int'],
            'DB_NAME' => ['required' => true, 'default' => ''],
            'DB_USER' => ['required' => true, 'default' => 'root'],
            'DB_PASS' => ['required' => true, 'default' => '', 'production_required' => true],
            'DB_CHARSET' => ['required' => false, 'default' => 'utf8mb4'],
            'DB_PREFIX' => ['required' => false, 'default' => ''],
        ];
        foreach ($dbVars as $name => $def) {
            $def['category'] = 'Database';
            $def['description'] = "Database: {$name}";
            $this->envDefinitions[$name] = $def;
        }
    }

    /**
     * Add JWT definitions | 添加 JWT 定义
     */
    protected function addJwtDefinitions(): void
    {
        $this->envDefinitions['JWT_SECRET'] = [
            'required' => true, 'default' => 'CHANGE_THIS_IN_PRODUCTION',
            'description' => 'JWT signing secret | JWT 签名密钥',
            'category' => 'Security', 'production_required' => true,
            'production_warning' => 'Must be changed from default',
        ];
        $this->envDefinitions['JWT_ISSUER'] = [
            'required' => true, 'default' => 'https://api.alkaidsys.local',
            'description' => 'JWT issuer (iss) | JWT 签发者',
            'category' => 'Security',
        ];
    }

    /**
     * Add Redis definitions | 添加 Redis 定义
     */
    protected function addRedisDefinitions(): void
    {
        $redisVars = [
            'REDIS_HOST' => ['required' => true, 'default' => 'redis'],
            'REDIS_PORT' => ['required' => false, 'default' => 6379, 'type' => 'int'],
            'REDIS_PASSWORD' => ['required' => false, 'default' => ''],
            'REDIS_DB' => ['required' => false, 'default' => 0, 'type' => 'int'],
            'CACHE_DRIVER' => ['required' => false, 'default' => 'file'],
        ];
        foreach ($redisVars as $name => $def) {
            $def['category'] = 'Redis/Cache';
            $def['description'] = "Redis/Cache: {$name}";
            $this->envDefinitions[$name] = $def;
        }
    }

    /**
     * Add Casbin definitions | 添加 Casbin 定义
     */
    protected function addCasbinDefinitions(): void
    {
        $vars = [
            'CASBIN_ENABLED' => ['required' => false, 'default' => false, 'type' => 'bool'],
            'CASBIN_MODE' => ['required' => false, 'default' => 'DB_ONLY'],
            'CASBIN_LOG_ENABLED' => ['required' => false, 'default' => false, 'type' => 'bool'],
            'CASBIN_CACHE_ENABLED' => ['required' => false, 'default' => true, 'type' => 'bool'],
        ];
        foreach ($vars as $name => $def) {
            $def['category'] = 'Authorization';
            $def['description'] = "Casbin: {$name}";
            $this->envDefinitions[$name] = $def;
        }
    }

    /**
     * Add rate limit definitions | 添加限流定义
     */
    protected function addRateLimitDefinitions(): void
    {
        $vars = [
            'RATELIMIT_ENABLED' => ['required' => false, 'default' => false, 'type' => 'bool'],
            'RATELIMIT_STORE' => ['required' => false, 'default' => 'redis'],
        ];
        foreach ($vars as $name => $def) {
            $def['category'] = 'Rate Limiting';
            $def['description'] = "Rate Limit: {$name}";
            $this->envDefinitions[$name] = $def;
        }
    }

    /**
     * Add CORS definitions | 添加 CORS 定义
     */
    protected function addCorsDefinitions(): void
    {
        $this->envDefinitions['CORS_ALLOWED_ORIGINS'] = [
            'required' => false, 'default' => '',
            'description' => 'CORS allowed origins | CORS 允许的源',
            'category' => 'Security',
            'production_warning' => 'Should be explicitly set in production',
        ];
    }

    /**
     * Check a single variable | 检查单个变量
     */
    protected function checkVariable(string $name, array $definition, bool $isProduction): array
    {
        $value = env($name);
        $hasValue = $value !== null && $value !== '';
        $default = $definition['default'] ?? null;
        $required = $definition['required'] ?? false;
        $prodRequired = $definition['production_required'] ?? false;
        $prodWarning = $definition['production_warning'] ?? null;

        // Check required | 检查必需
        if ($required && !$hasValue && $default === '') {
            return ['status' => 'error', 'message' => 'Required but not set | 必需但未设置'];
        }

        // Production-specific checks | 生产环境特定检查
        if ($isProduction) {
            if ($prodRequired && !$hasValue) {
                return ['status' => 'error', 'message' => 'Required in production | 生产环境必需'];
            }
            if ($prodWarning && ($value === $default || !$hasValue)) {
                return ['status' => 'warning', 'message' => $prodWarning];
            }
        }

        return ['status' => 'ok', 'message' => ''];
    }

    /**
     * Group definitions by category | 按分类分组定义
     */
    protected function groupByCategory(): array
    {
        $categories = [];
        foreach ($this->envDefinitions as $name => $definition) {
            $category = $definition['category'] ?? 'Other';
            $categories[$category][$name] = $definition;
        }
        return $categories;
    }

    /**
     * Output summary | 输出汇总
     */
    protected function outputSummary(Output $output, array $errors, array $warnings): void
    {
        $output->writeln('<comment>== Summary ==</comment>');
        $output->writeln(sprintf('  Total variables checked: %d', count($this->envDefinitions)));
        $output->writeln(sprintf('  Errors: <error>%d</error>', count($errors)));
        $output->writeln(sprintf('  Warnings: <comment>%d</comment>', count($warnings)));
        $output->writeln('');
    }

    /**
     * Generate documentation | 生成文档
     */
    protected function generateDocumentation(Output $output): int
    {
        $this->loadEnvDefinitions();
        $output->writeln('# Environment Variables Documentation');
        $output->writeln('');
        $categories = $this->groupByCategory();

        foreach ($categories as $category => $vars) {
            $output->writeln(sprintf('## %s', $category));
            $output->writeln('');
            $output->writeln('| Variable | Required | Default | Description |');
            $output->writeln('|----------|----------|---------|-------------|');

            foreach ($vars as $name => $def) {
                $required = ($def['required'] ?? false) ? 'Yes' : 'No';
                $default = $def['default'] ?? '';
                $desc = $def['description'] ?? '';
                $output->writeln(sprintf('| `%s` | %s | `%s` | %s |', $name, $required, $default, $desc));
            }
            $output->writeln('');
        }

        return 0;
    }
}
