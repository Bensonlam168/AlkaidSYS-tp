import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.describe('Tenant Headers Integration', () => {
  test('should attach tenant headers based on tenant store', async ({
    page,
  }) => {
    const capturedHeaders: Array<Record<string, string>> = [];

    // 拦截用于验证多租户请求头的探针接口
    await page.route('**/v1/tenant-headers-probe', async (route) => {
      const request = route.request();
      capturedHeaders.push(request.headers());

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { ok: true } }),
      });
    });

    // 直接进入登录页，复用现有的 authLogin 帮助函数完成登录
    await page.goto('/auth/login');

    // 确保 /v1/auth/me 请求完成，意味着后端已返回用户与租户信息
    const authMeResponsePromise = page.waitForResponse((response) => {
      return (
        response.url().includes('/v1/auth/me') &&
        response.request().method() === 'GET'
      );
    });

    await authLogin(page);
    const authMeResponse = await authMeResponsePromise;
    expect(authMeResponse.ok()).toBeTruthy();

    // 等待 Tenant Store 持久化到 localStorage
    const tenantStateHandle = await page.waitForFunction(() => {
      for (let i = 0; i < window.localStorage.length; i++) {
        const key = window.localStorage.key(i);
        if (!key) continue;

        const raw = window.localStorage.getItem(key);
        if (!raw) continue;

        try {
          const parsed = JSON.parse(raw as string) as any;
          // Tenant Store 的持久化状态应包含 tenantId 字段
          if (
            parsed &&
            Object.prototype.hasOwnProperty.call(parsed, 'tenantId') &&
            (typeof parsed.tenantId === 'number' || parsed.tenantId === null)
          ) {
            return parsed;
          }
        } catch {
          // Ignore non-JSON entries
        }
      }

      return null;
    });

    const tenantState = (await tenantStateHandle.jsonValue()) as {
      tenantId: number | null;
      tenantCode?: string | null;
      siteId?: number | null;
      siteCode?: string | null;
    } | null;

    expect(tenantState).not.toBeNull();

    // 使用应用内部暴露的 E2E HTTP 助手发起一次真实业务请求
    await page.evaluate(async () => {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const globalAny = window as any;
      const tenantRequest = globalAny.__APP_TENANT_REQUEST__ as
        | ((url: string) => Promise<unknown>)
        | undefined;

      if (!tenantRequest) {
        throw new Error('__APP_TENANT_REQUEST__ is not defined on window');
      }

      await tenantRequest('/v1/tenant-headers-probe');
    });

    // 确认探针请求已被拦截并记录
    expect(capturedHeaders.length).toBeGreaterThan(0);
    const headers = capturedHeaders[0];

    const expectedTenantId = tenantState!.tenantId;

    if (expectedTenantId != null) {
      // 当 Tenant Store 中存在 tenantId 时，必须携带 X-Tenant-ID 请求头
      expect(headers['x-tenant-id']).toBe(String(expectedTenantId));
    } else {
      // 如果 Tenant Store 中没有 tenantId，则不应发送 X-Tenant-ID
      expect(headers['x-tenant-id']).toBeUndefined();
    }

    const expectedSiteId = tenantState!.siteId ?? null;

    if (expectedSiteId != null) {
      // 当 Tenant Store 中存在 siteId 时，必须携带 X-Site-ID 请求头
      expect(headers['x-site-id']).toBe(String(expectedSiteId));
    } else {
      // 否则不应发送 X-Site-ID
      expect(headers['x-site-id']).toBeUndefined();
    }
  });
});
