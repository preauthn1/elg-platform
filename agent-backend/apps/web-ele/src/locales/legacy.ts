import { ref } from 'vue';

/** legacy 語言檔（扁平字典）。 */

/**
 * legacy 語言字典載入器，鍵為 api/languages 的 code。
 * en/zh-tw 沿用既有 en-US.json/zh-TW.json，其餘為語言檔轉出的扁平 JSON。 */
const loaders: Record<
  string,
  () => Promise<{ default: Record<string, string> }>
> = {
  bn: () => import('./legacy/bn.json'),
  en: () => import('./legacy/en-US.json'),
  fr: () => import('./legacy/fr.json'),
  ho: () => import('./legacy/ho.json'),
  'id-id': () => import('./legacy/id-id.json'),
  ko: () => import('./legacy/ko.json'),
  'ms-my': () => import('./legacy/ms-my.json'),
  'my-mm': () => import('./legacy/my-mm.json'),
  ru: () => import('./legacy/ru.json'),
  'th-th': () => import('./legacy/th-th.json'),
  'tl-ph': () => import('./legacy/tl-ph.json'),
  tpi: () => import('./legacy/tpi.json'),
  'vi-vn': () => import('./legacy/vi-vn.json'),
  'zh-cn': () => import('./legacy/zh-cn.json'),
  'zh-tw': () => import('./legacy/zh-TW.json'),
};

const dict = ref<Record<string, string>>({});
const fallbackDict = ref<Record<string, string>>({});

/**
 * 由框架 SupportedLanguagesType 推 api code（未顯式選語言時用）。
 * 維持現狀：zh-CN/en-US 預設用英文字典，zh-TW 用繁中；ko/fr 因框架已有對應
 * 語言包故直接對映；其餘語系一律靠使用者在 header 顯式選擇（getBackendLang）
 * 才載入，避免改動預設介面語言。 */
export function frameworkToCode(locale: string): string {
  switch (locale) {
    case 'fr': {
      return 'fr';
    }
    case 'ko': {
      return 'ko';
    }
    case 'zh-TW': {
      return 'zh-tw';
    }
    default: {
      return 'en';
    }
  }
}

/**
 * 由 api language code 推框架 chrome 語系。
 * 框架語言包（packages/locales）目前有 zh-TW / en-US / ko / fr 四種，其餘語系的
 * chrome 退英文，但後端內容仍照使用者所選語言呈現。
 * main.ts 首次進入與 header 切換語言共用此對映，避免兩處各自漂移。 */
export function chromeLocaleFor(code: string): 'en-US' | 'fr' | 'ko' | 'zh-TW' {
  switch (code) {
    case 'fr': {
      return 'fr';
    }
    case 'ko': {
      return 'ko';
    }
    case 'zh-cn':
    case 'zh-tw': {
      return 'zh-TW';
    }
    default: {
      return 'en-US';
    }
  }
}

export async function loadLegacyMessages(locale: string) {
  const code = getBackendLang() || frameworkToCode(locale);
  const loader = loaders[code] ?? loaders.en;
  if (loader) {
    const mod = await loader();
    dict.value = mod.default ?? (mod as any);
  }
  // 英文當作後備（缺 key 時退英文）
  if (code !== 'en' && Object.keys(fallbackDict.value).length === 0) {
    const en = await loaders.en?.();
    if (en) fallbackDict.value = en.default ?? (en as any);
  }
}

/**
 * 取得 legacy 翻譯文字
 * @param key legacy 語言 key
 * @param fallback 找不到時的預設文字（預設回傳 key 本身） */
export function tl(key: string, fallback?: string): string {
  return dict.value[key] ?? fallbackDict.value[key] ?? fallback ?? key;
}

/**
 * 目前載入字典的全部 key（Excel 轉多語系工具的 compareTargetLangKeys 用）。
 * 舊版傳 array_keys($lang)＝頂層 key；本專案字典已攤平成 dot-key，
 * 故同時補上第一段前綴以對齊舊版頂層陣列 key。 */
export function tlKeys(): string[] {
  const keys = new Set<string>();
  for (const k of [
    ...Object.keys(dict.value),
    ...Object.keys(fallbackDict.value),
  ]) {
    keys.add(k);
    const dot = k.indexOf('.');
    if (dot > 0) keys.add(k.slice(0, dot));
  }
  return [...keys];
}

/**
 * 後端語言代碼（送 Accept-Language 給真後端，讓後端回傳的內容以該語言呈現）。
 * 值為 api/languages 的 code（如 en / zh-tw / ms-my / vi-vn ...），與前端 chrome
 * 語系（僅 en-US / zh-TW 有翻譯）分離儲存。 */
const BACKEND_LANG_KEY = 'agent-backend-lang';
export function getBackendLang(): string {
  try {
    return localStorage.getItem(BACKEND_LANG_KEY) ?? '';
  } catch {
    return '';
  }
}
export function setBackendLang(code: string) {
  try {
    localStorage.setItem(BACKEND_LANG_KEY, code);
  } catch {
    // ignore（無痕模式等）
  }
}

/** 依瀏覽器語言(navigator.language)推 api code；推不到回 ''。 */
export function detectBrowserLang(): string {
  const nav = (globalThis.navigator?.language ?? '').toLowerCase();
  if (!nav) return '';
  // 完全吻合語言檔 key（如 zh-tw / ms-my / vi-vn）
  if (loaders[nav]) return nav;
  // 以主語言(第一段)對應
  const primary = nav.split('-')[0] ?? '';
  const byPrimary: Record<string, string> = {
    bn: 'bn',
    en: 'en',
    fil: 'tl-ph',
    fr: 'fr',
    ho: 'ho',
    id: 'id-id',
    ko: 'ko',
    ms: 'ms-my',
    my: 'my-mm',
    ru: 'ru',
    th: 'th-th',
    tl: 'tl-ph',
    vi: 'vi-vn',
    zh: /hant|hk|mo|tw/.test(nav) ? 'zh-tw' : 'zh-cn',
  };
  return byPrimary[primary] ?? '';
}

/**
 * 首次進入（使用者未曾在 header 選過語言）時，依瀏覽器語言自動設定。*/
export function initBackendLangFromBrowser(): string {
  if (getBackendLang()) return '';
  const code = detectBrowserLang();
  if (code) setBackendLang(code);
  return code;
}
