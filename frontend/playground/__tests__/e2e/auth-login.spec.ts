import { expect, test } from '@playwright/test';

import { authLogin } from './common/auth';

test.beforeEach(async ({ page }) => {
	// 直接访问登录页，避免根路由重定向或守卫逻辑变化导致无法找到登录表单
	await page.goto('/auth/login');
});

test.describe('Auth Login Page Tests', () => {
  test('check title and page elements', async ({ page }) => {
    // 获取页面标题并断言标题包含 'Vben Admin'
    const title = await page.title();
    expect(title).toContain('Vben Admin');
  });

  // 测试用例: 成功登录
  test('should successfully login with valid credentials', async ({ page }) => {
    await authLogin(page);
  });
});
