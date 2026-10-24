/**
 * C17 給使用者選的語言（側邊欄的站台語系、活動詳情彈窗的內容語言共用這一份）。
 *
 * i18n 設定裡有 14 個語系，但畫面上只開放這 5 個，順序與名稱照指定的清單。
 * 名稱是各語言的自稱，不翻譯（切到哪個語系都顯示原文）。
 * 國旗沿用舊前台 `static_setting.supportLangs` 的 `image_file`（ME77 語系切換用的那一份），
 * 素材在 `public/country/<flag>.svg`，所有皮共用。
 */
export const C17_LANGUAGES = [
  { code: 'zh-cn', name: '简体中文', flag: 'cn' },
  { code: 'en', name: 'English', flag: 'uk' },
  { code: 'ms-my', name: 'Bahasa', flag: 'my' },
  { code: 'th-th', name: 'ภาษาไทย', flag: 'th' },
  { code: 'vi-vn', name: 'TIẾNG VIỆT', flag: 'vn' },
] as const

export type C17LanguageCode = (typeof C17_LANGUAGES)[number]['code']

export function languageFlag(code: C17LanguageCode): string {
  const { flag } = C17_LANGUAGES.find(l => l.code === code)!
  return `/country/${flag}.svg`
}

/**
 * 單次請求指定內容語言用的標頭（BFF 收到就拿它當 Accept-Language，不看 locale cookie）。
 * 只接受語系代碼的格式，其餘一律忽略，不轉送給後端。
 */
export const CONTENT_LOCALE_HEADER = 'x-wf-locale'

export function isLocaleCode(value: unknown): value is string {
  return typeof value === 'string' && /^[a-z]{2,3}(?:-[a-z]{2})?$/.test(value)
}
