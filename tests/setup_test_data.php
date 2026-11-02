<?php

declare(strict_types=1);

/**
 * 开发脚手架：为手工联调准备「已知账号 + 测试角色 + 低代码权限」
 * DEV-ONLY SCAFFOLD — DO NOT RUN IN CI / STAGING / PRODUCTION
 *
 * ============================================================================
 * ⚠️ 先读这一段再决定要不要执行
 * ============================================================================
 *
 * 1. **这不是 PHPUnit 夹具**：`phpunit.xml` 的 bootstrap 是 `vendor/autoload.php`，
 *    `tests/**` 里没有任何用例 include 本文件，`.github/workflows/**` 也没有任何一步调用它。
 *    测试套件的夹具由 `tests/Factories`、`tests/Traits` 与迁移 seeder 自己建，
 *    本脚本**不在测试链路里**（历史用途见 `docs/report_archive/2025.11.25/`）。
 *
 * 2. **它会写库**：连的是 `.env` 里 `DB_NAME` 指向的库。在 `alkaid-backend` 容器里
 *    那默认是**开发库** `alkaid_sys`（不是 `alkaid_sys_test`）。想对测试库执行就显式
 *    传 `--db-name=alkaid_sys_test`；脚本会把**实际连上的库名**打印在第一段。
 *
 * 3. **它曾经是一条地雷**（缺陷 #2）：旧版把「授予 test_admin 角色」写死到 `user_id = 1`，
 *    并顺手把该用户的 `status` 覆盖成 1（active）。于是在开发库里跑一次，就会把
 *    `lowcode.read/write/delete`（其中 `delete` 能删物理表）重新授给一个**已被停用**的账号，
 *    还会把它重新启用。现在的规则是：
 *      - 用户按 **username** 解析（默认 `test_user`），不再有硬编码 id；
 *      - 解析到的用户 **`status !== 'active'` → 直接拒绝、非 0 退出、一行都不写**；
 *      - 不再改动既有用户的 `status` / `password` / `email`（需要同步凭据必须显式
 *        `--sync-credentials`，且只对 active 用户生效）；
 *      - `APP_ENV` 属于生产类环境（production / prod / stage / staging）→ 直接拒绝执行。
 *
 * 4. **长期归属**：这类「种子角色 + 权限」应当由**正式迁移 / seeder** 负责（可审计、可回放、
 *    有 CI 数据一致性检查兜底）。本脚本只是过渡期的手工脚手架，保留是为了不打断既有的
 *    本地联调习惯；**新增依赖请不要指向它**。
 *
 * ============================================================================
 * 用法
 * ============================================================================
 *
 *   php tests/setup_test_data.php                       # 默认 username=test_user，库取自 .env
 *   php tests/setup_test_data.php --username=alice      # 指定账号（推荐）
 *   php tests/setup_test_data.php --user-id=42          # 按 ID 指定（与 --username 二选一）
 *   php tests/setup_test_data.php --db-name=alkaid_sys_test
 *   php tests/setup_test_data.php --tenant-id=1         # 角色/权限落到的租户，默认 1
 *   php tests/setup_test_data.php --dry-run             # 只解析与校验，不写库
 *   php tests/setup_test_data.php --create-user         # 账号不存在时才创建（默认拒绝创建）
 *   php tests/setup_test_data.php --sync-credentials    # 同步密码/邮箱（仅对 active 用户）
 *
 * 对应环境变量（命令行参数优先）：TEST_SETUP_USERNAME / TEST_SETUP_USER_ID /
 * TEST_SETUP_DB_NAME / TEST_SETUP_TENANT_ID
 *
 * 退出码：0 = 成功（或 --dry-run 走完）；1 = 前置条件不满足（未写库）。
 *
 * @package Tests
 */

require __DIR__ . '/../vendor/autoload.php';

use think\facade\Config;
use think\facade\Db;

/**
 * 生产类环境词表（与 `Infrastructure\Auth\JwtService::PRODUCTION_ENVS` 保持一致）
 */
const PRODUCTION_LIKE_ENVS = ['production', 'prod', 'stage', 'staging'];

/**
 * 低代码权限定义（slug 必须符合 `resource.action` 两段式，见 scripts/check-permission-codes.php）
 */
const LOWCODE_PERMISSIONS = [
    [
        'slug' => 'lowcode.read',
        'name' => 'Lowcode Read',
        'resource' => 'lowcode',
        'action' => 'read',
        'description' => 'Read lowcode resources',
    ],
    [
        'slug' => 'lowcode.write',
        'name' => 'Lowcode Write',
        'resource' => 'lowcode',
        'action' => 'write',
        'description' => 'Create and update lowcode resources',
    ],
    [
        'slug' => 'lowcode.delete',
        'name' => 'Lowcode Delete',
        'resource' => 'lowcode',
        'action' => 'delete',
        'description' => 'Delete lowcode resources',
    ],
];

/**
 * 解析命令行参数与环境变量
 *
 * @param array<int, string> $argv 原始 argv
 * @return array<string, mixed> 归一化后的选项
 */
function parseOptions(array $argv): array
{
    $options = [
        'username' => getenv('TEST_SETUP_USERNAME') !== false ? (string) getenv('TEST_SETUP_USERNAME') : 'test_user',
        'user_id' => getenv('TEST_SETUP_USER_ID') !== false ? (int) getenv('TEST_SETUP_USER_ID') : null,
        'db_name' => getenv('TEST_SETUP_DB_NAME') !== false ? (string) getenv('TEST_SETUP_DB_NAME') : null,
        'tenant_id' => getenv('TEST_SETUP_TENANT_ID') !== false ? (int) getenv('TEST_SETUP_TENANT_ID') : 1,
        'dry_run' => false,
        'create_user' => false,
        'sync_credentials' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if ($argument === '--create-user') {
            $options['create_user'] = true;
            continue;
        }
        if ($argument === '--sync-credentials') {
            $options['sync_credentials'] = true;
            continue;
        }

        if (str_starts_with($argument, '--username=')) {
            $options['username'] = substr($argument, strlen('--username='));
            continue;
        }
        if (str_starts_with($argument, '--user-id=')) {
            $options['user_id'] = (int) substr($argument, strlen('--user-id='));
            continue;
        }
        if (str_starts_with($argument, '--db-name=')) {
            $options['db_name'] = substr($argument, strlen('--db-name='));
            continue;
        }
        if (str_starts_with($argument, '--tenant-id=')) {
            $options['tenant_id'] = (int) substr($argument, strlen('--tenant-id='));
            continue;
        }

        fail('未知参数：' . $argument . '（--help 查看可用参数）');
    }

    // --username 与 --user-id 互斥：两者同时给出时无从判断以谁为准，
    // 与其猜一个，不如让调用方把意图写清楚。
    if ($options['user_id'] !== null && $options['username'] !== 'test_user') {
        fail('--username 与 --user-id 不能同时使用，请只指定其中一个');
    }

    if ($options['tenant_id'] <= 0) {
        fail('--tenant-id 必须是正整数（当前：' . $options['tenant_id'] . '）');
    }

    return $options;
}

/**
 * 打印用法
 */
function printUsage(): void
{
    echo <<<'TXT'
开发脚手架：为手工联调准备「已知账号 + 测试角色 + 低代码权限」
⚠️  不要在 CI / 生产执行；它会写库。

用法：php tests/setup_test_data.php [选项]

选项：
  --username=<name>   要授权的账号（默认 test_user；推荐）
  --user-id=<id>      按 ID 指定账号（与 --username 二选一）
  --db-name=<name>    显式指定目标库（默认取 .env 的 DB_NAME）
  --tenant-id=<id>    角色与权限落到的租户（默认 1）
  --create-user       账号不存在时才创建（默认拒绝创建）
  --sync-credentials  同步该账号的密码/邮箱（仅对 status=active 生效）
  --dry-run           只做解析与校验，不写库
  -h, --help          显示本帮助

环境变量（命令行参数优先）：TEST_SETUP_USERNAME / TEST_SETUP_USER_ID /
TEST_SETUP_DB_NAME / TEST_SETUP_TENANT_ID

退出码：0 = 成功；1 = 前置条件不满足（此时库未被写入）。
TXT;
    echo PHP_EOL;
}

/**
 * 打印后以非 0 退出（**必须**在写入任何数据之前使用）
 *
 * @param string $message 失败原因
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL . '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}

/**
 * 打印一行普通进度
 *
 * 用 `fwrite(STDOUT, ...)` 而不是 `echo`：`STDOUT` 不走 PHP 的输出缓冲，
 * 与直接写 `STDERR` 的 `fail()` 混排时顺序才可读（否则「拒绝原因」会跑到
 * 「被拒绝的事实」前面，日志看起来像自相矛盾）。
 *
 * @param string $message 文本
 */
function info(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * 定位目标账号
 *
 * 优先按 username（可读、可迁移），只有显式给出 --user-id 时才按主键定位。
 * 返回 `null` 表示「找不到」——由调用方决定是拒绝还是（--create-user 时）创建。
 *
 * @param array<string, mixed> $options 选项
 * @return array<string, mixed>|null 用户行
 */
function findUser(array $options): ?array
{
    $query = Db::table('users');

    if ($options['user_id'] !== null) {
        $query->where('id', (int) $options['user_id']);
    } else {
        $query->where('username', (string) $options['username']);
    }

    $user = $query->find();

    return $user === null ? null : (array) $user;
}

/**
 * 断言账号可被授权
 *
 * 这是本脚本的**核心闸门**：非 active 账号一律不授权。
 * 历史事故正是「把 lowcode.delete（可删物理表）重新授给一个已停用账号」，
 * 静默跳过同样不可接受 —— 调用方会以为授权成功。
 *
 * @param array<string, mixed> $user 用户行
 */
function assertUserActive(array $user): void
{
    $status = (string) ($user['status'] ?? '');
    if ($status === 'active') {
        return;
    }

    fail(sprintf(
        '拒绝授权：账号 id=%s username=%s 的 status 为 "%s"，不是 "active"。'
        . PHP_EOL . '        已停用/锁定账号不得获得角色与权限（本轮未写入任何数据）。'
        . PHP_EOL . '        如确需启用，请走管理端「用户状态」变更流程，而不是用本脚本绕过。',
        (string) ($user['id'] ?? '?'),
        (string) ($user['username'] ?? '?'),
        $status === '' ? '(空)' : $status
    ));
}

/**
 * 断言 -dry-run 之外才允许写库
 *
 * @param array<string, mixed> $options 选项
 * @param string $description 将被执行的动作描述
 * @return bool true = 真正执行；false = dry-run 只打印
 */
function shouldWrite(array $options, string $description): bool
{
    if ($options['dry_run']) {
        info('   [DRY-RUN] 将执行：' . $description);
        return false;
    }

    return true;
}

$options = parseOptions($argv ?? []);

if ($options['help']) {
    printUsage();
    exit(0);
}

$app = new \think\App();
$app->initialize();

info('=== 开发脚手架：准备测试账号 / 角色 / 低代码权限（会写库）===' . PHP_EOL);
// ---------------------------------------------------------------------------
// 0. 生产类环境一律拒绝：本脚本会直接改角色与权限，生产应走迁移 / 管理端
// ---------------------------------------------------------------------------
$appEnv = strtolower(trim((string) env('APP_ENV', 'production')));
if ($appEnv === '') {
    $appEnv = 'production';
}
if (in_array($appEnv, PRODUCTION_LIKE_ENVS, true)) {
    fail(sprintf(
        '拒绝执行：APP_ENV=%s 属于生产类环境。请改用迁移文件 / 正式 seeder，'
        . '本脚本仅用于 dev / local / testing。',
        $appEnv
    ));
}

// ---------------------------------------------------------------------------
// 1. 目标库：默认取 .env 的 DB_NAME，--db-name 可显式覆盖
// ---------------------------------------------------------------------------
if ($options['db_name'] !== null && $options['db_name'] !== '') {
    // 只改 `config/database.php` 的解析结果还不够：`think\Db` 的 getConfig() 读的是
    // `think\Config` 对象，而连接实例在 `$app->initialize()` 期间可能已经建好了
    // （实测：只改 Config 时第一条 SQL 仍打到旧库）。所以还要强制重连一次，
    // 顺带在这里就把「库名写错 / 没权限」暴露出来，而不是等到后面写一半才炸。
    $databaseConfig = (array) config('database');
    $databaseConfig['connections']['mysql']['database'] = (string) $options['db_name'];
    Config::set($databaseConfig, 'database');
    Db::connect(null, true);
}

// 打印「实际连上的库」而不是「配置里写的库」：两者不一致时（例如 .env 与 shell 冲突），
// 只有前者能说明这次写入去了哪里。
try {
    $connectedDb = (string) (Db::query('SELECT DATABASE() AS db')[0]['db'] ?? 'unknown');
} catch (\Throwable $e) {
    // 连不上时不能把驱动异常原样抛出去（会带堆栈与连接串片段）
    fail('无法连接目标数据库（--db-name=' . (string) $options['db_name'] . '）：' . $e->getMessage());
}

info(sprintf(
    '[目标] APP_ENV=%s  数据库=%s  租户=%d  账号=%s',
    $appEnv,
    $connectedDb,
    (int) $options['tenant_id'],
    $options['user_id'] !== null ? ('id=' . $options['user_id']) : (string) $options['username']
));
info(sprintf(
    '[模式] dry-run=%s  账号不存在时创建=%s  同步凭据=%s',
    $options['dry_run'] ? 'yes' : 'no',
    $options['create_user'] ? 'yes' : 'no',
    $options['sync_credentials'] ? 'yes' : 'no'
));
info('');

try {
    // -----------------------------------------------------------------------
    // 2. 解析账号（**先解析、先校验，再写库**）
    // -----------------------------------------------------------------------
    info('1. 解析目标账号 ...');
    $user = findUser($options);

    if ($user === null) {
        if (!$options['create_user']) {
            fail(sprintf(
                '目标账号不存在（%s）。本脚本默认**不创建**账号：创建账号会留下一个'
                . PHP_EOL . '        可登录的开发账号，属于需要人明确决定的操作。'
                . PHP_EOL . '        确认要创建请加 --create-user，或改用正式 seeder。',
                $options['user_id'] !== null ? ('user_id=' . $options['user_id']) : ('username=' . $options['username'])
            ));
        }

        if ($options['user_id'] !== null) {
            // 按主键创建等于「我指定了这个 ID」——比硬编码 id=1 更隐蔽的同款地雷，直接拒绝
            fail('--create-user 只能与 --username 搭配使用：按主键创建账号没有意义，也容易撞上已有 ID');
        }

        $newUsername = (string) $options['username'];
        if ($newUsername === '') {
            fail('--create-user 需要非空的 --username');
        }

        if (shouldWrite($options, sprintf('创建账号 username=%s（status=active）', $newUsername))) {
            // 不指定主键：旧版硬编码 id=1 正是「授权授给了 1 号账号」的地雷来源
            $userId = (int) Db::table('users')->insertGetId([
                'username' => $newUsername,
                'email' => $newUsername . '@example.com',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'tenant_id' => (int) $options['tenant_id'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            info(sprintf('   [CREATED] 账号已创建：id=%d username=%s', $userId, $newUsername));
            $user = findUser(['user_id' => $userId, 'username' => $newUsername]);
        }
    }

    if ($user === null) {
        // 只可能出现在 dry-run 分支：账号尚未创建
        info('   [DRY-RUN] 账号尚未存在，后续授权步骤已跳过');
        info(PHP_EOL . '=== DRY-RUN 结束：库未被写入 ===');
        exit(0);
    }

    // 核心闸门：非 active 账号不得获得任何角色 / 权限（此刻尚未写库）。
    // 注意顺序：先过闸门、再打印「账号已就绪」——否则拒绝时日志里会先出现一行
    // 看起来正常的身份信息，读者容易误以为已经通过。
    assertUserActive($user);
    info(sprintf(
        '   [OK] 账号：id=%s username=%s status=%s tenant_id=%s → 允许后续授权',
        (string) ($user['id'] ?? '?'),
        (string) ($user['username'] ?? '?'),
        (string) ($user['status'] ?? '?'),
        (string) ($user['tenant_id'] ?? '?')
    ));

    // 可选：同步凭据（默认关闭；且只写 password/email，绝不写 status）
    if ($options['sync_credentials']) {
        if (shouldWrite($options, '同步该账号的 email / password（不改 status）')) {
            Db::table('users')->where('id', (int) $user['id'])->update([
                'email' => (string) ($user['username'] ?? 'test_user') . '@example.com',
                'password' => password_hash('password', PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            info('   [SYNCED] 已同步 email / password（status 未被改动）');
        }
    }

    // -----------------------------------------------------------------------
    // 3. 低代码权限（slug 全局唯一，按 slug 幂等）
    // -----------------------------------------------------------------------
    info('');
    info('2. 检查 lowcode 权限 ...');
    $permissionIds = [];
    foreach (LOWCODE_PERMISSIONS as $permission) {
        $existing = Db::table('permissions')->where('slug', $permission['slug'])->find();

        if ($existing !== null) {
            $permissionIds[$permission['slug']] = (int) $existing['id'];
            info(sprintf('   [OK] 已存在：%s (id=%d)', $permission['slug'], (int) $existing['id']));
            continue;
        }

        if (shouldWrite($options, '创建权限 ' . $permission['slug'])) {
            $permissionIds[$permission['slug']] = (int) Db::table('permissions')->insertGetId([
                'slug' => $permission['slug'],
                'name' => $permission['name'],
                'resource' => $permission['resource'],
                'action' => $permission['action'],
                'description' => $permission['description'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            info(sprintf('   [CREATED] %s (id=%d)', $permission['slug'], $permissionIds[$permission['slug']]));
        }
    }

    // -----------------------------------------------------------------------
    // 4. 测试角色（按 slug + tenant_id 定位，缺则创建）
    // -----------------------------------------------------------------------
    info('');
    info('3. 检查测试角色 test_admin ...');
    $role = Db::table('roles')
        ->where('slug', 'test_admin')
        ->where('tenant_id', (int) $options['tenant_id'])
        ->find();

    if ($role !== null) {
        $roleId = (int) $role['id'];
        info(sprintf('   [OK] 已存在：id=%d slug=test_admin tenant_id=%d', $roleId, (int) $options['tenant_id']));
    } else {
        // 说明：自动创建 test_admin 是过渡手段。正式做法应由 seeder 建种子角色
        // （可审计、可回放，且能被 check-migration-data 覆盖），而不是靠这个脚本。
        if (shouldWrite($options, '创建角色 test_admin（tenant_id=' . (int) $options['tenant_id'] . '）')) {
            $roleId = (int) Db::table('roles')->insertGetId([
                'slug' => 'test_admin',
                'name' => 'Test Admin',
                'description' => 'Test administrator role with all lowcode permissions',
                'tenant_id' => (int) $options['tenant_id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            info(sprintf('   [CREATED] 角色已创建：id=%d slug=test_admin', $roleId));
        }
    }

    if (!isset($roleId)) {
        info('   [DRY-RUN] 角色尚未存在，后续授权步骤已跳过');
        info(PHP_EOL . '=== DRY-RUN 结束：库未被写入 ===');
        exit(0);
    }

    // -----------------------------------------------------------------------
    // 5. 角色 ← 权限
    // -----------------------------------------------------------------------
    info('');
    info('4. 为角色授予 lowcode 权限 ...');
    foreach ($permissionIds as $slug => $permissionId) {
        $existing = Db::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->find();

        if ($existing !== null) {
            info(sprintf('   [OK] 已授予：%s', $slug));
            continue;
        }

        if (shouldWrite($options, sprintf('role_permissions += (role_id=%d, permission_id=%d) [%s]', $roleId, $permissionId, $slug))) {
            Db::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            info(sprintf('   [GRANTED] %s', $slug));
        }
    }

    // -----------------------------------------------------------------------
    // 6. 账号 ← 角色（user_id 来自解析结果，不再是硬编码的 1）
    // -----------------------------------------------------------------------
    info('');
    info('5. 为用户授予 test_admin 角色 ...');
    $userId = (int) $user['id'];
    $username = (string) ($user['username'] ?? '?');

    $existingGrant = Db::table('user_roles')
        ->where('user_id', $userId)
        ->where('role_id', $roleId)
        ->find();

    if ($existingGrant !== null) {
        info(sprintf('   [OK] 已授予：user_id=%d username=%s role_id=%d', $userId, $username, $roleId));
    } elseif (shouldWrite($options, sprintf('user_roles += (user_id=%d, role_id=%d)', $userId, $roleId))) {
        Db::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        info(sprintf('   [GRANTED] user_id=%d username=%s → role_id=%d', $userId, $username, $roleId));
    }

    info('');
    if ($options['dry_run']) {
        info('=== DRY-RUN 结束：库未被写入 ===');
        exit(0);
    }

    info('=== 完成 ===');
    info(sprintf('  数据库：%s', $connectedDb));
    info(sprintf('  账号：id=%d username=%s status=%s', $userId, $username, (string) ($user['status'] ?? '?')));
    info(sprintf('  角色：id=%d slug=test_admin tenant_id=%d', $roleId, (int) $options['tenant_id']));
    info('  权限：lowcode.read / lowcode.write / lowcode.delete');
} catch (\Throwable $e) {
    // 只打印异常自身信息，不打印堆栈：本脚本常在开发机上手工执行，
    // 堆栈里会出现绝对路径与数据库连接串片段。
    fail('执行中断：' . get_class($e) . ': ' . $e->getMessage());
}
