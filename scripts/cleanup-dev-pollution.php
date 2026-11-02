#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dev DB Pollution Cleanup | 开发库历史测试残留清理（#4）
 *
 * 背景：
 *   开发库 `alkaid_sys` 里积累了多轮 E2E / 集成测试直接写开发库留下的残留：
 *   假的第二租户、假的跨租户角色与权限、测试用户、E2E CLI 建的 lowcode 集合。
 *   它们会污染「权限行数 / 租户列表 / 集合列表」这类人工核对口径，
 *   也会让 `GrantCeilingService` 的「一码一行」前提不成立（见该类的注释）。
 *
 * 为什么做成脚本，而不是迁移：
 *   `second-tenant-*` / `e2e_cli_*` 这类行**只应存在于开发库**。
 *   迁移会在所有环境执行，要证明「这些行在生产也可安全删除」是做不到的
 *   （生产如果真有个租户恰好叫 `second-tenant-xxx`，迁移会把它删掉）。
 *   本脚本因此硬性绑定开发库（见 assertDevDatabase），任何其它库直接拒绝运行。
 *
 * 设计原则（fail-closed）：
 *   1. **默认 dry-run**：不带 `--apply` 只读 + 打印清点，绝不写库；
 *   2. `--apply` 必须**逐类显式**指定 `--only=<类名>`，没有 `--all` 之类的批量开关；
 *   3. `--apply` 必须带 `--backup=<文件>`，脚本会校验该文件存在、非空、
 *      且包含 mysqldump 的结束标记 —— 把「先全量备份」这条纪律落在代码里，
 *      而不是靠执行者自觉；
 *   4. 目标库必须就是开发库 `alkaid_sys`，否则拒绝（含 dry-run）；
 *   5. **歧义类别不提供删除路径**：`test_user`(id=1) 与 `test_admin` 角色
 *      只有清点、没有 DELETE 分支，`--only` 选中它们时直接拒绝；
 *   6. 删除前逐类做依赖守卫（外键 / 代码引用 / 测试引用），守卫不通过就整类跳过；
 *   7. 所有 DELETE 语句集中在本文件的 `applyDelete()` 里，便于逐条审查；
 *   8. 幂等：删除条件就是清点条件，重复执行第二次的命中行数为 0。
 *
 * lowcode 集合为什么不能直接 DELETE：
 *   删 `lowcode_collections` 只是删元数据，物理表 `lc_*` 会留下来变成孤儿表。
 *   因此集合一律走 `CollectionManager::delete()`（删关系/字段/元数据 + 删物理表 + 清缓存），
 *   与业务代码同一条路径。
 *
 * 用法：
 *   php scripts/cleanup-dev-pollution.php                                  # dry-run 全量清点
 *   php scripts/cleanup-dev-pollution.php --only=permissions-cross-tenant-test \
 *        --apply --backup=runtime/backup-dev-cleanup/alkaid_sys-full-xxx.sql
 *
 * 退出码：
 *   0 - dry-run 完成，或本次 --apply 全部成功
 *   1 - 参数非法 / 非开发库 / 备份校验失败 / 守卫拦截 / 删除过程出错
 *
 * @package scripts
 */

// ---------------------------------------------------------------------------
// 常量
// ---------------------------------------------------------------------------

/** 仓库根目录 */
const REPO_ROOT = __DIR__ . '/..';

/**
 * 唯一允许执行 --apply 的库名
 *
 * 刻意写死：本工具是开发库卫生工具，不是通用数据清理器。
 * 换库执行 = 换一个环境承担风险，必须由人改代码而不是改参数。
 */
const EXPECTED_DEV_DB = 'alkaid_sys';

/**
 * 受保护的用户主键
 *
 * id=1 是开发库里的 `test_user`：名字像测试数据，但**不能删**，原因三条：
 *   a. 迁移 `20251216000003_insert_initial_data.php` 把 `created_by/updated_by/user_id`
 *      硬编码成 1，删掉这个用户会让 7 行迁移期望的工作流记录被 `ON DELETE SET NULL`
 *      改写成 NULL —— 库就不再是「迁移跑出来的样子」；
 *   b. 该用户在 `user_roles` 里有 10 行，其中 role 1 是系统管理员角色（已授全量权限），
 *      即它事实上是「第二管理员」，删它属于权限面变更，不属库卫生；
 *   c. `database/seeds/CorePlatformSeed.php` 的 R52 注释把「开发库 id=1 是 test_user」
 *      当作已知状态并已适配，说明它是有记录的历史事实而非随手产生的脏数据。
 * 结论：只报告，不删。
 */
const PROTECTED_USER_IDS = [1];

/** 受保护的用户名（无论如何都不进候选集） */
const PROTECTED_USERNAMES = ['admin'];

/** 受保护的租户主键：id=1 是 `default` 租户，admin 与全部系统菜单都挂在它下面 */
const PROTECTED_TENANT_IDS = [1];

/** 受保护的角色 slug：系统角色不得出现在任何清理候选里 */
const PROTECTED_ROLE_SLUGS = ['admin', 'user'];

/**
 * 测试集合的判据（MySQL LIKE 模式，`\_` 为转义的下划线）
 *
 * 注意大小写敏感性来自模式本身而非排序规则：
 * `lowcode_collections.name` 的排序规则是 `utf8mb4_unicode_ci`（大小写不敏感），
 * 但 `TestCollection_xxx` 在 `test` 之后是字母 `C` 而不是 `_`，
 * 因此**不会**被 `test\_%` 命中 —— 这一点经过实测（见执行报告）。
 */
const TEST_COLLECTION_PATTERNS = ['e2e\\_cli%', 'test\\_%', '%\\_test'];

/** 「跨租户测试」权限的 slug 前缀（LIKE 模式，`\_` 为转义的下划线） */
const LIKE_CROSS_TENANT_TEST = 'cross\\_tenant.test.%';

/** 「跨租户测试」角色的 slug 前缀 */
const LIKE_CROSS_TENANT_ROLE = 'cross-tenant-role-%';

/** E2E 测试留下的第二租户前缀 */
const LIKE_SECOND_TENANT = 'second-tenant-%';

/** 需要静态扫描「代码是否引用了待删数据」的目录 */
const CODE_SCAN_DIRS = ['app', 'route', 'domain', 'infrastructure', 'config'];

/**
 * 允许运行时 DDL 的环境
 *
 * 与 `SchemaBuilder::assertCanRunDDL()` 的默认白名单保持一致：
 * 只有这几个环境才可能放行 dropTable()，生产/预发一律拒绝。
 */
const ALLOWED_DDL_ENVS = ['dev', 'local', 'testing'];

/**
 * 没有到 `tenants` 外键、但带 `tenant_id` 列的监控表
 *
 * 删租户**不会**级联到这些表 —— 这是本清理任务刻意保留的未做项：
 * 它们会被留成孤儿行（tenant_id 指向已删除的租户），本脚本只报告数量不删除。
 * 理由见执行报告「未做项」一节：清理范围限定在 6 类残留，扩大删除面不划算。
 */
const DANGLING_MONITOR_TABLES = ['event_alerts', 'hook_alerts', 'hook_dead_letters'];

// ---------------------------------------------------------------------------
// 输出工具
// ---------------------------------------------------------------------------

/**
 * 终端着色（NO_COLOR 环境变量存在时禁用）
 */
function colorize(string $text, string $color): string
{
    $codes = ['red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34', 'gray' => '90'];
    $code = $codes[$color] ?? null;
    if ($code === null || getenv('NO_COLOR') !== false) {
        return $text;
    }

    return "\033[{$code}m{$text}\033[0m";
}

/**
 * 打印小标题
 */
function printSection(string $title): void
{
    echo PHP_EOL . colorize('== ' . $title . ' ' . str_repeat('=', max(0, 58 - strlen($title))), 'blue') . PHP_EOL;
}

/**
 * 打印「键：值」一行（键左对齐，值可跨行）
 */
function printField(string $label, string $value): void
{
    $pad = str_repeat(' ', max(0, 12 - mb_strwidth($label)));
    echo '  ' . $label . $pad . ': ' . $value . PHP_EOL;
}

// ---------------------------------------------------------------------------
// 参数解析
// ---------------------------------------------------------------------------

/**
 * 打印用法
 */
function usage(): void
{
    echo <<<'TXT'
开发库历史测试残留清理工具（默认 dry-run）

用法：
  php scripts/cleanup-dev-pollution.php [选项]

选项：
  --only=<类名>      指定要处理的类别，可用逗号分隔或重复指定
  --apply            真正执行删除（必须同时给 --only 与 --backup）
  --backup=<文件>    --apply 时必填：指向一份 mysqldump 全库备份，脚本会校验其完整性
  --acknowledge-orphans
                     确认已知「删租户会在无外键的监控表里留下孤儿行」并接受
  --help             打印本帮助

类别（--only 的取值）：
  permissions-cross-tenant-test   [安全] 8 条 cross_tenant.test.* 权限
  roles-cross-tenant              [安全] 8 个 cross-tenant-role-* 角色
  users-test-nonprimary           [安全] 3 个非 id=1 的测试用户
  tenants-second-tenant           [安全] 206 个 second-tenant-* 租户
  lowcode-collections-test        [安全] 304 个测试命名模式的 lowcode 集合（走 CollectionManager）
  users-test-primary              [歧义·只报告] test_user(id=1)
  roles-test-admin                [歧义·只报告] test_admin 角色

TXT;
}

/**
 * 解析命令行参数
 *
 * fail-closed：出现任何未知参数直接报错退出，避免「打错一个开关就被当成默认值」。
 *
 * @param array<int, string> $argv 原始参数
 * @return array{help: bool, apply: bool, only: array<int, string>, backup: ?string, acknowledgeOrphans: bool}
 */
function parseArgs(array $argv): array
{
    $options = [
        'help' => false,
        'apply' => false,
        'only' => [],
        'backup' => null,
        'acknowledgeOrphans' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if ($arg === '--acknowledge-orphans') {
            $options['acknowledgeOrphans'] = true;
            continue;
        }
        if (str_starts_with($arg, '--only=')) {
            foreach (explode(',', substr($arg, strlen('--only='))) as $key) {
                $key = trim($key);
                if ($key !== '') {
                    $options['only'][] = $key;
                }
            }
            continue;
        }
        if (str_starts_with($arg, '--backup=')) {
            $options['backup'] = substr($arg, strlen('--backup='));
            continue;
        }

        fwrite(STDERR, colorize('[FATAL] 未知参数：' . $arg, 'red') . PHP_EOL);
        usage();
        exit(1);
    }

    $options['only'] = array_values(array_unique($options['only']));

    return $options;
}

// ---------------------------------------------------------------------------
// 框架引导与连接
// ---------------------------------------------------------------------------

/**
 * 兜底：确保「脚本自己崩了」不会被报成成功
 *
 * `think\App::initialize()` 会注册全局异常处理器，未捕获异常被它渲染后 PHP 可能以
 * exit 0 结束。对本工具尤其危险：删除过程崩了一半却返回 0，会被误认为「已经清理干净」。
 * 因此 initialize() 之后把处理器换回「打印到 STDERR + exit 1」。
 */
function enforceFailClosedOnError(): void
{
    set_exception_handler(static function (\Throwable $e): void {
        fwrite(STDERR, '[FATAL] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
        fwrite(STDERR, '脚本异常中断；数据库状态未知，请按备份核对' . PHP_EOL);
        exit(1);
    });
}

/**
 * 引导 ThinkPHP 应用并返回 App 实例
 *
 * 【为什么要在进程内打开 ALLOW_RUNTIME_DDL】
 *   本清理的 lowcode 集合必须走 `CollectionManager::delete()`，它会 `DROP TABLE lc_*`。
 *   而 `Infrastructure\Schema\SchemaBuilder::assertCanRunDDL()` 的放行条件是
 *   「APP_ENV ∈ dev/local/testing **且** ALLOW_RUNTIME_DDL=true」，两者缺一即抛
 *   `HttpResponseException`（消息为空，只能靠 runtime 日志里的
 *   `[SCHEMA_DDL_FORBIDDEN] ... reason=flag_disabled` 才能定位）。
 *   开发库的 `.env` 里没有 `ALLOW_RUNTIME_DDL`（只有 `.env.production.example` 显式写 false），
 *   APP_ENV 则由容器进程环境提供（docker compose 设为 dev）。
 *   因此这里在**本进程内**把开关置为 true：
 *     - 不改 `.env`、不改 `config/**`，影响范围仅限本次 php 进程；
 *     - 置开关之前先校验 APP_ENV，非 dev/local/testing 直接拒绝，绝不把生产带进放行分支。
 *
 * @param bool $allowRuntimeDdl 是否为本进程打开运行时 DDL（只有 --apply 需要）
 */
function bootstrapApp(bool $allowRuntimeDdl): \think\App
{
    $appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
    if (!in_array($appEnv, ALLOWED_DDL_ENVS, true)) {
        throw new RuntimeException(
            '拒绝执行：APP_ENV=`' . $appEnv . '` 不在 ' . implode('/', ALLOWED_DDL_ENVS)
            . ' 之内。低代码集合删除需要运行时 DDL 放行，本工具只在开发环境启用。'
        );
    }

    if ($allowRuntimeDdl) {
        // Env::__construct() 直接以 $_ENV 初始化，且 .env 未定义该键，因此这里能生效
        $_ENV['ALLOW_RUNTIME_DDL'] = 'true';
        putenv('ALLOW_RUNTIME_DDL=true');
    }

    $autoload = REPO_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('找不到 vendor/autoload.php，请先 docker exec alkaid-backend composer install');
    }
    require_once $autoload;

    $app = new \think\App();
    $app->initialize();
    enforceFailClosedOnError();

    return $app;
}

/**
 * 建立 PDO 连接
 *
 * 复用框架引导，保证读的是 `config/database.php` 解析出的同一个库，
 * 不在脚本里另写一套 DSN（否则「清点连 A 库、删除改 B 库」这类事故无从发现）。
 */
function connectPdo(\think\App $app): PDO
{
    $pdo = $app->db->connect()->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/**
 * 断言当前连接的就是开发库
 *
 * 这是本工具最重要的一道闸：测试库被清 = 测试全红，生产库被清 = 事故。
 * 因此连 dry-run 也一起拒绝 —— 在别的库上算出来的清点数字本来就没有意义。
 */
function assertDevDatabase(PDO $pdo): string
{
    $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($name !== EXPECTED_DEV_DB) {
        throw new RuntimeException(
            '拒绝执行：当前连接的库是 `' . $name . '`，本工具只允许对开发库 `' . EXPECTED_DEV_DB . '` 运行。'
            . '（测试库 alkaid_sys_test 与生产库都不得使用本工具）'
        );
    }

    return $name;
}

/**
 * 校验备份文件
 *
 * 只做「能不能用来恢复」的最低限度检查：存在、非空、含 mysqldump 结束标记。
 * 真正的恢复演练由执行者按报告里的步骤做，脚本不替人下结论。
 */
function assertBackupFile(string $path): string
{
    $real = str_starts_with($path, '/') ? $path : REPO_ROOT . '/' . $path;
    if (!is_file($real)) {
        throw new RuntimeException('备份文件不存在：' . $path);
    }
    $size = filesize($real);
    if ($size === false || $size < 1024) {
        throw new RuntimeException('备份文件过小（' . (string) $size . ' 字节），不像是完整的全库备份：' . $path);
    }
    $handle = fopen($real, 'rb');
    if ($handle === false) {
        throw new RuntimeException('备份文件无法读取：' . $path);
    }
    // 结束标记在文件尾部，只读最后 4KB 即可，避免把 2MB+ 的备份整个读进内存
    fseek($handle, max(0, $size - 4096));
    $tail = (string) fread($handle, 4096);
    fclose($handle);

    if (!str_contains($tail, 'Dump completed')) {
        throw new RuntimeException(
            '备份文件缺少 mysqldump 的 "Dump completed" 结束标记，可能是被截断的备份：' . $path
        );
    }

    return $real;
}

// ---------------------------------------------------------------------------
// 查询小工具
// ---------------------------------------------------------------------------

/**
 * 执行查询并取第一列第一行转成 int
 */
function fetchInt(PDO $pdo, string $sql): int
{
    $value = $pdo->query($sql)->fetchColumn();

    return $value === false || $value === null ? 0 : (int) $value;
}

/**
 * 执行查询并返回全部行
 *
 * @return array<int, array<string, mixed>>
 */
function fetchAll(PDO $pdo, string $sql): array
{
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    return $rows;
}

/**
 * 把一行样例压成 `a / b / c` 形式
 *
 * @param array<int, array<string, mixed>> $rows 行集
 * @param array<int, string>               $cols 取哪些列
 */
function sampleLine(array $rows, array $cols): string
{
    $parts = [];
    foreach ($rows as $row) {
        $cells = [];
        foreach ($cols as $col) {
            $cells[] = (string) ($row[$col] ?? '');
        }
        $parts[] = implode('|', $cells);
    }

    return implode('  ', $parts);
}

/**
 * 生成测试集合的 WHERE 片段
 *
 * 单独抽出来是为了「清点」与「删除」用**同一个**判据 ——
 * 两处各写一遍 LIKE 模式，迟早会出现「清点数 304、实际删 305」。
 */
function testCollectionWhere(): string
{
    $parts = [];
    foreach (TEST_COLLECTION_PATTERNS as $pattern) {
        $parts[] = "name LIKE '" . $pattern . "'";
    }

    return '(' . implode(' OR ', $parts) . ')';
}

// ---------------------------------------------------------------------------
// 指纹
// ---------------------------------------------------------------------------

/**
 * 采集当前库指纹
 *
 * @return array<string, int>
 */
function fingerprint(PDO $pdo): array
{
    $tables = fetchInt(
        $pdo,
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    );
    $lcTables = fetchInt(
        $pdo,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'lc\\_%'"
    );

    $counters = [
        'users' => 'users',
        'tenants' => 'tenants',
        'permissions' => 'permissions',
        'roles' => 'roles',
        'role_permissions' => 'role_permissions',
        'user_roles' => 'user_roles',
        'sites' => 'sites',
        'lowcode_collections' => 'lowcode_collections',
        'lowcode_fields' => 'lowcode_fields',
        'lowcode_schema_versions' => 'lowcode_schema_versions',
        'migrations' => 'migrations',
    ];

    $result = ['tables_total' => $tables, 'lc_tables' => $lcTables];
    foreach ($counters as $label => $table) {
        $result[$label] = fetchInt($pdo, 'SELECT COUNT(*) FROM `' . $table . '`');
    }

    return $result;
}

/**
 * 打印指纹（给了 before 就顺便算差值）
 *
 * @param array<string, int>      $current 当前指纹
 * @param array<string, int>|null $before  上一次指纹
 */
function printFingerprint(array $current, ?array $before = null): void
{
    foreach ($current as $key => $value) {
        $delta = '';
        if ($before !== null && isset($before[$key])) {
            $diff = $value - $before[$key];
            if ($diff !== 0) {
                $delta = '  (' . ($diff > 0 ? '+' : '') . $diff . ')';
            }
        }
        echo '  ' . str_pad($key, 24) . ': ' . str_pad((string) $value, 10, ' ', STR_PAD_LEFT) . $delta . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
// 类别目录（只读清点用的声明）
// ---------------------------------------------------------------------------

/**
 * 类别目录
 *
 * 每个类别声明：标题、判定（safe / ambiguous）、理由、条数、样例、依赖计数。
 * **删除逻辑不在目录里** —— 全部集中在 applyDelete()，便于一次性审查所有写操作。
 *
 * @return array<string, array<string, mixed>>
 */
function classCatalog(): array
{
    $collectionWhere = testCollectionWhere();
    $crossTenantPerm = LIKE_CROSS_TENANT_TEST;
    $crossTenantRole = LIKE_CROSS_TENANT_ROLE;
    $secondTenant = LIKE_SECOND_TENANT;

    return [
        'permissions-cross-tenant-test' => [
            'title' => '权限：permissions.slug LIKE \'cross_tenant.test.%\'',
            'verdict' => 'safe',
            'reason' => 'slug 带随机十六进制后缀的跨租户测试权限。迁移链与 seeder 里 0 命中'
                . '（已实测 grep database/ 为 0），route/ 无任何路由使用它，'
                . 'GrantCeilingService 的注释本身就把这 8 条记为「历史测试脏数据」。',
            'count' => static fn (PDO $pdo): int => fetchInt(
                $pdo,
                "SELECT COUNT(*) FROM permissions WHERE slug LIKE '" . $crossTenantPerm . "'"
            ),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll(
                    $pdo,
                    "SELECT id, slug, resource, action FROM permissions WHERE slug LIKE '"
                    . $crossTenantPerm . "' ORDER BY id LIMIT 4"
                ),
                ['id', 'slug', 'resource', 'action']
            ),
            'deps' => static function (PDO $pdo) use ($crossTenantPerm): array {
                $sql = 'SELECT COUNT(*) FROM role_permissions rp'
                    . ' JOIN permissions p ON p.id = rp.permission_id'
                    . " WHERE p.slug LIKE '" . $crossTenantPerm . "'";
                $roles = fetchAll(
                    $pdo,
                    'SELECT r.id, r.slug, r.is_system, COUNT(*) AS cnt FROM role_permissions rp'
                    . ' JOIN permissions p ON p.id = rp.permission_id'
                    . ' JOIN roles r ON r.id = rp.role_id'
                    . " WHERE p.slug LIKE '" . $crossTenantPerm . "'"
                    . ' GROUP BY r.id, r.slug, r.is_system ORDER BY r.id'
                );
                $detail = [];
                foreach ($roles as $row) {
                    $detail[] = 'role ' . $row['id'] . '(' . $row['slug'] . ',system=' . $row['is_system'] . ')x' . $row['cnt'];
                }

                return ['role_permissions' => fetchInt($pdo, $sql), '持有角色' => implode(', ', $detail)];
            },
            'extra' => static fn (): array => [
                '代码引用' => describeCodeReferences('cross_tenant.test'),
                'database/ 命中' => (string) scanDirectoryForLiteral('database', 'cross_tenant.test'),
            ],
        ],

        'roles-cross-tenant' => [
            'title' => '角色：roles.slug LIKE \'cross-tenant-role-%\'',
            'verdict' => 'safe',
            'reason' => '租户 2~9（均为 second-tenant-*）下的跨租户测试角色，is_system=0，'
                . 'slug 带随机后缀，只被 test_user 持有，业务代码 0 引用。',
            'count' => static fn (PDO $pdo): int => fetchInt(
                $pdo,
                "SELECT COUNT(*) FROM roles WHERE slug LIKE '" . $crossTenantRole . "'"
            ),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll(
                    $pdo,
                    "SELECT id, tenant_id, slug FROM roles WHERE slug LIKE '" . $crossTenantRole . "' ORDER BY id LIMIT 4"
                ),
                ['id', 'tenant_id', 'slug']
            ),
            'deps' => static function (PDO $pdo) use ($crossTenantRole): array {
                $ids = fetchAll($pdo, "SELECT id FROM roles WHERE slug LIKE '" . $crossTenantRole . "'");
                if ($ids === []) {
                    return ['user_roles' => 0, 'role_permissions' => 0];
                }
                $idList = implode(',', array_map(static fn (array $r): string => (string) $r['id'], $ids));

                return [
                    'user_roles' => fetchInt($pdo, 'SELECT COUNT(*) FROM user_roles WHERE role_id IN (' . $idList . ')'),
                    'role_permissions' => fetchInt(
                        $pdo,
                        'SELECT COUNT(*) FROM role_permissions WHERE role_id IN (' . $idList . ')'
                    ),
                ];
            },
        ],

        'users-test-nonprimary' => [
            'title' => '用户：username LIKE \'%test%\' 且 id<>1（非 test_user）',
            'verdict' => 'safe',
            'reason' => 'transaction_test_user 与 test_user_<随机串>：'
                . 'user_roles=0、workflow_* 引用=0、不持有任何系统角色，是测试事务泄漏到开发库的行。',
            'count' => static fn (PDO $pdo): int => fetchInt($pdo, candidateUsersWhere() === '' ? 'SELECT 0' : 'SELECT COUNT(*) FROM users WHERE ' . candidateUsersWhere()),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll($pdo, 'SELECT id, username, email FROM users WHERE ' . candidateUsersWhere() . ' ORDER BY id LIMIT 5'),
                ['id', 'username', 'email']
            ),
            'deps' => static function (PDO $pdo): array {
                $ids = fetchAll($pdo, 'SELECT id FROM users WHERE ' . candidateUsersWhere());
                if ($ids === []) {
                    return ['user_roles' => 0, 'workflow_*' => 0, '系统角色' => '无'];
                }
                $idList = implode(',', array_map(static fn (array $r): string => (string) $r['id'], $ids));
                $wf = fetchInt($pdo, 'SELECT (SELECT COUNT(*) FROM workflow_definitions WHERE created_by IN (' . $idList . ') OR updated_by IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_event_logs WHERE user_id IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_instances WHERE assignee IN (' . $idList . ') OR created_by IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_tasks WHERE assignee IN (' . $idList . ') OR created_by IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_triggers WHERE created_by IN (' . $idList . '))');
                $roles = fetchAll(
                    $pdo,
                    'SELECT DISTINCT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN (' . $idList . ')'
                );
                $slugs = array_map(static fn (array $r): string => (string) $r['slug'], $roles);

                return [
                    'user_roles' => fetchInt($pdo, 'SELECT COUNT(*) FROM user_roles WHERE user_id IN (' . $idList . ')'),
                    'workflow_*' => $wf,
                    '系统角色' => $slugs === [] ? '无' : implode(',', $slugs),
                ];
            },
        ],

        'tenants-second-tenant' => [
            'title' => '租户：tenants.slug LIKE \'second-tenant-%\'',
            'verdict' => 'safe',
            'reason' => '测试建的「第二个租户」，name 全部为 Second Tenant。'
                . '其下有 0 用户 / 0 站点 / 0 部门 / 0 菜单 / 0 表单 / 0 工作流，'
                . '仅 8 个跨租户测试角色（另一类一并清理）。default 租户(id=1) 不在候选内。',
            'count' => static fn (PDO $pdo): int => fetchInt(
                $pdo,
                "SELECT COUNT(*) FROM tenants WHERE slug LIKE '" . $secondTenant . "'"
            ),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll(
                    $pdo,
                    "SELECT id, slug, name FROM tenants WHERE slug LIKE '" . $secondTenant . "' ORDER BY id LIMIT 3"
                ),
                ['id', 'slug', 'name']
            ),
            'deps' => static function (PDO $pdo) use ($secondTenant): array {
                $set = "(SELECT id FROM tenants WHERE slug LIKE '" . $secondTenant . "')";

                return [
                    'users' => fetchInt($pdo, 'SELECT COUNT(*) FROM users WHERE tenant_id IN ' . $set),
                    'roles' => fetchInt($pdo, 'SELECT COUNT(*) FROM roles WHERE tenant_id IN ' . $set),
                    'sites' => fetchInt($pdo, 'SELECT COUNT(*) FROM sites WHERE tenant_id IN ' . $set),
                    'depts' => fetchInt($pdo, 'SELECT COUNT(*) FROM depts WHERE tenant_id IN ' . $set),
                    'menus' => fetchInt($pdo, 'SELECT COUNT(*) FROM menus WHERE tenant_id IN ' . $set),
                    'lowcode_forms' => fetchInt($pdo, 'SELECT COUNT(*) FROM lowcode_forms WHERE tenant_id IN ' . $set),
                    'workflow_*' => fetchInt(
                        $pdo,
                        'SELECT (SELECT COUNT(*) FROM workflow_definitions WHERE tenant_id IN ' . $set . ')'
                        . ' + (SELECT COUNT(*) FROM workflow_instances WHERE tenant_id IN ' . $set . ')'
                        . ' + (SELECT COUNT(*) FROM workflow_tasks WHERE tenant_id IN ' . $set . ')'
                        . ' + (SELECT COUNT(*) FROM workflow_triggers WHERE tenant_id IN ' . $set . ')'
                        . ' + (SELECT COUNT(*) FROM workflow_event_logs WHERE tenant_id IN ' . $set . ')'
                    ),
                    'lowcode_collections(无外键)' => fetchInt(
                        $pdo,
                        'SELECT COUNT(*) FROM lowcode_collections WHERE tenant_id IN ' . $set
                    ),
                    // 这些表没有 tenants 外键：删租户不会带走它们，会留成 tenant_id 悬空的孤儿行。
                    // 本工具只报告、不删（见文件头与执行报告的「未做项」）。
                    '无外键·孤儿行(仅报告)' => array_sum(
                        array_map(
                            static fn (string $table): int => fetchInt(
                                $pdo,
                                'SELECT COUNT(*) FROM `' . $table . '` WHERE tenant_id IN ' . $set
                            ),
                            DANGLING_MONITOR_TABLES
                        )
                    ),
                ];
            },
        ],

        'lowcode-collections-test' => [
            'title' => '集合：lowcode_collections.name 命中测试命名模式',
            'verdict' => 'safe',
            'reason' => '302 个 e2e_cli_*（tenant_id=99997，库里不存在该租户）+ '
                . '2 个 test_repo_list_*（tenant 0/1）。lowcode_forms 表 0 行，'
                . '不存在任何表单引用；lowcode_relationships 0 行。'
                . '删除一律走 CollectionManager::delete()，物理表 lc_* 一并删除。',
            'count' => static fn (PDO $pdo): int => fetchInt(
                $pdo,
                'SELECT COUNT(*) FROM lowcode_collections WHERE ' . $collectionWhere
            ),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll(
                    $pdo,
                    'SELECT id, name, tenant_id, table_name FROM lowcode_collections WHERE '
                    . $collectionWhere . ' ORDER BY id LIMIT 3'
                ),
                ['id', 'name', 'tenant_id', 'table_name']
            ),
            'deps' => static function (PDO $pdo) use ($collectionWhere): array {
                $set = '(SELECT id FROM lowcode_collections WHERE ' . $collectionWhere . ')';
                $names = '(SELECT name FROM lowcode_collections WHERE ' . $collectionWhere . ')';

                return [
                    'lowcode_fields' => fetchInt($pdo, 'SELECT COUNT(*) FROM lowcode_fields WHERE collection_id IN ' . $set),
                    'lowcode_relationships' => fetchInt(
                        $pdo,
                        'SELECT COUNT(*) FROM lowcode_relationships WHERE collection_id IN ' . $set
                    ),
                    'lowcode_forms(按名引用)' => fetchInt(
                        $pdo,
                        'SELECT COUNT(*) FROM lowcode_forms WHERE collection_name IN ' . $names
                    ),
                    '物理表 lc_*' => fetchInt(
                        $pdo,
                        'SELECT COUNT(*) FROM information_schema.tables t'
                        . ' WHERE t.table_schema = DATABASE() AND t.table_name IN ('
                        . 'SELECT table_name FROM lowcode_collections WHERE ' . $collectionWhere . ')'
                    ),
                    'lowcode_schema_versions(按名引用, CollectionManager 不清)' => fetchInt(
                        $pdo,
                        "SELECT COUNT(*) FROM lowcode_schema_versions WHERE object_type = 'collection'"
                        . ' AND object_name IN ' . $names
                    ),
                ];
            },
        ],

        'users-test-primary' => [
            'title' => '用户：test_user（id=1）',
            'verdict' => 'ambiguous',
            'reason' => '迁移 20251216000003 把 created_by/updated_by/user_id 硬编码为 1，'
                . '删它会 SET NULL 掉 7 行迁移期望的工作流记录；它在 user_roles 有 10 行且持有系统管理员角色 1；'
                . 'CorePlatformSeed 的 R52 已把「开发库 id=1 是 test_user」当已知状态适配。→ 只报告，不删。',
            'count' => static fn (PDO $pdo): int => fetchInt($pdo, 'SELECT COUNT(*) FROM users WHERE id = 1'),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll($pdo, 'SELECT id, username, email, name FROM users WHERE id = 1'),
                ['id', 'username', 'email', 'name']
            ),
            'deps' => static fn (PDO $pdo): array => [
                'user_roles' => fetchInt($pdo, 'SELECT COUNT(*) FROM user_roles WHERE user_id = 1'),
                'workflow_definitions(created_by/updated_by)' => fetchInt(
                    $pdo,
                    'SELECT COUNT(*) FROM workflow_definitions WHERE created_by = 1 OR updated_by = 1'
                ),
                'workflow_triggers.created_by' => fetchInt($pdo, 'SELECT COUNT(*) FROM workflow_triggers WHERE created_by = 1'),
                'workflow_event_logs.user_id' => fetchInt($pdo, 'SELECT COUNT(*) FROM workflow_event_logs WHERE user_id = 1'),
            ],
        ],

        'roles-test-admin' => [
            'title' => '角色：test_admin（id=3，tenant 1）',
            'verdict' => 'ambiguous',
            'reason' => '前端 router/routes/modules/admin.ts 把 test_admin 与 admin 并列写进'
                . '全部 /system/** 页面的 meta.authority，即它是「联合管理员」的实际用途；'
                . '同时它是 test_user 持有的唯一业务角色（lowcode.read/write/delete 3 条权限）。'
                . '删掉不报错，但属于权限面变更而非库卫生，且改前端授权口径不应由清理脚本代劳。→ 只报告，不删。',
            'count' => static fn (PDO $pdo): int => fetchInt($pdo, "SELECT COUNT(*) FROM roles WHERE slug = 'test_admin'"),
            'samples' => static fn (PDO $pdo): string => sampleLine(
                fetchAll($pdo, "SELECT id, tenant_id, name, slug FROM roles WHERE slug = 'test_admin'"),
                ['id', 'tenant_id', 'name', 'slug']
            ),
            'deps' => static fn (PDO $pdo): array => [
                'user_roles' => fetchInt($pdo, 'SELECT COUNT(*) FROM user_roles WHERE role_id = 3'),
                'role_permissions' => fetchInt($pdo, 'SELECT COUNT(*) FROM role_permissions WHERE role_id = 3'),
                '前端引用' => 'router/routes/modules/admin.ts（meta.authority）',
            ],
        ],
    ];
}

/**
 * 非主键测试用户的 WHERE 片段
 *
 * 与清点、删除共用同一判据：username 含 test，排除受保护 id 与受保护用户名。
 */
function candidateUsersWhere(): string
{
    $parts = ["username LIKE '%test%'"];
    $parts[] = 'id NOT IN (' . implode(',', PROTECTED_USER_IDS) . ')';
    $quoted = array_map(static fn (string $name): string => "'" . $name . "'", PROTECTED_USERNAMES);
    $parts[] = 'username NOT IN (' . implode(',', $quoted) . ')';

    return implode(' AND ', $parts);
}

// ---------------------------------------------------------------------------
// 代码引用静态扫描
// ---------------------------------------------------------------------------

/**
 * 扫描源码目录里是否出现某个字面量
 *
 * 目的：在删数据前回答「代码里还有没有人在用它」。
 * 注释里出现（例如 GrantCeilingService 把 cross_tenant.test.* 记为脏数据）不算引用，
 * 但会原样打印出来供人复核 —— 不做「注释自动放行」以外的任何推断。
 *
 * @return array<int, array{file: string, line: int, comment: bool, text: string}>
 */
function scanCodeReferences(string $needle): array
{
    $hits = [];
    foreach (CODE_SCAN_DIRS as $dir) {
        $path = REPO_ROOT . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $filePath = $file->getPathname();
            $lines = file($filePath);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $index => $line) {
                if (stripos($line, $needle) === false) {
                    continue;
                }
                $trimmed = ltrim($line);
                $isComment = str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '#')
                    || str_starts_with($trimmed, '/*');
                $hits[] = [
                    'file' => substr($filePath, strlen(REPO_ROOT) + 1),
                    'line' => $index + 1,
                    'comment' => $isComment,
                    'text' => trim($line),
                ];
            }
        }
    }

    return $hits;
}

/**
 * 把代码引用扫描结果压成一行可读文本
 *
 * 注释里出现（例如 GrantCeilingService 把 `cross_tenant.test.*` 记为脏数据）不算引用，
 * 但会打上 [注释] 标记原样列出，方便复核「为什么它可以删」。
 */
function describeCodeReferences(string $needle): string
{
    $hits = scanCodeReferences($needle);
    if ($hits === []) {
        return '0 处（' . implode('/', CODE_SCAN_DIRS) . ' 下无该字面量）';
    }
    $parts = [];
    foreach ($hits as $hit) {
        $parts[] = ($hit['comment'] ? '[注释] ' : '[代码] ')
            . $hit['file'] . ':' . $hit['line'] . ' ' . $hit['text'];
    }

    return implode(' ; ', $parts);
}

// ---------------------------------------------------------------------------
// 守卫
// ---------------------------------------------------------------------------

/**
 * 逐类守卫：返回阻塞项数组（空数组表示可以执行）
 *
 * 这是「先查依赖，再删」在代码里的落地。守卫不过就整类跳过，
 * 不做「部分删」—— 部分删除会让残留状态更难判断。
 *
 * @return array{blockers: array<int, string>, warnings: array<int, string>, orphans: array<string, int>}
 */
function guardClass(string $key, PDO $pdo): array
{
    $blockers = [];
    $warnings = [];
    $orphans = [];

    switch ($key) {
        case 'permissions-cross-tenant-test':
            // 迁移/seeder 里不允许出现待删 slug，否则删掉会让 check-migration-data 变红
            $hits = scanCodeReferences('cross_tenant.test');
            foreach ($hits as $hit) {
                if (!$hit['comment']) {
                    $blockers[] = '代码里仍有非注释引用：' . $hit['file'] . ':' . $hit['line'];
                }
            }
            $migrationHits = scanDirectoryForLiteral('database', 'cross_tenant.test');
            if ($migrationHits > 0) {
                $blockers[] = 'database/ 下发现 ' . $migrationHits . ' 处 cross_tenant.test，删除会与迁移/种子冲突';
            }
            break;

        case 'roles-cross-tenant':
            $rows = fetchAll(
                $pdo,
                "SELECT id, tenant_id, is_system FROM roles WHERE slug LIKE '" . LIKE_CROSS_TENANT_ROLE . "'"
            );
            foreach ($rows as $row) {
                if ((int) $row['is_system'] === 1) {
                    $blockers[] = '角色 ' . $row['id'] . ' 是系统角色（is_system=1）';
                }
                if (in_array((int) $row['tenant_id'], PROTECTED_TENANT_IDS, true)) {
                    $blockers[] = '角色 ' . $row['id'] . ' 属于受保护租户 ' . $row['tenant_id'];
                }
            }
            break;

        case 'users-test-nonprimary':
            $rows = fetchAll($pdo, 'SELECT id, username FROM users WHERE ' . candidateUsersWhere());
            foreach ($rows as $row) {
                if (in_array((int) $row['id'], PROTECTED_USER_IDS, true)) {
                    $blockers[] = '用户 ' . $row['id'] . ' 在受保护名单内';
                }
                if (in_array((string) $row['username'], PROTECTED_USERNAMES, true)) {
                    $blockers[] = '用户名 ' . $row['username'] . ' 在受保护名单内';
                }
            }
            if ($rows !== []) {
                $idList = implode(',', array_map(static fn (array $r): string => (string) $r['id'], $rows));
                // 持有系统角色的「测试用户」可能是真实管理员，必须人工判断
                $systemRoles = fetchAll(
                    $pdo,
                    'SELECT DISTINCT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id'
                    . ' WHERE ur.user_id IN (' . $idList . ')'
                );
                foreach ($systemRoles as $role) {
                    if (in_array((string) $role['slug'], PROTECTED_ROLE_SLUGS, true)) {
                        $blockers[] = '候选用户持有系统角色 ' . $role['slug'] . '，需人工确认';
                    }
                }
                $roleRows = fetchInt($pdo, 'SELECT COUNT(*) FROM user_roles WHERE user_id IN (' . $idList . ')');
                if ($roleRows > 0) {
                    $blockers[] = '候选用户仍有 ' . $roleRows . ' 行 user_roles，需人工确认后再删';
                }
                $wfRows = fetchInt(
                    $pdo,
                    'SELECT (SELECT COUNT(*) FROM workflow_definitions WHERE created_by IN (' . $idList . ') OR updated_by IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_event_logs WHERE user_id IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_instances WHERE assignee IN (' . $idList . ') OR created_by IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_tasks WHERE assignee IN (' . $idList . ') OR created_by IN (' . $idList . '))'
                    . ' + (SELECT COUNT(*) FROM workflow_triggers WHERE created_by IN (' . $idList . '))'
                );
                if ($wfRows > 0) {
                    $blockers[] = '候选用户被 ' . $wfRows . ' 行 workflow_* 引用（删了会被 SET NULL）';
                }
            }
            break;

        case 'tenants-second-tenant':
            $set = "(SELECT id FROM tenants WHERE slug LIKE '" . LIKE_SECOND_TENANT . "')";
            $protected = fetchAll(
                $pdo,
                "SELECT id FROM tenants WHERE slug LIKE '" . LIKE_SECOND_TENANT . "' AND id IN ("
                . implode(',', PROTECTED_TENANT_IDS) . ')'
            );
            foreach ($protected as $row) {
                $blockers[] = '租户 ' . $row['id'] . ' 是受保护租户';
            }
            // 级联目标里不允许出现「非测试形态」的数据
            $cascadeChecks = [
                'users' => '用户',
                'sites' => '站点',
                'depts' => '部门',
                'menus' => '菜单',
                'lowcode_forms' => '低代码表单',
            ];
            foreach ($cascadeChecks as $table => $label) {
                $cnt = fetchInt($pdo, 'SELECT COUNT(*) FROM `' . $table . '` WHERE tenant_id IN ' . $set);
                if ($cnt > 0) {
                    $blockers[] = '候选租户下仍有 ' . $cnt . ' 行 ' . $table . '（' . $label . '），删除会级联清掉';
                }
            }
            $wfCnt = fetchInt(
                $pdo,
                'SELECT (SELECT COUNT(*) FROM workflow_definitions WHERE tenant_id IN ' . $set . ')'
                . ' + (SELECT COUNT(*) FROM workflow_instances WHERE tenant_id IN ' . $set . ')'
                . ' + (SELECT COUNT(*) FROM workflow_tasks WHERE tenant_id IN ' . $set . ')'
                . ' + (SELECT COUNT(*) FROM workflow_triggers WHERE tenant_id IN ' . $set . ')'
                . ' + (SELECT COUNT(*) FROM workflow_event_logs WHERE tenant_id IN ' . $set . ')'
            );
            if ($wfCnt > 0) {
                $blockers[] = '候选租户下仍有 ' . $wfCnt . ' 行 workflow_*，删除会级联清掉';
            }
            // 角色：只允许跨租户测试角色
            $otherRoles = fetchAll(
                $pdo,
                'SELECT id, slug FROM roles WHERE tenant_id IN ' . $set
                . " AND slug NOT LIKE '" . LIKE_CROSS_TENANT_ROLE . "'"
            );
            foreach ($otherRoles as $row) {
                $blockers[] = '候选租户下存在非测试角色 ' . $row['id'] . '(' . $row['slug'] . ')';
            }
            // lowcode_collections 没有到 tenants 的外键，删租户不会带走它们，会留成孤儿
            $collectionCnt = fetchInt($pdo, 'SELECT COUNT(*) FROM lowcode_collections WHERE tenant_id IN ' . $set);
            if ($collectionCnt > 0) {
                $blockers[] = '候选租户下仍有 ' . $collectionCnt . ' 个 lowcode 集合（无外键，会变孤儿）';
            }
            // 无外键的监控表：会被留成孤儿行，只警告不阻塞
            foreach (DANGLING_MONITOR_TABLES as $table) {
                $cnt = fetchInt($pdo, 'SELECT COUNT(*) FROM `' . $table . '` WHERE tenant_id IN ' . $set);
                if ($cnt > 0) {
                    $orphans[$table] = $cnt;
                }
            }
            if ($orphans !== []) {
                $warnings[] = '以下表没有 tenants 外键，删除后会留下 tenant_id 指向已删租户的孤儿行'
                    . '（本脚本刻意不删，见报告「未做项」）：' . json_encode($orphans, JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'lowcode-collections-test':
            $names = '(SELECT name FROM lowcode_collections WHERE ' . testCollectionWhere() . ')';
            $formCnt = fetchInt($pdo, 'SELECT COUNT(*) FROM lowcode_forms WHERE collection_name IN ' . $names);
            if ($formCnt > 0) {
                $blockers[] = '有 ' . $formCnt . ' 个 lowcode_forms 引用这些集合，不能删';
            }
            // 候选集里若出现不匹配模式的集合，说明判据被改坏了 —— 直接拒绝
            $mismatch = fetchAll(
                $pdo,
                'SELECT id, name FROM lowcode_collections WHERE ' . testCollectionWhere()
                . ' AND name NOT LIKE \'e2e\\_cli%\' AND name NOT LIKE \'test\\_%\' AND name NOT LIKE \'%\\_test\''
            );
            foreach ($mismatch as $row) {
                $blockers[] = '集合 ' . $row['id'] . '(' . $row['name'] . ') 不匹配任何测试模式';
            }
            $tenantOne = fetchAll(
                $pdo,
                'SELECT id, name FROM lowcode_collections WHERE ' . testCollectionWhere() . ' AND tenant_id = 1'
            );
            foreach ($tenantOne as $row) {
                $warnings[] = '集合 ' . $row['id'] . '(' . $row['name'] . ') 位于真实租户 1，'
                    . '名称是 test_ 前缀的测试夹具，lowcode_forms 0 行引用，判定为可删';
            }
            break;

        default:
            $blockers[] = '未知或不允许执行的类别：' . $key;
            break;
    }

    return ['blockers' => $blockers, 'warnings' => $warnings, 'orphans' => $orphans];
}

/**
 * 递归扫描目录下 php/md 文件里某字面量的出现次数
 */
function scanDirectoryForLiteral(string $dir, string $needle): int
{
    $path = REPO_ROOT . '/' . $dir;
    if (!is_dir($path)) {
        return 0;
    }
    $count = 0;
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
    );
    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        $count += substr_count($content, $needle);
    }

    return $count;
}

// ---------------------------------------------------------------------------
// 守卫执行
// ---------------------------------------------------------------------------

/**
 * 运行守卫并打印结果
 *
 * dry-run 与 --apply 共用：保证「清点时判定可执行」和「执行时实际放行」是同一个判断。
 *
 * @return bool true=可以执行；false=守卫未通过
 */
function printGuardReport(string $key, PDO $pdo, bool $acknowledgeOrphans): bool
{
    $guard = guardClass($key, $pdo);

    foreach ($guard['warnings'] as $warning) {
        printField('警告', colorize($warning, 'yellow'));
    }
    if ($guard['orphans'] !== []) {
        printField(
            '孤儿行',
            colorize(json_encode($guard['orphans'], JSON_UNESCAPED_UNICODE), 'yellow')
                . '（无外键，删租户不会带走，本工具不删）'
        );
        if (!$acknowledgeOrphans) {
            printField('守卫', colorize('未通过：需显式加 --acknowledge-orphans 确认孤儿行风险', 'red'));

            return false;
        }
    }
    if ($guard['blockers'] !== []) {
        foreach ($guard['blockers'] as $blocker) {
            printField('阻塞', colorize($blocker, 'red'));
        }
        printField('守卫', colorize('未通过：存在阻塞项', 'red'));

        return false;
    }
    printField('守卫', colorize('通过', 'green'));

    return true;
}

// ---------------------------------------------------------------------------
// 删除（本文件唯一写 DELETE 的地方）
// ---------------------------------------------------------------------------

/**
 * 执行某一类的删除
 *
 * 所有写操作集中在这里，逐条可审查。每个分支都要求已经过 guardClass()。
 *
 * @return array{deleted: int, detail: array<string, int|string>, failures: array<int, string>}
 */
function applyDelete(\think\App $app, PDO $pdo, string $key): array
{
    $result = ['deleted' => 0, 'detail' => [], 'failures' => []];

    switch ($key) {
        case 'permissions-cross-tenant-test':
            $sql = "DELETE FROM permissions WHERE slug LIKE '" . LIKE_CROSS_TENANT_TEST . "'";
            $result['deleted'] = $pdo->exec($sql);
            break;

        case 'roles-cross-tenant':
            $sql = "DELETE FROM roles WHERE slug LIKE '" . LIKE_CROSS_TENANT_ROLE . "'";
            $result['deleted'] = $pdo->exec($sql);
            break;

        case 'users-test-nonprimary':
            $sql = 'DELETE FROM users WHERE ' . candidateUsersWhere();
            $result['deleted'] = $pdo->exec($sql);
            break;

        case 'tenants-second-tenant':
            $sql = "DELETE FROM tenants WHERE slug LIKE '" . LIKE_SECOND_TENANT . "'";
            $result['deleted'] = $pdo->exec($sql);
            break;

        case 'lowcode-collections-test':
            // 走业务同一条路径：CollectionManager::delete() 会删关系/字段/元数据 + 删物理表 + 清缓存
            $manager = $app->make(\Infrastructure\Lowcode\Collection\Service\CollectionManager::class);
            $rows = fetchAll(
                $pdo,
                'SELECT id, name, tenant_id, table_name FROM lowcode_collections WHERE '
                . testCollectionWhere() . ' ORDER BY id'
            );
            foreach ($rows as $row) {
                $name = (string) $row['name'];
                $tenantId = (int) $row['tenant_id'];
                $tableName = (string) $row['table_name'];
                try {
                    $manager->delete($name, true, $tenantId);
                    $result['deleted']++;
                } catch (\Throwable $e) {
                    // 有的守卫抛的是 HttpResponseException，getMessage() 为空 —— 只打消息会得到
                    // 一串没有原因的「失败：e2e_cli_xxx（）」，因此连类名一起打。
                    $message = $e->getMessage() !== '' ? $e->getMessage() : '（异常消息为空，请看类名与 runtime 日志）';
                    $result['failures'][] = $name . '（tenant ' . $tenantId . '）：'
                        . get_class($e) . ' ' . $message;
                }
            }
            $result['detail']['物理表是否存在'] = fetchInt(
                $pdo,
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('
                . 'SELECT table_name FROM lowcode_collections WHERE ' . testCollectionWhere() . ')'
            ) === 0 ? '已全部删除' : '仍有残留（见失败列表）';
            $result['detail']['示例表名'] = (string) ($rows[0]['table_name'] ?? '');
            break;

        default:
            $result['failures'][] = '未知或不允许执行的类别：' . $key;
            break;
    }

    return $result;
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

/**
 * 打印单个类别的清点块
 *
 * @param array<string, mixed> $definition 类别定义
 */
function printInventoryBlock(string $key, array $definition, PDO $pdo): void
{
    $verdict = (string) $definition['verdict'];
    $verdictText = $verdict === 'safe'
        ? colorize('[安全·可删]', 'green')
        : colorize('[歧义·只报告]', 'yellow');

    printField('类别', $key);
    printField('判据', (string) $definition['title']);
    printField('条数', (string) ($definition['count'])($pdo));
    printField('样例', (string) ($definition['samples'])($pdo));
    $deps = ($definition['deps'])($pdo);
    foreach ($deps as $label => $value) {
        printField('依赖·' . $label, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);
    }
    if (isset($definition['extra'])) {
        /** @var array<string, string> $extra */
        $extra = ($definition['extra'])();
        foreach ($extra as $label => $value) {
            printField($label, $value);
        }
    }
    printField('判定', $verdictText);
    printField('理由', (string) $definition['reason']);
    echo PHP_EOL;
}

/**
 * 主函数
 *
 * @param array<int, string> $argv 原始参数
 */
function main(array $argv): int
{
    $options = parseArgs($argv);
    if ($options['help']) {
        usage();

        return 0;
    }

    $catalog = classCatalog();
    $allKeys = array_keys($catalog);

    $selected = $options['only'];
    if ($selected === []) {
        $selected = $allKeys;
    }
    foreach ($selected as $key) {
        if (!isset($catalog[$key])) {
            fwrite(STDERR, colorize('[FATAL] 未知类别：' . $key, 'red') . PHP_EOL);
            fwrite(STDERR, '可选类别：' . implode(', ', $allKeys) . PHP_EOL);
            exit(1);
        }
    }

    // --apply 的硬前置：必须显式 --only + 必须给备份
    if ($options['apply']) {
        if ($options['only'] === []) {
            fwrite(STDERR, colorize('[FATAL] --apply 必须显式指定 --only=<类名>，不提供批量开关', 'red') . PHP_EOL);
            exit(1);
        }
        if ($options['backup'] === null) {
            fwrite(STDERR, colorize('[FATAL] --apply 必须给出 --backup=<全库备份文件>', 'red') . PHP_EOL);
            exit(1);
        }
    }

    $app = bootstrapApp($options['apply']);
    $pdo = connectPdo($app);
    $dbName = assertDevDatabase($pdo);

    echo colorize('开发库残留清理 | Dev DB Pollution Cleanup', 'blue') . PHP_EOL;
    echo str_repeat('-', 64) . PHP_EOL;
    printField('目标库', $dbName);
    printField('模式', $options['apply'] ? colorize('APPLY（会写库）', 'red') : 'dry-run（只读）');
    printField('类别', implode(', ', $selected));

    $backupPath = null;
    if ($options['apply']) {
        $backupPath = assertBackupFile((string) $options['backup']);
        printField('备份文件', $backupPath . '（' . number_format((float) filesize($backupPath)) . ' 字节）');
    }

    $before = fingerprint($pdo);
    printSection('清理前指纹');
    printFingerprint($before);

    printSection('清点报告');
    $runKeys = [];
    foreach ($selected as $key) {
        printInventoryBlock($key, $catalog[$key], $pdo);
        if ($catalog[$key]['verdict'] !== 'safe') {
            continue;
        }
        // dry-run 也跑一遍守卫：让「能不能删」在写库之前就暴露，而不是执行到一半才拦。
        $guardOk = printGuardReport($key, $pdo, $options['acknowledgeOrphans']);
        if ($guardOk) {
            $runKeys[] = $key;
        }
    }

    if (!$options['apply']) {
        printSection('dry-run 结论');
        printField('可执行类别', $runKeys === [] ? '（无）' : implode(', ', $runKeys));
        $ambiguous = array_values(array_diff($selected, $runKeys));
        printField('未通过/歧义', $ambiguous === [] ? '（无）' : implode(', ', $ambiguous));
        echo PHP_EOL . colorize('本次为 dry-run，数据库未被修改。', 'green') . PHP_EOL;

        return 0;
    }

    // ---------------- 真正执行 ----------------
    $current = $before;
    $failed = false;

    foreach ($selected as $key) {
        printSection('执行：' . $key);

        if ($catalog[$key]['verdict'] !== 'safe') {
            printField('结果', colorize('拒绝：该类别判定为「歧义」，本工具不提供删除路径', 'yellow'));
            printField('理由', (string) $catalog[$key]['reason']);
            continue;
        }

        if (!printGuardReport($key, $pdo, $options['acknowledgeOrphans'])) {
            printField('结果', colorize('拒绝：守卫未通过，整类跳过（不做部分删除）', 'red'));
            $failed = true;
            continue;
        }

        $result = applyDelete($app, $pdo, $key);
        printField('删除行数', (string) $result['deleted']);
        foreach ($result['detail'] as $label => $value) {
            printField((string) $label, is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);
        }
        foreach ($result['failures'] as $failure) {
            printField('失败', colorize($failure, 'red'));
            $failed = true;
        }

        $now = fingerprint($pdo);
        printField('本类后指纹差值', '');
        printFingerprint($now, $current);
        $current = $now;
    }

    printSection('清理后指纹（对比清理前）');
    printFingerprint($current, $before);

    printSection('结论');
    printField('目标库', $dbName);
    printField('状态', $failed ? colorize('有类别被拒绝或出错，请查看上文', 'red') : colorize('全部执行成功', 'green'));

    return $failed ? 1 : 0;
}

exit(main($argv));
