import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    meta: {
      // 插件示例菜单
      icon: 'mdi:puzzle-outline',
      keepAlive: true,
      order: 9997,
      title: '插件示例',
    },
    name: 'Plugin',
    path: '/plugins',
    children: [
      {
        meta: {
          title: 'iframe 插件加载器',
          description: '演示 postMessage 握手、上下文注入与安全配置',
        },
        name: 'PluginIframeLoader',
        path: '/plugins/iframe-loader',
        component: () => import('#/views/demos/plugin/iframe-loader.vue'),
      },
    ],
  },
];

export default routes;
