import { fireTrackingEvents, type TrackingSinks } from '#shared/utils/member'

/**
 * 把網站加到主畫面（PWA）。**已經不提供 APK／IPA 下載，只走 PWA。**
 *
 * 流程沿用舊前台 C13：
 *   plugins/pwa-install.client.ts 攔下 beforeinstallprompt 存起來
 *   → 使用者按「下載 APP」時 prompt() 叫出瀏覽器的安裝視窗
 *   → 攔不到（iOS Safari、或瀏覽器判定不可安裝）就顯示不支援的提示
 * 舊版按下時還會送 CLICK_PWA_INSTALL 給行銷像素；新專案的像素還沒接線
 * （fireTrackingEvents 需要呼叫端注入 sinks，目前沒有任何頁面在做），
 * 所以這裡把 sinks 開成選填，等像素接上再傳進來就會送。
 */

/** 瀏覽器的安裝提示事件，TS 內建型別沒有。 */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export type PwaInstallOutcome = 'accepted' | 'dismissed' | 'unsupported'

/** 舊前台送給行銷像素的事件名，沿用。 */
export const PWA_INSTALL_EVENT = 'CLICK_PWA_INSTALL'

export function usePwaInstall() {
  const prompt = useState<BeforeInstallPromptEvent | null>('pwa-prompt', () => null)
  const installed = useState<boolean>('pwa-installed', () => false)

  /** 可以叫出安裝視窗（瀏覽器給了事件，而且還沒裝）。 */
  const canInstall = computed(() => !installed.value && prompt.value !== null)

  /**
   * 是否顯示「下載 APP」入口。已經裝好就不顯示；
   * 還沒拿到事件時仍然顯示——iOS 要引導使用者走「加入主畫面」，不能直接藏掉。
   */
  const canShow = computed(() => !installed.value)

  async function install(sinks?: TrackingSinks): Promise<PwaInstallOutcome> {
    if (sinks) fireTrackingEvents([{ type: PWA_INSTALL_EVENT, data: {} }], sinks)
    const event = prompt.value
    if (!event) return 'unsupported'
    try {
      await event.prompt()
      const { outcome } = await event.userChoice
      // 事件只能用一次，用過就丟掉
      prompt.value = null
      if (outcome === 'accepted') installed.value = true
      return outcome
    }
    catch {
      prompt.value = null
      return 'unsupported'
    }
  }

  return { canInstall, canShow, installed, install }
}

declare global {
  interface WindowEventMap {
    beforeinstallprompt: BeforeInstallPromptEvent
  }
}
