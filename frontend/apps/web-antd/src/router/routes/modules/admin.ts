import type { RouteRecordRaw } from 'vue-router';

import { $t } from '#/locales';

/**
 * 系统管理菜单（`/system/**` 与 `/admin/permission-monitor`）
 *
 * 权限说明（缺陷 A7 修复）：
 * - 本文件所有 `meta.authority` 比对的是「角色 **slug**」（字符串，如 `admin` / `test_admin`），
 *   而不是角色 ID。
 * - 数据来源：后端 `GET /v1/auth/me` 的 `role_slugs` 字段（`roles` 字段为角色 ID，仅作兼容保留），
 *   由 `src/router/guard.ts` 读取后传给 `generateAccess({ roles })`，
 *   最终在 `@vben/utils` 的 `hasAuthority()` 中与 `meta.authority` 求交集。
 * - 失败关闭（刻意的）：若后端未返回 `role_slugs`（例如旧版本后端），`guard.ts` 会回退到
 *   `roles`（角色 ID，数字）。此时数字 ID 与字符串 slug 永不匹配，
 *   带 `authority` 的路由（即本文件的系统管理菜单）将**不可见**。
 *   这是有意的设计：权限信息缺失时应当收紧而不是放行。
 * - 注意：父级 `/system` 与所有子级必须声明同样的 `authority`，
 *   否则会出现「父级可见、子级全被隐藏」的菜单层级异常。
 */
const routes: RouteRecordRaw[] = [
  {
    meta: {
      // 父级路由同样需要 authority，避免父级可见而子级全部被过滤
      authority: ['admin', 'test_admin'],
      icon: 'ion:settings-outline',
      order: 9997,
      title: $t('system.title'),
    },
    name: 'System',
    path: '/system',
    children: [
      {
        path: '/system/tenant',
        name: 'SystemTenant',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:domain',
          title: $t('system.tenant.title'),
        },
        component: () => import('#/views/system/tenant/list.vue'),
      },
      {
        path: '/system/user',
        name: 'SystemUser',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:account-group',
          title: $t('system.user.title'),
        },
        component: () => import('#/views/system/user/list.vue'),
      },
      {
        path: '/system/role',
        name: 'SystemRole',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:account-key',
          title: $t('system.role.title'),
        },
        component: () => import('#/views/system/role/list.vue'),
      },
      {
        path: '/system/permission',
        name: 'SystemPermission',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:shield-key',
          title: $t('system.permission.title'),
        },
        component: () => import('#/views/system/permission/list.vue'),
      },
      {
        path: '/system/menu',
        name: 'SystemMenu',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:menu',
          title: $t('system.menu.title'),
        },
        component: () => import('#/views/system/menu/list.vue'),
      },
      {
        path: '/system/dept',
        name: 'SystemDept',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:sitemap',
          title: $t('system.dept.title'),
        },
        component: () => import('#/views/system/dept/list.vue'),
      },
      {
        path: '/admin/permission-monitor',
        name: 'PermissionMonitor',
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'ion:analytics',
          title: $t('system.permissionMonitor.title'),
        },
        component: () => import('#/views/admin/permission-monitor.vue'),
      },
    ],
  },
];

export default routes;
