import { defineOverridesPreferences } from '@vben/preferences';

/**
 * @description 项目配置文件
 * 只需要覆盖项目中的一部分配置，不需要的配置不用覆盖，会自动使用默认配置
 * !!! 更改配置后请清空缓存，否则可能不生效
 */
export const overridesPreferences = defineOverridesPreferences({
  // overrides
  app: {
    accessMode: 'backend',
    defaultHomePath: '/home',
    name: import.meta.env.VITE_APP_TITLE || 'Agent Management',
    enablePreferences: false,
  },
  breadcrumb: {
    enable: false,
  },
  copyright: {
    enable: false,
  },
  // 頂欄＋分頁籤跟著頁面內容一起捲（不釘在視窗頂端）；2026-09-09 隨 4274 會員詳情抽屜定調。
  // 使用者 localStorage 快取會蓋過這裡的 overrides，main.ts 的 updatePreferences 另有強制一次。
  header: {
    mode: 'static',
  },
  sidebar: {
    collapsed: false,
    expandOnHover: true,
    fixedButton: false,
  },
  tabbar: {
    showMaximize: false,
    showRefresh: false,
  },
  widget: {
    fullscreen: false,
    globalSearch: false,
    // 框架內建語言切換維持關閉：其 SUPPORT_LANGUAGES 僅中英兩種，且只切前端 i18n
    // 不同步後端語系。登入頁的語言切換改用 auth.vue 自訂 slot（target/tickets#4161）
    languageToggle: false,
    lockScreen: false,
    refresh: false,
    sidebarToggle: false,
  },
});
