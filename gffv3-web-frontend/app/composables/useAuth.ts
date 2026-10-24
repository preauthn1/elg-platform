import type { ApiEnvelope } from '#shared/types/api'
import type { RequestOf, ResponseOf } from '#shared/api/registry'
import type { LoginData, LoginParams } from '#shared/api/endpoints/auth'
import { isOn } from '#shared/utils/site'
import {
  AUTH_PATH,
  type LoginOutcome,
  parseMemberFieldSettings,
  resolveLoginOutcome,
  resolveRedirectPath,
  resolveRegisterMode,
} from '#shared/utils/auth'
import { useAuthStore } from '~/stores/auth'
import { useMemberStore } from '~/stores/member'

export interface LoginResult {
  res: ApiEnvelope<LoginData | null>
  outcome: LoginOutcome
}

/**
 * 登入／登出／註冊與登入後的 check-profile 導頁。登入類端點走 server/api/auth/* 專用 handler 寫 session。
 */
export function useAuth() {
  const api = useApi()
  const session = useUserSession()
  const authStore = useAuthStore()
  const memberStore = useMemberStore()
  const { site, flag } = useSite()

  /** agent/template 的 frontend_register 開了才能進註冊頁 */
  const canRegister = computed(() => isOn(site.value?.raw.frontend_register))
  const memberFieldSettings = computed(() => parseMemberFieldSettings(site.value?.raw.member_field_settings))
  const registerMode = computed(() => resolveRegisterMode(memberFieldSettings.value))
  const invitationCodeType = computed(() => (flag('invitation_code_type') ? 1 : 0))

  /**
   * 登入成功後：更新 session → 回 redirect 或首頁。
   * 這裡**不打** check-profile：舊版登入後也沒打，是首頁（Lobby）與會員中心掛載時才檢查，
   * 新版同樣交給那兩頁的 useMember().checkProfile，不在兩個地方各寫一份。
   */
  async function completeLogin(redirect?: unknown) {
    await session.fetch()
    await navigateTo(resolveRedirectPath(redirect))
  }

  async function login(params: LoginParams, opts: { redirect?: unknown } = {}): Promise<LoginResult> {
    // 走 useApi 的加密路徑：帳號密碼不能用明文 body 送（F12 直接看得到）
    const res = await api.postRaw<ApiEnvelope<LoginData | null>>('/api/auth/login', params as unknown as Record<string, unknown>)
    const outcome = resolveLoginOutcome(res)
    switch (outcome.kind) {
      case 'success':
        authStore.setPendingLogin(null)
        authStore.setPendingRegistration(null)
        authStore.setRetrieve(null)
        await completeLogin(opts.redirect)
        break
      case 'twoFactor':
        authStore.setPendingLogin({ params, options: outcome.options, memberId: outcome.memberId })
        await navigateTo(AUTH_PATH.twoFactor)
        break
      case 'retrievePassword':
        authStore.setRetrieve({ member_id: outcome.member_id, guest_validate_token: [REDACTED] })
        await navigateTo(AUTH_PATH.retrievePassword)
        break
      case 'redirect':
        await navigateTo(outcome.path)
        break
      case 'locked':
        if (session.loggedIn.value) await session.clear()
        break
      default:
        break
    }
    return { res, outcome }
  }

  /** 二次驗證：用暫存的原登入參數加 do_2fa / secret 重送。沒有暫存回 null（頁面退回 /login）。 */
  async function loginWithTwoFactor(method: 'google_auth' | 'mobile' | 'email', secret: [REDACTED] Promise<LoginResult | null> {
    const pending = authStore.pendingLogin
    if (!pending) return null
    return login({ ...pending.params, do_2fa: method, secret })
  }

  /** 清掉登入態但不導頁（註冊頁進場用：已登入開邀請連結要先變回訪客）。 */
  async function clearLogin() {
    await $fetch('/api/auth/logout', { method: 'POST' })
    authStore.reset()
    memberStore.reset()
    await session.fetch()
  }

  async function logout() {
    await clearLogin()
    await navigateTo(AUTH_PATH.login)
  }

  /** 註冊成功後用同一組帳密自動登入；帳密暫存供註冊後手機驗證（type=1）再登入。 */
  async function autoLoginAfterRegister(username: string, password: [REDACTED] {
    authStore.setPendingRegistration({ username, password })
    return login({ username, password })
  }

  async function register(payload: RequestOf<'guest/register'>): Promise<ApiEnvelope<ResponseOf<'guest/register'> | null>> {
    const res = await api.post('guest/register', payload)
    if (isApiOk(res)) await autoLoginAfterRegister(payload.username, payload.password)
    return res
  }

  async function registerV2(payload: RequestOf<'guest/register-v2'>): Promise<ApiEnvelope<ResponseOf<'guest/register-v2'> | null>> {
    const res = await api.post('guest/register-v2', payload)
    if (isApiOk(res)) {
      const username = res.data.username || payload.username || ''
      if (username && payload.password) await autoLoginAfterRegister(username, payload.password)
    }
    return res
  }

  function fetchDownlineCode() {
    return api.post('agent/downline-code', {})
  }

  return {
    canRegister,
    memberFieldSettings,
    registerMode,
    invitationCodeType,
    login,
    loginWithTwoFactor,
    completeLogin,
    clearLogin,
    logout,
    register,
    registerV2,
    fetchDownlineCode,
  }
}
