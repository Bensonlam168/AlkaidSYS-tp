import { expect, test } from '@playwright/test';

test.describe('Iframe Plugin Loader Page', () => {
  test('should render page and enforce sandbox/CSP hints', async ({ page }) => {
    await page.goto('/plugins/iframe-loader');

    await expect(page.getByText('iframe 插件加载器示例')).toBeVisible();

    const iframeLocator = page.locator('iframe');
    const iframeCount = await iframeLocator.count();

    if (iframeCount > 0) {
      const sandbox = await iframeLocator.first().getAttribute('sandbox');
      await expect(sandbox ?? '').toContain('allow-scripts');
      await expect(sandbox ?? '').toContain('allow-same-origin');
      const referrer = await iframeLocator
        .first()
        .getAttribute('referrerpolicy');
      await expect(referrer).toBe('no-referrer');
    } else {
      await expect(
        page.getByText('请配置 VITE_PLUGIN_IFRAME_URL 后再加载插件'),
      ).toBeVisible();
    }
  });
});
