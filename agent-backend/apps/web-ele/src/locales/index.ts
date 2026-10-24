import type { Language } from 'element-plus/es/locale';

import type { App } from 'vue';

import type { LocaleSetupOptions, SupportedLanguagesType } from '@vben/locales';

import { ref } from 'vue';

import {
  $t,
  setupI18n as coreSetup,
  loadLocalesMapFromDir,
} from '@vben/locales';
import { preferences } from '@vben/preferences';

import dayjs from 'dayjs';
import defaultLocale from 'element-plus/es/locale/lang/zh-cn';

import { frameworkToCode, getBackendLang, loadLegacyMessages } from './legacy';

const elementLocale = ref<Language>(defaultLocale);

/**
 * 第三方元件（ElementPlus / dayjs）語言包載入器，鍵為 api/languages 的 code。
 *
 * 刻意跟著「後端語系」而非框架 chrome 語系：chrome 只有 5 種語言包，
 * chromeLocaleFor() 會把其餘語系一律退成 en-US，但 ElementPlus 有對應語言包的
 * 語系（分頁「共 N 項」、表格空狀態、日期選擇器的月份星期…）就該照使用者在
 * header 所選的語言呈現。取不到 code 時退英文。
 */
const elementLocaleLoaders: Record<
  string,
  () => Promise<{ default: Language }>
> = {
  bn: () => import('element-plus/es/locale/lang/bn'),
  en: () => import('element-plus/es/locale/lang/en'),
  fr: () => import('element-plus/es/locale/lang/fr'),
  'id-id': () => import('element-plus/es/locale/lang/id'),
  ko: () => import('element-plus/es/locale/lang/ko'),
  'ms-my': () => import('element-plus/es/locale/lang/ms'),
  'my-mm': () => import('element-plus/es/locale/lang/my'),
  ru: () => import('element-plus/es/locale/lang/ru'),
  'th-th': () => import('element-plus/es/locale/lang/th'),
  'vi-vn': () => import('element-plus/es/locale/lang/vi'),
  'zh-cn': () => import('element-plus/es/locale/lang/zh-cn'),
  'zh-tw': () => import('element-plus/es/locale/lang/zh-tw'),
  // ElementPlus 未提供 tl-ph / ho / tpi，退英文
};

/**
 * dayjs 語言包載入器，鍵同上。dayjs 未提供 ho / tpi，退英文。
 *
 * 注意務必把語言包的 default（ILocale 物件）交給 dayjs.locale()，不能只靠
 * import 的副作用再以名稱套用：vite 下語言包 require 到的 dayjs 與應用 import
 * 的不是同一個實例，副作用註冊不會出現在應用這側的 dayjs.Ls，以名稱套用會
 * 靜默失敗（語系停在 en）。傳物件則會就地註冊，不受實例分裂影響。 */
const dayjsLocaleLoaders: Record<string, () => Promise<{ default: ILocale }>> =
  {
    bn: () => import('dayjs/locale/bn'),
    en: () => import('dayjs/locale/en'),
    fr: () => import('dayjs/locale/fr'),
    'id-id': () => import('dayjs/locale/id'),
    ko: () => import('dayjs/locale/ko'),
    'ms-my': () => import('dayjs/locale/ms-my'),
    'my-mm': () => import('dayjs/locale/my'),
    ru: () => import('dayjs/locale/ru'),
    'th-th': () => import('dayjs/locale/th'),
    'tl-ph': () => import('dayjs/locale/tl-ph'),
    'vi-vn': () => import('dayjs/locale/vi'),
    'zh-cn': () => import('dayjs/locale/zh-cn'),
    'zh-tw': () => import('dayjs/locale/zh-tw'),
  };

/**
 * 第三方元件要用的語系 code。與 loadLegacyMessages() 同一套推導：
 * 優先用 header 顯式選的後端語系，沒有才由框架語系推。 */
function thirdPartyCode(lang: SupportedLanguagesType): string {
  return getBackendLang() || frameworkToCode(lang);
}

const modules = import.meta.glob('./langs/**/*.json');

const localesMap = loadLocalesMapFromDir(
  /\.\/langs\/([^/]+)\/(.*)\.json$/,
  modules,
);
/**
 * 加载应用特有的语言包
 * 这里也可以改造为从服务端获取翻译数据
 * @param lang
 */
async function loadMessages(lang: SupportedLanguagesType) {
  const [appLocaleMessages] = await Promise.all([
    localesMap[lang]?.(),
    loadThirdPartyMessage(lang),
    // 載入 legacy 扁平語言字典（tl() 使用），與語系切換連動
    loadLegacyMessages(lang),
  ]);
  return appLocaleMessages?.default;
}

/**
 * 加载第三方组件库的语言包
 * @param lang
 */
async function loadThirdPartyMessage(lang: SupportedLanguagesType) {
  await Promise.all([loadElementLocale(lang), loadDayjsLocale(lang)]);
}

/**
 * 加载dayjs的语言包
 * @param lang
 */
async function loadDayjsLocale(lang: SupportedLanguagesType) {
  const code = thirdPartyCode(lang);
  // 默认使用英语
  const loader = dayjsLocaleLoaders[code] ?? dayjsLocaleLoaders.en;
  const locale = await loader?.();
  if (locale?.default) {
    dayjs.locale(locale.default);
  } else {
    console.error(`Failed to load dayjs locale for ${lang}`);
  }
}

/**
 * 加载element-plus的语言包
 * @param lang
 */
async function loadElementLocale(lang: SupportedLanguagesType) {
  const code = thirdPartyCode(lang);
  // ElementPlus 沒有對應語言包的語系（tl-ph / ho / tpi）退英文
  const loader = elementLocaleLoaders[code] ?? elementLocaleLoaders.en;
  const mod = await loader?.();
  if (mod?.default) {
    elementLocale.value = mod.default;
  }
}

async function setupI18n(app: App, options: LocaleSetupOptions = {}) {
  await coreSetup(app, {
    defaultLocale: example.invalid.locale,
    loadMessages,
    missingWarn: !import.meta.env.PROD,
    ...options,
  });
}

export {
  chromeLocaleFor,
  detectBrowserLang,
  frameworkToCode,
  getBackendLang,
  initBackendLangFromBrowser,
  setBackendLang,
  tl,
  tlKeys,
} from './legacy';
export { $t, elementLocale, setupI18n };
