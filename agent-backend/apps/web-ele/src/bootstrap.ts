import { createApp, watchEffect } from 'vue';

import { registerAccessDirective } from '@vben/access';
import { registerLoadingDirective } from '@vben/common-ui';
import { preferences } from '@vben/preferences';
import { initStores, setTimezoneHandler, useTimezoneStore } from '@vben/stores';
import '@vben/styles';
import '@vben/styles/ele';

import { useTitle } from '@vueuse/core';
import { ElLoading } from 'element-plus';

import { $t, setupI18n } from '#/locales';

import { initComponentAdapter } from './adapter/component';
import { initSetupVbenForm } from './adapter/form';
import App from './app.vue';
import { router } from './router';
import { DEFAULT_TIMEZONE, TIMEZONE_OPTIONS } from './utils/timezone-shift';

import './styles/dialog-responsive.css';
import './styles/image-viewer.css';
import './styles/menu-title.css';

const PAGE_TITLE_CACHE_KEY = 'agent-backend-page-title';
const PAGE_TITLE_CACHE_LIMIT = 50;

/** index.html 的 inline script 會在掛載前讀這份快取還原標題 */
function cachePageTitle(path: string, title: string) {
  try {
    const cache: Record<string, string> = JSON.parse(
      localStorage.getItem(PAGE_TITLE_CACHE_KEY) || '{}',
    );
    if (cache[path] === title) {
      return;
    }
    cache[path] = title;
    const kept = Object.entries(cache).slice(-PAGE_TITLE_CACHE_LIMIT);
    localStorage.setItem(
      PAGE_TITLE_CACHE_KEY,
      JSON.stringify(Object.fromEntries(kept)),
    );
  } catch (error) {
    void error;
  }
}

async function bootstrap(namespace: string) {
  // 初始化组件适配器
  await initComponentAdapter();

  // 初始化表单组件
  await initSetupVbenForm();

  // // 设置弹窗的默认配置
  // setDefaultModalProps({
  //   fullscreenButton: false,
  // });
  // // 设置抽屉的默认配置
  // setDefaultDrawerProps({
  //   zIndex: 2000,
  // });
  const app = createApp(App);

  // 注册Element Plus提供的v-loading指令
  app.directive('loading', ElLoading.directive);

  // 注册Vben提供的v-loading和v-spinning指令
  registerLoadingDirective(app, {
    loading: false, // Vben提供的v-loading指令和Element Plus提供的v-loading指令二选一即可，此处false表示不注册Vben提供的v-loading指令
    spinning: 'spinning',
  });

  // 国际化 i18n 配置
  await setupI18n(app);

  // 配置 pinia-tore
  await initStores(app, { namespace });

  // 時區（target/tickets#4095、#4144）：釘死 GMT+8，不開放代理切換。
  // Vben 內建 store 的初始值是 dayjs.tz.guess()（跟瀏覽器走），不釘死的話
  // 海外使用者升版當下報表數字會無聲變動；殘留的城市名 zone 也一併矯正。
  //
  // 4144 裁定收掉切換入口後，這裡改成「每次啟動都比對並釘回 GMT+8」——
  // 時區偏好走 pinia persist（localStorage），4095 上線期間已把自己切成
  // 非 +8 的使用者，入口一旦移除就再也切不回來、會永遠停在錯的口徑。
  // 這一段就是他們的矯正點，不能只鎖 UI。日後放開時改回「僅校驗合法值」即可。
  setTimezoneHandler({
    getTimezoneOptions: () =>
      Promise.resolve(
        TIMEZONE_OPTIONS.map(({ label, value }) => ({ label, value })),
      ),
  });
  const timezoneStore = useTimezoneStore();
  if (timezoneStore.timezone !== DEFAULT_TIMEZONE) {
    await timezoneStore.setTimezone(DEFAULT_TIMEZONE);
  }

  // 安装权限指令
  registerAccessDirective(app);

  // 初始化 tippy
  const { initTippy } = await import('@vben/common-ui/es/tippy');
  initTippy(app);

  // 配置路由及路由守卫
  app.use(router);

  // 配置Motion插件
  const { MotionPlugin } = await import('@vben/plugins/motion');
  app.use(MotionPlugin);

  // 动态更新标题
  watchEffect(() => {
    if (example.invalid.dynamicTitle) {
      const route = router.currentRoute.value;
      // 首次導航確認前是 START_LOCATION（無 matched、無 meta.title），
      // 這時算出來的標題只有 app name，會蓋掉 index.html 還原的標題造成閃動
      if (route.matched.length === 0) {
        return;
      }
      const routeTitle = route.meta?.title;
      const pageTitle =
        (routeTitle ? `${$t(routeTitle)} - ` : '') + example.invalid.name;
      useTitle(pageTitle);
      cachePageTitle(route.path, pageTitle);
    }
  });

  app.mount('#app');
}

export { bootstrap };
