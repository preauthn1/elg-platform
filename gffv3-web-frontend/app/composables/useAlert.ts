import { API_CODE } from '#shared/constants/codes'

/**
 * 全站共用的提示彈窗。
 *
 * API 回錯誤時以前是各頁自己把 `res.message` 印成頁面上的一行字——每頁長得不一樣，
 * 而且捲到下面時根本看不到。改成統一走這裡，由外殼掛的 UiAlert 呈現。
 *
 * 只保留「一則」訊息：連續幾個請求同時失敗時，後來的覆蓋前一則，
 * 不要排成佇列讓使用者關好幾次。
 */
export interface AlertState {
  message: string
  /** 成功類的提示用不同顏色；預設是錯誤 */
  tone: 'error' | 'success'
  /**
   * 關掉之後要做的事（目前只有 423 的整頁重載）。
   * 這個 state 會進 SSR payload，所以只能放字串，不能放 callback。
   */
  action?: 'reload'
  /**
   * 關掉之後導去的站內路徑（舊前台 Modal.hiddenModal 依 code 導頁，例如 check-profile 的 324 → 綁手機）。
   * 跟 action 一樣只能放字串。
   */
  redirect?: string
  /** redirect 用 replace（不留在上一頁歷史），例如設定完支付密碼回會員中心，按返回不該回到表單 */
  replace?: boolean
  /**
   * 後端的 request id，顯示成訊息下方的 `ErrorCode: <tid>`，客服拿來對帳。
   * 舊前台只在錯誤時顯示（code 200／1000 不顯示），所以成功提示一律不帶。
   */
  tid?: string
  /**
   * 第二顆按鈕（例如「前往實名認證」）：label 是 i18n key、to 是站內路徑。
   * 有它時彈窗變成「前往」＋「關閉」兩顆，關閉不導頁。同樣只能放字串。
   */
  confirm?: { label: string, to: string }
  /** 跳出的時間（ms），換頁時判斷要不要自動關（見 isStaleOnNavigation） */
  shownAt?: number
}

/**
 * 換頁開始前這段時間內才跳的彈窗不算舊的：「先跳成功訊息再導頁」
 * （realname 守衛、bind-email 的 router.back()）要讓訊息留到新頁面。
 */
export const ALERT_NAVIGATION_GRACE_MS = 500

/**
 * 換頁完成時這則彈窗要不要自動關：換頁前就已經開著一陣子的才關，
 * 換頁過程中或剛跳的保留；要整頁重載的（423）一定要使用者按掉，不自動關。
 */
export function isStaleOnNavigation(state: AlertState | null, navigationStartedAt: number): boolean {
  if (!state || state.action === 'reload') return false
  return (state.shownAt ?? 0) < navigationStartedAt - ALERT_NAVIGATION_GRACE_MS
}

export interface AlertOptions {
  action?: AlertState['action']
  redirect?: string
  replace?: boolean
  tid?: string
  confirm?: AlertState['confirm']
}

export function useAlert() {
  const state = useState<AlertState | null>('ui-alert', () => null)

  function show(message: string, tone: AlertState['tone'] = 'error', opts: AlertOptions = {}) {
    const text = String(message ?? '').trim()
    if (!text) return
    const tid = tone === 'error' ? opts.tid?.trim() : undefined
    state.value = {
      message: text,
      tone,
      ...(opts.action ? { action: opts.action } : {}),
      ...(opts.redirect ? { redirect: opts.redirect } : {}),
      ...(opts.redirect && opts.replace ? { replace: true } : {}),
      ...(tid ? { tid } : {}),
      ...(opts.confirm ? { confirm: opts.confirm } : {}),
      shownAt: Date.now(),
    }
  }

  /**
   * 把 API 回應的 message 丟進彈窗：成功用 success、失敗用 error 並帶 tid。
   * **API 回的 message 一律走彈窗，不要印成頁面上的一行字。**
   *
   * 非 silent 的請求失敗時 useApi 已經彈過了，呼叫端不用再叫這支；
   * 這支給「成功也要告知」以及「silent 請求自己決定要不要提示」的情況用。
   */
  function showResponse(res: { code: number, message?: string | null, tid?: string | null }, opts: Omit<AlertOptions, 'tid'> = {}) {
    const ok = res.code === API_CODE.OK
    show(String(res.message ?? ''), ok ? 'success' : 'error', ok ? opts : { ...opts, tid: res.tid ?? undefined })
  }

  /** 按第二顆按鈕：關窗後導去 confirm.to */
  function confirm() {
    const to = state.value?.confirm?.to
    state.value = null
    if (to) void navigateTo(to)
  }

  function close() {
    const action = state.value?.action
    const redirect = state.value?.redirect
    const replace = state.value?.replace === true
    state.value = null
    if (action === 'reload' && import.meta.client) reloadNuxtApp()
    else if (redirect) void navigateTo(redirect, { replace })
  }

  return { state, show, showResponse, close, confirm }
}
