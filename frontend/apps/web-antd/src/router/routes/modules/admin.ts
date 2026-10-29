import type { RouteRecordRaw } from 'vue-router';

import { $t } from '#/locales';

const routes: RouteRecordRaw[] = [
  {
    meta: {
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
          icon: 'mdi:domain',
          title: $t('system.tenant.title'),
        },
        component: () => import('#/views/system/tenant/list.vue'),
      },
      {
        path: '/system/user',
        name: 'SystemUser',
        meta: {
          icon: 'mdi:account-group',
          title: $t('system.user.title'),
        },
        component: () => import('#/views/system/user/list.vue'),
      },
      {
        path: '/system/role',
        name: 'SystemRole',
        meta: {
          icon: 'mdi:account-key',
          title: $t('system.role.title'),
        },
        component: () => import('#/views/system/role/list.vue'),
      },
      {
        path: '/system/permission',
        name: 'SystemPermission',
        meta: {
          icon: 'mdi:shield-key',
          title: $t('system.permission.title'),
        },
        component: () => import('#/views/system/permission/list.vue'),
      },
      {
        path: '/system/menu',
        name: 'SystemMenu',
        meta: {
          icon: 'mdi:menu',
          title: $t('system.menu.title'),
        },
        component: () => import('#/views/system/menu/list.vue'),
      },
      {
        path: '/system/dept',
        name: 'SystemDept',
        meta: {
          icon: 'mdi:sitemap',
          title: $t('system.dept.title'),
        },
        component: () => import('#/views/system/dept/list.vue'),
      },
      {
        path: '/admin/permission-monitor',
        name: 'PermissionMonitor',
        meta: {
          icon: 'ion:analytics',
          title: $t('system.permissionMonitor.title'),
        },
        component: () => import('#/views/admin/permission-monitor.vue'),
      },
    ],
  },
];

export default routes;
