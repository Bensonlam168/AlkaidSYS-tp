<?php

declare(strict_types=1);

namespace app\support;

/**
 * 生产环境配置要求目录（单一真相源）| Production environment requirement catalog
 *
 * ## 这个类解决什么问题
 *
 * 「生产必需项」的**定义**（哪些键、各自什么取值规则、怎么改）与**求值**（给定取值判合格与否）
 * 此前只存在于 `app\command\EnvCheckCommand` 里。于是任何第二个消费者
 * （例如不启动 App 的生产配置预检脚本 `scripts/check-env-production.php`）都只有两条路：
 * 复制一份规则（必然漂移），或者把自己的判定偷偷写松（门禁形同虚设）。
 * 本类把这两件事收敛到一处，两个消费者都只调用这里的方法。
 *
 * ## 为什么必须「零依赖」
 *
 * 本类**不 import 任何 ThinkPHP 类、不调用 `env()`、不碰容器 / Redis / DB**，
 * 只用 PHP 内置函数。原因很实在：
 *   · 生产配置预检脚本要在「Redis / DB 可能根本不通」的时刻运行，
 *     甚至连 `vendor/` 都没装（还没 `composer install`）时也要能跑；
 *   · `EnvCheckCommand` 通过 composer autoload 加载本类（命名空间 `app\support`），
 *     而脚本则直接 `require_once` 源文件 —— 同一份源码，两种加载方式都能用，
 *     前提就是本类不依赖任何其它类。
 *
 * ## 语义分三层（与 `env:check` 完全一致）
 *
 *   1. `production_required => true`：该键在生产类环境必须被显式配置（非空）；
 *   2. `production_rules`：对**取值本身**的校验，规则名 => 非生产环境的处理方式：
 *        · `warn`   —— 非生产环境也提示（用于「配错了在哪个环境都是错的」的规则）；
 *        · `ignore` —— 非生产环境静默（用于「本地就该这样」的规则，例如 dev 的
 *                      `DB_USER=root` / `DB_PASS=root` / `APP_DEBUG=true` / 短 dev 密钥）。
 *      规则清单：`not_empty` / `not_placeholder` / `positive_int` / `min_length:<n>` /
 *      `not_root` / `not_dev_default_credential` / `bool_false` / `production_env`。
 *   3. 条件必需项（`TRUSTED_PROXIES`）**不在**本类里：它取决于「是否经反向代理」这一
 *      部署拓扑事实，硬编码成数据只会误导。两个消费者各自按同一条件判定
 *      （仅生产类环境 + `RATELIMIT_ENABLED=true` 时告警）。
 *
 * ## 与之正交的第四个维度：`template_required`（见 {@see self::TEMPLATE_REQUIREMENTS}）
 *
 * 上面三层回答「**取值**合不合格」，`template_required` 回答「**模板文件**里有没有这一行」。
 * 两者是正交互补，不是同一件事的两种写法：
 *   · `DB_CHARSET` 只在模板维度（取值判定管不到它，模板是唯一防线）；
 *   · `APP_ENV` / `APP_DEBUG` / `DEFAULT_TENANT_ID` 只在 production 维度
 *     （危险形态是「值写错」，不是「模板缺行」）。
 * 逐键理由写在那张表的注释里，一致性由
 * `tests/Unit/Scripts/EnvExampleTemplateRequiredConsistencyTest` 与
 * `tests/Unit/Command/EnvRequirementCatalogConsistencyTest` 钉住。
 *
 * ## 覆盖范围（刻意不扩大）
 *
 * **production 维度**只有下列 8 个键。判定标准是三条同时成立：**① 配错不会让进程启动失败；
 * ② 故障出现在第一个业务请求或第一次鉴权；③ 有唯一正确的「可判定」取值形态。**
 * 反向例子（**刻意不**纳入 production 维度）：`DB_PORT` / `DB_CHARSET` / `DB_PREFIX` / `DB_TYPE`
 * （有安全且通用的默认值，属部署细节）、`REDIS_*`（已由 `app\provider\RedisHealthCheckService`
 * 启动期自检覆盖）、`CORS_ALLOWED_ORIGINS`（是否跨域取决于部署拓扑，仍是 `env:check` 的
 * 历史 `production_warning`，只告警）。
 * 其中 `DB_CHARSET` 虽然不进 production 维度，但**进了模板维度**（见上一条）——
 * 「不判值」与「模板里必须有」是两件事，可以同时成立。
 *
 * ## 脱敏（硬要求）
 *
 * 键名命中 {@see self::SENSITIVE_NAME_PATTERN} 时，{@see self::describeValue()} 只输出
 * 「前 2 个字符 + 总长度」。理由：消费者之一是 `env:check`，它的输出会原样进 CI 日志；
 * 另一个是部署前预检脚本，输出会进发布日志。完整口令一旦打印就等于泄露。
 *
 * @package app\support
 */
final class EnvRequirementCatalog
{
    /**
     * 生产类环境取值 | Production-like environment values
     *
     * 与 `config/cache.php`、`app\provider\CacheEnvironmentGuardService`、
     * `app\provider\SessionEnvironmentGuardService` 的判定口径保持一致。
     * 刻意不收录 `qa` / `pre` 这类自定义环境名：本清单只用来回答「这是不是生产类环境」，
     * 值域越宽越容易把「配置写错」误判成「自定义环境」。
     */
    public const PRODUCTION_ENVS = ['production', 'prod', 'stage', 'staging'];

    /**
     * 敏感键名模式：输出脱敏用（键名命中即视为口令/密钥）
     */
    public const SENSITIVE_NAME_PATTERN = '/(?:SECRET|PASSWORD|PASS|TOKEN|PRIVATE_KEY|API_KEY)/i';

    /**
     * 占位符标记（大小写不敏感的子串匹配）
     *
     * 覆盖两类真实事故：
     *   1. 部署模板里的 `REPLACE_WITH_*` / `your-....example.com` 忘替换；
     *   2. `.env.example` 与 `JwtService::DEV_FALLBACK_SECRET` 这类**仓库内公开**的 dev 默认值
     *      被原样带上生产（等于公开密钥）。
     */
    public const PLACEHOLDER_MARKERS = [
        'REPLACE', 'CHANGE_THIS', 'CHANGE_ME', 'YOUR_', 'YOUR-', 'EXAMPLE.COM',
        'PLACEHOLDER', 'DEV_ONLY', 'DEV-ONLY', 'TODO', 'FIXME', 'XXX',
    ];

    /**
     * 仓库内公开的 dev 默认账号/口令
     *
     * 取值来源：`.env.example`（root / root，以及注释里提到的业务账号 alkaid / alkaid123）
     * 与 `docker-compose.yml` 的 mysql 服务。它们对全网公开，生产环境不得复用。
     */
    public const DEV_DEFAULT_CREDENTIALS = ['root', 'alkaid123', 'password', '123456'];

    /**
     * 生产必需项规则表 | Production requirement table
     *
     * 字段语义（与 `EnvCheckCommand::$envDefinitions` 的同名字段一一对应，
     * 因此消费方可以整键合并）：
     *   · `production_required` —— 生产类环境必须显式配置；
     *   · `production_rules`    —— 规则名 => 非生产环境的处理方式（warn / ignore）；
     *   · `production_hint`     —— 不合格时「怎么改」（会原样打印，因此只描述做法，不含真实取值）。
     *
     * 新增/删除键属于**显式决策**：改了这里，`env:check` 与预检脚本会同时生效，
     * 这正是单一真相源要的效果；请同步更新
     * `docs/technical-specs/deployment/environment-validation.zh-CN.md` 的清单表格。
     *
     * @var array<string, array{production_required: bool, production_rules: array<string, string>, production_hint: string}>
     */
    private const REQUIREMENTS = [
        // APP_ENV 决定整套「生产策略」是否生效：cache 是否强制 redis、swoole 是否热重载、
        // 异常页是否只给通用文案。写错（如 prodcution）或留空时，这些策略**静默**退回开发行为，
        // 而进程照常启动 —— 因此它是生产预检的第一道关。
        //
        // 为什么唯一一条规则的 devMode 是 `ignore`：本规则问的是「你声称自己是不是生产」，
        // 非生产环境取 dev / testing 正是对的，对它告警只会训练开发者忽略输出。
        // 在 `env:check` 里本规则是**恒真**的（`$isProduction` 本身就由同一个取值算出），
        // 因此在命令侧不会改变任何输出；它真正的负载在预检脚本侧（那边强制按生产判定）。
        'APP_ENV' => [
            'production_required' => true,
            'production_rules'    => ['production_env' => 'ignore'],
            'production_hint'     => 'APP_ENV 必须显式写成 production / prod / stage / staging 之一；'
                . '它决定 config/cache.php 是否强制 redis、config/swoole.php 是否热重载、'
                . 'ExceptionHandle 是否对外只给通用文案 —— 写错或留空会让整套生产策略静默退回开发行为',
        ],
        // 生产必须为 false：debug 会把异常堆栈、SQL、绝对路径直接回显给调用方
        // （与 HealthController::checkConfig() 的就绪判定同源，这里提前到部署前）。
        'APP_DEBUG' => [
            'production_required' => true,
            'production_rules'    => [
                // 未设置时是危险的：config/database.php 的 trigger_sql 缺省取 true
                'not_empty'  => 'warn',
                'bool_false' => 'ignore',
            ],
            'production_hint' => '生产必须显式写 APP_DEBUG=false（未设置时 trigger_sql 缺省为 true；'
                . '为 true 会把堆栈、SQL、绝对路径回显给调用方）',
        ],
        // 默认租户：`TenantIdentify` 是全局中间件，未配置即 fail-closed 抛异常，
        // 表现为「所有不带 X-Tenant-ID 的请求（含探针）全部 500」，且在**第一个请求**才暴露。
        'DEFAULT_TENANT_ID' => [
            'production_required' => true,
            'production_rules'    => [
                'not_empty'       => 'warn',
                'not_placeholder' => 'warn',
                'positive_int'    => 'warn',
            ],
            'production_hint' => 'DEFAULT_TENANT_ID 必须是 tenants 表中真实存在的租户 ID（正整数），例如 1；'
                . '未配置或非正整数时 TenantIdentify 会 fail-closed，所有不带 X-Tenant-ID 的请求返回 500',
        ],
        // 生产必需项的判定依据（逐个给理由，而不是「看着像必需就加」）：
        //   · DB_HOST / DB_NAME：写错或留占位符 → 连不上库，全站 500（进程仍能启动）；
        //   · DB_USER：留 root 等于以全库权限运行应用，一次 SQL 注入即整库失守；
        //   · DB_PASS：留空 / 复用仓库内公开的 dev 默认口令 → 等于没有口令。
        'DB_HOST' => [
            'production_required' => true,
            'production_rules'    => ['not_empty' => 'warn', 'not_placeholder' => 'warn'],
            'production_hint'     => 'DB_HOST 必须是生产数据库的真实主机名；'
                . '模板里的 your-....example.com 之类占位值会导致「容器起来了但连不上库」',
        ],
        'DB_NAME' => [
            'production_required' => true,
            'production_rules'    => ['not_empty' => 'warn', 'not_placeholder' => 'warn'],
            'production_hint'     => 'DB_NAME 必须是生产库的真实库名（端口能连上不代表库名对，'
                . '留 REPLACE_WITH_* 会在第一个查询时报 Unknown database）',
        ],
        'DB_USER' => [
            'production_required' => true,
            'production_rules'    => ['not_empty' => 'warn', 'not_placeholder' => 'warn', 'not_root' => 'ignore'],
            'production_hint'     => 'DB_USER 请改用最小权限的业务账号（如 alkaid），不要用 root：'
                . 'root 拥有全库权限，应用侧任何注入或泄露都直接扩散到整库',
        ],
        'DB_PASS' => [
            'production_required' => true,
            'production_rules'    => [
                'not_empty'  => 'warn',
                'not_placeholder' => 'warn',
                // root / alkaid123 是 .env.example 与 docker compose 里的公开字符串，本地必须能用
                'not_dev_default_credential' => 'ignore',
            ],
            'production_hint' => 'DB_PASS 必须换成独立强口令（例如 openssl rand -base64 24）；'
                . '仓库内的 dev 默认口令对全网公开，等于没有口令',
        ],
        // 判定与 JwtService::assertProductionSecretIsSecure() 对齐（同一条安全约束，
        // 只是提前到部署前；那边是「第一个鉴权请求被拒」，这里是「部署前直接红」）。
        'JWT_SECRET' => [
            'production_required' => true,
            'production_rules'    => [
                'not_empty'      => 'warn',
                'not_placeholder' => 'warn',
                'min_length:32'  => 'ignore',
            ],
            'production_hint' => 'JWT_SECRET 生产必须替换为 ≥32 字节的随机串（openssl rand -hex 32）；'
                . '沿用 CHANGE_THIS_IN_PRODUCTION / REPLACE_WITH_* / dev-only-* 等于任何人可伪造任意用户 token',
        ],
    ];

    /**
     * 模板必需项规则表 | Template requirement table
     *
     * ## 为什么这是一个**独立维度**，而不是 `REQUIREMENTS` 的一个字段
     *
     * 两个维度回答的是不同问题，取值集合也不同：
     *   · `production_*`（见 {@see self::REQUIREMENTS}）—— 「生产类环境里这个键的**取值**合不合格」；
     *   · `template_required`（本表）—— 「**模板文件**里有没有这一行」。
     *
     * 差别最清楚的是 `DB_CHARSET`：它**只在**模板维度（模板必须提醒运维写 `utf8mb4`），
     * **不在** production 维度 —— `utf8` / `utf8mb4` / `utf8mb4_0900_ai_ci` 都是合法字符串，
     * 判值只能靠白名单，会把合法的扩展形态误伤；而「模板里漏了它」的后果是回落到
     * `config/database.php` 的默认字符集，默认值一旦被改成 `utf8` 就是「写入报 1366 / 静默截断」。
     *
     * 反过来 `APP_ENV` / `APP_DEBUG` / `DEFAULT_TENANT_ID` **只在** production 维度 ——
     * 它们的危险形态是「**值写错**」，而不是「模板里没有这一行」（逐键理由见下方注释）。
     *
     * 因此**不允许**把两个维度取并集：那会让 `DB_CHARSET` 被迫进入取值判定（与 §6 的结论冲突），
     * 或者让 `APP_DEBUG` 的「模板缺失」被当成 CI 错误（把门禁语义从 6 键扩到 8 键，
     * 属于需要单独评审的显式决策，不该随实现漂移）。
     *
     * ## 为什么把 false 的键也逐条列出来
     *
     * 表里覆盖 9 个键（8 个生产必需项 + `DB_CHARSET`），**每个都写明** true / false，
     * 而不是只列 true 的那几个：这样「新增一个生产必需键、但忘了想清楚模板要不要」会被
     * `tests/Unit/Scripts/EnvExampleTemplateRequiredConsistencyTest` 立刻发现
     * （漏登记 → 测试红），而不是等到某次部署踩坑才暴露。
     *
     * @var array<string, array{template_required: bool, template_note: string}>
     */
    private const TEMPLATE_REQUIREMENTS = [
        // 连不上库的四个键：模板必须给出「这一行」，否则复制出来的 .env.production 里
        // 根本没有可填的位置，运维只能凭记忆补键名（写错键名不会报错，只会静默不生效）。
        'DB_HOST' => [
            'template_required' => true,
            'template_note'     => '模板必须保留这一行：缺行时运维需凭记忆补键名，'
                . '而 INI 里写错键名不会报错、只会静默不生效（表现为「容器起来了但连不上库」）',
        ],
        'DB_NAME' => [
            'template_required' => true,
            'template_note'     => '模板必须保留这一行：库名是「端口能连上也不代表对」的典型配置，'
                . '缺行会让 .env.production 里没有可填位置',
        ],
        'DB_USER' => [
            'template_required' => true,
            'template_note'     => '模板必须保留这一行：缺行时 `env(\'DB_USER\', \'root\')` 会以 root 连库，'
                . '模板是唯一提醒「改用最小权限账号」的地方',
        ],
        'DB_PASS' => [
            'template_required' => true,
            'template_note'     => '模板必须保留这一行：缺行时口令为空，等于没有口令',
        ],
        // ★ 只在模板维度：production 维度刻意不判它的值（见类注释），因此模板是**唯一**防线。
        'DB_CHARSET' => [
            'template_required' => true,
            'template_note'     => '只在模板维度（production 维度刻意不判值）：模板必须写明 utf8mb4，'
                . '否则回落到 config/database.php 的默认字符集，默认值一旦是 utf8 就会'
                . '「写入报 1366 / 静默截断」—— 这是唯一一个「取值判定管不到、只能靠模板」的键',
        ],
        'JWT_SECRET' => [
            'template_required' => true,
            'template_note'     => '模板必须保留这一行：缺行时密钥为空，会被回退到内置开发密钥，'
                . '表现为「登录 200 带 token、随后每个受保护接口 401」',
        ],
        // 以下三个「只在 production 维度」：危险形态是值写错，不是模板缺行。
        'APP_ENV' => [
            'template_required' => false,
            'template_note'     => '不进模板维度：`.env.example` 是**本地开发**模板，本地就该是 dev；'
                . '把它提升为「模板必需键」等于要求本地模板也声明生产环境取值，且它的危险形态是'
                . '「值写错（如 prodcution）」，已由 production 维度的 production_env 规则拦下',
        ],
        'APP_DEBUG' => [
            'template_required' => false,
            'template_note'     => '不进模板维度：`.env.example` 里 APP_DEBUG = true 是**本地正确值**，'
                . '把它列为模板必需键会让「本地模板」与「生产取值」两个维度互相拉扯；'
                . '生产必须为 false 由 production 维度的 not_empty + bool_false 规则拦下，'
                . '`env:check` 与 check-env-production.php 在生产类环境都会 fail-closed',
        ],
        'DEFAULT_TENANT_ID' => [
            'template_required' => false,
            'template_note'     => '不进模板维度：缺失/非法由 production 维度的 not_empty + positive_int '
                . '规则拦下（TenantIdentify 本身也 fail-closed）；另外它今天已同时出现在两份模板里，'
                . '存在性还受检查 E（.env.production.example 必须覆盖 .env.example 的每个键）间接保护，'
                . '删它需要同时删两处 —— 再加一条模板维度只是同一件事的第二次报警',
        ],
    ];

    /**
     * 取整张规则表 | All production requirements
     *
     * @return array<string, array{production_required: bool, production_rules: array<string, string>, production_hint: string}>
     */
    public static function requirements(): array
    {
        return self::REQUIREMENTS;
    }

    /**
     * 取模板必需项规则表 | All template requirements
     *
     * @return array<string, array{template_required: bool, template_note: string}>
     */
    public static function templateRequirements(): array
    {
        return self::TEMPLATE_REQUIREMENTS;
    }

    /**
     * 取「模板文件里必须出现」的键清单 | Keys the template files must contain
     *
     * 顺序即消费方的报错顺序（`scripts/check-env-example.php` 的检查 D 按此顺序逐键报缺），
     * 因此调整顺序会影响门禁输出的行序 —— 属于显式决策，改动请同步更新一致性测试。
     *
     * @return array<int, string>
     */
    public static function templateRequiredKeys(): array
    {
        $keys = [];

        foreach (self::TEMPLATE_REQUIREMENTS as $name => $requirement) {
            if ($requirement['template_required'] === true) {
                $keys[] = (string) $name;
            }
        }

        return $keys;
    }

    /**
     * 该键是否必须出现在模板文件里 | Must the key appear in the template files
     *
     * @param string $name 环境变量名
     */
    public static function isTemplateRequired(string $name): bool
    {
        return (self::TEMPLATE_REQUIREMENTS[strtoupper($name)]['template_required'] ?? false) === true;
    }

    /**
     * 取某个键的模板维度说明 | Template-dimension note of one key
     *
     * @param string $name 环境变量名
     * @return string 未登记时返回空串
     */
    public static function templateNote(string $name): string
    {
        return (string) (self::TEMPLATE_REQUIREMENTS[strtoupper($name)]['template_note'] ?? '');
    }

    /**
     * 取单个键的要求 | Requirement of one key
     *
     * @param string $name 环境变量名
     * @return array{production_required: bool, production_rules: array<string, string>, production_hint: string}|null 未登记时返回 null
     */
    public static function requirement(string $name): ?array
    {
        return self::REQUIREMENTS[strtoupper($name)] ?? null;
    }

    /**
     * 已登记的键名集合 | Registered key names
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::REQUIREMENTS);
    }

    /**
     * 取值是否属于生产类环境 | Is the value a production-like environment
     *
     * @param mixed $value 通常来自 `env('APP_ENV')`
     */
    public static function isProductionEnv(mixed $value): bool
    {
        return in_array(strtolower(trim(self::valueToString($value))), self::PRODUCTION_ENVS, true);
    }

    /**
     * 规则名是否已实现 | Is the rule implemented
     *
     * 必须逐个白名单：规则名写错（例如 `min_lenght:32`）时不能静默跳过，
     * 否则「加了规则但没生效」会表现为永远通过 —— 这是门禁最典型的失效方式。
     *
     * @param string $rule 规则名（`min_length:32` 形式带参数）
     */
    public static function isKnownRule(string $rule): bool
    {
        if (in_array(
            $rule,
            [
                'not_empty', 'not_placeholder', 'positive_int', 'not_root',
                'not_dev_default_credential', 'bool_false', 'production_env',
            ],
            true
        )) {
            return true;
        }

        return preg_match('/^min_length:\d+$/', $rule) === 1;
    }

    /**
     * 对一个键的取值执行其全部规则 | Evaluate all rules of one key
     *
     * 关键语义：**只有生产类环境才阻断**。非生产环境按各规则的「处理方式」降级：
     *   · warn   —— 记为提示（不改变退出码，`--strict` 下才失败）；
     *   · ignore —— 完全静默（留给「本地就该这样」的值）。
     *
     * 返回的是**原因文案**（未组装成完整消息），由调用方决定排版：
     * `env:check` 用 {@see self::formatMessage()} 拼成多行块，预检脚本按自己的清单格式打印。
     * 每条原因都不含敏感值原文（取值只经 {@see self::describeValue()} 脱敏后出现）。
     *
     * @param string $name         键名（必须已在 {@see self::REQUIREMENTS} 中登记）
     * @param mixed  $value        当前取值
     * @param bool   $isProduction 是否生产类环境
     * @return array{errors: array<int, string>, notices: array<int, string>} 阻断原因 / 提示原因
     */
    public static function evaluate(string $name, mixed $value, bool $isProduction): array
    {
        $requirement = self::requirement($name);

        if ($requirement === null) {
            // 未登记的键没有「要求」可执行 —— 调用方若有键集合断言（测试），会另外发现它
            return ['errors' => [], 'notices' => []];
        }

        $errors  = [];
        $notices = [];

        foreach ($requirement['production_rules'] as $rule => $devMode) {
            $rule = (string) $rule;

            // 规则名写错时必须可见，不能静默通过（fail-closed）：这条只可能来自本类的配置错误。
            if (!self::isKnownRule($rule)) {
                $errors[] = sprintf('规则「%s」未实现，无法判定（规则目录自身的配置错误）', $rule);
                continue;
            }

            $reason = self::evaluateRule($rule, $value);

            if ($reason === null) {
                continue;
            }

            if ($isProduction) {
                $errors[] = $reason;
                continue;
            }

            if ($devMode === 'warn') {
                $notices[] = $reason;
            }
        }

        return ['errors' => $errors, 'notices' => $notices];
    }

    /**
     * 执行单条规则 | Evaluate one rule
     *
     * @param string $rule  规则名
     * @param mixed  $value 当前取值
     * @return string|null 不合格时返回「原因」文案（可直接打印，**不含**敏感值原值）；通过返回 null
     */
    public static function evaluateRule(string $rule, mixed $value): ?string
    {
        $text = trim(self::valueToString($value));

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

            case 'production_env':
                return in_array(strtolower($text), self::PRODUCTION_ENVS, true)
                    ? null
                    : sprintf(
                        '必须是生产类环境取值（%s）之一：写错或留空会让 cache/session 强制 redis、'
                        . '异常页收敛等生产策略静默失效',
                        implode(' / ', self::PRODUCTION_ENVS)
                    );

            default:
                if (preg_match('/^min_length:(\d+)$/', $rule, $matches) === 1) {
                    $min = (int) $matches[1];

                    return strlen($text) >= $min
                        ? null
                        : sprintf(
                            '长度不足：当前 %d 字节，要求 ≥ %d 字节（建议 openssl rand -hex 32 生成）',
                            strlen($text),
                            $min
                        );
                }

                return null;
        }
    }

    /**
     * 组装「生产必需项不合格」的多行文案 | Build the multi-line message
     *
     * 逐条给出：为什么不合格、怎么改。调用方已在前面打印了键名（`✗ KEY:`），
     * 这里只负责原因列表 + 当前值（脱敏）+ 修复提示。
     *
     * @param string             $name         键名
     * @param mixed              $value        当前取值
     * @param array<int, string> $reasons      不合格原因
     * @param string             $hint         修复提示（来自规则表的 production_hint）
     * @param bool               $isProduction 是否生产类环境（决定文案：阻断 / 仅提示）
     */
    public static function formatMessage(
        string $name,
        mixed $value,
        array $reasons,
        string $hint,
        bool $isProduction
    ): string {
        $lines = [sprintf(
            '%s（%d 条）| current: %s',
            $isProduction
                ? '生产必需项不合格'
                : '生产环境会失败，当前非生产环境仅提示 | would fail in production',
            count($reasons),
            self::describeValue($name, $value)
        )];

        foreach ($reasons as $reason) {
            $lines[] = '      · ' . $reason;
        }

        if ($hint !== '') {
            $lines[] = '      修复: ' . $hint;
        }

        return implode("\n", $lines);
    }

    /**
     * 把任意取值转成用于比较/打印的字符串 | Stringify a value
     *
     * `parse_ini_file` 给出的是字符串，而 ThinkPHP 的 `env()` 会把 `true/false/on/off` 转成 bool。
     * 本方法把两者统一还原成文本，保证「同一条规则在两个消费者里看到的是同一个东西」。
     */
    public static function valueToString(mixed $value): string
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
     * 脱敏规则（硬要求）：键名命中 {@see self::SENSITIVE_NAME_PATTERN} 时只保留
     * **前 2 个字符 + 总长度**。非敏感键全量显示，但会截断到 64 字符并去掉控制字符，
     * 避免一条日志变成多行、污染 CI / 发布日志。
     *
     * @param string $name  键名
     * @param mixed  $value 当前取值
     */
    public static function describeValue(string $name, mixed $value): string
    {
        // 控制字符（含换行）会让一条日志裂成多行，先替换掉；刻意不加 /u：
        // 非 UTF-8 的旧口令在 /u 模式下会让 preg_replace 返回 null，反而把值「吃掉」。
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', '?', self::valueToString($value));

        if (trim($text) === '') {
            return '(未设置 / 空值)';
        }

        if (preg_match(self::SENSITIVE_NAME_PATTERN, $name) === 1) {
            return sprintf('"%s"***（已脱敏：共 %d 字符）', mb_substr($text, 0, 2), mb_strlen($text));
        }

        $shown = mb_strlen($text) > 64 ? mb_substr($text, 0, 64) . '…' : $text;

        return sprintf('"%s"', $shown);
    }
}
