import { resolve } from 'path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  root: __dirname,
  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['tests/**/*.test.ts'],
  },
  resolve: {
    alias: {
      // 将 @vben/stores 映射到源码，便于在 SDK 包内单测使用
      '@vben/stores': resolve(__dirname, '../stores/src'),
      // 避免依赖真实 pinia 模块，单测使用轻量 stub
      pinia: resolve(__dirname, './tests/pinia-stub.ts'),
    },
  },
});
