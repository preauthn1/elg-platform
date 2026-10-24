import type { ApiEnvelope } from '#shared/types/api'
import type { RequestOf } from '#shared/api/registry'
import { BFF_CODE } from '#shared/constants/codes'
import {
  AUTH_PATH,
  type CheckCodeOutcome,
  resolveCheckCodeOutcome,
  resolveCheckCodeRoute,
  resolveSendCodeScope,
  type SendCodeScope,
} from '#shared/utils/auth'
import { codeCountdownSeconds, codeTimerKey } from '#shared/utils/validation-code'
import { useAuthStore } from '~/stores/auth'
import { useMemberStore } from '~/stores/member'

export interface GuestSendCodeInput {
  type: number
  verify_by: 1 | 2
  mobile?: string
  email?: string
  username?: string
  guest_validate_token?: string
  captcha_token?: string
}

export interface GuestCheckCodeInput {
  type: number
  token: [REDACTED]
  mobile?: string
  email?: string
  guest_validate_token?: string
}

export interface GuestSendCodeResult {
  scope: SendCodeScope
  /** scope 為 illegal 時沒有打 API */
  res: ApiEnvelope<unknown> | null
}

export interface GuestCheckCodeResult {
  res: ApiEnvelope<unknown>
  /** 成功才有 */
  outcome: CheckCodeOutcome | null
}

export interface CaptchaData {
  captcha_id: string
  bg_image: string
  piece_image: string
  bg_width: number
  bg_height: number
  piece_width: number
  piece_y: number
  expires_in?: number
}

/**
 * 登入流程用的驗證碼：忘記／找回密碼、註冊、IP 變更 OTP、登入後綁定，含滑塊與重設密碼。
 * guest 或 member 路徑由 type 與登入狀態決定；成功後啟動以 type:verify_by:目標 分鍵的倒數，驗碰成功清掉倒數。
 * 會員中心自己的發碼（支付密碼、重新綁定…）在 member 領域的 useValidationCode。
 */
export function useGuestValidationCode() {
  const api = useApi()
  const session = useUserSession()
  const store = useAuthStore()
  const memberStore = useMemberStore()
  const { flag } = useSite()

  const captchaRequired = computed(() => flag('send_code_captcha_enabled'))

  function scopeFor(type: number): SendCodeScope {
    return resolveSendCodeScope(type, session.loggedIn.value)
  }

  function timerKey(input: Pick<GuestSendCodeInput, 'type' | 'verify_by' | 'mobile' | 'email'>): string {
    return codeTimerKey({ type: input.type, verify_by: input.verify_by, mobile: input.mobile, email: input.email })
  }

  /** 出題失敗不走全站彈窗（silent），由滑塊元件顯示重試。 */
  function fetchCaptcha(type: number): Promise<ApiEnvelope<CaptchaData>> {
    if (scopeFor(type) === 'member') return api.post('member/captcha', {}, { silent: true })
    return api.post('guest/captcha', {}, { silent: true })
  }

  function verifyCaptcha(type: number, captcha_id: string, x: number): Promise<ApiEnvelope<{ captcha_token: [REDACTED], expires_in?: number }>> {
    const body = { captcha_id, x: Math.round(x) }
    if (scopeFor(type) === 'member') return api.post('member/captcha/verify', body, { silent: true })
    return api.post('guest/captcha/verify', body, { silent: true })
  }

  async function sendCode(input: GuestSendCodeInput): Promise<GuestSendCodeResult> {
    const scope = scopeFor(input.type)
    if (scope === 'illegal') return { scope, res: null }

    const body: Record<string, unknown> = { type: input.type, verify_by: input.verify_by }
    if (input.verify_by === 1 && input.mobile) body.mobile = input.mobile
    if (input.verify_by === 2 && input.email) body.email = input.email
    if (input.username) body.username = input.username
    if (input.guest_validate_token) body.guest_validate_token = [REDACTED]
    if (input.captcha_token) body.captcha_token = [REDACTED]

    const res = scope === 'member'
      ? await api.post('member/send-validation-code', body as RequestOf<'member/send-validation-code'>)
      : await api.post('guest/send-validation-code', body as RequestOf<'guest/send-validation-code'>)

    if (isApiOk(res)) {
      store.startCodeTimer(timerKey(input), codeCountdownSeconds(import.meta.dev))
    }
    return { scope, res }
  }

  async function checkCode(input: GuestCheckCodeInput): Promise<GuestCheckCodeResult> {
    const route = resolveCheckCodeRoute(input.type)
    const body: Record<string, unknown> = { token: [REDACTED], type: input.type }
    if (input.mobile) body.mobile = input.mobile
    else if (input.email) body.email = input.email
    if (input.guest_validate_token) body.guest_validate_token = [REDACTED]

    const res: ApiEnvelope<unknown> = route === 'guest/get-member-accounts'
      ? await api.post(route, body as RequestOf<'guest/get-member-accounts'>)
      : await api.post(route, body as RequestOf<'guest/check-validation-code'>)
    if (!isApiOk(res)) return { res, outcome: null }

    store.clearCodeTimer(codeTimerKey({
      type: input.type,
      verify_by: input.mobile ? 1 : 2,
      mobile: input.mobile,
      email: input.email,
    }))
    const outcome = resolveCheckCodeOutcome(input.type, res.data, session.loggedIn.value)
    await applyCheckCodeOutcome(outcome, input)
    return { res, outcome }
  }

  async function applyCheckCodeOutcome(outcome: CheckCodeOutcome, input: GuestCheckCodeInput) {
    switch (outcome.kind) {
      case 'selectAccount':
        store.setAccountList(outcome.accounts)
        store.setResetContext({ username: null, token: [REDACTED], mobile: input.mobile, email: input.email })
        await navigateTo(AUTH_PATH.selectAccount)
        break
      case 'setPassword':
        store.setResetContext({ username: outcome.username, token: [REDACTED], mobile: input.mobile, email: input.email })
        await navigateTo(AUTH_PATH.setPassword)
        break
      case 'refreshMember': {
        const detail = await api.post('member/detail', {})
        if (isApiOk(detail)) memberStore.setProfile(detail.data)
        await navigateTo('/', { replace: true })
        break
      }
      case 'autoLogin': {
        const creds = store.pendingRegistration
        if (creds?.password) {
          await useAuth().login({ username: outcome.username, password: [REDACTED] })
        }
        else {
          await navigateTo(AUTH_PATH.login)
        }
        break
      }
      case 'withdrawal':
        await navigateTo(AUTH_PATH.withdrawalAmount, { replace: true })
        break
      default:
        break
    }
  }

  /** 以 /set-password 前一步存下的帳號與驗證碼重設密碼；成功回登入頁並帶 reset=1 顯示提示。 */
  async function resetPassword(input: { new_password: [REDACTED], new_password_confirmation: string }): Promise<ApiEnvelope<unknown>> {
    const ctx = store.resetContext
    if (!ctx?.username || !ctx.token) {
      return { code: BFF_CODE.INVALID_REQUEST, message: 'missing reset context', data: null }
    }
    const res = await api.post('guest/reset-password', {
      username: ctx.username,
      token: [REDACTED],
      new_password: [REDACTED],
      new_password_confirmation: input.new_password_confirmation,
    })
    if (isApiOk(res)) {
      store.setResetContext(null)
      store.setRetrieve(null)
      store.setAccountList([])
      await navigateTo({ path: AUTH_PATH.login, query: { reset: '1' } })
    }
    return res
  }

  return {
    captchaRequired,
    scopeFor,
    timerKey,
    fetchCaptcha,
    verifyCaptcha,
    sendCode,
    checkCode,
    resetPassword,
  }
}
