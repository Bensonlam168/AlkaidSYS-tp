import type { RouteRecordRaw } from 'vue-router';

import { $t } from '#/locales';

/**
 * 工作流菜单（`/workflow/**`）
 *
 * ## 权限说明（为什么用角色 slug，而不是权限码）
 *
 * 本文件 `meta.authority` 比对的是**角色 slug**（`admin` / `test_admin`），与 `modules/admin.ts`
 * 保持一致。原因是路由级**没有**权限码门禁这一机制：
 * - `@vben/utils` 的 `hasAuthority(route, access)` 只做一件事 ——
 *   把 `route.meta.authority` 与 `generateAccess({ roles })` 传入的 `roles` 求交集；
 * - `roles` 来自 `router/guard.ts` 的 `userInfo.role_slugs`（角色 slug 字符串）；
 * - `generateRoutesByFrontend()` 里没有任何「按权限码过滤路由」的分支，
 *   `@vben/access` 的 `hasAccessByCodes()` / `v-access:code` 只能用于**组件内部**。
 *
 * 因此若在这里写权限码 `workflow_task.read`，交集恒为空 → 菜单对**所有人**不可见。
 * 那不是「更严格」，而是「功能直接消失」（失败关闭在这里的代价是零收益）。
 *
 * ## 这只是菜单可见性，不是鉴权
 *
 * 真正的鉴权在后端：`route/workflow.php` 给每个端点挂了 `workflow_task.read` /
 * `workflow_task.write` 权限码，越权请求会被 `app\middleware\Permission` 拦下（错误码 2002）。
 * 前端隐藏菜单只能减少误点，拦不住任何构造出来的请求 —— 这一点不因本文件而改变。
 *
 * ## 父子一致性
 *
 * 父级 `/workflow` 与子级必须声明同样的 `authority`，
 * 否则会出现「父级可见、子级全被隐藏」的菜单层级异常（与 `modules/admin.ts` 同一条约定）。
 */
const routes: RouteRecordRaw[] = [
  {
    meta: {
      // 父级路由同样需要 authority，避免父级可见而子级全部被过滤
      authority: ['admin', 'test_admin'],
      icon: 'mdi:clipboard-flow-outline',
      order: 200,
      title: $t('workflow.title'),
    },
    name: 'Workflow',
    path: '/workflow',
    children: [
      {
        component: () => import('#/views/workflow/todo/list.vue'),
        meta: {
          authority: ['admin', 'test_admin'],
          icon: 'mdi:clipboard-check-outline',
          title: $t('workflow.todo.title'),
        },
        name: 'WorkflowTodo',
        path: '/workflow/todo',
      },
    ],
  },
];

export default routes;
