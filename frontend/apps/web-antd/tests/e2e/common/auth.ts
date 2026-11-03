import type { Page } from '@playwright/test';

/**
 * Shared login helper for web-antd E2E tests.
 *
 * 对于 AlkaidSYS 的 web-antd 应用，这里不再直接操作登录表单与滑块验证码，
 * 而是通过浏览器上下文内的 useAuthStore().authLogin 调用真实后端接口，
 * 以确保 /v1/auth/login 与 /v1/auth/me 权限链路被打通，同时避免对 UI 细节的强耦合。
 */
export async function authLogin(page: Page) {
  // 确保 SPA 与登录路由已经加载完成，避免在应用尚未初始化时注入脚本
  await page.waitForLoadState('networkidle');

  // 进一步等待 bootstrap 在 window 上暴露 __APP_AUTH_LOGIN__，避免偶发性时序问题
  await page.waitForFunction(() => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const globalAny = window as any;
    return typeof globalAny.__APP_AUTH_LOGIN__ === 'function';
  });

  // 监听浏览器 console 输出，用于诊断错误
  const consoleMessages: string[] = [];
  page.on('console', (msg) => {
    const text = msg.text();
    consoleMessages.push(`[Browser Console] ${msg.type()}: ${text}`);
  });

  try {
    await page.evaluate(async () => {
      // 从全局对象上获取在 bootstrap 中暴露的 E2E 登录帮助函数
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const globalAny = window as any;
      const loginHelper = globalAny.__APP_AUTH_LOGIN__ as
        | ((
            params: { email: string; password: string },
            onSuccess?: () => Promise<void> | void,
          ) => Promise<unknown>)
        | undefined;

      if (!loginHelper) {
        throw new Error('__APP_AUTH_LOGIN__ is not defined on window');
      }

      try {
        // 使用 tests/setup_test_data.php 中创建的测试账号完成登录
        await loginHelper(
          {
            email: 'test@example.com',
            password: 'password',
          },
          // 在 E2E 场景下不做路由跳转，专注于权限接口与 Store 状态验证
          async () => {},
        );
      } catch (error: any) {
        // 捕获并详细记录错误信息
        const errorDetails = {
          name: error?.name,
          message: error?.message,
          code: error?.code,
          status: error?.response?.status,
          statusText: error?.response?.statusText,
          responseData: error?.response?.data,
          config: {
            url: error?.config?.url,
            method: error?.config?.method,
            headers: error?.config?.headers,
          },
        };
        console.error(
          '[E2E][authLogin] Login failed with error:',
          JSON.stringify(errorDetails, null, 2),
        );
        throw error;
      }
    });
  } catch (error: any) {
    // 输出所有收集到的 console 消息
    if (consoleMessages.length > 0) {
      console.error('[E2E][authLogin] Browser console messages:');
      consoleMessages.forEach((msg) => console.error(msg));
    }

    // 重新抛出错误，附带更多上下文信息
    throw new Error(
      `authLogin failed: ${error.message}\nSee browser console messages above for details.`,
    );
  }
}
