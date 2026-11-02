<?php

declare(strict_types=1);

namespace app\command;

use app\AppService;
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
 * ## 生产必需项（`production_required` + `production_rules`）
 *
 * 背景：`DEFAULT_TENANT_ID` / `DB_*` / `JWT_SECRET` / `APP_DEBUG` 这类配置错了**不会**让
 * 进程启动失败，只会在运行期以「所有请求 500 / 每个鉴权请求被拒 / 错误页泄露堆栈」的形式暴露。
 * 也就是说，故障发生在**部署之后、第一个请求之上**。本命令的目标是把这类判定提前到部署前
 * （CI 的部署前置 job、发布脚本里的 smoke check、或发布前手工执行一次）。
 *
 * 语义分三层：
 *   1. `production_required => true`：该键在生产类环境必须被显式配置（非空）；
 *   2. `production_rules`：对**取值本身**的校验（占位符 / 正整数 / 最小长度 / 禁 root / 必须 false）。
 *      每条规则带一个「非生产环境的处理方式」：
 *        · `warn`   —— 非生产环境也提示（用于「配错了在哪个环境都是错的」的规则，如占位符未替换）；
 *        · `ignore` —— 非生产环境静默（用于「本地就该这样」的规则，
 *                      如 dev 里 `DB_USER=root`、`DB_PASS=root`、`APP_DEBUG=true` 是文档化的默认值，
 *                      对它们刷告警只会让开发者习惯性忽略输出）。
 *   3. 条件必需项（见 {@see self::checkConditionalRequirements()}）：不硬性必填，只在条件成立时告警，
 *      例如 `TRUSTED_PROXIES` 仅当「生产类环境 + 开启应用层限流」时必需。
 *
 * 只在生产类环境（production / prod / stage / staging）**阻断**；非生产环境同一套规则降级为
 * 告警（`warn` 级规则）或静默（`ignore` 级规则），不会让本地 `env:check` 变红。
 *
 * 脱敏（硬要求）：本命令的输出会进 CI 日志，口令 / 密钥类键（键名含 PASS / SECRET / PASSWORD /
 * TOKEN / KEY）一律只显示「前 2 个字符 + 总长度」，绝不打印完整值。
 *
 * Exit codes | 退出码:
 *   0 - All required variables are set | 所有必需变量已设置
 *   1 - Required variables missing | 缺少必需变量、生产必需项不合格，或 .env 存在但无法解析（R37）
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
     *     'required' => bool,      // 是否必需（全环境，历史语义）
     *     'type' => 'string|int|bool|array', // 类型
     *     'default' => mixed,      // 默认值
     *     'description' => string, // 描述
     *     'category' => string,    // 分类
     *     'production_required' => bool, // 生产环境必需（必须显式配置，不能为空）
     *     'production_rules' => [        // 生产环境的取值规则：规则名 => 非生产环境处理方式（warn|ignore）
     *         'not_empty' => 'warn',       // 空值
     *         'not_placeholder' => 'warn', // 命中占位符（REPLACE_* / CHANGE_THIS / DEV_ONLY / your-... 等）
     *         'positive_int' => 'warn',    // 必须是正整数
     *         'min_length:32' => 'ignore', // 最小长度（字节）
     *         'not_root' => 'ignore',      // 禁止 MySQL 超级账号 root
     *         'not_dev_default_credential' => 'ignore', // 禁止仓库内公开的 dev 默认口令
     *         'bool_false' => 'ignore',    // 生产环境必须为 false
     *     ],
     *     'production_hint' => string,      // 不合格时「怎么改」的提示（会原样打印）
     *     'production_warning' => string,   // 生产环境告警文案（历史字段；与 production_rules 不要重复声明同一问题）
     *     'valid_values' => array,          // 允许的取值（不匹配时只告警，不阻断）
     *   ]
     * ]
     */
    protected array $envDefinitions = [];

    /**
     * 敏感键名模式：输出脱敏用（键名命中即视为口令/密钥）
     *
     * 本命令的输出会进 CI 日志，密钥/口令一旦被完整打印就等于泄露 —— 所以脱敏是硬要求，
     * 而不是「尽量别打印」。
     */
    protected const SENSITIVE_NAME_PATTERN = '/(?:SECRET|PASSWORD|PASS|TOKEN|PRIVATE_KEY|API_KEY)/i';

    /**
     * 占位符标记（大小写不敏感的子串匹配）
     *
     * 覆盖两类真实事故：
     *   1. 部署模板里的 `REPLACE_WITH_*` / `your-....example.com` 忘替换；
     *   2. `.env.example` 与 `JwtService::DEV_FALLBACK_SECRET` 这类**仓库内公开**的 dev 默认值
     *      被原样带上生产（等于公开密钥）。
     */
    protected const PLACEHOLDER_MARKERS = [
        'REPLACE', 'CHANGE_THIS', 'CHANGE_ME', 'YOUR_', 'YOUR-', 'EXAMPLE.COM',
        'PLACEHOLDER', 'DEV_ONLY', 'DEV-ONLY', 'TODO', 'FIXME', 'XXX',
    ];

    /**
     * 仓库内公开的 dev 默认账号/口令
     *
     * 取值来源：`.env.example`（root / root，以及注释里提到的业务账号 alkaid / alkaid123）
     * 与 `docker-compose.yml` 的 mysql 服务。它们对全网公开，生产环境不得复用。
     */
    protected const DEV_DEFAULT_CREDENTIALS = ['root', 'alkaid123', 'password', '123456'];

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
        // R37：先把「.env 自身能否被解析」的结论拿到手，必须早于任何变量检查。
        // 背景：think\Env::load() 的实现是 `parse_ini_file($file, true, INI_SCANNER_RAW) ?: []`，
        // .env 一旦有语法错误（典型：注释行含半角括号），拿到的就是空数组、所有键静默失效。
        // 此时若继续逐变量检查，会把「一个键都读不到」误报成几十条「必需但未设置」，掩盖真正的原因。
        $envFileReport = $this->inspectEnvFile();

        $this->loadEnvDefinitions();

        $strict = $input->getOption('strict');
        $generate = $input->getOption('generate');

        // `--generate` 只输出文档模板、不读 .env 的取值，因此不受「.env 解析失败」影响
        if ($generate) {
            return $this->generateDocumentation($output);
        }

        $output->writeln('<info>Checking environment variables...</info>');
        $output->writeln('<info>检查环境变量...</info>');
        $output->writeln('');

        $errors = [];
        $warnings = [];

        // R37：.env 存在但解析失败 → 计入 error 汇总，并跳过后续变量检查
        // （跳过是刻意的：所有键都读不到，继续检查只会刷出一堆误导性的「必需但未设置」）
        if ($envFileReport['exists'] && !$envFileReport['parsed']) {
            $errors[] = [
                'status'  => 'error',
                'message' => sprintf(
                    '.env 存在但无法解析（行号 %s）：%s',
                    $envFileReport['line'] === null ? '未知' : $envFileReport['line'],
                    $envFileReport['error'] ?? '未知错误'
                ),
            ];
            $this->outputEnvFileParseError($output, $envFileReport);
            $output->writeln('');
            $this->outputSummary($output, $errors, $warnings, 0);
            $output->writeln('<error>Environment check failed! 环境检查失败！</error>');
            return 1;
        }

        // 解析正常 / 文件不存在（容器可以只用环境变量注入配置）→ 一行确认，沿用既有 ✓/⚠ 风格
        if ($envFileReport['exists']) {
            $output->writeln(sprintf('  <info>✓</info> .env parsed | .env 解析正常 (%s)', $envFileReport['path']));
        } else {
            $output->writeln(sprintf(
                '  <comment>⚠</comment> .env not found, file parse check skipped | 未找到 .env，跳过文件解析检查 (%s)',
                $envFileReport['path']
            ));
        }
        $output->writeln('');

        $appEnv = strtolower((string) env('APP_ENV', 'local'));
        $isProduction = in_array($appEnv, ['production', 'prod', 'stage', 'staging'], true);

        $output->writeln(sprintf('Current environment: <comment>%s</comment>', $appEnv));

        // APP_ENV 未显式设置（或显式留空）时不能沉默：本命令按 local 处理（跳过生产必需项校验），
        // 而 JwtService / HealthController 把「未设置 / 空值」当 production（失败安全）。
        // 两者语义不一致，部署侧若漏配 APP_ENV，就会出现「部署前检查通过、运行期每个鉴权请求被拒
        // 或 readiness 一直不 ok」—— 这种矛盾必须显式说出来，而不是让检查静默放过。
        $rawAppEnv = env('APP_ENV');

        if ($rawAppEnv === null || trim($this->valueToString($rawAppEnv)) === '') {
            $appEnvNotice = [
                'name'    => 'APP_ENV',
                'status'  => 'warning',
                'message' => '未设置或为空：本命令按 local 处理（不做生产必需项校验），'
                    . '但 JwtService / HealthController 按 production 处理（会拒绝占位密钥、要求 APP_DEBUG=false）'
                    . "\n" . '      修复: 部署时显式设置 APP_ENV=production（预发取 stage / staging）',
            ];
            $warnings[] = $appEnvNotice;
            $output->writeln(sprintf('  <comment>⚠</comment> APP_ENV: %s', $appEnvNotice['message']));
        }

        $output->writeln('');

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

        // 条件必需项：不参与上面的「N 个变量」统计，单独一段说明「什么条件下才必需」
        $output->writeln('<comment>== Conditional (production) | 条件必需项 ==</comment>');
        foreach ($this->checkConditionalRequirements($isProduction) as $conditional) {
            if ($conditional['status'] === 'warning') {
                // 计入 warnings：不改变退出码，但 `--strict` 下会失败（严格模式 = 你要求生产级配置）
                $warnings[] = $conditional;
                $output->writeln(sprintf(
                    '  <comment>⚠</comment> %s: %s',
                    $conditional['name'],
                    $conditional['message']
                ));
                continue;
            }

            if ($conditional['status'] === 'ok') {
                $output->writeln(sprintf(
                    '  <info>✓</info> %s: %s',
                    $conditional['name'],
                    $conditional['message']
                ));
                continue;
            }

            $output->writeln(sprintf('  · %s: %s', $conditional['name'], $conditional['message']));
        }
        $output->writeln('');

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
                // 生产环境必须为 false：debug 会把异常堆栈、SQL、绝对路径直接回显给调用方
                // （与 HealthController::checkConfig() 的就绪判定同源，这里提前到部署前）。
                // 注：原先用 production_warning 只告警，现升级为生产环境阻断 —— warning 版本的问题是
                // 「不 --strict 就永远绿」，而它恰恰是部署前必须拦下的那类配置。
                'production_required' => true,
                'production_rules' => [
                    // 未设置时是危险的：config/database.php 的 trigger_sql 缺省取 true
                    'not_empty' => 'warn',
                    'bool_false' => 'ignore',
                ],
                'production_hint' => '生产必须显式写 APP_DEBUG=false（未设置时 trigger_sql 缺省为 true；'
                    . '为 true 会把堆栈、SQL、绝对路径回显给调用方）',
            ],
            // 默认租户：`TenantIdentify` 是全局中间件，未配置即 fail-closed 抛异常，
            // 表现为「所有不带 X-Tenant-ID 的请求（含探针）全部 500」，且在**第一个请求**才暴露。
            // 它此前完全不在本命令的定义表里 —— 部署期没有任何工具会提示它没被替换。
            'DEFAULT_TENANT_ID' => [
                'required' => false, 'type' => 'int', 'default' => '',
                'description' => 'Default tenant ID (no X-Tenant-ID header) | 默认租户 ID',
                'category' => 'Application',
                'production_required' => true,
                'production_rules' => [
                    'not_empty' => 'warn',
                    'not_placeholder' => 'warn',
                    'positive_int' => 'warn',
                ],
                'production_hint' => 'DEFAULT_TENANT_ID 必须是 tenants 表中真实存在的租户 ID（正整数），例如 1；'
                    . '未配置或非正整数时 TenantIdentify 会 fail-closed，所有不带 X-Tenant-ID 的请求返回 500',
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
        // 生产必需项的判定依据（逐个给理由，而不是「看着像必需就加」）：
        //   · DB_HOST / DB_NAME：写错或留占位符 → 连不上库，全站 500（进程仍能启动）；
        //   · DB_USER：留 root 等于以全库权限运行应用，一次 SQL 注入即整库失守；
        //   · DB_PASS：留空 / 复用仓库内公开的 dev 默认口令 → 等于没有口令。
        // 反向例子（**刻意不**纳入必需集合）：DB_PORT / DB_CHARSET / DB_PREFIX / DB_TYPE ——
        // 它们有安全且通用的默认值（3306 / utf8mb4 / 空前缀 / mysql），属于调优或部署细节，
        // 强行必填只会制造「为了过检查而填」的假配置。
        $dbVars = [
            'DB_TYPE' => ['required' => false, 'default' => 'mysql'],
            'DB_DRIVER' => ['required' => false, 'default' => 'mysql'],
            'DB_HOST' => [
                'required' => true, 'default' => 'mysql',
                'production_required' => true,
                'production_rules' => ['not_empty' => 'warn', 'not_placeholder' => 'warn'],
                'production_hint' => 'DB_HOST 必须是生产数据库的真实主机名；'
                    . '模板里的 your-....example.com 之类占位值会导致「容器起来了但连不上库」',
            ],
            'DB_PORT' => ['required' => false, 'default' => 3306, 'type' => 'int'],
            'DB_NAME' => [
                'required' => true, 'default' => '',
                'production_required' => true,
                'production_rules' => ['not_empty' => 'warn', 'not_placeholder' => 'warn'],
                'production_hint' => 'DB_NAME 必须是生产库的真实库名（端口能连上不代表库名对，'
                    . '留 REPLACE_WITH_* 会在第一个查询时报 Unknown database）',
            ],
            'DB_USER' => [
                'required' => true, 'default' => 'root',
                'production_required' => true,
                'production_rules' => ['not_empty' => 'warn', 'not_placeholder' => 'warn', 'not_root' => 'ignore'],
                'production_hint' => 'DB_USER 请改用最小权限的业务账号（如 alkaid），不要用 root：'
                    . 'root 拥有全库权限，应用侧任何注入或泄露都直接扩散到整库',
            ],
            'DB_PASS' => [
                'required' => true, 'default' => '',
                'production_required' => true,
                'production_rules' => [
                    'not_empty' => 'warn',
                    'not_placeholder' => 'warn',
                    // root / alkaid123 是 .env.example 与 docker compose 里的公开字符串，本地必须能用
                    'not_dev_default_credential' => 'ignore',
                ],
                'production_hint' => 'DB_PASS 必须换成独立强口令（例如 openssl rand -base64 24）；'
                    . '仓库内的 dev 默认口令对全网公开，等于没有口令',
            ],
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
            'category' => 'Security',
            'production_required' => true,
            // 判定与 JwtService::assertProductionSecretIsSecure() 对齐（同一条安全约束，
            // 只是提前到部署前；那边是「第一个鉴权请求被拒」，这里是「部署前直接红」）。
            // 原先只声明 production_warning，非 --strict 时永远是绿的 —— 等于没检查。
            'production_rules' => [
                'not_empty' => 'warn',
                'not_placeholder' => 'warn',
                'min_length:32' => 'ignore',
            ],
            'production_hint' => 'JWT_SECRET 生产必须替换为 ≥32 字节的随机串（openssl rand -hex 32）；'
                . '沿用 CHANGE_THIS_IN_PRODUCTION / REPLACE_WITH_* / dev-only-* 等于任何人可伪造任意用户 token',
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
     *
     * @param string $name         变量名
     * @param array  $definition   变量定义
     * @param bool   $isProduction 是否生产类环境
     * @return array{status: string, message: string}
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

        // 取值词表（目前只有 APP_ENV）：`valid_values` 此前只声明、从不执行，于是
        // APP_ENV=prodcution 这类笔误会让「是否生产类环境」判成 false，
        // 下面所有生产必需项校验被**整体静默跳过** —— 部署前检查照样全绿。
        // 这里改成告警：可见但不阻断（阻断会把使用自定义环境名 qa / pre 的既有部署直接打红）。
        if (!empty($definition['valid_values']) && $hasValue) {
            $normalized = strtolower(trim($this->valueToString($value)));
            $allowed = array_map(
                static fn ($item): string => strtolower((string) $item),
                (array) $definition['valid_values']
            );

            if (!in_array($normalized, $allowed, true)) {
                return [
                    'status'  => 'warning',
                    'message' => sprintf(
                        '取值「%s」不在已知词表（%s）内：无法判定是否生产类环境，'
                        . '生产必需项校验可能被整体跳过 | unknown APP_ENV',
                        $normalized,
                        implode(' / ', $allowed)
                    ),
                ];
            }
        }

        // 生产必需项的**取值**规则（占位符 / 正整数 / 最小长度 / 禁 root / 必须 false）
        $ruleResult = $this->checkProductionRules($name, $value, $definition, $isProduction);

        if ($ruleResult !== null) {
            return $ruleResult;
        }

        // Production-specific checks | 生产环境特定检查
        if ($isProduction) {
            // 历史语义兜底：声明了 production_required 却没有配置任何 production_rules 的键，
            // 仍然只要求「显式配置、不能为空」（保持向后兼容，不因新增规则引擎改变旧键的行为）。
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
     * 生产必需项的取值规则校验 | Production value rule checks
     *
     * 关键语义：**只有生产类环境才阻断**。非生产环境按各规则的「处理方式」降级：
     *   · warn   —— 输出告警（不改变退出码，`--strict` 下才失败）
     *   · ignore —— 完全静默（留给「本地就该这样」的值）
     *
     * @param string $name         键名
     * @param mixed  $value        当前取值
     * @param array  $definition   变量定义
     * @param bool   $isProduction 是否生产类环境
     * @return array{status: string, message: string}|null 全部通过（或没有规则）时返回 null
     */
    protected function checkProductionRules(
        string $name,
        mixed $value,
        array $definition,
        bool $isProduction
    ): ?array {
        $rules = $definition['production_rules'] ?? [];

        if (!is_array($rules) || $rules === []) {
            return null;
        }

        $failures = [];
        $notices  = [];

        foreach ($rules as $rule => $devMode) {
            $rule = (string) $rule;

            // 规则名写错时必须可见，不能静默通过（fail-closed）：这条只可能来自本命令自身的配置错误。
            if (!$this->isKnownProductionRule($rule)) {
                $failures[] = sprintf('规则「%s」未实现，无法判定（命令自身的配置错误）', $rule);
                continue;
            }

            $reason = $this->evaluateProductionRule($rule, $value);

            if ($reason === null) {
                continue;
            }

            if ($isProduction) {
                $failures[] = $reason;
                continue;
            }

            if ($devMode === 'warn') {
                $notices[] = $reason;
            }
        }

        if ($failures !== []) {
            return [
                'status'  => 'error',
                'message' => $this->formatProductionMessage($name, $value, $failures, $definition, true),
            ];
        }

        if ($notices !== []) {
            return [
                'status'  => 'warning',
                'message' => $this->formatProductionMessage($name, $value, $notices, $definition, false),
            ];
        }

        return null;
    }

    /**
     * 规则名是否已实现 | Is the rule implemented
     *
     * @param string $rule 规则名（`min_length:32` 形式带参数）
     */
    protected function isKnownProductionRule(string $rule): bool
    {
        if (in_array(
            $rule,
            ['not_empty', 'not_placeholder', 'positive_int', 'not_root', 'not_dev_default_credential', 'bool_false'],
            true
        )) {
            return true;
        }

        return preg_match('/^min_length:\d+$/', $rule) === 1;
    }

    /**
     * 执行单条生产规则 | Evaluate one production rule
     *
     * @param string $rule  规则名
     * @param mixed  $value 当前取值
     * @return string|null 不合格时返回「原因」文案（可直接打印，**不含**敏感值原值）；通过返回 null
     */
    protected function evaluateProductionRule(string $rule, mixed $value): ?string
    {
        $text = trim($this->valueToString($value));

        switch ($rule) {
            case 'not_empty':
                return $text === '' ? '未配置或为空：生产环境不允许留空' : null;

            case 'not_placeholder':
                $upper = strtoupper($text);
                foreach (self::PLACEHOLDER_MARKERS as $marker) {
                    if (str_contains($upper, $marker)) {
                        return sprintf(
                            '命中占位标记「%s」：这是模板/示例值，必须替换为真实配置',
                            $marker
                        );
                    }
                }

                return null;

            case 'positive_int':
                return preg_match('/^[1-9][0-9]*$/', $text) === 1
                    ? null
                    : '必须是正整数（> 0）：0、负数、小数与非数字都会被中间件判为「未配置」';

            case 'not_root':
                return strtolower($text) === 'root'
                    ? '不允许使用 MySQL 超级账号 root：应用以全库权限运行，一次注入或泄露即整库失守'
                    : null;

            case 'not_dev_default_credential':
                return in_array(strtolower($text), self::DEV_DEFAULT_CREDENTIALS, true)
                    ? '仍是仓库内公开的 dev 默认口令（见 .env.example / docker compose），等于全网已知'
                    : null;

            case 'bool_false':
                // null / 未设置交给 not_empty 报，避免同一个键刷两条重复原因
                if ($text === '') {
                    return null;
                }

                return filter_var($value, FILTER_VALIDATE_BOOL) === false
                    ? null
                    : '生产环境必须为 false：true 会把异常堆栈、SQL 与绝对路径回显给调用方';

            default:
                if (preg_match('/^min_length:(\d+)$/', $rule, $matches) === 1) {
                    $min = (int) $matches[1];

                    return strlen($text) >= $min
                        ? null
                        : sprintf('长度不足：当前 %d 字节，要求 ≥ %d 字节（建议 openssl rand -hex 32 生成）', strlen($text), $min);
                }

                return null;
        }
    }

    /**
     * 组装「生产必需项不合格」的多行文案
     *
     * 逐条给出：哪个键（调用方已输出 `✗ 键名:` 前缀）、当前值（口令/密钥脱敏）、
     * 为什么不合格、怎么改。输出会被贴进 CI 日志，因此这里绝不拼接完整口令/密钥。
     *
     * @param string   $name         键名
     * @param mixed    $value        当前取值
     * @param string[] $reasons      不合格原因
     * @param array    $definition   变量定义
     * @param bool     $isProduction 是否生产类环境（决定文案：阻断 / 仅提示）
     */
    protected function formatProductionMessage(
        string $name,
        mixed $value,
        array $reasons,
        array $definition,
        bool $isProduction
    ): string {
        $lines = [sprintf(
            '%s（%d 条）| current: %s',
            $isProduction
                ? '生产必需项不合格'
                : '生产环境会失败，当前非生产环境仅提示 | would fail in production',
            count($reasons),
            $this->describeValue($name, $value)
        )];

        foreach ($reasons as $reason) {
            $lines[] = '      · ' . $reason;
        }

        $hint = (string) ($definition['production_hint'] ?? '');

        if ($hint !== '') {
            $lines[] = '      修复: ' . $hint;
        }

        return implode("\n", $lines);
    }

    /**
     * 把任意 env 取值转成用于比较/打印的字符串 | Stringify an env value
     *
     * ThinkPHP 的 `env()` 会把 `true/false/on/off` 转成 bool，这里统一还原成文本，
     * 保证规则判定与输出展示看到的是同一个东西。
     */
    protected function valueToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * 输出用的取值描述（敏感键脱敏）| Describe a value for output (masked when sensitive)
     *
     * 脱敏规则（硬要求）：键名命中 SENSITIVE_NAME_PATTERN 时只保留**前 2 个字符 + 总长度**。
     * 非敏感键全量显示，但会截断到 64 字符并去掉控制字符，避免一条日志变成多行、污染 CI 日志。
     *
     * @param string $name  键名
     * @param mixed  $value 当前取值
     */
    protected function describeValue(string $name, mixed $value): string
    {
        // 控制字符（含换行）会让一条日志裂成多行，先替换掉；刻意不加 /u：
        // 非 UTF-8 的旧口令在 /u 模式下会让 preg_replace 返回 null，反而把值「吃掉」。
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', '?', $this->valueToString($value));

        if (trim($text) === '') {
            return '(未设置 / 空值)';
        }

        if (preg_match(self::SENSITIVE_NAME_PATTERN, $name) === 1) {
            return sprintf('"%s"***（已脱敏：共 %d 字符）', mb_substr($text, 0, 2), mb_strlen($text));
        }

        $shown = mb_strlen($text) > 64 ? mb_substr($text, 0, 64) . '…' : $text;

        return sprintf('"%s"', $shown);
    }

    /**
     * 条件必需项校验 | Conditional (non-mandatory) requirements
     *
     * 为什么不做成硬性必填：它们的必要性取决于**部署方式**，而不只是「是不是生产」。
     * 硬加进必填集合会让合理的部署（单机部署、客户端直连、不开限流）永远变红，
     * 而长期变红的门禁最终会被绕过 —— 那比没有门禁更糟。
     *
     * 逐项说明（含本轮的判定结论）：
     *   · TRUSTED_PROXIES：**条件必需** —— 仅当「生产类环境」且「RATELIMIT_ENABLED=true」时必需。
     *     缺失不会报错，只会让限流的 ip 维度静默退化成「全站共用一个桶」（XFF 不被采信），
     *     表现为整站 429；反过来说，客户端直连的部署（不经反代）本就不需要它，
     *     而填错的代价更大（把伪造 XFF 的能力交给客户端）——所以只告警、不阻断。
     *   · 空 TRUSTED_PROXIES 本身是 `config/ratelimit.php` 的**安全默认值**（不信任任何 XFF），
     *     因此非生产环境不评估它。
     *
     * @param bool $isProduction 是否生产类环境
     * @return array<int, array{name: string, status: string, message: string}>
     */
    protected function checkConditionalRequirements(bool $isProduction): array
    {
        $results = [];

        $ratelimitEnabled = filter_var(env('RATELIMIT_ENABLED', false), FILTER_VALIDATE_BOOL);
        $trustedProxies = trim($this->valueToString(env('TRUSTED_PROXIES', '')));

        if (!$isProduction) {
            $results[] = [
                'name'    => 'TRUSTED_PROXIES',
                'status'  => 'skipped',
                'message' => '非生产环境不评估（条件必需项只在生产类环境判断）',
            ];

            return $results;
        }

        if (!$ratelimitEnabled) {
            $results[] = [
                'name'    => 'TRUSTED_PROXIES',
                'status'  => 'skipped',
                'message' => '条件不成立：RATELIMIT_ENABLED 未开启，本项不被消费',
            ];

            return $results;
        }

        if ($trustedProxies === '') {
            $results[] = [
                'name'    => 'TRUSTED_PROXIES',
                'status'  => 'warning',
                'message' => '未配置：经反向代理且开启限流时，所有客户端会共用同一个 IP 限流桶（可能整站 429）'
                    . "\n" . '      条件: 仅当「请求经反向代理」且「RATELIMIT_ENABLED=true」时必需；客户端直连可忽略本条'
                    . "\n" . '      修复: 填真实反向代理 / 负载均衡器地址（逗号分隔，支持 IPv4 / IPv6 / CIDR），例如 10.0.0.5',
            ];

            return $results;
        }

        $entries = array_filter(array_map('trim', explode(',', $trustedProxies)), static fn (string $item): bool => $item !== '');

        $results[] = [
            'name'    => 'TRUSTED_PROXIES',
            'status'  => 'ok',
            'message' => sprintf('已配置 %d 个可信代理条目', count($entries)),
        ];

        return $results;
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
     *
     * @param int|null $checkedCount 实际检查的变量数（null 表示按全部定义计数；
     *                               R37 的「.env 解析失败」分支会跳过变量检查，此时传 0）
     */
    protected function outputSummary(Output $output, array $errors, array $warnings, ?int $checkedCount = null): void
    {
        $output->writeln('<comment>== Summary ==</comment>');
        $output->writeln(sprintf('  Total variables checked: %d', $checkedCount ?? count($this->envDefinitions)));
        $output->writeln(sprintf('  Errors: <error>%d</error>', count($errors)));
        $output->writeln(sprintf('  Warnings: <comment>%d</comment>', count($warnings)));
        $output->writeln('');
    }

    /**
     * R37：检查 `.env` 能否被解析（路径取自应用根目录）
     *
     * 抽成 protected 方法是为了让测试能指向临时文件，而不必反复替换真实的 `.env`。
     *
     * @return array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null}
     */
    protected function inspectEnvFile(): array
    {
        return AppService::inspectEnvFile($this->app->getRootPath() . '.env');
    }

    /**
     * R37：输出 `.env` 解析失败详情（行号 + 出错行原文 + 后果 + 修复方式）
     *
     * @param array{path: string, exists: bool, parsed: bool, line: int|null, raw: string|null, error: string|null} $report
     */
    protected function outputEnvFileParseError(Output $output, array $report): void
    {
        $output->writeln('  <error>✗</error> .env: 文件存在但无法被解析（parse_ini_file 返回 false）');
        $output->writeln(sprintf('      文件: %s', $report['path']));
        $output->writeln(sprintf(
            '      行号: %s',
            $report['line'] === null ? '未知（未能从错误消息中解析出行号）' : (string) $report['line']
        ));
        $output->writeln(sprintf('      出错行原文: %s', $report['raw'] ?? '未知（读取失败或行号越界）'));
        $output->writeln(sprintf('      解析错误: %s', $report['error'] ?? '未知错误'));
        $output->writeln('      后果: think\Env::load() 会静默兜底为空数组，.env 中所有键都会失效（容器起来了但配置像没生效）');
        $output->writeln('      修复: .env 注释里不要出现半角 ASCII 括号与引号，改用全角（），见 AGENTS.md Configuration & Security');
        $output->writeln('      说明: 已跳过逐变量检查 —— 所有键都读不到，继续检查只会报出一堆误导性的「必需但未设置」');
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
