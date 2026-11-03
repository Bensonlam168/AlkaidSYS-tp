import type { PlaywrightTestConfig } from '@playwright/test';

import { devices } from '@playwright/test';

/**
 * Playwright configuration for @vben/web-antd
 *
 * Mirrors the playground config but runs the web-antd app on a separate port
 * to avoid conflicts, and uses tests located in ./tests/e2e.
 */
const config: PlaywrightTestConfig = {
  expect: {
    /**
     * Maximum time expect() should wait for the condition to be met.
     */
    timeout: 5000,
  },
  forbidOnly: !!process.env.CI,
  outputDir: 'node_modules/.e2e/test-results/',
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],
  reporter: [
    ['list'],
    ['html', { outputFolder: 'node_modules/.e2e/test-results' }],
  ],
  retries: process.env.CI ? 2 : 0,
  testDir: './tests/e2e',
  timeout: 30 * 1000,
  use: {
    actionTimeout: 0,
    /** Base URL for the web-antd dev server
     *
     * Note: the Vite config for this app uses port 5666 by default,
     * so we align Playwright's baseURL and webServer.port to 5666
     * to avoid "webServer timeout" issues caused by port mismatch.
     */
    baseURL: 'http://localhost:5666',
    headless: !!process.env.CI,
    trace: 'retain-on-failure',
  },
  webServer: {
    /**
     * Use dev server locally and preview in CI, aligned to port 5666.
     */
    command: process.env.CI
      ? 'pnpm preview -- --port 5666'
      : 'pnpm dev -- --port 5666',
    port: 5666,
    reuseExistingServer: !process.env.CI,
  },
  workers: process.env.CI ? 1 : undefined,
};

export default config;
