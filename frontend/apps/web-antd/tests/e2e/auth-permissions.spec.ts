import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.describe('Auth & permission integration (/v1/auth/me)', () => {
  test.beforeEach(async ({ page }) => {
    // 记录与认证相关的所有请求，便于诊断真实请求 URL / Host
    page.on('request', (request) => {
      const url = request.url();
      if (url.includes('/auth') || url.includes('/v1/auth')) {
        // eslint-disable-next-line no-console
        console.log('[E2E][auth-request]', request.method(), url);
      }
    });
  });

  test('should login and receive permissions from /v1/auth/me', async ({ page }) => {
    // 直接访问认证登录页，避免根路由偏好设置或守卫逻辑变化
    await page.goto('/auth/login');

    const authMeResponsePromise = page.waitForResponse((response) => {
      return (
        response.url().includes('/v1/auth/me') &&
        response.request().method() === 'GET'
      );
    });

    await authLogin(page);

    const authMeResponse = await authMeResponsePromise;
    expect(authMeResponse.ok()).toBeTruthy();

    const body = await authMeResponse.json();

    expect(body.code).toBe(0);
    expect(Array.isArray(body.data.permissions)).toBe(true);
    expect(body.data.permissions.length).toBeGreaterThan(0);

    for (const permission of body.data.permissions as string[]) {
      expect(typeof permission).toBe('string');
      expect(permission).toContain(':');

      const [resource, action] = permission.split(':');
      expect(resource.length).toBeGreaterThan(0);
      expect(action.length).toBeGreaterThan(0);
    }
  });

  test('should persist permissions into access store (localStorage)', async ({ page }) => {
    // 保持与上一个用例一致，直接进入登录页
    await page.goto('/auth/login');

    const authMeResponsePromise = page.waitForResponse((response) => {
      return (
        response.url().includes('/v1/auth/me') &&
        response.request().method() === 'GET'
      );
    });

    await authLogin(page);

    const authMeResponse = await authMeResponsePromise;
    const body = await authMeResponse.json();
    const permissions = body.data.permissions as string[];

    // Wait until access store has been persisted into localStorage
    const accessCodesHandle = await page.waitForFunction(() => {
      for (let i = 0; i < window.localStorage.length; i++) {
        const key = window.localStorage.key(i);
        if (!key) continue;

        const raw = window.localStorage.getItem(key);
        if (!raw) continue;

        try {
          const parsed = JSON.parse(raw as string) as any;
          if (parsed && Array.isArray(parsed.accessCodes)) {
            return parsed.accessCodes as string[];
          }
        } catch {
          // Ignore non-JSON entries
        }
      }

      return null;
    });

    const storedAccessCodes = (await accessCodesHandle.jsonValue()) as
      | string[]
      | null;

    expect(storedAccessCodes).not.toBeNull();
    expect(Array.isArray(storedAccessCodes)).toBe(true);
    expect((storedAccessCodes as string[]).length).toBeGreaterThan(0);

    // All permissions from /v1/auth/me should be reflected in access store
    for (const permission of permissions) {
      expect(storedAccessCodes).toContain(permission);
    }

    // Stored codes must also use resource:action format
    for (const code of storedAccessCodes as string[]) {
      expect(typeof code).toBe('string');
      expect(code).toContain(':');
      const [resource, action] = code.split(':');
      expect(resource.length).toBeGreaterThan(0);
      expect(action.length).toBeGreaterThan(0);
    }
  });
});
