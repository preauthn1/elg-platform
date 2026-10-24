import type { ApiEnvelope } from '#shared/types/api'
import type {
  ChangePasswordInput,
  ChangePaymentPasswordInput,
  TrackingEvent,
  UpdateProfileInput,
} from '#shared/api/endpoints/member'
import {
  MEMBER_PATH,
  fireTrackingEvents,
  nextToggleValue,
  profilePatchFromUpdate,
  profileRedirectFor,
  type BankCardInfoLike,
  type TrackingSinks,
} from '#shared/utils/member'

export type IpChangeChannel = 'mobile' | 'email'

/**
 * 會員資料與會員中心的所有寫入。成功後寫回 useMemberStore，頁面只綁資料。
 */
export function useMember() {
  const api = useApi()
  const store = useMemberStore()
  const profile = computed(() => store.profile)

  async function load() {
    const res = await api.post('member/detail', {})
    if (isApiOk(res)) store.setProfile(res.data)
    return res
  }

  /** 沒載過才打 detail；頁面直接進入時用。 */
  async function ensureLoaded() {
    if (store.profile) return null
    return load()
  }

  /** 只送要改的欄位；後端會把空值濾掉但保留 0。 */
  async function updateProfile(fields: UpdateProfileInput) {
    const res = await api.post('member/update-profile', fields)
    if (isApiOk(res)) store.patchProfile(profilePatchFromUpdate(fields))
    return res
  }

  function setNickname(nickname: string) {
    return updateProfile({ nickname: nickname.trim() })
  }

  function setAvatar(image: string) {
    return updateProfile({ image })
  }

  async function setBirthday(birthday: string) {
    const res = await api.post('member/set-birthday', { birthday })
    if (isApiOk(res)) store.patchProfile({ birthday })
    return res
  }

  async function setRealName(realName: string) {
    const real_name = realName.trim()
    const res = await api.post('member/set-realname', { real_name })
    if (isApiOk(res)) store.patchProfile({ first_name: real_name })
    return res
  }

  function changePassword(input: ChangePasswordInput) {
    return api.post('member/change-password', input)
  }

  async function changePaymentPassword(input: ChangePaymentPasswordInput) {
    const res = await api.post('member/change-payment-password', input)
    if (isApiOk(res)) store.patchProfile({ payment_password: 1 })
    return res
  }

  /**
   * member/check-profile：檢查會員還有哪一項資料沒驗證，**全站只有這一支**。
   * 照舊版 actionCheckProfile ＋ Modal.hiddenModal：沒過就彈後端訊息，**使用者關掉彈窗才**依 code 導去補資料的頁
   * （324 綁手機、326 綁信箱、323 支付密碼、325 真實姓名…，對照表在 profileRedirectFor）。
   * 呼叫時機也照舊版：首頁（Lobby）與會員中心（My）掛載時，已登入才打。
   * 彈窗與導頁交給 useApi 的全域錯誤處理（跟其他 API 同一套），這裡只回傳結果。
   */
  async function checkProfile(currentPath: string = MEMBER_PATH.home): Promise<{ res: ApiEnvelope<unknown>, redirect: string | null }> {
    const res: ApiEnvelope<unknown> = await api.post('member/check-profile', {})
    return { res, redirect: isApiOk(res) ? null : profileRedirectFor(res.code, currentPath) }
  }

  /**
   * 提款域的銀行卡資訊，只拿來決定設定選單的綁卡入口；失敗當沒資料。
   * type -1 = 不限代付方式，與舊前台 /setting 的呼叫一致（不帶 type 後端預設本地銀行，會漏掉其他類型的卡）。
   * silent：這裡是背景查詢，324／321 不該把使用者從設定頁導走。
   */
  async function loadBankCardInfo(): Promise<BankCardInfoLike | null> {
    const res = await api.post('withdrawal/check-bank-account', { type: -1 }, { silent: true })
    return isApiOk(res) ? res.data : null
  }

  /** 行銷追蹤事件：失敗一律吞掉，不彈提示。 */
  async function fetchTrackingEvents(): Promise<TrackingEvent[]> {
    try {
      const res = await api.post('member/tracking-events', {}, { silent: true })
      return isApiOk(res) ? res.data.items : []
    }
    catch {
      return []
    }
  }

  /** 取回待送的追蹤事件並打像素；後端每筆只回一次。 */
  async function runTrackingEvents(sinks: TrackingSinks): Promise<number> {
    const items = await fetchTrackingEvents()
    return fireTrackingEvents(items, sinks)
  }

  function getGoogleAuthImage() {
    return api.post('google-auth/image', {})
  }

  /** 啟用 2FA：先驗 TOTP，通過才把 has_2fa 開起來。 */
  async function enableGoogleAuth(secret: [REDACTED] {
    const username = store.profile?.username ?? ''
    const auth = await api.post('google-auth/auth', { username, secret })
    if (!isApiOk(auth)) return auth
    return updateProfile({ has_2fa: '1' })
  }

  /** 停用 2FA 不需驗碼。 */
  function disableGoogleAuth() {
    return updateProfile({ has_2fa: '0' })
  }

  /** IP 變更驗證開關：呼叫前頁面要先用型別 11 驗過該管道。 */
  function toggleIpChangeVerify(channel: IpChangeChannel) {
    if (channel === 'mobile') {
      return updateProfile({ ip_change_mobile_verify: nextToggleValue(store.profile?.ip_change_mobile_verify) })
    }
    return updateProfile({ ip_change_email_verify: nextToggleValue(store.profile?.ip_change_email_verify) })
  }

  return {
    profile,
    load,
    ensureLoaded,
    updateProfile,
    setNickname,
    setAvatar,
    setBirthday,
    setRealName,
    changePassword,
    changePaymentPassword,
    checkProfile,
    loadBankCardInfo,
    fetchTrackingEvents,
    runTrackingEvents,
    getGoogleAuthImage,
    enableGoogleAuth,
    disableGoogleAuth,
    toggleIpChangeVerify,
  }
}
