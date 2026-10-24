import { defineConfig } from '@vben/vite-config';

import ElementPlus from 'unplugin-element-plus/vite';

export default defineConfig(async ({ command }) => {
  return {
    application: {
      devtools: true,
      nitroMock: command !== 'build',
    },
    vite: {
      plugins: [
        ElementPlus({
          format: 'esm',
        }),
      ],
      server: {
        proxy: {
          // 前端打 /proxy/<真實API路徑>，轉發到 Nitro gateway（加密 + 網域路由 + session）
          '/proxy': {
            changeOrigin: true,
            target: 'http://localhost:5320',
            ws: true,
          },
        },
      },
    },
  };
});
