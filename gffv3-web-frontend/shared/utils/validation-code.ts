/** 會員端發碼型別，必須登入、走 member/send-validation-code。 */
export const MEMBER_CODE_TYPES: readonly number[] = [1, 5, 6, 7, 9, 11]
/** 訪客端發碼型別，必須未登入、走 guest/send-validation-code（auth 領域）。 */
export const GUEST_CODE_TYPES: readonly number[] = [2, 3, 4, 8, 10, 12]

export const CODE_TYPE = {
  BINDING: 1,
  PAYMENT_PASSWORD: 5,
  FORGET_PAYMENT_PASSWORD: 6,
  REBINDING: 9,
  LOGIN_2FA: 10,
  VERIFY: 11,
} as const

export const VERIFY_BY = {
  MOBILE: 1,
  EMAIL: 2,
} as const

export type VerifyBy = (typeof VERIFY_BY)[keyof typeof VERIFY_BY]

/** 驗證碼流程會碰到的業務碼。 */
export const VALIDATION_CODE = {
  RESEND_LIMIT: 2037,
  REBIND_LIMIT: 2045,
  CAPTCHA_FAILED: 2080,
  CAPTCHA_REQUIRED: 2081,
  INVALID: 9992,
  MOBILE_ALREADY_VALIDATED: 9012,
  EMAIL_ALREADY_VALIDATED: 9013,
  MOBILE_NOT_VALIDATED: 324,
  EMAIL_NOT_VALIDATED: 326,
} as const

/** 前端自產：登入狀態與發碼型別不符，不打 API。 */
export const ILLEGAL_LOGIN_STATUS_CODE = -3

export type CodeUserType = 'member' | 'guest'

/** 依型別與登入狀態決定走 member 或 guest 路徑（captcha 與發碼要一致）。 */
export function resolveCodeUserType(type: number, isLogin: boolean): CodeUserType {
  if (MEMBER_CODE_TYPES.includes(type) && isLogin) return 'member'
  if (GUEST_CODE_TYPES.includes(type)) return 'guest'
  return isLogin ? 'member' : 'guest'
}

/** 會員型別卻未登入、或訪客型別卻已登入，都算狀態不合法。 */
export function isIllegalLoginStatus(type: number, isLogin: boolean): boolean {
  return (MEMBER_CODE_TYPES.includes(type) && !isLogin) || (GUEST_CODE_TYPES.includes(type) && isLogin)
}

/** 發碼後的重送倒數秒數。 */
export function codeCountdownSeconds(isDev: boolean): number {
  return isDev ? 60 : 180
}

export interface CodeTimerKeyInput {
  type: number
  verify_by: number
  mobile?: string | null
  email?: string | null
}

/** 倒數分組鍵：同一畫面的簡訊與信箱按鈕各自計時。 */
export function codeTimerKey(input: CodeTimerKeyInput): string {
  return [input.type, input.verify_by, input.mobile || input.email || ''].join(':')
}

/** 倒數顯示：10 秒以下補零。 */
export function formatCountdown(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds))
  return s < 10 ? `0${s}` : String(s)
}

/** 把畫面上拖曳的距離換算回 bg_width 邏輯像素。 */
export function scaleSliderX(renderedX: number, renderedWidth: number, bgWidth: number): number {
  if (renderedWidth <= 0 || bgWidth <= 0) return 0
  const x = Math.round((renderedX * bgWidth) / renderedWidth)
  return Math.min(Math.max(x, 0), bgWidth)
}

/** 驗證碼輸入只留數字、最多 6 碼。 */
export function normalizeCode(value: string | null | undefined): string {
  return (value ?? '').replace(/\D/g, '').slice(0, 6)
}

/** 國碼 + 手機號組成後端要的純數字字串。 */
export function joinMobile(countryCode: string, number: string): string {
  return `${countryCode}${number}`.replace(/\D/g, '')
}
