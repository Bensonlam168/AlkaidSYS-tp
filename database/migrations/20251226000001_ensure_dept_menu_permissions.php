<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 补齐部门 / 菜单权限（dept.* / menu.* 共 6 条）| Ensure dept & menu permissions exist
 *
 * ## 为什么需要这条迁移（缺陷 R3）
 *
 * `20251219000002_add_dept_and_menu_permissions` 在开发库的 `migrations` 表里
 * 记为**已应用**（version 20251219000002 / batch 13），但它负责插入的 6 条权限行
 * （`dept.read` / `dept.write` / `dept.delete` / `menu.read` / `menu.write` / `menu.delete`）
 * **一行都不存在**。账本说「跑过了」，数据说「没有」——于是
 * `GET /v1/admin/depts`、`GET /v1/admin/menus` 对 admin 一律 403 / 2002（实测），
 * 而迁移历史里没有任何一条记录会再把它补回来：已应用的迁移不会被 `migrate:run` 重跑。
 *
 * 这与 R2 修的 12 条（`20251218000001` → 修复迁移 `20251225000001_ensure_system_permissions`）
 * 是**同一类**缺陷，处置方式也保持一致 —— 见下文「为什么是新建一条而不是修历史迁移」。
 *
 * ## 为什么是「新建一条」而不是「修历史迁移」
 *
 * `20251219000002` 已在开发库 / 测试库记为已应用，改它对**已应用**的库毫无效果
 * （Phinx 不会重跑），只会让「文件内容」与「当初实际执行的代码」脱钩 —— 那正是账本失信的开始。
 * 因此按仓库既定做法（见 AGENTS.md「迁移文件改名 / 删除的协调流程」）：历史迁移一律不动，
 * 用一条**版本号更大**的新迁移（20251226000001 > 已应用最大值 20251225000001）
 * 把数据收敛到应有的状态。
 *
 * 相对「把 R2 与本条合并成一个系统权限全集迁移」的方案，这里选「再建一个同构的」：
 * 合并方案只能新增第三个迁移（`20251225000001` 已应用、不可改），旧的两条仍留在链上，
 * 只会让同一个 slug 在链上出现三次，收益是 0、回溯成本反而上升。代价与论证见交付报告。
 *
 * ## 本迁移的语义：收敛（ensure），不是创建（create）
 *
 * 每一条权限都按 `slug` 做 ensure：存在即跳过，不存在才插入。
 * 因此在**已经正确**的库（测试库、按正确顺序建出来的全新库）上执行结果是 0 行插入、0 报错；
 * 在**缺失**的库（开发库）上补 6 行。两种库跑完的状态一致 —— 这正是「收敛」的含义。
 *
 * 不写死 `id`：原迁移里这 6 条权限是自增插入的，各库实际 id 各不相同，写死 id 会与
 * 既有库的主键 / 唯一索引直接冲突。
 *
 * ## 授权：对齐原迁移的设计（它当初就授权）
 *
 * 原迁移 `20251219000002` 的 `up()` 结尾（源码 98-110 行）会把 `resource IN ('dept','menu')`
 * 的权限授予**管理员角色 `role_id = 1`**，本迁移照做。此处不做任何「新增权限码 / 改授权策略」
 * 的动作，避免与 `database/seeds/CorePlatformSeed.php` 的 `assignPermissionsToRoles()` 分叉。
 *
 * 相对于原迁移唯一的有意差异：原迁移硬编码 `role_id = 1`，而 `roles` 上有唯一键
 * `idx_tenant_slug(tenant_id, slug)` —— 多租户库里每个租户都可以有自己的 admin 角色。
 * 本迁移授予**所有** `slug = 'admin'` 的角色（各自租户内生效，`PermissionService` 的授权查询
 * 本就带 `roles.tenant_id` 过滤），这是原语义在多租户下的正确展开，不会给任何角色
 * 多授其租户之外的东西。这一写法与 `20251225000001_ensure_system_permissions` 完全一致。
 *
 * ## 幂等性（可重复执行）
 *
 * - `permissions.slug` 有唯一索引 `idx_slug`，且插入语句自带 `WHERE NOT EXISTS`；
 * - `role_permissions` 有复合主键 `(role_id, permission_id)`，授权语句同样自带 `WHERE NOT EXISTS`。
 * 故重复执行只会打印「已存在，跳过」，不会报错、不会重复插入。
 *
 * @package database/migrations
 */
class EnsureDeptMenuPermissions extends Migrator
{
    /**
     * 6 条部门 / 菜单权限定义
     *
     * **逐字对齐** `20251219000002_add_dept_and_menu_permissions.php` 的 `$permissions` 数组
     * （name / slug / resource / action / description 五项全部一致），不自行改写名字或描述：
     * 否则同一个 slug 在「迁移建的库」与「本迁移补的库」里会得到不同的显示名，
     * 排查时无法判断两者是否同一件事。
     *
     * @var array<int, array{name:string, slug:string, resource:string, action:string, description:string}>
     */
    private array $permissions = [
        // 部门权限 | Department permissions
        [
            'name' => '查看部门',
            'slug' => 'dept.read',
            'resource' => 'dept',
            'action' => 'read',
            'description' => 'View department list and details | 查看部门列表和详情',
        ],
        [
            'name' => '编辑部门',
            'slug' => 'dept.write',
            'resource' => 'dept',
            'action' => 'write',
            'description' => 'Create and update departments | 创建和更新部门',
        ],
        [
            'name' => '删除部门',
            'slug' => 'dept.delete',
            'resource' => 'dept',
            'action' => 'delete',
            'description' => 'Delete departments | 删除部门',
        ],
        // 菜单权限 | Menu permissions
        [
            'name' => '查看菜单',
            'slug' => 'menu.read',
            'resource' => 'menu',
            'action' => 'read',
            'description' => 'View menu list and details | 查看菜单列表和详情',
        ],
        [
            'name' => '编辑菜单',
            'slug' => 'menu.write',
            'resource' => 'menu',
            'action' => 'write',
            'description' => 'Create and update menus | 创建和更新菜单',
        ],
        [
            'name' => '删除菜单',
            'slug' => 'menu.delete',
            'resource' => 'menu',
            'action' => 'delete',
            'description' => 'Delete menus | 删除菜单',
        ],
    ];

    /**
     * 执行迁移：按 slug 补齐 6 条权限，并把它们授予 admin 角色
     * Run migration: ensure the 6 permissions by slug and grant them to the admin role(s)
     */
    public function up(): void
    {
        $now      = date('Y-m-d H:i:s');
        $inserted = 0;
        $skipped  = 0;

        foreach ($this->permissions as $permission) {
            // 一条语句完成「不存在才插入」，避免先 SELECT 再 INSERT 的两步写法在并发下撞唯一索引。
            // FROM DUAL：MySQL 允许省略 FROM 的 SELECT 把 WHERE 优化掉，
            // 显式写 FROM DUAL 语义更稳定（与 20251225000001 的写法保持一致）。
            $affected = $this->execute(
                'INSERT INTO `permissions` (`name`, `slug`, `resource`, `action`, `description`, `created_at`, `updated_at`)
                 SELECT ?, ?, ?, ?, ?, ?, ?
                 FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM `permissions` WHERE `slug` = ?
                 )',
                [
                    $permission['name'],
                    $permission['slug'],
                    $permission['resource'],
                    $permission['action'],
                    $permission['description'],
                    $now,
                    $now,
                    $permission['slug'],
                ]
            );

            if ($affected > 0) {
                $inserted++;
                $this->output->writeln("<info>  + 新增权限 {$permission['slug']}</info>");
            } else {
                $skipped++;
                $this->output->writeln("<comment>  = 权限 {$permission['slug']} 已存在，跳过</comment>");
            }
        }

        $this->output->writeln(sprintf(
            '<info>权限补齐完成：新增 %d 条，已存在跳过 %d 条（共 %d 条）</info>',
            $inserted,
            $skipped,
            count($this->permissions)
        ));

        $this->grantToAdminRoles($now);
    }

    /**
     * 把本迁移负责的 6 条权限授予 admin 角色
     * Grant the 6 permissions of this migration to the admin role(s)
     *
     * 【为什么这条修复迁移要授权（源码证据）】
     *   原迁移 `20251219000002_add_dept_and_menu_permissions.php` 的 `up()`
     *   **本来就带授权动作**（第 98-110 行）：
     *       INSERT INTO role_permissions (role_id, permission_id, created_at)
     *       SELECT 1, p.id, NOW() FROM permissions p
     *       WHERE p.resource IN ('dept','menu') ...
     *   也就是说「插入权限 + 授予 admin」在原设计里是**同一件事**。
     *   本迁移只补数据不补授权，就会把库修成「半执行」状态：权限行有了，但 admin 拿不到
     *   —— 新增的 6 条不会被任何现存逻辑自动补授（seeder 的 assignPermissionsToRoles()
     *   只在跑 `seed:run` 时生效），后台 depts / menus 依然 403。
     *   因此这里按原设计对齐，把授权一并补上。
     *
     * 【为什么是按 slug 找所有 admin 角色，而不是取 role_id = 1】
     *   `roles` 的唯一键是 `idx_tenant_slug(tenant_id, slug)`，多租户库里每个租户各有一条
     *   `slug = 'admin'`。原迁移硬编码 `role_id = 1`，是其历史缺陷；本迁移修正为全部，
     *   因为 `PermissionService` 的授权查询是
     *   `SELECT rp.permission_id FROM role_permissions rp INNER JOIN roles r ON rp.role_id = r.id
     *    WHERE r.tenant_id = ? AND rp.role_id = ?`，授权只在各自租户内生效，不会越界。
     *
     * 【角色不存在时（全新库）】
     *   `roles` 由 seed（CorePlatformSeed::createRoles）创建，而 seed 在 migrate 之后才跑，
     *   所以全新库执行 `migrate:run` 时这里匹配 0 行 —— 只打印提示、绝不因为「没有 admin 角色」
     *   中断迁移链（与 20251219000002 的 EXISTS 守卫、20251225000001 的处理同一思路）。
     *   跳过 ≠ 丢失授权：随后的 `seed:run` 会用 assignPermissionsToRoles()
     *   把 permissions 表的**全部**权限授予 role 1。
     *
     * @param string $now 本迁移统一的写入时间戳
     */
    private function grantToAdminRoles(string $now): void
    {
        $adminRoleIds = $this->resolveAdminRoleIds();

        if ([] === $adminRoleIds) {
            $this->output->writeln(
                '<comment>未找到 admin 角色（roles.slug = admin），跳过授权：'
                . '全新库由 seed 的 assignPermissionsToRoles() 负责授予全部权限</comment>'
            );

            return;
        }

        $slugs        = array_column($this->permissions, 'slug');
        $placeholders = implode(', ', array_fill(0, count($slugs), '?'));

        // 只给「admin 角色 × 本迁移的 6 条权限」这个笛卡尔积里**尚未授权**的组合插入行。
        // NOT EXISTS 保证幂等：重复执行不会撞 (role_id, permission_id) 复合主键。
        $granted = $this->execute(
            "INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
             SELECT r.`id`, p.`id`, ?
             FROM `roles` r
             CROSS JOIN `permissions` p
             WHERE r.`slug` = 'admin'
               AND p.`slug` IN ({$placeholders})
               AND NOT EXISTS (
                   SELECT 1 FROM `role_permissions` rp
                   WHERE rp.`role_id` = r.`id` AND rp.`permission_id` = p.`id`
               )",
            array_merge([$now], $slugs)
        );

        $this->output->writeln(sprintf(
            '<info>授权完成：admin 角色（id: %s）本次新增 %d 条 role_permissions</info>',
            implode(', ', $adminRoleIds),
            $granted
        ));
    }

    /**
     * 查出所有 admin 角色的 id（按 id 升序，便于报告稳定可比）
     *
     * @return int[]
     */
    private function resolveAdminRoleIds(): array
    {
        $statement = $this->query('SELECT `id` FROM `roles` WHERE `slug` = ? ORDER BY `id` ASC', ['admin']);

        if (!($statement instanceof PDOStatement)) {
            return [];
        }

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * 回滚迁移：**有意为空操作（no-op）**
     * Rollback migration: intentionally a no-op
     *
     * 【为什么不删任何东西】
     *   1. 本迁移的语义是「把数据收敛到应有的状态」，而不是「新增了一个能力」。
     *      这 6 条权限是 `20251219000002` 的**预期结果**，而那条迁移至今仍记为已应用。
     *      回滚时按 slug 删掉它们，等于**把 R3 的缺陷重新制造一遍**（depts / menus 403 回归），
     *      而账本上「20251219000002 已应用」还在 —— 库会处于「已应用却缺数据」的自相矛盾状态，
     *      与本次修复的初衷完全相反。
     *   2. 迁移没有状态，无法区分「本迁移插入的行」与「原本就存在的行」。
     *      在健康库（如测试库）里这 6 条本来就存在（本迁移只是 ensure，0 行插入），
     *      按 slug 删会把**别人正常建出来的数据**删掉。
     *   3. 删除是**有连带损坏**的：`role_permissions.permission_id` 的外键是
     *      `ON DELETE CASCADE`，删权限会静默连带清空 admin 的授权行；
     *      之后即使 `migrate:run` 再跑一次把权限补回来，也要等 `seed:run` 才会恢复授权。
     *   4. `up()` 本身是幂等的（ensure-by-slug），因此 `migrate:rollback` + `migrate:run`
     *      的往返是**安全的空转**：账本记录进出一趟，数据保持正确。
     *
     * 【为什么不留空方法体】
     *   留一段显式说明，避免后来者把「看起来什么都没做」当成漏写而顺手补一个 DELETE ——
     *   那会把这条迁移变成一个回滚即致残的陷阱。
     *   同时满足 check-migrations 的检查 C（必须实现 up() + down()，且不得写 change()）。
     */
    public function down(): void
    {
        $this->output->writeln(
            '<comment>EnsureDeptMenuPermissions::down() 为有意空操作：'
            . '本迁移只做数据收敛（ensure-by-slug），删除会重新制造 R3 缺陷，'
            . '并因外键 ON DELETE CASCADE 连带清空 admin 的 role_permissions 授权。</comment>'
        );
    }
}
