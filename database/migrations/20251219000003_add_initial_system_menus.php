<?php

use think\migration\Migrator;

/**
 * Add Initial System Menus Migration | 添加初始系统菜单迁移
 *
 * Creates default system menu structure for tenant 1.
 * 为租户 1 创建默认系统菜单结构。
 */
class AddInitialSystemMenus extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $tenantId = 1;

        // =========================================================================
        // 【R43 冷启动修复】先确保系统租户（id = 1）存在，再插菜单
        // =========================================================================
        //
        // ① 为什么把「建租户」内联在本迁移里，而不是新增迁移 / 改文件名：
        //    - 全新库执行 migrate:run 时 tenants 表是空的，而 menus.tenant_id 上有外键
        //      fk_menus_tenant_id → tenants.id，于是下面的菜单 insert 会报 1452 并中断整条迁移链
        //      （这正是 R43 要修的 B3：全新库冷启动失败）。
        //    - 「再补一条 20251120xxxxxx_create_system_tenant」这类回填写法被门禁禁止：
        //      scripts/check-migrations.php 的检查 B 规定「未应用迁移的 version 必须大于已应用的最大 version」
        //      （当前已应用到 20251224000001），新文件的 version 只能排到链尾，插不到本迁移之前。
        //    - 把本迁移（连同后继的 20251219000004）改名挪到租户之后同样不行：
        //      两者的 up() 都不幂等（无条件 insert 21 行菜单），改名会让既有库重跑一遍，
        //      直接产生一份重复菜单。
        //    → 结论：唯一既能修好冷启动、又不削弱门禁的做法，就是在 up() 开头内联这段
        //      「存在才不插」的租户初始化。
        //
        // ② 系统租户 id = 1 是本项目的 schema 不变量，不是随便挑的默认值：
        //    - app/Request.php 的 tenantId() 在请求未带 X-Tenant-ID 时回落到 1（参数 $default = 1），
        //      租户中间件也按 DEFAULT_TENANT_ID 默认 1 取值；
        //    - 全库有 11 个外键指向 tenants.id（sites / users / roles / depts / menus /
        //      lowcode_forms 与 5 张 workflow 表），漏建会引发一连串 1452；
        //    - 因此这里用显式 id = 1，且字段值刻意与 database/seeds/CorePlatformSeed.php 的
        //      createDefaultTenant() 保持一致（同样的 name / slug / status / config / max_sites / max_users），
        //      让「只跑迁移」与「跑完迁移再跑种子」得到同一行租户记录。
        //
        // ③ 幂等：这里必须写 FROM DUAL。MySQL 对「没有 FROM 的 SELECT」可能把 WHERE 子句优化掉，
        //    不带 DUAL 时守卫会形同虚设；带上 DUAL 才确保 WHERE NOT EXISTS 真的生效。
        //    既有库（已有 id = 1 的租户）重跑本迁移时不会插入、也不会改动任何既有行。
        //
        // ④ 附注：默认站点 sites 不在这里创建 —— 没有任何外键引用 sites，
        //    缺一行默认站点不会阻断迁移，留给种子的 createDefaultTenant() 处理。
        $defaultTenantConfig = '{"locale":"zh_CN","timezone":"Asia/Shanghai"}';
        $this->execute("
            INSERT INTO `tenants`
                (`id`, `name`, `slug`, `domain`, `status`, `config`, `max_sites`, `max_users`, `expires_at`)
            SELECT 1, 'Default Tenant', 'default', NULL, 'active', '{$defaultTenantConfig}', 10, 100, NULL
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `tenants` WHERE `id` = 1)
        ");

        // System Management catalog | 系统管理目录
        $systemCatalogId = $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'System',
            'type' => 'catalog',
            'path' => '/system',
            'component' => null,
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => '系统管理',
                'icon' => 'lucide:settings',
                'order' => 9000,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 9000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // User Management | 用户管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'UserManagement',
            'type' => 'menu',
            'path' => '/system/user',
            'component' => '/system/user/list',
            'redirect' => null,
            'auth_code' => 'user.read',
            'meta' => json_encode([
                'title' => '用户管理',
                'icon' => 'lucide:user',
                'order' => 1,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Role Management | 角色管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'RoleManagement',
            'type' => 'menu',
            'path' => '/system/role',
            'component' => '/system/role/list',
            'redirect' => null,
            'auth_code' => 'role.read',
            'meta' => json_encode([
                'title' => '角色管理',
                'icon' => 'lucide:users',
                'order' => 2,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Permission Management | 权限管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'PermissionManagement',
            'type' => 'menu',
            'path' => '/system/permission',
            'component' => '/system/permission/list',
            'redirect' => null,
            'auth_code' => 'permission.read',
            'meta' => json_encode([
                'title' => '权限管理',
                'icon' => 'lucide:shield',
                'order' => 3,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Tenant Management | 租户管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'TenantManagement',
            'type' => 'menu',
            'path' => '/system/tenant',
            'component' => '/system/tenant/list',
            'redirect' => null,
            'auth_code' => 'tenant.read',
            'meta' => json_encode([
                'title' => '租户管理',
                'icon' => 'lucide:building',
                'order' => 4,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Department Management | 部门管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'DeptManagement',
            'type' => 'menu',
            'path' => '/system/dept',
            'component' => '/system/dept/list',
            'redirect' => null,
            'auth_code' => 'dept.read',
            'meta' => json_encode([
                'title' => '部门管理',
                'icon' => 'lucide:network',
                'order' => 5,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Menu Management | 菜单管理
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $systemCatalogId,
            'name' => 'MenuManagement',
            'type' => 'menu',
            'path' => '/system/menu',
            'component' => '/system/menu/list',
            'redirect' => null,
            'auth_code' => 'menu.read',
            'meta' => json_encode([
                'title' => '菜单管理',
                'icon' => 'lucide:menu',
                'order' => 6,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 6,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Rollback migration | 回滚迁移
     */
    public function down(): void
    {
        // 【刻意不删除系统租户】
        //
        // 1. up() 里会「确保租户 1 存在」，但 down() 不撤销 —— 这是有意的非对称设计：
        //    - 租户 1 是 schema 不变量（app/Request.php 的 tenantId() 默认回落到 1），
        //      被 11 个外键引用（见 up() 注释）；删掉它会让系统在回滚后彻底不可用；
        //    - 「up 时顺便补上本该存在的基础数据」不应该被当成「迁移创建的数据」回滚 ——
        //      在全新库上，这条租户是唯一一条租户，删掉等于把库清空到无法登录的状态；
        //    - 若租户 1 本来就在（既有库），它更不属于本迁移创建的资产，删它属于数据事故。
        //    → 因此 down() 只撤销本迁移真正插入的 7 个具名菜单。
        //
        // 2. 只按 name 白名单删除，不用 tenant_id = 1 全删：租户 1 下可能还有运维手工建的菜单，
        //    必须避免回滚误删用户数据。
        $this->execute("DELETE FROM menus WHERE tenant_id = 1 AND name IN (
            'System', 'UserManagement', 'RoleManagement', 'PermissionManagement',
            'TenantManagement', 'DeptManagement', 'MenuManagement'
        )");
    }

    /**
     * Insert menu and return ID | 插入菜单并返回 ID
     */
    private function insertMenu(array $data): int
    {
        $this->table('menus')->insert($data)->saveData();
        return $this->fetchRow("SELECT LAST_INSERT_ID() as id")['id'];
    }
}
