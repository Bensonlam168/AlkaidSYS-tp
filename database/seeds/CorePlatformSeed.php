<?php

use think\migration\Seeder;

/**
 * Core Platform Seed | 核心平台种子数据
 *
 * Seeds the database with initial data for tenants, roles, permissions, and users.
 * 为数据库填充租户、角色、权限和用户的初始数据。
 *
 * 【R43：本 seeder 已改为可重复执行（幂等），且默认口令已移出代码】
 *   1. createPermissions() 保留，但**不再写死主键** —— 这才是此前 `seed:run` 必然失败的根因。
 *      原实现用 `'id' => $index + 1` 强制指定 1..20，而迁移链已经占用 id 1..19，
 *      于是在已迁移的库上执行必然报 `Duplicate entry '1' for key 'permissions.PRIMARY'`。
 *      注意：这是**主键**冲突，不是 slug 冲突。
 *      ⚠️ 千万**不要**把它当成「重复数据」删掉 —— 两套权限并不重叠：
 *        - 迁移创建的是系统权限：casbin.manage / tenant.* / role.* / permission.* /
 *          user.*（单数）/ dept.* / menu.*，共 19 条；
 *        - 本 seeder 创建的是业务权限：forms.* / form_data.* / collections.* /
 *          users.*（复数）/ roles.*（复数），共 20 条。
 *      删掉本方法会让 forms.* / form_data.* / collections.* 等 12 个权限在任何环境都不存在，
 *      并让 assignPermissionsToRoles() 里给普通用户的那 3 条只读授权变成匹配 0 行的死代码。
 *      现改为逐条 INSERT ... WHERE NOT EXISTS(slug)，主键交给 AUTO_INCREMENT。
 *   2. createDefaultTenant / createRoles / createDefaultUser 原先都写死主键且无判重，
 *      第二遍执行必撞主键；现均改为「存在即跳过」，重复执行不产生任何新行。
 *      其中**租户段与站点段是两段独立幂等**：迁移 20251219000003 会先建出租户 1，
 *      若站点插入跟在租户段的「已存在就 return」之后，全新库的 sites 会永远停在 0。
 *   3. 管理员初始口令不再硬编码（原先是 password_hash('admin123')），改为读取环境变量
 *      ADMIN_INIT_PASSWORD；缺失或为空时**明确报错并终止**，绝不静默创建弱口令。
 */
class CorePlatformSeed extends Seeder
{
    /**
     * 初始化管理员口令所在的环境变量名
     *
     * 刻意不在代码里给出任何默认值：默认口令一旦落进仓库，就等于全网公开的后门。
     */
    private const ADMIN_PASSWORD_ENV = 'ADMIN_INIT_PASSWORD';

    /**
     * Run seed | 执行填充
     */
    public function run(): void
    {
        // 1. Create default tenant | 创建默认租户
        $this->createDefaultTenant();

        // 2. Create roles | 创建角色
        $this->createRoles();

        // 3. Create permissions | 创建权限
        //    创建迁移链**没有覆盖**的 20 条业务权限（forms.* / form_data.* / collections.* /
        //    users.* / roles.*）。它们与迁移创建的 user.* / role.*（单数）等系统权限是
        //    两套不同的 slug，**不是重复数据**，详见类注释第 1 点。
        $this->createPermissions();

        // 4. Assign permissions to roles | 分配权限给角色
        $this->assignPermissionsToRoles();

        // 5. Create default admin user | 创建默认管理员用户
        $this->createDefaultUser();
    }

    /**
     * 输出一行提示 | Write one line of notice
     *
     * 【为什么不用 $this->output->writeln()（R43 实测踩坑）】
     * think-migration 的 seed:run（vendor/topthink/think-migration/src/command/seed/Run.php 的
     * executeSeed）只给 Seeder 调用了 setAdapter()，**从来没有调用 setOutput()**；
     * 而 AbstractSeed::__construct 声明为无参，Seed::getSeeds() 传进去的 input/output 会被静默丢弃。
     * 结果就是 $this->output 恒为 null，直接调用会抛
     * "Call to a member function writeln() on null"（属于 \Error 而非 \Exception），
     * 把真正的业务错误信息完全盖掉，且因为 \Error 不会被 think\Console::run 捕获，退出码仍是 0。
     *
     * 因此这里做一次兜底：有注入的 output 就用它，没有就直接 echo（CLI 下 output_buffering 为 0，
     * 顺序与 think 的 Output 一致）。
     */
    private function writeLine(string $message): void
    {
        if (!empty($this->output)) {
            $this->output->writeln($message);

            return;
        }

        echo $message . PHP_EOL;
    }

    /**
     * Create default tenant | 创建默认租户
     *
     * 注意：本方法同时负责默认站点，但租户与站点是**两段各自独立的幂等逻辑**，理由见方法内注释。
     */
    protected function createDefaultTenant(): void
    {
        // ===================== 第一段：租户（幂等） =====================
        //
        // 判存在用 id = 1 **或** slug = 'default' 两个条件：
        //   - 迁移 20251219000003 已内联创建 id = 1 的系统租户（全新库必然命中）；
        //   - 老库可能只有 slug = 'default' 而 id 不是 1（历史数据），也应当视作已初始化。
        $existingTenant = $this->fetchRow(
            "SELECT `id` FROM `tenants` WHERE `id` = 1 OR `slug` = 'default' ORDER BY `id` LIMIT 1"
        );
        if (!empty($existingTenant)) {
            $this->writeLine('  跳过：默认租户已存在（id 为 ' . $existingTenant['id'] . '）');
        } else {
            $this->table('tenants')->insert([
                [
                    'id' => 1,
                    'name' => 'Default Tenant',
                    'slug' => 'default',
                    'domain' => null,
                    'status' => 'active',
                    'config' => json_encode([
                        'locale' => 'zh_CN',
                        'timezone' => 'Asia/Shanghai',
                    ]),
                    'max_sites' => 10,
                    'max_users' => 100,
                    'expires_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
            ])->saveData();
            $this->writeLine('  新建：默认租户（id 为 1）');
        }

        // 站点要挂到的租户：优先用上面查到的真实 id（老库上可能不是 1），否则就是刚建的 1。
        $siteTenantId = !empty($existingTenant) ? (int) $existingTenant['id'] : 1;

        // ===================== 第二段：默认站点（独立幂等） =====================
        //
        // 【为什么站点必须与租户分开判断（R43 修复）】
        // 迁移 20251219000003 已内联创建租户 1，所以全新库跑 seed 时第一段**必然**走「跳过」分支。
        // 早先把站点插入跟在第一段的 return 之后，后果是：全新库 sites 恒为 0 ——
        // 不是「已存在所以没插」，而是**代码根本没执行到**。这类「静默缺失」比报错更难查。
        //
        // 因此站点有自己独立的判存口径（id = 1 或 slug = 'default'），与租户是否已存在无关：
        // 「只跑过 migrate:run 的库」正是「租户在、站点缺」的典型状态，必须在这里补齐。
        // 若把两段合并，就等于默认「租户在则站点必在」，而事实并非如此。
        $existingSite = $this->fetchRow(
            "SELECT `id` FROM `sites` WHERE `id` = 1 OR `slug` = 'default' ORDER BY `id` LIMIT 1"
        );
        if (!empty($existingSite)) {
            $this->writeLine('  跳过：默认站点已存在（id 为 ' . $existingSite['id'] . '）');

            return;
        }

        // 站点 id 写死 1 与租户同理：单站点部署下 site 1 是默认站点，
        // 且本方法有独立判重守卫，重复执行不会撞主键。
        $this->table('sites')->insert([
            [
                'id' => 1,
                'tenant_id' => $siteTenantId,
                'name' => 'Default Site',
                'slug' => 'default',
                'domain' => null,
                'status' => 'active',
                'config' => json_encode([
                    'locale' => 'zh_CN',
                    'timezone' => 'Asia/Shanghai',
                ]),
                'theme' => 'default',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();
        $this->writeLine('  新建：默认站点（id 为 1）');
    }

    /**
     * Create roles | 创建角色
     */
    protected function createRoles(): void
    {
        $now = date('Y-m-d H:i:s');

        // 角色 id 必须稳定为 1（admin）与 2（user）：
        // assignPermissionsToRoles() 与下面的 user_roles 授权都以 role_id 1 / 2 为约定，
        // 所以这里不能改成「自增 id + 按 slug 唯一」的建法。
        $roles = [
            [
                'id' => 1,
                'name' => 'Administrator',
                'slug' => 'admin',
                'description' => 'System administrator with full access',
            ],
            [
                'id' => 2,
                'name' => 'User',
                'slug' => 'user',
                'description' => 'Regular user with basic access',
            ],
        ];

        foreach ($roles as $role) {
            // 幂等：INSERT ... WHERE NOT EXISTS，按 slug 判存在（slug 才是角色的业务语义键）。
            // 同样必须带 FROM DUAL —— MySQL 对「没有 FROM 的 SELECT」可能把 WHERE 优化掉。
            $this->execute(sprintf(
                "INSERT INTO `roles`
                    (`id`, `tenant_id`, `name`, `slug`, `description`, `is_system`, `created_at`, `updated_at`)
                 SELECT %d, 1, '%s', '%s', '%s', 1, '%s', '%s'
                 FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `slug` = '%s')",
                $role['id'],
                $role['name'],
                $role['slug'],
                $role['description'],
                $now,
                $now,
                $role['slug']
            ));

            // 【不变量校验】slug 与 id 必须一一对应。
            // 若 id 已被别的角色占用，上面的 INSERT 会因主键冲突抛错（可见的失败，可以接受）；
            // 若 slug 已存在但 id 不同，上面的 INSERT 会静默跳过 —— 那种情况下
            // assignPermissionsToRoles() 会把管理员权限发给 role_id = 1 的**另一个**角色，
            // 属于「静默错配授权」，因此这里显式失败并要求人工核对。
            $created = $this->fetchRow(sprintf(
                "SELECT `id` FROM `roles` WHERE `slug` = '%s' ORDER BY `id` LIMIT 1",
                $role['slug']
            ));
            if ((int) ($created['id'] ?? 0) !== $role['id']) {
                throw new RuntimeException(sprintf(
                    '角色与预期 id 不一致，种子终止：slug 为 %s 的角色期望 id 为 %d，实际为 %s。'
                    . '继续执行会把管理员权限静默分配给错误的角色，请先人工核对 roles 表。',
                    $role['slug'],
                    $role['id'],
                    isset($created['id']) ? (string) $created['id'] : '不存在'
                ));
            }
        }
    }

    /**
     * Create permissions | 创建权限
     *
     * 创建的是**业务权限**：forms.* / form_data.* / collections.* / users.* / roles.* 各 4 条，共 20 条。
     *
     * 【这套权限与迁移创建的权限是两套不同的 slug，不是重复数据 —— 不要「去重」删掉】
     *   - 迁移链创建的是系统权限（19 条）：casbin.manage、tenant.*、role.*、permission.*、
     *     user.*（单数）、dept.*、menu.*；
     *   - 本方法创建的是业务权限（20 条）：users.*（复数）、roles.*（复数）以及表单/集合权限。
     *   两套 slug 完全不重叠（`user.read` 与 `users.view` 是两个不同的权限）。
     *   删掉本方法的后果：全新库上 forms.* / form_data.* / collections.* 等 12 个权限永久缺失，
     *   且 assignPermissionsToRoles() 里给普通用户的那 3 条只读授权会变成匹配 0 行的死代码。
     *
     * 【幂等：不再写死主键】原实现用 `'id' => $index + 1` 强制指定主键 1..20，
     * 而迁移链已占用 id 1..19，于是重复执行 / 在已迁移的库上执行必然报
     * `Duplicate entry '1' for key 'permissions.PRIMARY'` —— 这是**主键**冲突，不是 slug 冲突。
     * 现改为逐条 INSERT ... WHERE NOT EXISTS(slug)，主键交给 AUTO_INCREMENT；
     * 判重键用 slug 是因为 permissions.slug 上有唯一索引（UNI），它才是权限的业务标识。
     */
    protected function createPermissions(): void
    {
        $now = date('Y-m-d H:i:s');

        $permissions = [
            // Form Designer permissions | 表单设计器权限
            ['name' => 'View Forms', 'slug' => 'forms.view', 'resource' => 'forms', 'action' => 'view'],
            ['name' => 'Create Forms', 'slug' => 'forms.create', 'resource' => 'forms', 'action' => 'create'],
            ['name' => 'Update Forms', 'slug' => 'forms.update', 'resource' => 'forms', 'action' => 'update'],
            ['name' => 'Delete Forms', 'slug' => 'forms.delete', 'resource' => 'forms', 'action' => 'delete'],

            // Form Data permissions | 表单数据权限
            ['name' => 'View Form Data', 'slug' => 'form_data.view', 'resource' => 'form_data', 'action' => 'view'],
            ['name' => 'Create Form Data', 'slug' => 'form_data.create', 'resource' => 'form_data', 'action' => 'create'],
            ['name' => 'Update Form Data', 'slug' => 'form_data.update', 'resource' => 'form_data', 'action' => 'update'],
            ['name' => 'Delete Form Data', 'slug' => 'form_data.delete', 'resource' => 'form_data', 'action' => 'delete'],

            // Collection permissions | 集合权限
            ['name' => 'View Collections', 'slug' => 'collections.view', 'resource' => 'collections', 'action' => 'view'],
            ['name' => 'Create Collections', 'slug' => 'collections.create', 'resource' => 'collections', 'action' => 'create'],
            ['name' => 'Update Collections', 'slug' => 'collections.update', 'resource' => 'collections', 'action' => 'update'],
            ['name' => 'Delete Collections', 'slug' => 'collections.delete', 'resource' => 'collections', 'action' => 'delete'],

            // User management permissions | 用户管理权限
            ['name' => 'View Users', 'slug' => 'users.view', 'resource' => 'users', 'action' => 'view'],
            ['name' => 'Create Users', 'slug' => 'users.create', 'resource' => 'users', 'action' => 'create'],
            ['name' => 'Update Users', 'slug' => 'users.update', 'resource' => 'users', 'action' => 'update'],
            ['name' => 'Delete Users', 'slug' => 'users.delete', 'resource' => 'users', 'action' => 'delete'],

            // Role management permissions | 角色管理权限
            ['name' => 'View Roles', 'slug' => 'roles.view', 'resource' => 'roles', 'action' => 'view'],
            ['name' => 'Create Roles', 'slug' => 'roles.create', 'resource' => 'roles', 'action' => 'create'],
            ['name' => 'Update Roles', 'slug' => 'roles.update', 'resource' => 'roles', 'action' => 'update'],
            ['name' => 'Delete Roles', 'slug' => 'roles.delete', 'resource' => 'roles', 'action' => 'delete'],
        ];

        $created = 0;
        foreach ($permissions as $permission) {
            // 逐条 INSERT ... WHERE NOT EXISTS(slug)：已存在则插 0 行，天然幂等。
            // 同样必须带 FROM DUAL —— MySQL 对「没有 FROM 的 SELECT」可能把 WHERE 优化掉。
            // description 沿用原实现的值（等于 name），保持与历史数据一致。
            $created += (int) $this->execute(sprintf(
                "INSERT INTO `permissions`
                    (`name`, `slug`, `resource`, `action`, `description`, `created_at`, `updated_at`)
                 SELECT '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                 FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = '%s')",
                $permission['name'],
                $permission['slug'],
                $permission['resource'],
                $permission['action'],
                $permission['name'],
                $now,
                $now,
                $permission['slug']
            ));
        }

        $this->writeLine(sprintf(
            '  权限：确保 %d 条业务权限存在（本次新增 %d 条）',
            count($permissions),
            $created
        ));
    }

    /**
     * Assign permissions to roles | 分配权限给角色
     */
    protected function assignPermissionsToRoles(): void
    {
        // Admin gets all permissions | 管理员获得所有权限
        //
        // 【为什么不能硬编码权限 ID（R43 修复）】
        // 这里原先是 `for ($i = 1; $i <= 20; $i++)`，把管理员权限写死成 ID 1..20。隐患有两个：
        //   1. 权限并非只由本 seeder 创建，多个迁移也会插入权限：
        //      20251126000001（casbin.manage）、20251218000001（tenant/role 管理权限）、
        //      20251219000002（dept.* / menu.* 共 6 条）。它们的自增 ID 都不在 1..20 内，
        //      于是**管理员永远拿不到这些后加的权限** —— 表现为「迁移已建出 dept.read，
        //      但管理员进不了部门管理页」，且全程无报错，极难定位。
        //   2. 硬编码 ID 与表的自增分配顺序绑定，任何一次插入顺序变化都会静默错配。
        //
        // 因此改为 INSERT ... SELECT 动态读取 permissions 表的**全部** ID 分配给管理员。
        // NOT EXISTS 保证幂等：重复执行 seeder 不会产生重复行，也不会撞复合主键 (role_id, permission_id)。
        $this->execute("
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 1, p.id, NOW()
            FROM permissions p
            WHERE NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = 1 AND rp.permission_id = p.id
            )
        ");

        // Regular user gets view permissions only | 普通用户只有查看权限
        // 同样不再硬编码 ID：原写法写死 1 / 5 / 9，与「谁先插入权限」的物理顺序强耦合；
        // 而 slug 才是 permissions 表的唯一键（idx_slug），按 slug 取 ID 是稳定契约。
        // 这三个 slug 由本 seeder 的 createPermissions() 负责创建（不是迁移），
        // 因此本语句必须在 createPermissions() 之后执行（见 run() 的步骤 3 → 4 顺序）。
        $this->execute("
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 2, p.id, NOW()
            FROM permissions p
            WHERE p.slug IN ('forms.view', 'form_data.view', 'collections.view')
            AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = 2 AND rp.permission_id = p.id
            )
        ");
    }

    /**
     * Create default admin user | 创建默认管理员用户
     */
    protected function createDefaultUser(): void
    {
        // 【幂等守卫】管理员 admin 已存在就整体跳过（含下面的 user_roles 授权）。
        // 按 username + tenant_id 判存在：users 表的 username 索引是普通索引（不是唯一索引），
        // 但业务上「同一租户内用户名唯一」，因此这两个条件一起用才是正确的判重口径。
        $existingUser = $this->fetchRow(
            "SELECT `id` FROM `users` WHERE `username` = 'admin' AND `tenant_id` = 1 ORDER BY `id` LIMIT 1"
        );
        if (!empty($existingUser)) {
            $this->writeLine(
                '  跳过：管理员 admin 已存在（id 为 ' . $existingUser['id'] . '），跳过用户与角色授权'
            );

            return;
        }

        // 【初始口令不再写在代码里】原先这里是 password_hash('admin123', ...)，
        // 等于把一个全网公开的弱口令固化进仓库。现改为读取环境变量 ADMIN_INIT_PASSWORD。
        //
        // 只在「确实需要新建管理员」时才读它：已经种过数据的库重跑 seed:run 不需要口令，
        // 因此不会被这条校验挡住（见上面的幂等守卫）。
        //
        // 缺失或为空 → 明确报错并抛异常终止。这里刻意**不提供任何内置回退值**：
        // 静默用弱口令建号比直接失败危险得多，运维会以为环境是安全的。
        $rawPassword = env(self::ADMIN_PASSWORD_ENV);
        $password = is_string($rawPassword) ? trim($rawPassword) : '';
        if ($password === '') {
            $message = sprintf(
                '缺少初始管理员口令，种子终止：请先设置环境变量 %s（值为 admin 账号的初始登录口令）后重新执行 php think seed:run。'
                . '为避免创建弱口令，本命令不会使用任何内置默认口令，也不会以空口令创建账号。',
                self::ADMIN_PASSWORD_ENV
            );
            $this->writeLine('');
            $this->writeLine('  [错误] ' . $message);
            $this->writeLine('');

            // 抛 \RuntimeException（属于 \Exception）而不是 \Error：
            // think\Console::run 只捕获 \Exception，命中后会把退出码从 0 修正为 1，
            // 这样 CI / 脚本才能真正感知到失败（实测 \Error 会被 exception handler 吞成退出码 0）。
            throw new RuntimeException($message);
        }

        // Create admin user | 创建管理员用户
        $this->table('users')->insert([
            [
                'id' => 1,
                'tenant_id' => 1,
                'username' => 'admin',
                'email' => 'admin@alkaidsys.local',
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'name' => 'System Administrator',
                'avatar' => null,
                'phone' => null,
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
                'last_login_at' => null,
                'last_login_ip' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ])->saveData();

        // Assign admin role to admin user | 分配管理员角色
        // 幂等写法：NOT EXISTS 保证重复执行不会撞 (user_id, role_id) 复合主键。
        $this->execute("
            INSERT INTO `user_roles` (`user_id`, `role_id`, `created_at`)
            SELECT 1, 1, NOW()
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `user_roles` WHERE `user_id` = 1 AND `role_id` = 1)
        ");
    }
}
