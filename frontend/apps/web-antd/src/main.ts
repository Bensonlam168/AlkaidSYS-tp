import { initPreferences } from '@vben/preferences';
import { unmountGlobalLoading } from '@vben/utils';

import { overridesPreferences } from './preferences';

/**
 * 注入 CSP，限制 frame-ancestors 与 frame-src，默认允许自身与配置的插件域。
 */
function applySecurityCsp() {
  const frameAncestors = import.meta.env.VITE_FRAME_ANCESTORS || "'self'";
  const pluginOrigin =
    import.meta.env.VITE_PLUGIN_IFRAME_ORIGIN ||
    (import.meta.env.VITE_PLUGIN_IFRAME_URL
      ? (() => {
          try {
            return new URL(import.meta.env.VITE_PLUGIN_IFRAME_URL as string).origin;
          } catch {
            return '';
          }
        })()
      : '');
  const extraFrameSrc = (import.meta.env.VITE_FRAME_SRC_EXTRA || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);

  const frameSrcList = Array.from(
    new Set(["'self'", ...(pluginOrigin ? [pluginOrigin] : []), ...extraFrameSrc]),
  );

  const cspContent = `frame-ancestors ${frameAncestors}; frame-src ${frameSrcList.join(
    ' ',
  )};`;

  let meta = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
  if (!meta) {
    meta = document.createElement('meta');
    meta.setAttribute('http-equiv', 'Content-Security-Policy');
    document.head.appendChild(meta);
  }
  meta.setAttribute('content', cspContent);
}

/**
 * 应用初始化完成之后再进行页面加载渲染
 */
async function initApplication() {
  // CSP 应优先注入，防止 iframe 被非授权站点嵌入
  applySecurityCsp();

  // name用于指定项目唯一标识
  // 用于区分不同项目的偏好设置以及存储数据的key前缀以及其他一些需要隔离的数据
  const env = import.meta.env.PROD ? 'prod' : 'dev';
  const appVersion = import.meta.env.VITE_APP_VERSION;
  const namespace = `${import.meta.env.VITE_APP_NAMESPACE}-${appVersion}-${env}`;

  // app偏好设置初始化
  await initPreferences({
    namespace,
    overrides: overridesPreferences,
  });

  // 启动应用并挂载
  // vue应用主要逻辑及视图
  const { bootstrap } = await import('./bootstrap');
  await bootstrap(namespace);

  // 移除并销毁loading
  unmountGlobalLoading();
}

initApplication();
