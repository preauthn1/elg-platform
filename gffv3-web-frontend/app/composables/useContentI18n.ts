/**
 * 活動詳情彈窗的文案翻譯。彈窗上方的語言晶片選了語言（store.contentLocale）時，
 * 彈窗裡的固定文案（活動期間、參加狀態、按鈕…）也照那個語言顯示；沒選就是站台語系。
 *
 * vue-i18n 的 `$t` 一律吃全域語系，沒辦法只對某一塊畫面換語言，
 * 所以彈窗內的元件改用這裡的 `ct()`：同一支 `t()`，只是每次都帶 `{ locale }` 指定語言。
 * 語系包是 lazy 載入的，晶片切換時會先 loadLocaleMessages 再寫 store（見 EventsLanguageChips）。
 *
 * 彈窗以外的元件 contentLocale 永遠是 null，用了也只是等同 `$t`。
 */
export function useContentI18n() {
  const { t, locale } = useI18n()
  const store = useEventsStore()

  /** t() 的 locale 只吃 i18n 設定的語系字面量，contentLocale 來自 C17_LANGUAGES（都在設定裡），所以收斂一次 */
  type AppLocale = typeof locale.value
  const contentLocale = computed(() => (store.contentLocale ?? locale.value) as AppLocale)

  function ct(key: string, named: Record<string, unknown> = {}): string {
    return t(key, named, { locale: contentLocale.value })
  }

  return { ct, contentLocale }
}
