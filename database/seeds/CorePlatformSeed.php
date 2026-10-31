<?php

use Infrastructure\Permission\Service\PermissionCacheService;
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
 *          users.*（复数）/ roles.*（复数），共 20 条；R50 又补了 lowcode.* 3 条，现共 23 条。
 *      删掉本方法会让 forms.* / form_data.* / collections.* 等 12 个权限在任何环境都不存在，
 *      并让 assignPermissionsToRoles() 里给普通用户的那 3 条只读授权变成匹配 0 行的死代码。
 *      现改为逐条 INSERT ... WHERE NOT EXISTS(slug)；
 *      主键自 **R50** 起不再无条件交给 AUTO_INCREMENT（那会让测试库里的行撞进别的用例的清理区间），
 *      改为「能钉 1001..1023 就钉，钉不上才退回自增」——理由见下面 R50 第 1 点。
 *   2. createDefaultTenant / createRoles / createDefaultUser 原先都写死主键且无判重，
 *      第二遍执行必撞主键；现均改为「存在即跳过」，重复执行不产生任何新行。
 *      其中**租户段与站点段是两段独立幂等**：迁移 20251219000003 会先建出租户 1，
 *      若站点插入跟在租户段的「已存在就 return」之后，全新库的 sites 会永远停在 0。
 *   3. 管理员初始口令不再硬编码（原先是 password_hash('admin123')），改为读取环境变量
 *      ADMIN_INIT_PASSWORD；缺失或为空时**明确报错并终止**，绝不静默创建弱口令。
 *
 * 【R48：普通用户的 3 条只读授权改为「确保 → 断言 → 按 id 插入」】
 *   旧写法是一条 `INSERT ... SELECT ... WHERE p.slug IN ('forms.view', ...)`，有两个静默失败模式：
 *     ① createPermissions() 没执行/提前 return → 匹配 0 行，不报错，role 2 空授权；
 *     ② 某个 slug 被改名或打错 → 同样匹配 0 行，不报错。
 *   现改为在 assignPermissionsToRoles() 内先确保权限存在，再用 slug 查出真实 id 并**断言齐全**，
 *   最后按 id 参数化插入。缺任何一个 slug 都会抛 RuntimeException 让 seed:run 以非 0 退出。
 *
 * 【R49：createPermissions() 改用参数绑定】
 *   权限的 name/slug/resource/action/description 全部来自本文件内的代码常量，**本无注入面**；
 *   改成参数绑定不是为了修安全漏洞，而是消除「值里出现半角单引号就会破 SQL / 被静默截断」
 *   这类脆弱性（属于健壮性改进）。
 *
 * 【R50：业务权限改为带显式 id，并补上 lowcode.* 三条路由级权限】
 *   1. 显式 id（1001..1023）解决的是**跨用例数据破坏**，而不是风格问题：
 *      测试库里 `permissions` 的 AUTO_INCREMENT 会被 `tests/Unit/Infrastructure/Permission/**`
 *      那些 7xxx/8xxx/9xxx/99xxx 夹具一路撑到 ~99 万，于是本 seeder 插入的业务权限会拿到
 *      99xxxx 的 id；而同一批测试的清理语句是按 id 区间删的 ——
 *      `PermissionServiceIntegrationTest:107` 删 `id >= 7000`、`CasbinServiceTest:116` 删
 *      `id >= 8000`、`DatabaseAdapterTest:128` 删 `id >= 9000`。
 *      实测后果：`seed:run` 刚建好的 23 条权限，会在同一轮 phpunit 里被别的用例顺手删光
 *      （39 → 19 行），依赖它们的用例全部退化成「缺种子数据」的红 —— 与 seeder 本身无关。
 *      把 id 钉在 1001..1023（低于工作流夹具 6000-6999 区间，也低于 7000+ 的各清理阈值）
 *      后，这些清理语句再也碰不到它们，seed 结果在同一轮测试里稳定存活。
 *      id 只是「插入时的建议值」：已存在（按 slug 判）就保留原 id 不动；建议 id 被别的行占用时
 *      退回 AUTO_INCREMENT，绝不为了凑 id 去覆盖别人的行。
 *   2. `lowcode.read` / `lowcode.write` / `lowcode.delete` 是 `route/lowcode.php` 里
 *      21 处 Permission 中间件**硬依赖**的内部 slug（见 docs/technical-specs/api/route-reference.md）。
 *      迁移链和旧的 20 条业务权限都没有它们，因此任何用户在低代码接口上恒返回 403；
 *      这属于「权限数据缺口」，和表单/集合权限一样应由本 seeder 负责。
 *   3. run() 末尾清除受影响用户的权限缓存：权限行变了而缓存还在（L2 TTL 300s），
 *      下一次读取会拿到旧集合 —— 「seed 之后仍然 403」的另一种成因。
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
     * 普通用户（role_id = 2）默认可读的权限 slug
     *
     * 这三个 slug 由本 seeder 的 ensurePermissions() 负责创建（不是迁移链创建的），
     * 是「普通用户只能看、不能改」这条业务规则在权限层的表达。
     * 一旦它们不存在，role_permissions 里 role 2 的行数就会不声不响地变成 0，
     * 因此 assignPermissionsToRoles() 会用本常量做**齐全性断言**（见 R48）。
     */
    private const USER_READONLY_PERMISSION_SLUGS = [
        'forms.view',
        'form_data.view',
        'collections.view',
    ];

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
        //    创建迁移链**没有覆盖**的业务权限（forms.* / form_data.* / collections.* /
        //    users.* / roles.* 共 20 条，R50 起再加 lowcode.read/write/delete 共 23 条）。
        //    它们与迁移创建的 user.* / role.*（单数）等系统权限是
        //    两套不同的 slug，**不是重复数据**，详见类注释第 1 点。
        //
        //    【为什么这一步不再是「唯一」创建权限的地方（R48）】
        //    assignPermissionsToRoles() 开头也会调用一次 ensurePermissions()，
        //    使授权逻辑**不依赖 run() 的步骤顺序**（把 4 调到 3 之前也仍然成立）。
        //    两处调用都是幂等的（按 slug 判存在的 INSERT ... WHERE NOT EXISTS），
        //    重复执行的代价只是 23 条「插 0 行」的 SQL，没有副作用。
        //    这里保留独立一步，是因为它负责把「本次新增 N 条」这件事打印出来；
        //    assign 内部那次是**静默**确保，避免同一行输出刷两次。
        $this->createPermissions();

        // 4. Assign permissions to roles | 分配权限给角色
        $this->assignPermissionsToRoles();

        // 5. Create default admin user | 创建默认管理员用户
        $this->createDefaultUser();

        // 6. Invalidate permission caches | 清除受影响的权限缓存（R50）
        //
        // 为什么放在 seeder 里：本 seeder 会新增权限行、并给角色补授权。而 PermissionService
        // 走 PermissionCacheService 的两级缓存（L2 TTL 默认 300s），键是「租户 + 用户」，
        // 不随 role_permissions 变化而失效。于是「刚 seed 完仍然 403」会出现，
        // 且看起来像「seed 没生效」，排查成本很高。
        // 这里只清「确实拥有角色」的用户的缓存 —— 不调用 clearAllCache()，
        // 避免把与权限无关的缓存（限流、配置、业务查询）一并清掉。
        $this->clearPermissionCaches();
    }

    /**
     * 清除受影响的权限缓存 | Clear permission caches affected by this seed
     *
     * 清的是「当前 user_roles 里出现过的用户」—— 只有这些用户的权限集合可能因本次 seed 改变。
     * 失败只提示、不终止：数据已经落库，缓存也会按 TTL 自然过期，把 seed 弄失败反而更糟。
     */
    private function clearPermissionCaches(): void
    {
        try {
            $statement = $this->query(
                'SELECT DISTINCT `ur`.`user_id` AS `user_id`, `u`.`tenant_id` AS `tenant_id`
                 FROM `user_roles` `ur`
                 JOIN `users` `u` ON `u`.`id` = `ur`.`user_id`'
            );

            if (!($statement instanceof PDOStatement)) {
                return;
            }

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return;
            }

            $cacheService = new PermissionCacheService();
            foreach ($rows as $row) {
                $cacheService->clearUserCache((int) $row['user_id'], (int) $row['tenant_id']);
            }

            $this->writeLine(sprintf('  缓存：已清除 %d 个用户的权限缓存', count($rows)));
        } catch (Throwable $e) {
            // Throwable 而不是 Exception：缓存驱动（Redis/文件）缺失时可能抛 \Error。
            // 这类问题与「数据是否补齐」无关，不该让 seed 以非 0 退出。
            $this->writeLine('  [提示] 权限缓存清理失败（数据已写入，不影响后续读取）：' . $e->getMessage());
        }
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
     *
     * 可见性由 private 放宽为 protected（R50）：子类 TestPlatformSeed 需要复用同一套输出兜底，
     * 否则它只能自己再抄一份，两处实现迟早漂移。
     */
    protected function writeLine(string $message): void
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
     * 创建的是**业务权限**：forms.* / form_data.* / collections.* / users.* / roles.* 各 4 条（共 20 条），
     * 外加 R50 补齐的 lowcode.read / lowcode.write / lowcode.delete（3 条路由级权限），合计 23 条。
     *
     * 【这套权限与迁移创建的权限是两套不同的 slug，不是重复数据 —— 不要「去重」删掉】
     *   - 迁移链创建的是系统权限（19 条）：casbin.manage、tenant.*、role.*、permission.*、
     *     user.*（单数）、dept.*、menu.*；
     *   - 本方法创建的是业务权限（23 条）：users.*（复数）、roles.*（复数）、表单/集合权限
     *     以及 lowcode.*；
     *   两套 slug 完全不重叠（`user.read` 与 `users.view` 是两个不同的权限）。
     *   删掉本方法的后果：全新库上 forms.* / form_data.* / collections.* 等 12 个权限永久缺失，
     *   且 assignPermissionsToRoles() 里给普通用户的那 3 条只读授权会变成匹配 0 行的死代码。
     *
     * 【幂等：不再写死主键】原实现用 `'id' => $index + 1` 强制指定主键 1..20，
     * 而迁移链已占用 id 1..19，于是重复执行 / 在已迁移的库上执行必然报
     * `Duplicate entry '1' for key 'permissions.PRIMARY'` —— 这是**主键**冲突，不是 slug 冲突。
     * 现改为逐条 INSERT ... WHERE NOT EXISTS(slug)；
     * 判重键用 slug 是因为 permissions.slug 上有唯一索引（UNI），它才是权限的业务标识。
     * R50 起主键改为「优先钉 1001..1023，占用了才退回 AUTO_INCREMENT」，理由见类注释 R50 第 1 点。
     */
    protected function createPermissions(): void
    {
        // 真正的创建逻辑在 ensurePermissions() 里，此处只负责把它「本次新增 N 条」的结果打印出来。
        // 拆成两个方法的原因见 run() 步骤 3 的注释：assign 内部需要静默地再确保一次，
        // 若直接调用本方法，同一条「权限：确保 …（本次新增 0 条）」会被打印两遍，看着像 bug。
        $permissions = $this->permissionDefinitions();
        $created     = $this->ensurePermissions();

        $this->writeLine(sprintf(
            '  权限：确保 %d 条业务权限存在（本次新增 %d 条）',
            count($permissions),
            $created
        ));
    }

    /**
     * 确保业务权限存在 | Ensure business permissions exist（幂等，静默）
     *
     * 之所以从 createPermissions() 里拆出来，是为了让 assignPermissionsToRoles()
     * 能在**不产生额外输出**的前提下复用它 —— 这样授权逻辑不再依赖 run() 的步骤顺序（R48）。
     *
     * @return int 本次真正插入的行数（重复执行时为 0）
     */
    private function ensurePermissions(): int
    {
        $now = date('Y-m-d H:i:s');

        $created = 0;
        foreach ($this->permissionDefinitions() as $permission) {
            // 【R50 第 1 步：按 slug 判存在，已存在就整行不动】
            // 保留历史 id / name / description，绝不把人工维护过的权限改号或覆盖。
            if ($this->permissionIdBySlug($permission['slug']) !== null) {
                continue;
            }

            // description 沿用原实现的值（等于 name），保持与历史数据一致。
            //
            // 【R49：改用参数绑定，不再用 sprintf 拼字面量】
            // 这些值全部来自本文件内的代码常量，**本无注入面**；改参数化的目的不是修安全漏洞，
            // 而是消除「值里含半角单引号就会破 SQL / 被静默截断」这类脆弱性（健壮性改进）。
            // 例：name 若写成 'View O\'Brien Forms'，旧写法会拼出语法错误的 SQL，新写法原样入库。
            $values = [
                $permission['name'],
                $permission['slug'],
                $permission['resource'],
                $permission['action'],
                $permission['name'],
                $now,
                $now,
                $permission['slug'],
            ];

            // 【R50 第 2 步：能钉 id 就钉 id，钉不上就退回自增】
            // 钉 id 的理由见类注释 R50 第 1 点（躲开其它测试按 id 区间做的清理）。
            // 仍必须带 WHERE NOT EXISTS(slug) 与 FROM DUAL：
            //   - NOT EXISTS 保留原有的「并发/重复执行插 0 行」幂等性；
            //   - FROM DUAL 是因为 MySQL 对「没有 FROM 的 SELECT」可能把 WHERE 优化掉。
            if ($this->isPermissionIdAvailable($permission['id'])) {
                $created += $this->execute(
                    'INSERT INTO `permissions`
                        (`id`, `name`, `slug`, `resource`, `action`, `description`, `created_at`, `updated_at`)
                     SELECT ?, ?, ?, ?, ?, ?, ?, ?
                     FROM DUAL
                     WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = ?)',
                    array_merge([$permission['id']], $values)
                );

                continue;
            }

            $created += $this->execute(
                'INSERT INTO `permissions`
                    (`name`, `slug`, `resource`, `action`, `description`, `created_at`, `updated_at`)
                 SELECT ?, ?, ?, ?, ?, ?, ?
                 FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `slug` = ?)',
                $values
            );
        }

        return $created;
    }

    /**
     * 按 slug 查权限 id | Resolve a permission id by slug
     *
     * @param string $slug 权限 slug（内部格式 resource.action）
     * @return int|null 已存在时返回其 id，不存在返回 null
     */
    private function permissionIdBySlug(string $slug): ?int
    {
        $row = $this->fetchOne('SELECT `id` FROM `permissions` WHERE `slug` = ? LIMIT 1', [$slug]);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * 判断建议 id 是否可用 | Check whether the suggested id is free
     *
     * 「不可用」只代表不能钉 id，不代表要跳过这条权限 —— 调用方会退回 AUTO_INCREMENT。
     * 典型场景：老库里正好有别的行占着 1001。
     *
     * @param int $id 建议主键
     */
    private function isPermissionIdAvailable(int $id): bool
    {
        return $this->fetchOne('SELECT `id` FROM `permissions` WHERE `id` = ? LIMIT 1', [$id]) === null;
    }

    /**
     * 查询单行（带参数绑定）| Fetch a single row with bound parameters
     *
     * 【为什么不用 $this->fetchRow()】
     * Phinx 的 AbstractSeed::fetchRow(string $sql) **不接受参数绑定**，
     * 只能把值拼进 SQL —— 正是 R49 要去掉的做法。这里统一走 query() + PDO 预处理。
     *
     * @param string $sql    带 ? 占位符的 SQL
     * @param array<int, mixed> $params 绑定参数
     * @return array<string, mixed>|null 无结果时返回 null
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->query($sql, $params);

        // query() 正常返回 PDOStatement（已实测）；接口签名允许返回 false，
        // 这里显式挡一下，避免 "fetch() on false" 变成看不懂的 \Error。
        if (!($statement instanceof PDOStatement)) {
            throw new RuntimeException('权限查询失败：permissions 表查询没有返回结果集。');
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * 业务权限定义 | Business permission definitions
     *
     * 单独抽成方法，是为了让「创建」与「打印条数」两处共用同一份清单，避免两边条数对不上。
     *
     * 【R50：每条都带 id】
     *   id 取 1001..1023 这个**保留区间**，理由见类注释 R50 第 1 点：
     *   低于工作流夹具 6000-6999 与各测试 7xxx/8xxx/9xxx/10xxx 的清理阈值。
     *   新增条目请继续往后排，不要把区间打散成孤立数字 —— 连续区间才好一眼看出「这是种子段」。
     *
     * @return array<int, array{id: int, name: string, slug: string, resource: string, action: string}>
     */
    private function permissionDefinitions(): array
    {
        return [
            // Form Designer permissions | 表单设计器权限
            ['id' => 1001, 'name' => 'View Forms', 'slug' => 'forms.view', 'resource' => 'forms', 'action' => 'view'],
            ['id' => 1002, 'name' => 'Create Forms', 'slug' => 'forms.create', 'resource' => 'forms', 'action' => 'create'],
            ['id' => 1003, 'name' => 'Update Forms', 'slug' => 'forms.update', 'resource' => 'forms', 'action' => 'update'],
            ['id' => 1004, 'name' => 'Delete Forms', 'slug' => 'forms.delete', 'resource' => 'forms', 'action' => 'delete'],

            // Form Data permissions | 表单数据权限
            ['id' => 1005, 'name' => 'View Form Data', 'slug' => 'form_data.view', 'resource' => 'form_data', 'action' => 'view'],
            ['id' => 1006, 'name' => 'Create Form Data', 'slug' => 'form_data.create', 'resource' => 'form_data', 'action' => 'create'],
            ['id' => 1007, 'name' => 'Update Form Data', 'slug' => 'form_data.update', 'resource' => 'form_data', 'action' => 'update'],
            ['id' => 1008, 'name' => 'Delete Form Data', 'slug' => 'form_data.delete', 'resource' => 'form_data', 'action' => 'delete'],

            // Collection permissions | 集合权限
            ['id' => 1009, 'name' => 'View Collections', 'slug' => 'collections.view', 'resource' => 'collections', 'action' => 'view'],
            ['id' => 1010, 'name' => 'Create Collections', 'slug' => 'collections.create', 'resource' => 'collections', 'action' => 'create'],
            ['id' => 1011, 'name' => 'Update Collections', 'slug' => 'collections.update', 'resource' => 'collections', 'action' => 'update'],
            ['id' => 1012, 'name' => 'Delete Collections', 'slug' => 'collections.delete', 'resource' => 'collections', 'action' => 'delete'],

            // User management permissions | 用户管理权限
            ['id' => 1013, 'name' => 'View Users', 'slug' => 'users.view', 'resource' => 'users', 'action' => 'view'],
            ['id' => 1014, 'name' => 'Create Users', 'slug' => 'users.create', 'resource' => 'users', 'action' => 'create'],
            ['id' => 1015, 'name' => 'Update Users', 'slug' => 'users.update', 'resource' => 'users', 'action' => 'update'],
            ['id' => 1016, 'name' => 'Delete Users', 'slug' => 'users.delete', 'resource' => 'users', 'action' => 'delete'],

            // Role management permissions | 角色管理权限
            ['id' => 1017, 'name' => 'View Roles', 'slug' => 'roles.view', 'resource' => 'roles', 'action' => 'view'],
            ['id' => 1018, 'name' => 'Create Roles', 'slug' => 'roles.create', 'resource' => 'roles', 'action' => 'create'],
            ['id' => 1019, 'name' => 'Update Roles', 'slug' => 'roles.update', 'resource' => 'roles', 'action' => 'update'],
            ['id' => 1020, 'name' => 'Delete Roles', 'slug' => 'roles.delete', 'resource' => 'roles', 'action' => 'delete'],

            // Lowcode API permissions | 低代码接口权限（R50 新增）
            //
            // 【不是「重复的 collections.*」—— 它们守的是另一层】
            // route/lowcode.php 里 21 处 Permission 中间件写的是 lowcode.read / lowcode.write /
            // lowcode.delete（内部 slug，点号），覆盖集合、字段、关系、表单、表单数据全部低代码路由；
            // 而 collections.* / forms.* / form_data.* 目前没有任何路由在用（属前端/后端权限码约定）。
            // 缺这三条的实际后果：**所有低代码接口对任何用户恒 403**（admin 也一样），
            // 依赖 HTTP 链路的低代码用例因此整片失败。
            // 注意 slug 必须用**点号**：`:read` 形式是历史脏数据，路由层规范见
            // docs/technical-specs/api/route-reference.md 与 security-guidelines.md（内部 slug = resource.action）。
            ['id' => 1021, 'name' => 'Read Lowcode Resources', 'slug' => 'lowcode.read', 'resource' => 'lowcode', 'action' => 'read'],
            ['id' => 1022, 'name' => 'Write Lowcode Resources', 'slug' => 'lowcode.write', 'resource' => 'lowcode', 'action' => 'write'],
            ['id' => 1023, 'name' => 'Delete Lowcode Resources', 'slug' => 'lowcode.delete', 'resource' => 'lowcode', 'action' => 'delete'],
        ];
    }

    /**
     * Assign permissions to roles | 分配权限给角色
     *
     * 【R48：本方法整体分三步 —— 确保 → 断言 → 按 id 插入，每一步各防一类静默失败】
     *   第 0 步「确保」   ：在方法开头静默地跑一次 ensurePermissions()，防的是「调用方忘了先建权限」
     *                      （即 run() 的步骤顺序被人调换），让授权逻辑自给自足。
     *   第 1 步「断言」   ：按 slug 查出真实 id 并核对齐全性，缺任何一个就抛 RuntimeException。
     *                      防的是「slug 被改名/打错 → 匹配 0 行 → 普通用户静默变成无权限」。
     *   第 2 步「按 id 插入」：用断言得到的 id 参数化插入，防的是「拿字符串拼 slug 进 SQL」以及
     *                      「WHERE slug IN (...) 与表里实际 slug 脱钩」这两类问题。
     */
    protected function assignPermissionsToRoles(): void
    {
        // ---------- 第 0 步：确保（R48）----------
        // 直接复用幂等的 ensurePermissions()，而不是依赖 run() 里先调用 createPermissions()。
        // 正常情况下（run() 按 3 → 4 顺序执行）这里是 20 条「插 0 行」的 no-op；
        // 若有人在 run() 里把本方法调到 createPermissions() 之前，这一步会把权限补齐，
        // 结果与正常顺序完全一致 —— 这正是「顺序无关」的含义。
        $this->ensurePermissions();

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
        $this->execute('
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            SELECT 1, p.id, NOW()
            FROM permissions p
            WHERE NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = 1 AND rp.permission_id = p.id
            )
        ');

        // Regular user gets view permissions only | 普通用户只有查看权限
        //
        // 【R48 第 1 步：断言，不再「匹配不到就悄悄算完」】
        // 旧写法是一条 `INSERT ... WHERE p.slug IN ('forms.view', ...)`：
        // 只要 slug 有任何偏差（改名、打错、或权限压根没创建），语句就会匹配 0 行，
        // **既不报错也不提示**，表现是「普通用户登录后所有页面都是 403」，极难定位。
        // 现在先把 3 个 slug 的真实 id 查出来并核对齐全性，缺一个就抛异常终止 seed。
        $readonlyPermissionIds = $this->resolvePermissionIds(self::USER_READONLY_PERMISSION_SLUGS);

        // 【R48 第 2 步：按 id 参数化插入】
        // 用上一步断言过的真实 id 逐条插入（每条只插 1 行），不再在 SQL 里重复写 slug 字面量：
        //   - 参数化：id 通过占位符传入，语句本身不含任何用户/配置侧拼接；
        //   - NOT EXISTS：保持幂等，重复执行不会撞 (role_id, permission_id) 复合主键；
        //   - FROM DUAL：同 createRoles()，MySQL 对「没有 FROM 的 SELECT」可能把 WHERE 优化掉。
        $assigned = 0;
        foreach ($readonlyPermissionIds as $permissionId) {
            $assigned += $this->execute(
                'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
                 SELECT 2, ?, NOW()
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM `role_permissions`
                     WHERE `role_id` = 2 AND `permission_id` = ?
                 )',
                [$permissionId, $permissionId]
            );
        }

        $this->writeLine(sprintf(
            '  授权：普通用户可读权限 %d 条（本次新增 %d 条）',
            count($readonlyPermissionIds),
            $assigned
        ));
    }

    /**
     * 按 slug 解析权限 id（缺任何一个即抛异常）| Resolve permission ids by slug, fail loudly
     *
     * 【为什么必须抛异常而不是返回空数组】
     * 调用方要拿这些 id 去授权。若某个 slug 缺失却只返回部分 id（或空数组），
     * 授权语句会照常执行并「成功」—— 结果是那个角色少拿权限，且日志里一片正常。
     * 这类「静默缺权限」比直接失败难排查得多，所以这里选择**响亮失败**。
     *
     * 抛 RuntimeException（属于 \Exception）而不是 \Error：
     * think\Console::run 只捕获 \Exception，命中后会把退出码修正为 1，
     * CI / 运维脚本才能真正感知到失败。
     *
     * @param array<int, string> $slugs 期望存在的权限 slug 清单
     *
     * @return array<int, int> 与 $slugs **同序**的权限 id 列表
     */
    private function resolvePermissionIds(array $slugs): array
    {
        // 占位符个数由清单长度决定（清单是代码内常量，不是外部输入）；
        // 注意不能用 fetchAll()：它不接受参数绑定，只能拼字符串，正是本次要去掉的做法。
        $placeholders = implode(', ', array_fill(0, count($slugs), '?'));

        $statement = $this->query(
            "SELECT `slug`, `id` FROM `permissions` WHERE `slug` IN ({$placeholders})",
            array_values($slugs)
        );

        // query() 正常返回 PDOStatement（已实测）；执行失败时 PDO 会抛 PDOException，
        // 但接口签名允许返回 false，这里显式挡一下，避免 "fetchAll() on false" 变成看不懂的 \Error。
        if (!($statement instanceof PDOStatement)) {
            throw new RuntimeException(sprintf(
                '权限断言失败，种子终止：按 slug 查询 permissions 表没有得到结果集（期望清单：%s）。',
                implode(', ', $slugs)
            ));
        }

        $idBySlug = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idBySlug[(string) $row['slug']] = (int) $row['id'];
        }

        // array_diff 保留第一个数组的顺序，因此报错信息里的缺失清单顺序稳定、便于比对
        $missing = array_values(array_diff($slugs, array_keys($idBySlug)));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                '权限断言失败，种子终止：以下权限 slug 在 permissions 表中不存在 —— %s。'
                . '期望清单：%s。'
                . '这通常意味着 ensurePermissions() 里的 slug 被改名或打错了（也可能权限被人工删除）。'
                . '继续执行会让普通用户的只读授权静默变成 0 行，因此这里显式失败。',
                implode(', ', $missing),
                implode(', ', $slugs)
            ));
        }

        // 按入参顺序返回，保证「打印条数 / 插入顺序」稳定可预期
        return array_map(static fn (string $slug): int => $idBySlug[$slug], $slugs);
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
        $this->execute('
            INSERT INTO `user_roles` (`user_id`, `role_id`, `created_at`)
            SELECT 1, 1, NOW()
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `user_roles` WHERE `user_id` = 1 AND `role_id` = 1)
        ');
    }
}
