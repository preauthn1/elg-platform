import type { ApiEnvelope } from '#shared/types/api'
import type {
  BankAccount,
  ChildOrderType,
  DepositResult,
  OrderType,
  OrderTypeField,
  OrderTypeFieldOption,
  OrderTypeGroupItem,
} from '#shared/api/endpoints/deposit'
import { API_CODE } from '#shared/constants/codes'
import { decFixed, decMul, isDecimalText, type AmountLike } from './amount'

/** apis CashFlowEnum 中本領域會分流的金流 id。 */
export const CASH_FLOW = {
  AUTO_DEPOSIT: 5,
  AUTO_WITHDRAWAL: 6,
  MB88_PAY: 25,
  SURE_PAY: 26,
  VG_PAY: 55,
} as const

/** 這些 order_type_id 的付款頁要開新視窗（與 cf_id 25 同一套處理）。 */
export const NEW_WINDOW_ORDER_TYPE_IDS: readonly number[] = [66]

/** apis DepositResponseType。 */
export const DEPOSIT_RESULT_TYPE = {
  ERROR: 0,
  URL_REDIRECT: 1,
  QR_CODE_LEGACY: 2,
  HTML_FORM: 3,
  SUCCESS: 4,
  QR_CODE: 11,
  QR_CODE_IMAGE_PATH: 12,
} as const

/** TngPin 以 HTTP 200 回的自訂碼（無 data）。 */
export const TNGPIN_CODE = {
  STAY_ON_PAGE: 998,
  RETURN_TO_PAYMENT: 999,
} as const

/** type 4 成功提示的關閉導頁碼（舊 Modal 碼表：1956 → /my）。 */
export const DEPOSIT_SUCCESS_MODAL_CODE = 1956

/** 銀行轉帳訂單狀態（orders.status）。 */
export const BANK_ORDER_STATUS = {
  CREATED: 0,
  APPLIED: 1,
  FAILED: 2,
  SUCCESS: 3,
} as const

/** 憑證上傳只收這些 base64 data URL（同後端 LazyBase64Image）。 */
export const BASE64_IMAGE_RE = /^data:image\/(jpeg|png|gif|bmp|webp);base64,/

export const RECEIPT_IMAGE_MAX_WIDTH = 1200
export const RECEIPT_IMAGE_JPEG_QUALITY = 0.7

export const PRESET_AMOUNTS: readonly number[] = [
  10, 100, 200, 500, 1000, 5000, 10000, 20000, 50000, 100000, 500000, 1000000, 5000000, 10000000, 50000000,
]

export function isOnlinePayment(item: Pick<OrderType, 'cf_id'>): boolean {
  return item.cf_id !== CASH_FLOW.AUTO_DEPOSIT && item.cf_id !== CASH_FLOW.AUTO_WITHDRAWAL
}

/** 線上支付 = cf_id 不是 5/6；自助上分 = cf_id 5；cf_id 6（自助洗分）存款頁不用。 */
export function splitTopUpTypes(items: readonly OrderType[]): { online: OrderType[], autoDeposit: OrderType | null } {
  return {
    online: items.filter(isOnlinePayment),
    autoDeposit: items.find(i => i.cf_id === CASH_FLOW.AUTO_DEPOSIT) ?? null,
  }
}

export type TopupTabKey = 'quickRecharge' | 'topup'

export interface TopupTab {
  key: TopupTabKey
  name: string
}

/** /topup 的分頁：有線上支付才有 quickRecharge；銀行轉帳分頁名稱用固定項的 name。 */
export function buildTopupTabs(items: readonly OrderType[], quickRechargeLabel: string): TopupTab[] {
  const { online, autoDeposit } = splitTopUpTypes(items)
  const tabs: TopupTab[] = []
  if (online.length > 0) tabs.push({ key: 'quickRecharge', name: quickRechargeLabel })
  if (autoDeposit) tabs.push({ key: 'topup', name: autoDeposit.name ?? '' })
  return tabs
}

export function distinctCurrencyIds(items: readonly { currency_id: number }[]): number[] {
  return Array.from(new Set(items.map(i => i.currency_id).filter(id => id !== 0)))
}

/** 沒選幣別（null）就全部列出。 */
export function filterByCurrency<T extends { currency_id: number }>(items: readonly T[], currencyId: number | null): T[] {
  if (currencyId === null) return [...items]
  return items.filter(i => i.currency_id === currencyId)
}

function isFieldDef(value: unknown): value is OrderTypeField {
  return typeof value === 'object' && value !== null && typeof (value as { name?: unknown }).name === 'string'
}

/** 動態欄位定義的三種來源形狀：array、{ data: [] }、JSON 字串。 */
export function normalizeFieldDefs(input: unknown): OrderTypeField[] {
  if (input === null || input === undefined || input === '') return []
  if (typeof input === 'string') {
    try {
      return normalizeFieldDefs(JSON.parse(input))
    }
    catch {
      return []
    }
  }
  if (Array.isArray(input)) return input.filter(isFieldDef)
  if (typeof input === 'object') {
    const data = (input as { data?: unknown }).data
    return Array.isArray(data) ? data.filter(isFieldDef) : []
  }
  return []
}

/** 子渠道先看 options（新 API），再退回 order_type_info JSON 字串；沒有子渠道就用父項的 order_type_info。 */
export function resolveFieldDefs(payment: OrderType | null, child: ChildOrderType | null): OrderTypeField[] {
  if (payment && payment.choose_bank === 1 && child) {
    const fromOptions = normalizeFieldDefs(child.options)
    if (fromOptions.length > 0) return fromOptions
    const fromInfo = normalizeFieldDefs(child.order_type_info)
    if (fromInfo.length > 0) return fromInfo
  }
  return payment ? normalizeFieldDefs(payment.order_type_info) : []
}

export type FieldValue = string | OrderTypeFieldOption | null
export type FieldModel = Record<string, FieldValue>

export function isSelectField(field: OrderTypeField): boolean {
  return field.type === 'select'
}

/** select 預設第一個選項（整個 option 物件），其他型別預設空字串。 */
export function buildInitialModel(fields: readonly OrderTypeField[]): FieldModel {
  const model: FieldModel = {}
  for (const f of fields) {
    model[f.name] = isSelectField(f) ? (f.option?.[0] ?? null) : ''
  }
  return model
}

export function hasQrcodeField(fields: readonly OrderTypeField[]): boolean {
  return fields.some(f => f.type === 'qrcode')
}

export function isFieldRequired(field: OrderTypeField): boolean {
  const r = field.require
  return r === true || r === 1 || r === '1' || r === 'true'
}

export interface DepositLimits {
  min: number
  max: number
}

/** 子渠道的 min/max 非 0 才覆寫父項；0 = 不限制。 */
export function resolveDepositLimits(payment: OrderType | null, child: ChildOrderType | null): DepositLimits {
  const min = child?.min_amount ? child.min_amount : (payment?.min_amount ?? 0)
  const max = child?.max_amount ? child.max_amount : (payment?.max_amount ?? 0)
  return { min, max }
}

export function isAmountWithinLimits(amount: number, limits: DepositLimits): boolean {
  if (!Number.isFinite(amount) || amount <= 0) return false
  if (limits.min > 0 && amount < limits.min) return false
  if (limits.max > 0 && amount > limits.max) return false
  return true
}

/**
 * 換算點數的小數位：匯率去掉尾端 0 後的小數位數 > 2 就用它，
 * 有小數但不超過 2 位用 shortFallback（線上支付 2、銀行轉帳 4），整數匯率固定 2。
 */
export function rateDecimals(rate: number | string | null | undefined, shortFallback = 2): number {
  const fraction = String(rate ?? '').split('.')[1]?.replace(/0+$/, '') ?? ''
  if (!fraction) return 2
  return fraction.length > 2 ? fraction.length : shortFallback
}

export function toAmountNumber(value: unknown): number {
  if (typeof value === 'number') return value
  if (typeof value === 'string' && value.trim() !== '') return Number(value)
  return Number.NaN
}

/** 顯示用：amount * rate，固定小數位。金額運算走 amount.ts，不用浮點數。 */
export function convertPoints(amount: unknown, rate: number | string | null | undefined, decimals: number): string {
  if (!isDecimalText(amount as AmountLike)) return decFixed(0, decimals)
  return decFixed(decMul(amount as AmountLike, rate), decimals)
}

export interface FieldValidation {
  ok: boolean
  invalid: string[]
}

/** require 的欄位不可空；select 要有 key；amount 要在 min..max 內。 */
export function validateFields(fields: readonly OrderTypeField[], model: FieldModel, limits: DepositLimits): FieldValidation {
  const invalid: string[] = []
  for (const f of fields) {
    const value = model[f.name]
    if (isFieldRequired(f)) {
      if (isSelectField(f)) {
        const opt = (value ?? null) as OrderTypeFieldOption | null
        if (!opt || opt.key === null || opt.key === undefined || opt.key === '') invalid.push(f.name)
      }
      else if (value === null || value === undefined || String(value) === '') {
        invalid.push(f.name)
      }
    }
    if (f.name === 'amount' && !isAmountWithinLimits(toAmountNumber(value), limits)) {
      invalid.push(f.name)
    }
  }
  return { ok: invalid.length === 0, invalid: Array.from(new Set(invalid)) }
}

export interface CreateOrderBody {
  order_type_id: number
  amount?: number
  [extra: string]: unknown
}

/** choose_bank == 1 送子渠道 id；select 送 option.key；空值不送；amount 轉數字。 */
export function buildCreateOrderBody(
  payment: OrderType,
  child: ChildOrderType | null,
  fields: readonly OrderTypeField[],
  model: FieldModel,
): CreateOrderBody {
  const body: CreateOrderBody = {
    order_type_id: payment.choose_bank === 1 && child ? child.id : payment.order_type_id,
  }
  for (const f of fields) {
    const raw = model[f.name]
    const value: unknown = isSelectField(f) ? ((raw ?? null) as OrderTypeFieldOption | null)?.key : raw
    if (value === undefined || value === null || value === '') continue
    body[f.name] = f.name === 'amount' ? toAmountNumber(value) : value
  }
  return body
}

export interface BsbAccount {
  bsb: string | null
  account: string
}

/** account_no 為 'BSB__帳號' 且站台開 enable_bsb_code 時拆開顯示。 */
export function splitBsbAccountNo(accountNo: unknown, enableBsb: boolean): BsbAccount {
  const raw = accountNo === null || accountNo === undefined ? '' : String(accountNo)
  if (!enableBsb || !raw.includes('__')) return { bsb: null, account: raw }
  const [bsb = '', ...rest] = raw.split('__')
  return { bsb, account: rest.join('__') }
}

/** 銀行轉帳金額：> 0 且在帳號 min..max 內（後端同樣嚴格比對，不把 0 當不限）。 */
export function isBankAmountValid(amount: unknown, account: Pick<BankAccount, 'min_amount' | 'max_amount'> | null): boolean {
  if (!account) return false
  const a = toAmountNumber(amount)
  return Number.isFinite(a) && a > 0 && a >= account.min_amount && a <= account.max_amount
}

/** 快選金額：落在 min..max 內的預設值；max 0 視為不限。 */
export function presetAmounts(min: number, max: number): number[] {
  return PRESET_AMOUNTS.filter(v => v >= min && (max <= 0 || v <= max))
}

export type DepositOutcome
  = | { kind: 'error', message: string }
    | { kind: 'stay', message: string }
    | { kind: 'return', message: string }
    | { kind: 'redirect', url: string }
    | { kind: 'open-window', url: string }
    | { kind: 'write-html', html: string }
    | { kind: 'open-window-html', html: string }
    | { kind: 'success', code: number }
    | { kind: 'qrcode', payload: string }
    | { kind: 'image', url: string }

export interface DepositResultContext {
  cf_id: number
  order_type_id: number
}

/** QR 內容：apis 放在 url（type 2/11/12），code 欄只有 TngPin 用；舊前台讀 code，保留為退路。 */
export function qrPayloadOf(result: Pick<DepositResult, 'url' | 'code'>): string {
  if (result.url) return result.url
  return result.code === null || result.code === undefined ? '' : String(result.code)
}

/**
 * 建單回應分流：
 * 998 留在原頁、999 回支付列表；非 200 為錯誤；
 * type 0 錯誤、1 轉址（cf 25 / order_type 66 開新視窗）、3 寫入表單 html（cf 26 開新視窗）、
 * 4 成功、11/2 QR 內容、12 QR 圖片網址。
 */
export function classifyDepositResult(res: ApiEnvelope<DepositResult | null>, ctx: DepositResultContext): DepositOutcome {
  if (res.code === TNGPIN_CODE.STAY_ON_PAGE) return { kind: 'stay', message: res.message }
  if (res.code === TNGPIN_CODE.RETURN_TO_PAYMENT) return { kind: 'return', message: res.message }
  if (res.code !== API_CODE.OK || !res.data) return { kind: 'error', message: res.message }

  const data = res.data
  switch (data.type) {
    case DEPOSIT_RESULT_TYPE.ERROR:
      return { kind: 'error', message: data.message || res.message }
    case DEPOSIT_RESULT_TYPE.URL_REDIRECT: {
      const url = data.url ?? ''
      if (!url) return { kind: 'error', message: res.message }
      const newWindow = ctx.cf_id === CASH_FLOW.MB88_PAY || NEW_WINDOW_ORDER_TYPE_IDS.includes(ctx.order_type_id)
      return newWindow ? { kind: 'open-window', url } : { kind: 'redirect', url }
    }
    case DEPOSIT_RESULT_TYPE.HTML_FORM: {
      const html = data.html ?? ''
      if (!html) return { kind: 'error', message: res.message }
      return ctx.cf_id === CASH_FLOW.SURE_PAY ? { kind: 'open-window-html', html } : { kind: 'write-html', html }
    }
    case DEPOSIT_RESULT_TYPE.SUCCESS:
      return { kind: 'success', code: DEPOSIT_SUCCESS_MODAL_CODE }
    case DEPOSIT_RESULT_TYPE.QR_CODE:
    case DEPOSIT_RESULT_TYPE.QR_CODE_LEGACY:
      return { kind: 'qrcode', payload: qrPayloadOf(data) }
    case DEPOSIT_RESULT_TYPE.QR_CODE_IMAGE_PATH:
      return { kind: 'image', url: data.url ?? '' }
    default:
      return { kind: 'error', message: res.message }
  }
}

/** 收據頁狀態標籤的 i18n key。 */
export function bankOrderStatusKey(status: number): string {
  switch (status) {
    case BANK_ORDER_STATUS.CREATED:
      return 'deposit.enum.orderStatus.create'
    case BANK_ORDER_STATUS.APPLIED:
      return 'deposit.enum.orderStatus.pending'
    case BANK_ORDER_STATUS.FAILED:
      return 'deposit.enum.orderStatus.refuse'
    case BANK_ORDER_STATUS.SUCCESS:
      return 'common.ui.msg.success'
    default:
      return ''
  }
}

export function isAcceptedImageDataUrl(value: string): boolean {
  return BASE64_IMAGE_RE.test(value)
}

export function isImageFile(mime: string | null | undefined): boolean {
  return /^image\/\w+/.test(mime ?? '')
}

/** deposit/types 的分組項對齊 top-up-types 的鍵名，讓同一套畫面吃兩種形狀。 */
export function normalizeGroupItem(item: OrderTypeGroupItem): OrderType {
  return {
    ...item,
    cf_id: item.cash_flow_id,
    PopupText: item.popup_text ?? '',
  }
}
