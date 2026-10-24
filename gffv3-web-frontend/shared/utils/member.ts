import type { MemberDetail, UpdateProfileInput } from '#shared/api/endpoints/member'
import { isOn } from './site'

/** 會員中心頁面路徑，導頁一律從這裡取。 */
export const MEMBER_PATH = {
  home: '/member',
  setting: '/member/setting',
  nickname: '/member/setting/name',
  changePassword: [REDACTED]',
  paymentPassword: [REDACTED]',
  changePaymentPassword: [REDACTED]',
  resetPaymentPassword: [REDACTED]',
  phoneVerification: '/member/phone-verification',
  bindEmail: '/member/bind-email',
  changePhone: '/member/change-phone',
  changeEmail: '/member/change-email',
  setFullname: '/member/set-fullname',
  setBirthday: '/member/set-birthday',
  realNameVerification: '/member/real-name-verification',
  ipChangeVerification: '/member/ip-change-verification',
  googleAuthenticator: '/member/google-authenticator',
  appearance: '/member/appearance',
  /** 綁卡頁在提款域，設定頁的綁卡入口導到這裡 */
  bindBankCard: '/withdrawal/bind-bank-card',
} as const

/** 會員領域會碰到的業務碼。 */
export const MEMBER_CODE = {
  FORCED_LOGOUT: 319,
  UNAUTHORIZED: 401,
  NOT_ACCEPTABLE: 406,
  OLD_PASSWORD_WRONG: 1018,
  EMAIL_BINDING_LIMIT: 1088,
  MOBILE_BINDING_LIMIT: 1090,
  PAYMENT_PASSWORD_SAME_AS_LOGIN: 1094,
  REALNAME_ALREADY_SET: 1097,
  BIRTHDAY_ALREADY_SET: 1100,
  REALNAME_DUPLICATE: 2065,
  NEW_PASSWORD_SAME_AS_PAYMENT: 9019,
  CODE_INVALID: 9992,
} as const

/** 後端空值有 null、''、'null' 三種寫法，統一判斷。 */
export function hasValue(value: unknown): value is string | number {
  if (value === null || value === undefined) return false
  const s = String(value).trim()
  return s !== '' && s !== 'null'
}

/**
 * **全站唯一**的「API 錯誤碼 → 關掉錯誤彈窗後導去哪」對照表，照舊版 C13 `Page/Modal.vue` 的 hiddenModal
 * （331、332 是 C15 版多的）。useApi 的全域錯誤處理用它，各領域**不要**再自己寫一份、也不要收到就立刻導頁：
 * 舊版是先彈後端訊息、使用者關掉才導。
 * 319/9998（登出）、317（維護）、423（重載）由 useApi 另外處理，不在這裡。
 * 2041 要帶目前路徑回跳，故用函式而不是純表。
 */
export function profileRedirectFor(code: number, currentPath: string = MEMBER_PATH.home): string | null {
  switch (code) {
    // 舊版導 /retrieve-password；新版那頁要登入流程帶的 member_id／token 才能用，所以導忘記密碼
    case 322:
    case 9006:
      return '/forget-password'
    case 334:
      return MEMBER_PATH.resetPaymentPassword
    case 324:
    case 9007:
      return MEMBER_PATH.phoneVerification
    case 326:
    case 9008:
      return MEMBER_PATH.bindEmail
    case 323:
    case 9014:
      return MEMBER_PATH.paymentPassword
    case 325:
    case 1095:
    case 1096:
      return MEMBER_PATH.setFullname
    case 331:
      return `${MEMBER_PATH.setFullname}?next=${encodeURIComponent('/withdrawal')}`
    case 332:
      return MEMBER_PATH.realNameVerification
    case 327:
    case 2040:
      return `/withdrawal?redirect=${encodeURIComponent(MEMBER_PATH.home)}`
    case 2041:
      return `${MEMBER_PATH.setFullname}?next=${encodeURIComponent(`/withdrawal?redirect=${currentPath}`)}`
    case 321:
    case 999:
    case 1956:
      return MEMBER_PATH.home
    case 320:
    case 1938:
      return '/'
    default:
      return null
  }
}

function firstString(value: unknown): string | null {
  const v = Array.isArray(value) ? value[0] : value
  return typeof v === 'string' && v ? v : null
}

/** 只接受站內相對路徑，避免 open redirect。 */
function isInternalPath(path: string): boolean {
  return path.startsWith('/') && !path.startsWith('//')
}

/** set-fullname 成功後的回跳：query.next 優先，再 query.redirect，否則會員中心。 */
export function resolveSetFullnameRedirect(query: Record<string, unknown>, fallback: string = MEMBER_PATH.home): string {
  const candidate = firstString(query.next) ?? firstString(query.redirect)
  return candidate && isInternalPath(candidate) ? candidate : fallback
}

function toYmd(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/** 成年門檻：後端要求生日必須早於「今天減 18 年」。 */
export function maxAdultBirthday(today: Date = new Date()): string {
  const limit = new Date(today.getTime())
  limit.setFullYear(limit.getFullYear() - 18)
  return toYmd(limit)
}

export function isAdultBirthday(birthday: string, today: Date = new Date()): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(birthday)) return false
  const parsed = new Date(`${birthday}T00:00:00`)
  if (Number.isNaN(parsed.getTime()) || toYmd(parsed) !== birthday) return false
  return birthday < maxAdultBirthday(today)
}

/** 開關切換：目前開 → 送 0，否則送 1（不能送 false，後端會當空值丟掉）。 */
export function nextToggleValue(current: unknown): 0 | 1 {
  return isOn(current) ? 0 : 1
}

/** update-profile 成功後要寫回 store 的欄位。 */
export function profilePatchFromUpdate(fields: UpdateProfileInput): Partial<MemberDetail> {
  const patch: Partial<MemberDetail> = {}
  if (fields.nickname !== undefined) patch.nickname = fields.nickname
  if (fields.email !== undefined) patch.email = fields.email
  if (fields.image !== undefined) patch.image = fields.image
  if (fields.timezone_id !== undefined) patch.timezone_id = Number(fields.timezone_id)
  if (fields.country_id !== undefined) patch.country_id = Number(fields.country_id)
  if (fields.zip_code !== undefined) patch.zip_code = String(fields.zip_code)
  if (fields.district !== undefined) patch.district = fields.district
  if (fields.city !== undefined) patch.city = fields.city
  if (fields.address !== undefined) patch.address = fields.address
  if (fields.has_2fa !== undefined) patch.has_2fa = isOn(fields.has_2fa) ? 1 : 0
  if (fields.ip_change_mobile_verify !== undefined) patch.ip_change_mobile_verify = isOn(fields.ip_change_mobile_verify) ? 1 : 0
  if (fields.ip_change_email_verify !== undefined) patch.ip_change_email_verify = isOn(fields.ip_change_email_verify) ? 1 : 0
  return patch
}

export interface SettingMenuItem {
  key: string
  /** i18n key；沒有對應文案時用 plain */
  i18n?: string
  plain?: string
  to: string
}

export interface SettingMenuFlags {
  modifyMobileVisible: boolean
  modifyEmailVisible: boolean
  bindingEmailVisible: boolean
  paymentPasswordVisible: boolean
  /** 代理開銀行卡制且會員尚未綁卡才出現綁卡入口，值由 bankCardMenuEnabled 算 */
  bankCardSettingEnabled: boolean
  hasGoogleAuth: boolean
  /** 這個皮有第二套外觀（--wf-has-alt-appearance）才出現外觀設定入口 */
  appearanceVisible?: boolean
}

type SettingProfile = Pick<MemberDetail, 'mobile' | 'email' | 'payment_password' | 'birthday'>

/**
 * 設定選單：依 template 旗標與會員資料決定顯示哪些入口，照舊版 C13 `Page/Setting.vue`。
 * 重設支付密碼沒有獨立入口，只從修改支付密碼頁的「忘記舊密碼」進去。
 */
export function buildSettingMenu(profile: SettingProfile | null, flags: SettingMenuFlags): SettingMenuItem[] {
  const items: SettingMenuItem[] = []
  const mobileBound = hasValue(profile?.mobile)
  const emailBound = hasValue(profile?.email)

  if (flags.modifyMobileVisible && mobileBound) {
    items.push({ key: 'changePhone', i18n: 'member.bindPhone.title.changePhoneNumber', to: MEMBER_PATH.changePhone })
  }
  if (flags.modifyMobileVisible && !mobileBound) {
    items.push({ key: 'bindPhone', i18n: 'member.bindPhone.title.bindPhone', to: MEMBER_PATH.phoneVerification })
  }
  if (flags.modifyEmailVisible && emailBound) {
    items.push({ key: 'changeEmail', i18n: 'member.bindEmail.title.changeEmail', to: MEMBER_PATH.changeEmail })
  }
  if (flags.bindingEmailVisible && !emailBound) {
    items.push({ key: 'bindEmail', i18n: 'member.bindEmail.title.bindEmail', to: MEMBER_PATH.bindEmail })
  }
  items.push({ key: 'changePassword', i18n: 'member.password.title.changePassword', to: MEMBER_PATH.changePassword })
  if (flags.paymentPasswordVisible) {
    if (isOn(profile?.payment_password)) {
      items.push({ key: 'changePaymentPassword', i18n: 'member.paymentPassword.title.changePaymentPassword', to: MEMBER_PATH.changePaymentPassword })
    }
    else {
      items.push({ key: 'paymentPassword', i18n: 'member.paymentPassword.label.paymentPassword', to: MEMBER_PATH.paymentPassword })
    }
  }
  if (flags.bankCardSettingEnabled) {
    items.push({ key: 'bindBankCard', i18n: 'withdrawal.bankAccount.title.bindBankCard', to: MEMBER_PATH.bindBankCard })
  }
  if (flags.hasGoogleAuth) {
    items.push({ key: 'googleAuthenticator', plain: 'Google Authenticator', to: MEMBER_PATH.googleAuthenticator })
  }
  items.push({ key: 'ipChangeVerification', i18n: 'member.security.label.ipChangeVerification', to: MEMBER_PATH.ipChangeVerification })
  if (flags.appearanceVisible) {
    items.push({ key: 'appearance', i18n: 'member.appearance.title.settings', to: MEMBER_PATH.appearance })
  }
  if (!hasValue(profile?.birthday)) {
    items.push({ key: 'setBirthday', i18n: 'member.profile.action.setBirthday', to: MEMBER_PATH.setBirthday })
  }
  return items
}

/**
 * 實名認證的路由守衛（舊前台 router/index.js 的 beforeEach）要檢查哪一種：
 *   page   進實名認證頁本身
 *   entry  進提款（transfer_withdraw_realname_required）或活動類頁面（event_reward_realname_validation）；
 *          兩者都要站台開著 real_name_verification_apply_visible 才檢查
 */
export type RealnameRouteKind = 'page' | 'entry'

const REALNAME_TRANSFER_PATHS = ['/withdrawal']
const REALNAME_EVENT_PATHS = ['/events', '/promotions', '/v-rebate']

export interface RealnameRouteFlags {
  transferRequired: boolean
  eventRequired: boolean
  applyVisible: boolean
}

export function realnameRouteKind(path: string, flags: RealnameRouteFlags): RealnameRouteKind | null {
  if (path.startsWith(MEMBER_PATH.realNameVerification)) return 'page'
  if (!flags.applyVisible) return null
  const hit = (list: string[]) => list.some(p => path.startsWith(p))
  if ((flags.transferRequired && hit(REALNAME_TRANSFER_PATHS)) || (flags.eventRequired && hit(REALNAME_EVENT_PATHS))) return 'entry'
  return null
}

/** 守衛擋下的原因：required 未申請、reviewing 審核中、approved 已通過（只有進實名頁時才會擋）。null 放行。 */
export type RealnameRouteBlock = 'required' | 'reviewing' | 'approved'

export function realnameRouteBlock(kind: RealnameRouteKind, check: { is_exists: unknown, is_validated: unknown }): RealnameRouteBlock | null {
  const exists = isOn(check.is_exists)
  const validated = isOn(check.is_validated)
  if (exists && !validated) return 'reviewing'
  if (kind === 'page') return !exists && validated ? 'approved' : null
  return !exists && !validated ? 'required' : null
}

export interface RealnameFieldRule {
  show: boolean
  required: boolean
}

/** 後端沒有欄位設定時回 {}，其餘每個 key 補齊 show/required。 */
export function normalizeRealnameFields(fields: unknown): Record<string, RealnameFieldRule> {
  if (!fields || typeof fields !== 'object' || Array.isArray(fields)) return {}
  const out: Record<string, RealnameFieldRule> = {}
  for (const [key, value] of Object.entries(fields as Record<string, unknown>)) {
    const rule = (value && typeof value === 'object' ? value : {}) as { show?: unknown, required?: unknown }
    out[key] = { show: rule.show !== false, required: rule.required === true }
  }
  return out
}

export interface RealnameForm {
  bank: RealnameFieldRule
  birthday: RealnameFieldRule
  id_number: RealnameFieldRule
  id_photo: RealnameFieldRule
}

const HIDDEN: RealnameFieldRule = { show: false, required: false }

/** 沒有欄位設定 → 姓名＋銀行卡必填；有設定 → 逐欄依設定。 */
export function resolveRealnameForm(fields: Record<string, RealnameFieldRule>): RealnameForm {
  if (Object.keys(fields).length === 0) {
    return { bank: { show: true, required: true }, birthday: HIDDEN, id_number: HIDDEN, id_photo: HIDDEN }
  }
  return {
    bank: fields.bank ?? HIDDEN,
    birthday: fields.birthday ?? HIDDEN,
    id_number: fields.id_number ?? HIDDEN,
    id_photo: fields.id_photo ?? HIDDEN,
  }
}

/** 代理開 BSB 時帳號前綴 `${bsb}__`。 */
export function buildBankAccount(account: string, bsb: string, enableBsb: boolean): string {
  const acc = account.trim()
  const code = bsb.trim()
  return enableBsb && code ? `${code}__${acc}` : acc
}

/** withdrawal/check-bank-account 回應中，會員中心與實名頁會用到的部分。 */
export interface BankCardInfoLike {
  has_bank_card_setting?: number | null
  account_name?: string | null
  account?: readonly BankCardAccountLike[] | null
}

export interface BankCardAccountLike {
  bank_id?: number | null
  bank_name?: string | null
  bank_code?: string | null
  account_name?: string | null
  account_no?: string | null
}

/**
 * 設定頁的綁卡入口：代理開銀行卡制（has_bank_card_setting == 1）且還沒綁卡才顯示。
 * 舊前台在 /setting 以 type -1 呼叫 check-bank-account，只用這兩個條件判斷。
 */
export function bankCardMenuEnabled(info: BankCardInfoLike | null): boolean {
  if (!info || !isOn(info.has_bank_card_setting)) return false
  return !info.account || info.account.length === 0
}

/** withdrawal/banks 回應中實名表單需要的部分。 */
export interface BankOptionLike {
  bank_id?: number | null
  bank_name?: string | null
  bank_code?: string | null
}

/** 實名認證表單的預填值；locked 為 true 時銀行與帳號欄位不可改。 */
export interface RealnamePrefill {
  accountName: string
  bankId: number | null
  bankName: string
  accountNo: string
  locked: boolean
}

/**
 * 銀行卡制且已經綁過卡時，實名表單帶入既有的姓名／銀行／帳號並鎖定輸入。
 * 沒開銀行卡制或還沒綁卡就回 null，表單維持手填。
 */
export function realnamePrefillFromBankInfo(
  info: BankCardInfoLike | null,
  banks: readonly BankOptionLike[] = [],
): RealnamePrefill | null {
  if (!info || !isOn(info.has_bank_card_setting)) return null
  const bound = info.account?.[0]
  if (!bound) return null

  const bankId = bound.bank_id ?? null
  const matched = banks.find(b => b.bank_id === bankId)
  return {
    accountName: bound.account_name ?? info.account_name ?? '',
    bankId,
    bankName: bound.bank_name ?? matched?.bank_name ?? '',
    accountNo: bound.account_no ?? '',
    locked: true,
  }
}

/** member/tracking-events 的一筆事件。 */
export interface TrackingEventLike {
  type: string
  data?: unknown
}

/**
 * 行銷像素的接口，由頁面在瀏覽器端注入；沒提供的就略過。
 * googleAds 只在 CompleteRegistration 觸發（沿用舊前台）。
 */
export interface TrackingSinks {
  fb?: (name: string, data?: unknown) => void
  tt?: (name: string, data?: unknown) => void
  ga?: (name: string, data?: unknown) => void
  googleAds?: (data?: unknown) => void
}

export const TRACKING_EVENT_COMPLETE_REGISTRATION = 'CompleteRegistration'

/**
 * 打行銷像素。後端每筆只回一次，所以這裡逐筆 try/catch，
 * 單一像素壞掉不影響其他事件，也絕不往外丟錯（舊前台同樣靜默）。
 * 回傳實際處理的筆數。
 */
export function fireTrackingEvents(items: readonly TrackingEventLike[], sinks: TrackingSinks): number {
  let fired = 0
  for (const item of items) {
    if (!item?.type) continue
    try {
      sinks.fb?.(item.type, item.data)
      sinks.tt?.(item.type, item.data)
      sinks.ga?.(item.type, item.data)
      if (item.type === TRACKING_EVENT_COMPLETE_REGISTRATION) sinks.googleAds?.(item.data)
      fired += 1
    }
    catch {
      // 像素失敗不影響會員流程
    }
  }
  return fired
}
