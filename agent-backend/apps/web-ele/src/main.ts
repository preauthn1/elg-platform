import { initPreferences, updatePreferences } from '@vben/preferences';
import { unmountGlobalLoading } from '@vben/utils';

import {
  chromeLocaleFor,
  getBackendLang,
  initBackendLangFromBrowser,
} from './locales/legacy';
import { overridesPreferences } from './preferences';

/**
 * 应用初始化完成之后再进行页面加载渲染
 */
async function initApplication() {
  // name用于指定项目唯一标识
  // 用于区分不同项目的偏好设置以及存储数据的key前缀以及其他一些需要隔离的数据
  const env = import.meta.env.PROD ? 'prod' : 'dev';
  const appVersion = import.meta.env.VITE_APP_VERSION;
  const namespace = `${import.meta.env.VITE_APP_NAMESPACE}-${appVersion}-${env}`;

  // app偏好设置初始化
  await initPreferences({
    namespace,
    overrides: overridesPreferences,
  });

  // 首次進入（使用者未曾選過語言）依瀏覽器語言自動適配。
  initBackendLangFromBrowser();

  // chrome 語系每次啟動都對齊已選的後端語言：框架語言包新增語系時（如 ko/fr），
  // 早先已選過該語言的使用者不需重新切換一次語言才會生效。
  const backendLang = getBackendLang();

  updatePreferences({
    app: {
      enablePreferences: false,
      ...(backendLang ? { locale: chromeLocaleFor(backendLang) } : {}),
    },
    breadcrumb: { enable: false },
    copyright: { enable: false },
    // 頂欄跟內容一起捲（4274 定調）；放這裡是因為使用者 localStorage 快取會蓋過 overrides
    header: { mode: 'static' },
    sidebar: { collapsed: false, expandOnHover: true, fixedButton: false },
    tabbar: { showMaximize: false, showRefresh: false },
    widget: {
      fullscreen: false,
      globalSearch: false,
      lockScreen: false,
      refresh: false,
      sidebarToggle: false,
    },
  });

  // 启动应用并挂载
  // vue应用主要逻辑及视图
  const { bootstrap } = await import('./bootstrap');
  await bootstrap(namespace);

  // 移除并销毁loading
  unmountGlobalLoading();
}

initApplication();
