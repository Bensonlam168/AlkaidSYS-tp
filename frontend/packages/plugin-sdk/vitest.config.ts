import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vitest/config';

// 本包是 ESM（package.json 的 type 为 module），没有 __dirname，
// 因此用 import.meta.url 推导包根目录，兼容 Node 20.10+（import.meta.dirname 需要 20.11+）
const packageRoot = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  root: packageRoot,
  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['tests/**/*.test.ts'],
  },
  resolve: {
    alias: {
      // 将 @vben/stores 映射到源码，便于在 SDK 包内单测使用
      '@vben/stores': resolve(packageRoot, '../stores/src'),
      // 避免依赖真实 pinia 模块，单测使用轻量 stub
      pinia: resolve(packageRoot, './tests/pinia-stub.ts'),
    },
  },
});
