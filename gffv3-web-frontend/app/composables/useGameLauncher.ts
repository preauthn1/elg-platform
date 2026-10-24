import type { ApiEnvelope } from '#shared/types/api'
import type { RequestOf } from '#shared/api/registry'
import { BFF_CODE } from '#shared/constants/codes'
import type { VendorEntryKind } from '#shared/utils/wallet'
import {
  detectPlatform,
  FOUR_D_MAINTENANCE_FIELD,
  isInDailyWindow,
  isVendorPasswordValid,
  parseDailyWindow,
  resolveGameEntry,
  resolvePushToGame,
  to12Hour,
  VENDOR_ID,
  type GameEntry,
  type GameEntryContext,
  type GameLike,
} from '#shared/utils/games'

/** 這些版型點遊戲另開視窗，其餘 iframe 內嵌。 */
const POPUP_GAME_SKINS = ['C13', 'C16']

/** 叫起 APP 後這段時間內頁面沒有失焦，就當作沒裝 APP、改開下載連結（舊前台 $openApp）。 */
const OPEN_APP_FALLBACK_MS = 5000

/** 全站同時只能有一個「等 APP 叫起」的計時，連點不重複觸發。 */
let openAppTimer: ReturnType<typeof setTimeout> | null = null

interface TelegramWebAppLink {
  initData?: string
  openLink?: (url: string, options?: { try_instant_view?: boolean }) => void
}

/** 在 Telegram WebApp 內時回傳它的 openLink，否則 null。 */
function telegramOpenLink(): NonNullable<TelegramWebAppLink['openLink']> | null {
  const webApp = (window as unknown as { Telegram?: { WebApp?: TelegramWebAppLink } }).Telegram?.WebApp
  return webApp?.initData && webApp.openLink ? webApp.openLink.bind(webApp) : null
}

export interface FourDMaintenanceTip {
  start24: string
  start12: string
  end24: string
  end12: string
}

export interface GameUrlInput {
  vendorId?: number
  gameId?: number
  exitUrl?: string
}

/**
 * 進場類端點（game/show、game/url、widget、APP 場館）與進場分流。
 * 會開視窗、讀 navigator 的動作都在函式內、只在瀏覽器執行。
 */
export function useGameLauncher() {
  const api = useApi()
  const store = useGamesStore()
  const games = useGames()
  const site = useSite()
  const wallet = useWallet()
  const session = useUserSession()
  const origin = useRequestURL().origin

  const isMobile = computed(() => example.invalid.value?.device !== 'pc')

  function platform() {
    return detectPlatform(import.meta.client ? navigator.userAgent : '')
  }

  function isStandalone(): boolean {
    return import.meta.client && window.matchMedia('(display-mode: standalone)').matches
  }

  /** 進遊戲前記下目前頁面，給 game/url 的 exit_url。 */
  function storeGameFromUrl() {
    if (import.meta.client) store.setGameFromUrl(window.location.href)
  }

  function exitUrl(): string {
    return store.gameFromUrl || `${origin}/`
  }

  /** 另開視窗；被瀏覽器擋下時 throw，頁面接住後顯示「點此開啟遊戲」。 */
  function openWindow(url: string) {
    const win = window.open(url, '_blank')
    if (!win) throw new Error('popup blocked')
  }

  /**
   * 叫起遊戲商 APP（舊前台 $openApp）：有開啟連結就導過去，Telegram WebApp 內改用 openLink；
   * 5 秒內頁面沒失焦（APP 沒被叫起）就另開下載連結。沒有開啟連結時直接開下載連結。
   */
  function openApp(appLink: string | null, downloadUrl: string | null | undefined) {
    if (openAppTimer !== null) return
    if (!appLink) {
      if (downloadUrl) openWindow(downloadUrl)
      return
    }

    const tgOpenLink = telegramOpenLink()
    if (tgOpenLink) tgOpenLink(appLink, { try_instant_view: false })
    else window.location.assign(appLink)

    if (!downloadUrl) return
    const cancel = () => {
      if (openAppTimer !== null) clearTimeout(openAppTimer)
      openAppTimer = null
    }
    openAppTimer = setTimeout(() => {
      openAppTimer = null
      window.removeEventListener('blur', cancel)
      try {
        openWindow(downloadUrl)
      }
      catch {
        // 計時器裡沒有使用者手勢，瀏覽器可能擋下新視窗；舊前台同樣是擋下就不開
      }
    }, OPEN_APP_FALLBACK_MS)
    window.addEventListener('blur', cancel, { once: true })
  }

  async function fetchGame(gameId: number) {
    return api.post('game/show', { game_id: gameId })
  }

  /** 只給 vendorId 時後端取該場館第一款遊戲；回應有 app_infos 就存進 store。 */
  async function fetchGameUrl(input: GameUrlInput) {
    const body: RequestOf<'game/url'> = { exit_url: input.exitUrl ?? exitUrl() }
    if (input.vendorId !== undefined) body.vendor_id = input.vendorId
    if (input.gameId !== undefined) body.game_id = input.gameId
    const res = await api.post('game/url', body)
    if (isApiOk(res) && res.data.app_infos) store.setVendorInfo(res.data.app_infos)
    return res
  }

  /**
   * SABA 快速投注 widget 的網址。silent：這是首頁背景載入的區塊，
   * 拿不到就整段不顯示，使用者並沒有要求做什麼，**不要為它彈錯誤視窗**。
   */
  async function fetchWidgetUrl(vendorId: number = VENDOR_ID.SABA) {
    return api.post('game/widget-url', { vendor_id: vendorId, exit_url: `${origin}/` }, { silent: true })
  }

  /** VPower 選「Android」：取該場館 app_infos 後叫起 Android APP（舊前台 launchGameAndroid）。 */
  async function launchVpowerAndroid(vendorId: number) {
    storeGameFromUrl()
    const res = await fetchGameUrl({ vendorId })
    if (!isApiOk(res) || !store.vendorInfo) return
    openApp(store.vendorInfo.android_open ?? null, store.vendorInfo.android_downloads[0])
  }

  /** 前端先驗格式；成功後重打 game/url 更新 app_infos 的帳密。 */
  async function resetVendorPassword(vendorId: number, newPassword: [REDACTED] Promise<ApiEnvelope<unknown[]>> {
    if (!isVendorPasswordValid(newPassword)) {
      return { code: BFF_CODE.INVALID_REQUEST, message: 'invalid password format', data: [] }
    }
    const res = await api.post('vendor/reset-password', { vendor_id: vendorId, new_password: [REDACTED] })
    if (isApiOk(res)) await fetchGameUrl({ vendorId })
    return res
  }

  async function fetchVendorEvents(vendorId: number) {
    const res = await api.post('event/vendor-games', { vendor_id: vendorId })
    if (isApiOk(res)) store.setVendorEvents(res.data ?? [])
    return res
  }

  /**
   * 進列表／遊戲／APP 場館／聲明頁時把主錢包轉入該場館，**所有進場轉入都走這一支**。
   * 要不要轉在 wallet 領域決定（enterVendor → shouldTransferOnVendorEntry），未登入不呼叫。
   */
  async function syncSingleWallet(vendorId: number, kind: VendorEntryKind = 'vendor') {
    if (!session.loggedIn.value) return null
    return wallet.enterVendor(vendorId, kind)
  }

  /** 4D 場館每日維護時段內回提示參數，否則 null。 */
  function fourDMaintenanceTip(vendorId: number, now = new Date()): FourDMaintenanceTip | null {
    const field = FOUR_D_MAINTENANCE_FIELD[vendorId]
    if (!field) return null
    const window = parseDailyWindow(example.invalid.value?.raw[field])
    if (!window || !isInDailyWindow(window, now)) return null
    return {
      start24: window.start,
      start12: to12Hour(window.start),
      end24: window.end,
      end12: to12Hour(window.end),
    }
  }

  function entryContext(): GameEntryContext {
    return {
      ...platform(),
      isStandalone: isStandalone(),
      vendors: store.vendorMap,
      popupGames: POPUP_GAME_SKINS.includes(site.template.value?.skin ?? ''),
      // 舊版只有 CJ7、TPowerHK、Unet 不跳；C17（VWorld）一律跳。那幾站的皮搬進來時改吃 site.settings 旗標
      vpowerAndroidNotice: true,
      redirectUrl: store.redirectUrl,
      origin,
    }
  }

  async function perform(entry: GameEntry, game: GameLike): Promise<GameEntry> {
    switch (entry.kind) {
      case 'statement':
        await navigateTo(entry.path)
        break
      case 'statement2':
        store.selectGame(game)
        await navigateTo('/game/statement2')
        break
      case 'app':
      case 'game':
        storeGameFromUrl()
        await navigateTo(entry.path)
        break
      case 'popup':
        storeGameFromUrl()
        openWindow(entry.url)
        break
      case 'npt':
        // NPT 外連需要會員 token，新版 token 只在伺服器，先退回站內遊戲頁
        storeGameFromUrl()
        await navigateTo(`/game/${entry.gameId}`)
        break
      default:
        break
    }
    return entry
  }

  /**
   * need_login 場館要進 /game/app，判斷靠大廳場館清單。舊版首頁本身就載入遊戲商列表，
   * 新版首頁、搜尋、標籤頁沒有，進場前補抓一次，否則 APP 場館的遊戲會直接開成網頁版。
   */
  async function ensureVendors() {
    if (store.vendors.length === 0) await games.fetchLobbyVendors()
  }

  /** 進場分流；回傳結果給頁面決定要不要彈說明（vpower-notice）。 */
  async function enterGame(game: GameLike, opts: { toStatementPage?: boolean } = {}): Promise<GameEntry> {
    await ensureVendors()
    return perform(resolveGameEntry(game, entryContext(), opts), game)
  }

  async function pushToGame(game: GameLike): Promise<GameEntry> {
    await ensureVendors()
    return perform(resolvePushToGame(game, entryContext()), game)
  }

  return {
    isMobile,
    platform,
    exitUrl,
    storeGameFromUrl,
    openWindow,
    openApp,
    fetchGame,
    fetchGameUrl,
    fetchWidgetUrl,
    launchVpowerAndroid,
    resetVendorPassword,
    fetchVendorEvents,
    syncSingleWallet,
    fourDMaintenanceTip,
    enterGame,
    pushToGame,
  }
}
