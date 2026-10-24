import type { ApiEnvelope } from '#shared/types/api'
import { API_CODE } from '#shared/constants/codes'
import { retrievePasswordDataSchema, twoFactorDataSchema } from '#shared/api/endpoints/auth'
import { MEMBER_PATH } from './member'
import { GUEST_CODE_TYPES, MEMBER_CODE_TYPES } from './validation-code'

/** 登入流程自己的頁面；補資料頁在會員／洗分領域，路徑從對方的常數取。 */
export const AUTH_PATH = {
  login: '/login',
  signup: '/signup',
  forgetPassword: [REDACTED]',
  retrievePassword: [REDACTED]',
  phoneVerification: '/phone-verification',
  emailVerification: '/phone-verification?verify_by=2',
  selectAccount: '/select-account',
  setPassword: [REDACTED]',
  twoFactor: '/two-factor-authentication',
  googleAuthenticator: '/google-authenticator',
  telegramLoginOptions: '/telegram-login-options',
  telegramCallback: '/telegram-callback',
  tgLogin: '/tg-login',
  bindBankCard: '/withdrawal/bind-bank-card',
  withdrawalAmount: '/withdrawal/amount',
} as const

/** apis HttpCode 導頁碼與登入流程相關的業務碼。 */
export const AUTH_CODE = {
  /** 舊 API 的成功碼，tg-bot/get-brand-by-domain 仍回這個 */
  OK_LEGACY: 1000,
  TWO_FACTOR: 318,
  LOGOUT: 319,
  RESET_PASSWORD: [REDACTED],
  SET_PAYMENT_PASSWORD: [REDACTED],
  SET_MOBILE: 324,
  SET_REALNAME: 325,
  SET_EMAIL: 326,
  BIND_BANK_CARD: 327,
  INPUT_INVITE_CODE: 333,
  UNAUTHORIZED: 401,
  CUSTOMER_SERVICE_LOGIN: 10001,
  LEGACY_MOBILE_UNVERIFIED: 9007,
  LEGACY_EMAIL_UNVERIFIED: 9008,
  LEGACY_PAYMENT_PASSWORD: [REDACTED],
  LEGACY_REALNAME: 1096,
} as const

/** 舊前台一律以 [200, 1000] 判定成功；登入與 tg-bot 端點沿用。 */
export function isLoginOk(code: number): boolean {
  return code === API_CODE.OK || code === AUTH_CODE.OK_LEGACY
}

/** 登入回應是不是會員（客服 hasDeviceId 分支只有 token/id，沒有 username）。 */
export function isMemberLoginData(data: unknown): data is { token: [REDACTED], id: number | string, username: string, nickname?: string | null } {
  if (!data || typeof data !== 'object') return false
  const d = data as Record<string, unknown>
  return typeof d.token === 'string' && d.token !== ''
    && typeof d.username === 'string' && d.username !== ''
    && (typeof d.id === 'number' || typeof d.id === 'string')
}

export type TwoFactorMethod = 'google_auth' | 'mobile' | 'email'

export interface TwoFactorOption {
  type: TwoFactorMethod
  /** OTP 方式的 send-validation-code verify_by：1 手機、2 信箱 */
  verify_by?: 1 | 2
  /** OTP 收件目標（手機號或信箱），Google 沒有 */
  target?: string
}

export type LoginOutcome
  = | { kind: 'success' }
    | { kind: 'twoFactor', options: TwoFactorOption[], memberId?: number }
    | { kind: 'locked', message: string }
    | { kind: 'retrievePassword', member_id: number, guest_validate_token: [REDACTED] }
    | { kind: 'redirect', path: string }
    | { kind: 'customerService', token?: string, id?: number | string }
    | { kind: 'error', message: string }

/** 318 的 auth_2fa 轉成可選的驗證方式清單，順序照後端。 */
export function resolveTwoFactorOptions(data: unknown): TwoFactorOption[] {
  const parsed = twoFactorDataSchema.safeParse(data)
  if (!parsed.success) return []
  const { auth_2fa, mobile, email } = parsed.data
  const methods = typeof auth_2fa === 'string' ? [auth_2fa] : auth_2fa
  const options: TwoFactorOption[] = []
  for (const method of methods) {
    if (method === 'google_auth') options.push({ type: 'google_auth' })
    else if (method === 'mobile' && mobile) options.push({ type: 'mobile', verify_by: 1, target: mobile })
    else if (method === 'email' && email) options.push({ type: 'email', verify_by: 2, target: email })
  }
  return options
}

/** 登入回應 → 下一步。 */
export function resolveLoginOutcome(res: ApiEnvelope<unknown>): LoginOutcome {
  const data = res.data
  if (isLoginOk(res.code)) {
    // 專用 handler 已把 token 拿掉，瀏覽器端只能靠 username 分辨會員與客服（hasDeviceId）回應
    const d = (data && typeof data === 'object' ? data : {}) as Record<string, unknown>
    if (typeof d.username === 'string' && d.username !== '') return { kind: 'success' }
    return { kind: 'customerService', token: [REDACTED] d.token === 'string' ? d.token : [REDACTED], id: d.id as number | string | undefined }
  }
  switch (res.code) {
    case AUTH_CODE.TWO_FACTOR: {
      const parsed = twoFactorDataSchema.safeParse(data)
      return { kind: 'twoFactor', options: resolveTwoFactorOptions(data), memberId: parsed.success ? parsed.data.id : undefined }
    }
    case AUTH_CODE.LOGOUT:
      return { kind: 'locked', message: res.message }
    case AUTH_CODE.RESET_PASSWORD: {
      const parsed = retrievePasswordDataSchema.safeParse(data)
      if (parsed.success) {
        return { kind: 'retrievePassword', member_id: parsed.data.member_id, guest_validate_token: [REDACTED] }
      }
      return { kind: 'error', message: res.message }
    }
    case AUTH_CODE.CUSTOMER_SERVICE_LOGIN: {
      const d = (data ?? {}) as Record<string, unknown>
      return { kind: 'customerService', token: [REDACTED] d.token === 'string' ? d.token : [REDACTED], id: d.id as number | string | undefined }
    }
    case AUTH_CODE.LEGACY_MOBILE_UNVERIFIED:
    case AUTH_CODE.SET_MOBILE:
      return { kind: 'redirect', path: `${AUTH_PATH.phoneVerification}?verify_by=1` }
    case AUTH_CODE.LEGACY_EMAIL_UNVERIFIED:
    case AUTH_CODE.SET_EMAIL:
      return { kind: 'redirect', path: AUTH_PATH.emailVerification }
    case AUTH_CODE.LEGACY_PAYMENT_PASSWORD:
    [REDACTED] AUTH_CODE.SET_PAYMENT_PASSWORD:
      [REDACTED] { kind: 'redirect', path: MEMBER_PATH.paymentPassword }
    case AUTH_CODE.LEGACY_REALNAME:
    case AUTH_CODE.SET_REALNAME:
      return { kind: 'redirect', path: MEMBER_PATH.setFullname }
    default:
      return { kind: 'error', message: res.message }
  }
}

/** 登入後導頁只接受站內相對路徑，避免 open redirect；不合法回首頁。 */
export function resolveRedirectPath(redirect: unknown, fallback = '/'): string {
  if (typeof redirect !== 'string' || redirect === '') return fallback
  if (!redirect.startsWith('/') || redirect.startsWith('//') || /[\\\s]/.test(redirect)) return fallback
  return redirect
}

/** 登入／註冊的「Telegram 登入」入口：帶著登入後要回去的頁面，註冊時再帶推薦碼。 */
export function telegramLoginRoute(redirect: unknown, code?: string | null) {
  return {
    path: AUTH_PATH.telegramLoginOptions,
    query: { redirect: resolveRedirectPath(redirect), code: code || undefined },
  }
}

/* ---------------------------------------------------------------- 驗證碼 */

export type SendCodeScope = 'guest' | 'member' | 'illegal'

/**
 * 發碼／滑塊該走 guest 還是 member 路徑。
 * 會員型 type 未登入、訪客型 type 已登入 → illegal（顯示 illegalLoginStatus 並回首頁）。
 *
 * 型別清單、倒數鍵與倒數秒數都在 validation-code.ts，這裡只引用，不再各留一份。
 */
export function resolveSendCodeScope(type: number, loggedIn: boolean): SendCodeScope {
  const isMemberType = MEMBER_CODE_TYPES.includes(type)
  const isGuestType = GUEST_CODE_TYPES.includes(type)
  if (isMemberType && !loggedIn) return 'illegal'
  if (isGuestType && loggedIn) return 'illegal'
  if (isMemberType) return 'member'
  if (isGuestType) return 'guest'
  return loggedIn ? 'member' : 'guest'
}

/** 手機號送出格式 = 國碼 + 號碼；號碼首碼 0 去除、所有非數字丟棄。 */
export function normalizeMobile(countryCode: string | number | null | undefined, phone: string | null | undefined): string {
  const cc = String(countryCode ?? '').replace(/\D/g, '')
  const local = String(phone ?? '').replace(/\D/g, '').replace(/^0+/, '')
  if (!local) return ''
  return `${cc}${local}`
}

/** 驗證碼輸入只留數字、最多 6 碼。 */
export function normalizeOtp(value: string | null | undefined): string {
  return String(value ?? '').replace(/\D/g, '').slice(0, 6)
}

/** type=2（忘記密碼）驗碼改打 get-member-accounts，其餘打 check-validation-code。 */
export function resolveCheckCodeRoute(type: number): 'guest/get-member-accounts' | 'guest/check-validation-code' {
  return type === 2 ? 'guest/get-member-accounts' : 'guest/check-validation-code'
}

export type CheckCodeOutcome
  = | { kind: 'selectAccount', accounts: string[] }
    | { kind: 'setPassword', username: string }
    | { kind: 'refreshMember' }
    | { kind: 'autoLogin', username: string }
    | { kind: 'withdrawal' }
    | { kind: 'done', username?: string }

/**
 * 驗碼成功後的下一步。
 * type 2：帳號清單 >1 選帳號、==1 直接設密碼；type 3：設密碼；
 * type 1：已登入重抓會員資料、未登入用註冊密碼自動登入；type 4/7：洗分頁。
 */
export function resolveCheckCodeOutcome(type: number, data: unknown, loggedIn: boolean): CheckCodeOutcome {
  if (type === 2) {
    const list = (data && typeof data === 'object' && Array.isArray((data as { username_list?: unknown }).username_list))
      ? (data as { username_list: string[] }).username_list
      : []
    if (list.length > 1) return { kind: 'selectAccount', accounts: list }
    if (list.length === 1 && list[0]) return { kind: 'setPassword', username: list[0] }
    return { kind: 'selectAccount', accounts: [] }
  }
  const username = Array.isArray(data) && typeof data[0] === 'string' ? data[0] : undefined
  if (type === 3) return username ? { kind: 'setPassword', username } : { kind: 'done' }
  if (type === 1) {
    if (loggedIn) return { kind: 'refreshMember' }
    return username ? { kind: 'autoLogin', username } : { kind: 'done' }
  }
  if (type === 4 || type === 7) return { kind: 'withdrawal' }
  return { kind: 'done', username }
}

/* ---------------------------------------------------------------- 註冊 */

export interface MemberFieldSetting {
  field_key: string
  is_required: number
  field_group: number
  sort?: number
  extra_config?: {
    min_length?: number
    max_length?: number
    lowercase_username?: boolean
    mobile_as_username?: boolean
    /** 密碼字元規則：開著（true）的每一項都至少要出現一次 */
    rules?: Partial<Record<PasswordRule, boolean>>
  } | null
}

export interface MemberFieldSettings {
  register: MemberFieldSetting[]
  login: string[]
}

/** agent/template 的 member_field_settings 是 unknown，這裡收成型別安全的形狀。 */
export function parseMemberFieldSettings(raw: unknown): MemberFieldSettings {
  const out: MemberFieldSettings = { register: [], login: [] }
  if (!raw || typeof raw !== 'object') return out
  const r = raw as Record<string, unknown>
  if (Array.isArray(r.register)) {
    for (const item of r.register) {
      if (!item || typeof item !== 'object') continue
      const f = item as Record<string, unknown>
      if (typeof f.field_key !== 'string') continue
      out.register.push({
        field_key: f.field_key,
        is_required: Number(f.is_required ?? 0),
        field_group: Number(f.field_group ?? 1) || 1,
        sort: typeof f.sort === 'number' ? f.sort : undefined,
        extra_config: (f.extra_config && typeof f.extra_config === 'object') ? f.extra_config as MemberFieldSetting['extra_config'] : null,
      })
    }
  }
  if (Array.isArray(r.login)) {
    out.login = r.login.filter((x): x is string => typeof x === 'string')
  }
  return out
}

/** member_field_settings.register 有設定 → 動態表單 v2，否則靜態表單。 */
export function resolveRegisterMode(settings: MemberFieldSettings): 'v2' | 'static' {
  return settings.register.length > 0 ? 'v2' : 'static'
}

/** 依 field_group 分步（群組小的先），組內依 sort。 */
export function groupRegisterFields(fields: MemberFieldSetting[]): { group: number, fields: MemberFieldSetting[] }[] {
  const map = new Map<number, MemberFieldSetting[]>()
  for (const f of fields) {
    const list = map.get(f.field_group) ?? []
    list.push(f)
    map.set(f.field_group, list)
  }
  return [...map.entries()]
    .sort(([a], [b]) => a - b)
    .map(([group, list]) => ({ group, fields: [...list].sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0)) }))
}

/** register-v2 的錯誤碼定位到欄位（找不到回 null，停留當前步）。 */
export function resolveRegisterErrorField(code: number): string | null {
  switch (code) {
    case 1002:
    case 2057:
      return 'username'
    case 1021:
      return 'confirm_password'
    case 1050:
    case 1051:
      return 'referral_code'
    case 2058:
      return 'mobile'
    case 2059:
      return 'email'
    default:
      return null
  }
}

export function isAlphaNumeric(value: string): boolean {
  return /^[A-Za-z0-9]*$/.test(value)
}

/** 只收英數的欄位（舊皮 ALPHANUMERIC_FIELDS）；含空白或符號就擋。 */
export const ALPHANUMERIC_FIELDS = ['username', 'password', 'confirm_password'] as const

/** 密碼欄位的 extra_config.rules，順序照後端 MemberFieldSettingService::assertPasswordRules。 */
export const PASSWORD_RULES = ['alphabet', 'number', 'symbol', 'uppercase'] as const
export type PasswordRule = typeof PASSWORD_RULES[number]

/** 後台開啟的密碼規則（確認密碼不另外設，跟著密碼欄位）。 */
export function enabledPasswordRules(fields: readonly MemberFieldSetting[]): PasswordRule[] {
  const rules = findRegisterField(fields, 'password')?.extra_config?.rules
  if (!rules) return []
  return PASSWORD_RULES.filter(rule => rules[rule] === true)
}

/**
 * 該欄位是否只收英數。後台要求密碼含符號時，密碼與確認密碼就不能再擋符號，
 * 否則照提示輸入符號會先被前端擋下、永遠送不出去。
 */
export function isAlphanumericOnlyField(fields: readonly MemberFieldSetting[], key: string): boolean {
  if (!(ALPHANUMERIC_FIELDS as readonly string[]).includes(key)) return false
  if (key === 'username') return true
  return !enabledPasswordRules(fields).includes('symbol')
}

/** 有專屬區塊、不走一般文字欄位渲染的欄位（舊皮 SPECIAL_FIELDS）。 */
export const SPECIAL_REGISTER_FIELDS = ['mobile', 'sms_verify_code', 'email', 'email_verify_code', 'referral_code'] as const

/** 畫在手機／信箱區塊內部、不單獨佔一個位置的欄位（舊皮 INLINE_FIELDS）。 */
export const INLINE_REGISTER_FIELDS = ['sms_verify_code', 'email_verify_code'] as const

/** 一般必填／字數檢查不適用、由專屬邏輯檢查的欄位（舊皮 CUSTOM_VALIDATED_FIELDS）。 */
export const CUSTOM_VALIDATED_FIELDS = ['mobile', 'email', 'referral_code'] as const

/** 未設定字數時的手機號長度（不含國碼），與舊皮相同。 */
export const DEFAULT_MOBILE_MIN_LENGTH = 7
export const DEFAULT_MOBILE_MAX_LENGTH = 13

/**
 * 帳號規則。**字數與小寫只在後台有設時才檢查**，前端不自己發明 4-10 或
 * 「至少一個英文字母」這種後台沒設的限制（舊皮動態表單就沒有）。
 */
export function validateUsername(value: string, cfg: MemberFieldSetting['extra_config'] = null): 'alnum' | 'length' | 'lowercase' | null {
  if (!isAlphaNumeric(value)) return 'alnum'
  if (cfg?.mobile_as_username) return null
  if (cfg?.min_length != null && value.length < cfg.min_length) return 'length'
  if (cfg?.max_length != null && value.length > cfg.max_length) return 'length'
  if (cfg?.lowercase_username && /[A-Z]/.test(value)) return 'lowercase'
  return null
}

/** 該欄位要畫在畫面上（驗證碼併進手機／信箱區塊，不單獨佔位）。 */
export function isRenderedRegisterField(key: string): boolean {
  return !(INLINE_REGISTER_FIELDS as readonly string[]).includes(key)
}

export function findRegisterField(fields: readonly MemberFieldSetting[], key: string): MemberFieldSetting | undefined {
  return fields.find(f => f.field_key === key)
}

export function hasRegisterField(fields: readonly MemberFieldSetting[], key: string): boolean {
  return findRegisterField(fields, key) !== undefined
}

/**
 * 確認密碼後台通常不另外設字數，沿用密碼欄位的設定（舊皮 lengthConfig）。
 */
export function registerLengthConfig(fields: readonly MemberFieldSetting[], field: MemberFieldSetting): { min_length?: number, max_length?: number } {
  const cfg = field.extra_config ?? {}
  if (field.field_key !== 'confirm_password') return cfg
  if (cfg.min_length != null && cfg.max_length != null) return cfg
  return findRegisterField(fields, 'password')?.extra_config ?? cfg
}

/** 手機長度規則：後台有設優先，否則沿用舊皮預設 7-13。 */
export function mobileLengthRange(fields: readonly MemberFieldSetting[]): { min: number, max: number, fromConfig: boolean } {
  const cfg = findRegisterField(fields, 'mobile')?.extra_config ?? {}
  const fromConfig = cfg.min_length != null && cfg.max_length != null
  return {
    min: cfg.min_length ?? DEFAULT_MOBILE_MIN_LENGTH,
    max: cfg.max_length ?? DEFAULT_MOBILE_MAX_LENGTH,
    fromConfig,
  }
}

/** 手機號（不含國碼）長度是否合法——送出與發碼鈕用同一把尺。 */
export function isMobileLengthValid(fields: readonly MemberFieldSetting[], phone: string): boolean {
  const digits = (phone ?? '').replace(/\D/g, '')
  if (!digits) return false
  const { min, max } = mobileLengthRange(fields)
  return digits.length >= min && digits.length <= max
}

/** 輸入時即清掉非數字並去掉開頭的 0（舊皮 watch phone）。 */
export function normalizePhoneInput(value: string): string {
  const digits = (value ?? '').replace(/\D/g, '')
  return digits.replace(/^0+/, '')
}

/** 帳號取自手機號時，畫面上的帳號欄唯讀且值來自手機。 */
export function isMobileAsUsername(fields: readonly MemberFieldSetting[]): boolean {
  return findRegisterField(fields, 'username')?.extra_config?.mobile_as_username === true
}

export interface RegisterFormValues {
  /** 欄位鍵 → 值，例如 username / password / last_name / birthday / email */
  values: Record<string, string>
  /** 手機號（不含國碼） */
  phone: string
  /** 邀請碼（手填或下拉選中的代理碼） */
  invitationCode: string
}

export type RegisterFieldIssue
  = | { field: string, kind: 'required' }
    | { field: string, kind: 'length', min?: number, max?: number }
    | { field: string, kind: 'alnum' }
    | { field: string, kind: 'lowercase' }
    | { field: string, kind: 'mismatch' }
    | { field: string, kind: 'mobileLength', min: number, max: number }
    | { field: string, kind: 'codeRequired' }

/**
 * 驗證一組欄位（送出時傳全部、按下一步時只傳當前步），回傳所有問題。
 * 舊皮 validateFields 只回 true/false 且一次只顯示一則，這裡回全部，
 * 讓每個欄位下方都能就地顯示自己的問題。
 */
export function validateRegisterFields(
  all: readonly MemberFieldSetting[],
  scope: readonly MemberFieldSetting[],
  form: RegisterFormValues,
): RegisterFieldIssue[] {
  const issues: RegisterFieldIssue[] = []
  const mobileAsUsername = isMobileAsUsername(all)

  for (const field of scope) {
    const key = field.field_key
    // mobile / email / referral_code 走下面的專屬檢查
    if ((CUSTOM_VALIDATED_FIELDS as readonly string[]).includes(key)) continue
    // 驗證碼併在手機／信箱區塊裡，也在下面一起檢查
    if ((INLINE_REGISTER_FIELDS as readonly string[]).includes(key)) continue
    // 帳號取自手機號時不用手填
    if (key === 'username' && mobileAsUsername) continue

    const value = form.values[key] ?? ''
    if (field.is_required === 1 && !value) {
      issues.push({ field: key, kind: 'required' })
      continue
    }
    if (!value) continue

    if (isAlphanumericOnlyField(all, key) && !isAlphaNumeric(value)) {
      issues.push({ field: key, kind: 'alnum' })
    }
    const cfg = registerLengthConfig(all, field)
    if (cfg.min_length != null && value.length < cfg.min_length) {
      issues.push({ field: key, kind: 'length', min: cfg.min_length, max: cfg.max_length })
    }
    else if (cfg.max_length != null && value.length > cfg.max_length) {
      issues.push({ field: key, kind: 'length', min: cfg.min_length, max: cfg.max_length })
    }
    if (key === 'username' && field.extra_config?.lowercase_username && /[A-Z]/.test(value)) {
      issues.push({ field: key, kind: 'lowercase' })
    }
  }

  // 確認密碼必須與密碼一致（舊皮不看 is_required，只要欄位在就比對）
  if (scope.some(f => f.field_key === 'confirm_password')) {
    if ((form.values.password ?? '') !== (form.values.confirm_password ?? '')) {
      issues.push({ field: 'confirm_password', kind: 'mismatch' })
    }
  }

  // 手機：長度合法 + 簡訊驗證碼（有 mobile 欄位就一定要驗，與 is_required 無關）
  if (scope.some(f => f.field_key === 'mobile')) {
    if (!isMobileLengthValid(all, form.phone)) {
      const { min, max } = mobileLengthRange(all)
      issues.push({ field: 'mobile', kind: 'mobileLength', min, max })
    }
    if (!(form.values.sms_verify_code ?? '')) {
      issues.push({ field: 'sms_verify_code', kind: 'codeRequired' })
    }
  }

  // 信箱：填值 + 郵件驗證碼（同上，不看 is_required）
  if (scope.some(f => f.field_key === 'email')) {
    if (!(form.values.email ?? '')) issues.push({ field: 'email', kind: 'required' })
    if (!(form.values.email_verify_code ?? '')) {
      issues.push({ field: 'email_verify_code', kind: 'codeRequired' })
    }
  }

  // 邀請碼：後台設必填時，手填或選代理擇一有值即可
  const referral = scope.find(f => f.field_key === 'referral_code')
  if (referral && referral.is_required === 1 && !form.invitationCode) {
    issues.push({ field: 'referral_code', kind: 'required' })
  }

  return issues
}

export interface InvitationCodeState {
  /** 1 = 下拉選代理、2 = 手填 */
  codeType: 1 | 2
  code: string
  locked: boolean
}

/**
 * 邀請碼呈現方式：網址帶碼鎖定手填；has_open 開下拉；is_auto_agent_code 且沒帶碼預填預設分享碼並切回下拉。
 */
export function resolveInvitationCodeState(input: {
  urlCode?: string | null
  hasOpen: boolean
  isAutoAgentCode: boolean
  defaultCode?: string | null
}): InvitationCodeState {
  const urlCode = (input.urlCode ?? '').trim()
  if (urlCode) return { codeType: 2, code: urlCode, locked: true }
  if (input.isAutoAgentCode && input.defaultCode) return { codeType: 1, code: input.defaultCode, locked: false }
  if (input.hasOpen) return { codeType: 1, code: '', locked: false }
  return { codeType: 2, code: '', locked: false }
}

/**
 * APP WebView 會把裝置識別碼寫進 sessionStorage 的 "device"（沿用舊前台的鍵名），
 * 註冊與登入都要帶上去，後端靠它做裝置綁定。瀏覽器開就沒有，回空字串。
 */
export function readDeviceId(): string {
  // shared 也會在伺服器端跑，走 globalThis 取用瀏覽器才有的儲存區
  const store = globalThis.sessionStorage
  if (!store) return ''
  try {
    return store.getItem('device') ?? ''
  }
  catch {
    return ''
  }
}

/** 對齊舊前台 $getPlatformName().toLowerCase()，寫入 members.device_type。 */
export function detectPlatformName(userAgent: string | null | undefined): string {
  const ua = (userAgent ?? '').toLowerCase()
  if (!ua) return 'unknown'
  if (ua.includes('windows')) return 'windows'
  if (ua.includes('ipad')) return 'ipad'
  if (ua.includes('iphone') || ua.includes('ipod')) return 'ios'
  if (ua.includes('android')) return 'android'
  if (ua.includes('mac os') || ua.includes('macintosh')) return 'mac'
  return 'unknown'
}

/* ---------------------------------------------------------------- Telegram */

export interface TelegramCallbackQuery {
  user: { id: string, first_name?: string, last_name?: string, username?: string, photo_url?: string, auth_date: string, hash: string } | null
  bot_username: string
}

/** /telegram-callback 的 query string → oauth-login 的 user 與 bot_username。缺 id/hash 視為無效。 */
export function parseTelegramCallbackQuery(query: Record<string, unknown>): TelegramCallbackQuery {
  const pick = (k: string) => {
    const v = query[k]
    return typeof v === 'string' ? v : Array.isArray(v) && typeof v[0] === 'string' ? v[0] : ''
  }
  const id = pick('id')
  const hash = pick('hash')
  const auth_date = pick('auth_date')
  const bot_username = pick('bot_username')
  if (!id || !hash || !auth_date) return { user: null, bot_username }
  return {
    user: {
      id,
      first_name: pick('first_name') || undefined,
      last_name: pick('last_name') || undefined,
      username: pick('username') || undefined,
      photo_url: pick('photo_url') || undefined,
      auth_date,
      hash,
    },
    bot_username,
  }
}

export function buildTelegramOAuthUrl(input: { botId: string, botUsername: string, origin: string }): string {
  const returnTo = `${input.origin}/telegram-callback?bot_username=${encodeURIComponent(input.botUsername)}`
  return `https://example.invalid/auth?bot_id=${encodeURIComponent(input.botId)}`
    + `&origin=${encodeURIComponent(input.origin)}&request_access=write&return_to=${encodeURIComponent(returnTo)}`
}

export function buildTelegramBotUrl(botUsername: string, code?: string | null): string {
  const base = `https://example.invalid/${botUsername}`
  return code ? `${base}/startapp?startapp=${encodeURIComponent(code)}` : base
}

/** OAuth widget 以 postMessage 回傳 {event:'auth_result', result}，字串或物件都可能。 */
export function parseTelegramAuthMessage(data: unknown): Record<string, unknown> | null {
  let parsed: unknown = data
  if (typeof data === 'string') {
    try {
      parsed = JSON.parse(data)
    }
    catch {
      return null
    }
  }
  if (!parsed || typeof parsed !== 'object') return null
  const msg = parsed as Record<string, unknown>
  if (msg.event !== 'auth_result' || !msg.result || typeof msg.result !== 'object') return null
  return msg.result as Record<string, unknown>
}

export function needsInviteCode(code: number): boolean {
  return code === AUTH_CODE.INPUT_INVITE_CODE
}
