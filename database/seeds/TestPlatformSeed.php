<?php

/**
 * Test Platform Seed | 测试库平台夹具种子
 *
 * 与 CorePlatformSeed 的唯一差别：**系统用户（id = 1）没有可用口令**。
 *
 * 【为什么需要它（R50）】
 *   测试硬编码了 `userId: 1, tenantId: 1, siteId: 0`（见 tests/Helpers/AuthHelper::generateTestToken），
 *   但迁移链只建 schema 与 19 条系统权限，**不建任何角色/用户**：
 *   全新库 `migrate:run` 之后是 permissions=19 / roles=0 / users=0 / sites=0，
 *   于是所有「以 admin(user_id=1) 身份访问受保护接口」的用例都拿不到权限，整片变红。
 *   这些数据属于「种子数据」而不是「表结构」，因此不塞进迁移链（数据归 seeder、schema 归迁移）。
 *
 * 【为什么不复用 CorePlatformSeed 的 createDefaultUser()】
 *   它有两条为生产/开发环境设计的硬约束，直接搬到测试库都不合适：
 *     1. 必须显式提供 ADMIN_INIT_PASSWORD，缺失即 fail-closed —— CI 与本地测试库因此必须
 *        多维护一个「口令类」环境变量；
 *     2. 会创建一个**可登录**的 admin 账号，而测试链路只校验 JWT（AuthHelper 直接签发），
 *        从头到尾不需要任何口令。
 *   所以这里重写 createDefaultUser()：插入 id = 1 的系统用户，口令字段写一个
 *   **随机生成、任何人都不知道原文**的哈希。它既不是「代码里的弱口令」，
 *   也不是「环境变量里的口令」，而是「根本没有可用口令」—— 顺着 R43「口令不进代码」的方向再走一步。
 *
 * 【使用方式】
 *   测试库（库名必须含 `_test`）：
 *     PHP_ENV_NAME=test php think seed:run -s TestPlatformSeed
 *   CI：见 .github/workflows/backend-php-cs-fixer.yml 的 "Seed test fixtures" 步骤。
 *
 * 【产出清单】
 *   - tenants：1（迁移已建）+ 2（隔离对照，本文件 ensureSecondTenant()）
 *   - sites：1
 *   - roles：1 admin / 2 user（显式 id，父类 createRoles()）
 *   - permissions：33 条业务权限（显式 id 1001..1033，含 workflow_* 10 条，父类 ensurePermissions()）
 *   - role_permissions：admin 全量 + user 只读 3 条
 *   - users：id = 1 的系统用户（无可用口令）
 *   - user_roles：user 1 → role 1
 *
 * 【幂等】
 *   继承的 run() 全部是「存在即跳过」；本文件新增的 createDefaultUser() 同样先按 id = 1 判存在，
 *   角色绑定用 INSERT ... WHERE NOT EXISTS。重复执行不产生新行、不覆盖人工数据、不报错。
 *
 * 【边界：不会写进开发库/生产库】
 *   run() 入口先查 `SELECT DATABASE()`，库名不含 `_test` 时**只打印跳过、什么都不做**。
 *   为什么是「跳过」而不是「抛异常」：`php think seed:run` 不带 -s 时会遍历 database/seeds 下
 *   所有 seeder，若这里抛异常，开发库上原本正常的 `seed:run` 会被这个测试专用 seeder 带崩。
 *   显式 `-s TestPlatformSeed` 且库名含 `_test` 时才会真正写数据。
 */
class TestPlatformSeed extends CorePlatformSeed
{
    /**
     * 测试库库名必须包含的标记（与 tests/ThinkPHPTestCase::TEST_DB_MARKER 保持一致）
     */
    private const TEST_DB_MARKER = '_test';

    /**
     * 测试系统用户主键
     *
     * 固定为 1：tests/Helpers/AuthHelper 的默认 userId 就是 1，
     * 动态 id 无法满足「JWT 里写 1，库里也必须是 1」这条硬约束。
     */
    private const TEST_USER_ID = 1;

    /**
     * 测试系统用户所属租户
     */
    private const TEST_USER_TENANT_ID = 1;

    /**
     * 测试系统用户名（刻意不叫 admin：避免与开发库的真实管理员混淆）
     */
    private const TEST_USERNAME = 'test_system_user';

    /**
     * 测试系统用户邮箱
     */
    private const TEST_USER_EMAIL = 'test_system_user@alkaid.test';

    /**
     * Run seed | 执行填充
     *
     * 非测试库直接跳过（见类注释「边界」）。
     */
    public function run(): void
    {
        $database = $this->databaseName();

        if (!str_contains($database, self::TEST_DB_MARKER)) {
            $this->writeLine(sprintf(
                '  跳过：当前连接的库为「%s」，不含「%s」。本 seeder 只用于测试库，'
                . '不会在开发库/生产库上创建测试专用系统用户。',
                $database,
                self::TEST_DB_MARKER
            ));

            return;
        }

        $this->writeLine('  目标库：' . $database . '（测试库，开始写入平台夹具）');

        parent::run();

        // 第二租户（隔离对照）| Second tenant used as the isolation counterpart
        $this->ensureSecondTenant();
    }

    /**
     * 确保「第二租户」存在 | Ensure the second tenant exists
     *
     * 【为什么测试库需要它】
     *   `FormApiMultiTenantE2ETest` 与 `FormDataControllerMultiTenantTest` 把租户 2 当作
     *   「另一个租户」的隔离对照（`headersForTenant(2)` / `tenant_id = 2` 的数据行）。
     *   而 `lowcode_forms.tenant_id` 上有外键 `fk_lowcode_forms_tenant_id`（迁移 20251224000001）——
     *   租户 2 不存在时，往租户 2 建表单会直接撞外键，接口返回 400，
     *   用例表现成「多租户隔离坏了」。实测：补上租户 2 后，这两条从 400 转绿。
     *
     * 【为什么不放在 CorePlatformSeed】
     *   真实环境里「第二个租户」是业务数据，平台种子没有任何理由凭空造一个；
     *   它只是测试的隔离对照物，因此留在测试专用 seeder 里。
     *
     * 幂等：按 id = 2 或 slug = 'test-tenant-2' 判存在，存在即跳过。
     */
    private function ensureSecondTenant(): void
    {
        $existing = $this->fetchRow(
            "SELECT `id` FROM `tenants` WHERE `id` = 2 OR `slug` = 'test-tenant-2' ORDER BY `id` LIMIT 1"
        );

        if (!empty($existing)) {
            $this->writeLine('  跳过：第二租户已存在（id 为 ' . $existing['id'] . '）');

            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->table('tenants')->insert([
            [
                'id'         => 2,
                'name'       => 'Test Tenant Two',
                'slug'       => 'test-tenant-2',
                'domain'     => null,
                'status'     => 'active',
                'config'     => json_encode(['locale' => 'zh_CN', 'timezone' => 'Asia/Shanghai']),
                'max_sites'  => 10,
                'max_users'  => 100,
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();

        $this->writeLine('  新建：第二租户（id 为 2，slug 为 test-tenant-2，供多租户隔离用例作对照）');
    }

    /**
     * 创建测试系统用户（无可用口令）| Create the test system user without a usable password
     *
     * 与父类的三点差异：
     *   1. 不读 ADMIN_INIT_PASSWORD，也不需要任何口令 —— 测试只校验 JWT；
     *   2. 口令字段是随机哈希，任何人都无法用它登录；
     *   3. 用户与角色绑定**分开判存**：用户已存在但没绑角色时也能修好（父类在这种情况下会整体 return）。
     */
    protected function createDefaultUser(): void
    {
        $existing = $this->fetchRow(sprintf(
            'SELECT `id`, `username` FROM `users` WHERE `id` = %d LIMIT 1',
            self::TEST_USER_ID
        ));

        if (!empty($existing)) {
            // 不覆盖已有行：测试库里 id = 1 可能已经是别的用例建的用户（全新库上
            // TestDataFactory 的自增从 1 开始，很容易先占住）。此时**沿用**它 ——
            // 测试真正依赖的是「存在 id = 1 的用户，且它有 admin 角色」，用户名并不参与断言。
            // 这里把实际情况打出来，避免「以为种了 test_system_user，实际是别人」的静默错位。
            $this->writeLine(sprintf(
                '  沿用：id 为 %d 的用户已存在（username 为 %s），不覆盖；仅补齐管理员角色绑定',
                self::TEST_USER_ID,
                (string) $existing['username']
            ));
        } else {
            // 随机 32 字节 → 64 位十六进制，再做 bcrypt。
            // 原文不落盘、不进日志、不进代码，因此这个账号**不可能被登录**：
            // 这比「写一个已知测试口令」更安全 —— 测试库一旦被误暴露，也没有可用凭据。
            $unusablePassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

            $now = date('Y-m-d H:i:s');

            $this->table('users')->insert([
                [
                    'id'                => self::TEST_USER_ID,
                    'tenant_id'         => self::TEST_USER_TENANT_ID,
                    'username'          => self::TEST_USERNAME,
                    'email'             => self::TEST_USER_EMAIL,
                    'password'          => $unusablePassword,
                    'name'              => 'Test System User',
                    'avatar'            => null,
                    'phone'             => null,
                    'status'            => 'active',
                    'email_verified_at' => $now,
                    'last_login_at'     => null,
                    'last_login_ip'     => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            ])->saveData();

            $this->writeLine(sprintf(
                '  新建：系统用户（id 为 %d，username 为 %s，口令为随机不可用哈希）',
                self::TEST_USER_ID,
                self::TEST_USERNAME
            ));
        }

        // 绑定管理员角色（role_id = 1 由父类 createRoles() 以显式 id 建出）。
        // 幂等：NOT EXISTS 保证重复执行不会撞 (user_id, role_id) 复合主键。
        $this->execute(sprintf(
            'INSERT INTO `user_roles` (`user_id`, `role_id`, `created_at`)
             SELECT %d, 1, NOW()
             FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM `user_roles` WHERE `user_id` = %d AND `role_id` = 1)',
            self::TEST_USER_ID,
            self::TEST_USER_ID
        ));

        $this->writeLine(sprintf('  授权：系统用户 id=%d → 角色 admin（role_id=1）已确保存在', self::TEST_USER_ID));
    }

    /**
     * 当前连接的库名 | Current database name
     *
     * 用 `SELECT DATABASE()` 而不是读配置：它反映的是**这条连接实际连上的库**，
     * 配置文件写什么都不影响判断结果 —— 这道守卫要挡住的正是「以为连的是测试库」。
     */
    private function databaseName(): string
    {
        $row = $this->fetchRow('SELECT DATABASE() AS `db`');

        return is_array($row) ? (string) ($row['db'] ?? '') : '';
    }
}
