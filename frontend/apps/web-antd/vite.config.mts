import { defineConfig } from '@vben/vite-config';

export default defineConfig(async () => {
  return {
    application: {},
    vite: {
      server: {
        proxy: {
          '/api': {
            changeOrigin: true,
            rewrite: (path) => path.replace(/^\/api/, ''),
            // 代理到后端 PHP 服务器
            target: 'http://localhost:9501',
            ws: true,
          },
        },
      },
    },
  };
});
