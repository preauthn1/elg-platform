/**
 * 外觀（舊前台的 `template_variant`）。與「款式」是兩個獨立的軸：
 *
 *   款式（代碼後兩碼，C1311 的 11）＝ 配色，後端決定 → <html data-variant="11">
 *   外觀（這支）＝ 使用者偏好，存瀏覽器           → <html data-appearance="1">
 *
 * **刻意不叫 dark / light**：追加的那套是深是淺要看基底。這一層只表達
 * 「基底(0) / 追加的那套(1)」，叫什麼名字由各皮在外觀設定頁決定。
 */

/** 0 = 設計稿原本那套；1 = 後來追加的第二套。 */
export type AppearanceMode = 0 | 1

/** 舊前台的鍵名，不要改，換了就讀不到既有使用者的偏好。 */
export const APPEARANCE_STORAGE_KEY = 'template_variant'

export function appearanceFromStored(raw: string | null | undefined): AppearanceMode {
  return raw === '1' ? 1 : 0
}

export function useAppearance() {
  /**
   * SSR 讀不到 localStorage，所以先當基底；
   * app/plugins/appearance.client.ts 會在水合前把實際值套上去，不會閃。
   */
  const mode = useState<AppearanceMode>('appearance', () => 0)

  /**
   * 這個皮有沒有第二套外觀。舊版是寫死白名單（只有 C1322 / C1622），
   * 新版改看皮自己有沒有宣告——theme.css 的 --wf-has-alt-appearance 為 1 就是有。
   */
  const hasAlt = computed(() => {
    if (import.meta.server) return false
    return getComputedStyle(document.documentElement).getPropertyValue('--wf-has-alt-appearance').trim() === '1'
  })

  function apply(next: AppearanceMode) {
    mode.value = next
    if (import.meta.server) return
    document.documentElement.dataset.appearance = String(next)
    try {
      localStorage.setItem(APPEARANCE_STORAGE_KEY, String(next))
    }
    catch {
      // 無痕模式寫不進去就算了，當次瀏覽仍然生效
    }
  }

  function toggle() {
    apply(mode.value === 1 ? 0 : 1)
  }

  return { mode, hasAlt, apply, toggle }
}
