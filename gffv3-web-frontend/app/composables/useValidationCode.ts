import type { ApiEnvelope } from '#shared/types/api'
import type { EndpointKey } from '#shared/api/registry'
import type { SendCodeInput } from '#shared/api/endpoints/member'
import {
  CODE_TYPE,
  ILLEGAL_LOGIN_STATUS_CODE,
  codeCountdownSeconds,
  codeTimerKey,
  formatCountdown,
  isIllegalLoginStatus,
  type CodeTimerKeyInput,
} from '#shared/utils/validation-code'

/** auth 領域登記的端點，這裡只借道呼叫；對方登記後型別自然收斂。 */
const CHECK_CODE_ROUTE = 'guest/check-validation-code' as EndpointKey

export interface CheckCodeInput {
  /** 六碼驗證碼 */
  token: [REDACTED]
  mobile?: string
  email?: string
  /** 只作紀錄；後端依發碼時存的型別處理 */
  type?: number
}

/**
 * 會員端驗證碼：發碼（含滑塊閘門與倒數）、驗碼、重新綁定。
 * 訪客型別（忘記密碼、註冊…）屬 auth 領域，不在這裡。
 */
export function useValidationCode() {
  const api = useApi()
  const site = useSite()
  const session = useUserSession()
  const timers = useValidationCodeStore()
  const memberStore = useMemberStore()
  const { t } = useI18n()

  /** 代理開了滑塊驗證，發碼前要先過 captcha 拿 captcha_token。 */
  const captchaRequired = computed(() => site.flag('send_code_captcha_enabled'))

  async function sendCode(input: SendCodeInput): Promise<ApiEnvelope<unknown>> {
    if (isIllegalLoginStatus(input.type, session.loggedIn.value)) {
      await navigateTo('/')
      return { code: ILLEGAL_LOGIN_STATUS_CODE, message: t('auth.session.error.illegalLogin'), data: null }
    }
    const res = await api.post('member/send-validation-code', input)
    if (isApiOk(res)) timers.start(codeTimerKey(input), codeCountdownSeconds(import.meta.dev))
    return res
  }

  function remaining(input: CodeTimerKeyInput): number {
    return timers.remaining(codeTimerKey(input))
  }

  function countdownLabel(input: CodeTimerKeyInput): string {
    return formatCountdown(remaining(input))
  }

  /**
   * 驗碼。成功後清掉該目標的倒數；型別 1 綁定完成要重抓 detail，
   * 型別 5/6 代表支付密碼已寫入。
   */
  async function checkCode(input: CheckCodeInput): Promise<ApiEnvelope<unknown>> {
    const res: ApiEnvelope<unknown> = await api.post(CHECK_CODE_ROUTE, input as never)
    if (!isApiOk(res)) return res
    timers.clearTarget(input.mobile ?? input.email ?? '')
    if (input.type === CODE_TYPE.BINDING) {
      await useMember().load()
    }
    else if (input.type === CODE_TYPE.PAYMENT_PASSWORD || input.type === CODE_TYPE.FORGET_PAYMENT_PASSWORD) {
      memberStore.patchProfile({ payment_password: 1 })
    }
    return res
  }

  /** 出題失敗要在滑塊元件內重試，不走全站導頁。 */
  function getCaptcha() {
    return api.post('member/captcha', {}, { silent: true })
  }

  /** 一題只能答一次，2080 之後要重新出題。 */
  function verifyCaptcha(captcha_id: string, x: number) {
    return api.post('member/captcha/verify', { captcha_id, x }, { silent: true })
  }

  async function resetMobile(input: { token: [REDACTED], new_mobile: string }) {
    const res = await api.post('member/reset-mobile', input)
    if (isApiOk(res)) {
      timers.clearTarget(input.new_mobile)
      memberStore.patchProfile({ mobile: input.new_mobile, mobile_validate: 1 })
    }
    return res
  }

  async function resetEmail(input: { token: [REDACTED], new_email: string }) {
    const res = await api.post('member/reset-email', input)
    if (isApiOk(res)) {
      timers.clearTarget(input.new_email)
      memberStore.patchProfile({ email: input.new_email, email_validate: 1 })
    }
    return res
  }

  return {
    captchaRequired,
    sendCode,
    remaining,
    countdownLabel,
    checkCode,
    getCaptcha,
    verifyCaptcha,
    resetMobile,
    resetEmail,
  }
}
