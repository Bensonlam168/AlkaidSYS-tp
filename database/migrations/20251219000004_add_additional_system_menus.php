<?php

use think\migration\Migrator;

/**
 * Add Additional System Menus Migration | 添加额外系统菜单迁移
 *
 * Creates Dashboard, Demos, Vben Project, and About menus for tenant 1.
 * 为租户 1 创建概览、演示、Vben项目和关于菜单。
 */
class AddAdditionalSystemMenus extends Migrator
{
    /**
     * Run migration | 执行迁移
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $tenantId = 1;

        // ========================================
        // Dashboard (概览) - order: -1
        // ========================================
        $dashboardId = $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'Dashboard',
            'type' => 'catalog',
            'path' => '/dashboard',
            'component' => null,
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'page.dashboard.title',
                'icon' => 'lucide:layout-dashboard',
                'order' => -1,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Analytics (分析页)
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $dashboardId,
            'name' => 'Analytics',
            'type' => 'menu',
            'path' => '/analytics',
            'component' => '#/views/dashboard/analytics/index.vue',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'page.dashboard.analytics',
                'icon' => 'lucide:area-chart',
                'affixTab' => true,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Workspace (工作台)
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $dashboardId,
            'name' => 'Workspace',
            'type' => 'menu',
            'path' => '/workspace',
            'component' => '#/views/dashboard/workspace/index.vue',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'page.dashboard.workspace',
                'icon' => 'carbon:workspace',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ========================================
        // Demos (演示) - order: 1000
        // ========================================
        $demosId = $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'Demos',
            'type' => 'catalog',
            'path' => '/demos',
            'component' => null,
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.title',
                'icon' => 'ic:baseline-view-in-ar',
                'order' => 1000,
                'keepAlive' => true,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // AntDesignDemos
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $demosId,
            'name' => 'AntDesignDemos',
            'type' => 'menu',
            'path' => '/demos/ant-design',
            'component' => '#/views/demos/antd/index.vue',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.antd',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ========================================
        // VbenProject (Vben项目) - order: 9998
        // ========================================
        $vbenProjectId = $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'VbenProject',
            'type' => 'catalog',
            'path' => '/vben-admin',
            'component' => null,
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.vben.title',
                'icon' => 'https://unpkg.com/@vben/static-source@0.1.7/source/logo-v1.webp',
                'order' => 9998,
                'badgeType' => 'dot',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 9998,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // VbenDocument (文档)
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $vbenProjectId,
            'name' => 'VbenDocument',
            'type' => 'embedded',
            'path' => '/vben-admin/document',
            'component' => 'IFrameView',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.vben.document',
                'icon' => 'lucide:book-open-text',
                'link' => 'https://doc.vben.pro',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // VbenGithub
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $vbenProjectId,
            'name' => 'VbenGithub',
            'type' => 'embedded',
            'path' => '/vben-admin/github',
            'component' => 'IFrameView',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'Github',
                'icon' => 'mdi:github',
                'link' => 'https://github.com/vbenjs/vue-vben-admin',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // VbenNaive (Naive UI)
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $vbenProjectId,
            'name' => 'VbenNaive',
            'type' => 'embedded',
            'path' => '/vben-admin/naive',
            'component' => 'IFrameView',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.vben.naive-ui',
                'icon' => 'logos:naiveui',
                'link' => 'https://naive.vben.pro',
                'badgeType' => 'dot',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // VbenTDesign
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $vbenProjectId,
            'name' => 'VbenTDesign',
            'type' => 'embedded',
            'path' => '/vben-admin/tdesign',
            'component' => 'IFrameView',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.vben.tdesign',
                'icon' => 'tdesign',
                'link' => 'https://tdesign.vben.pro',
                'badgeType' => 'dot',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // VbenElementPlus
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => $vbenProjectId,
            'name' => 'VbenElementPlus',
            'type' => 'embedded',
            'path' => '/vben-admin/ele',
            'component' => 'IFrameView',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.vben.element-plus',
                'icon' => 'logos:element',
                'link' => 'https://ele.vben.pro',
                'badgeType' => 'dot',
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ========================================
        // VbenAbout (关于) - order: 9999
        // ========================================
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'VbenAbout',
            'type' => 'menu',
            'path' => '/vben-admin/about',
            'component' => '#/views/_core/about/index.vue',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'demos.vben.about',
                'icon' => 'lucide:copyright',
                'order' => 9999,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 9999,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ========================================
        // Profile (个人中心) - 隐藏菜单
        // ========================================
        $this->insertMenu([
            'tenant_id' => $tenantId,
            'pid' => null,
            'name' => 'Profile',
            'type' => 'menu',
            'path' => '/profile',
            'component' => '#/views/_core/profile/index.vue',
            'redirect' => null,
            'auth_code' => null,
            'meta' => json_encode([
                'title' => 'page.auth.profile',
                'icon' => 'lucide:user',
                'hideInMenu' => true,
            ], JSON_UNESCAPED_UNICODE),
            'status' => 1,
            'sort' => 10000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ========================================
        // PermissionMonitor (权限监控) - 添加到系统管理下
        // ========================================
        // 首先查找系统管理的 ID
        $systemMenu = $this->fetchRow("SELECT id FROM menus WHERE tenant_id = {$tenantId} AND name = 'System' LIMIT 1");
        if ($systemMenu) {
            $this->insertMenu([
                'tenant_id' => $tenantId,
                'pid' => $systemMenu['id'],
                'name' => 'PermissionMonitor',
                'type' => 'menu',
                'path' => '/admin/permission-monitor',
                'component' => '#/views/admin/permission-monitor.vue',
                'redirect' => null,
                'auth_code' => 'permission.monitor',
                'meta' => json_encode([
                    'title' => 'system.permissionMonitor.title',
                    'icon' => 'ion:analytics',
                    'order' => 7,
                ], JSON_UNESCAPED_UNICODE),
                'status' => 1,
                'sort' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Rollback migration | 回滚迁移
     */
    public function down(): void
    {
        $menuNames = [
            'Dashboard', 'Analytics', 'Workspace',
            'Demos', 'AntDesignDemos',
            'VbenProject', 'VbenDocument', 'VbenGithub', 'VbenNaive', 'VbenTDesign', 'VbenElementPlus',
            'VbenAbout', 'Profile', 'PermissionMonitor'
        ];
        $nameList = implode("', '", $menuNames);
        $this->execute("DELETE FROM menus WHERE tenant_id = 1 AND name IN ('{$nameList}')");
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
