/**
 * 提款領域的純函式：稽核流水、限額、手續費、銀行欄位與 payload 組法。
 * 金額運算一律用 ./amount（decimal.js），這裡不自己算。
 * 不碰 Nuxt / Vue，composable 與頁面只負責呼叫。
 */

import {
  decCmp,
  decDiv,
  decIsZero,
  decMax,
  decMul,
  decSub,
  decText,
  isDecimalText,
  type AmountLike,
} from './amount'

/** 銀行類型（banks.type = withdrawal_type_id） */
export const BANK_TYPE = {
  MOBILE: 2,
  CRYPTO: 3,
  CONTACT_CS: 5,
  BSB: 6,
  PAY_ID: 7,
} as const

/** 洗分簡訊驗證的 validation code type */
export const WITHDRAWAL_SMS_CODE_TYPE = 7

/** deposit/top-up-types 裡自助洗分的 cash flow id */
export const SELF_WITHDRAWAL_CF_ID = 6

/** flow_limit_application：0 無、1 鎖分中、2 已申請待審核 */
export const FLOW_LIMIT_APPLICATION_PENDING = 2

// ---- 稽核流水 -----------------------------------------------------------------------------------

export interface FlowLimitLike {
  flow_limit?: AmountLike
  reach?: AmountLike
  pass?: number | null
}

export interface FlowLimitFlags {
  isMultipleWallet: boolean
  deductFlowLimit: boolean
}

/** 剩餘稽核流水 = flow_limit - reach */
export function flowRemaining(flow: FlowLimitLike | null | undefined): string {
  if (!flow) return '0'
  return decSub(flow.flow_limit, flow.reach)
}

/** flow_limit_display_visible == 1 且剩餘 != 0 才顯示 */
export function showFlowRemaining(flow: FlowLimitLike | null | undefined, displayVisible: boolean): boolean {
  return displayVisible && !decIsZero(flowRemaining(flow))
}

export function canWithdrawal(flow: FlowLimitLike | null | undefined, flags: FlowLimitFlags): boolean {
  if (flags.isMultipleWallet) return true
  if (flags.deductFlowLimit) return true
  if (!flow) return false
  return decCmp(flow.reach, flow.flow_limit) >= 0
}

/** 所選銀行匯率：rate || bank_rate，缺省 1 */
export function bankRate(bank: { rate?: AmountLike, bank_rate?: AmountLike } | null | undefined): string {
  const candidate = bank?.rate ?? bank?.bank_rate
  if (candidate === null || candidate === undefined || candidate === '' || decIsZero(candidate)) return '1'
  return decText(candidate)
}

export interface AvailableInput extends FlowLimitFlags {
  balance: AmountLike
  flow: FlowLimitLike | null | undefined
  rate: AmountLike
}

/** 可提領金額；不顯示時回 null */
export function availableForWithdrawal(input: AvailableInput): string | null {
  const rate = bankRate({ rate: input.rate })
  if (input.isMultipleWallet) return decDiv(input.balance, rate)
  if (input.deductFlowLimit) {
    const net = decSub(input.balance, flowRemaining(input.flow))
    return decMax('0', decDiv(net, rate))
  }
  return null
}

export type ReleaseButtonState = 'hidden' | 'pending' | 'enabled'

export interface ReleaseButtonInput {
  flowLimitApplication: number | null | undefined
  balance: AmountLike
  flowLimit: AmountLike
}

/** 垃圾桶（申請解除稽核）：待審核灰化；餘額 < 1 且 flow_limit > 0 才可按 */
export function releaseButtonState(input: ReleaseButtonInput): ReleaseButtonState {
  if (Number(input.flowLimitApplication) === FLOW_LIMIT_APPLICATION_PENDING) return 'pending'
  if (decCmp(input.balance, '1') < 0 && decCmp(input.flowLimit, '0') > 0) return 'enabled'
  return 'hidden'
}

// ---- 限額、手續費、點數換算 ----------------------------------------------------------------------------

export interface AmountLimits {
  min: string
  max: string
}

interface LimitLike {
  min_amount?: AmountLike
  max_amount?: AmountLike
}

function positiveOrNull(value: AmountLike): string | null {
  if (value === null || value === undefined || value === '') return null
  if (!isDecimalText(value) || decCmp(value, 0) <= 0) return null
  return decText(value)
}

/** 所選卡片/銀行的上下限優先，0/空時退回頂層；min > max 時以 max 為 min */
export function resolveAmountLimits(selected: LimitLike | null | undefined, fallback: LimitLike | null | undefined): AmountLimits {
  const min = positiveOrNull(selected?.min_amount) ?? positiveOrNull(fallback?.min_amount) ?? '0'
  const max = positiveOrNull(selected?.max_amount) ?? positiveOrNull(fallback?.max_amount) ?? '0'
  if (decCmp(min, max) > 0) return { min: max, max }
  return { min, max }
}

export function isAmountValid(amount: AmountLike, limits: AmountLimits): boolean {
  if (!isDecimalText(amount)) return false
  if (decCmp(amount, '0') <= 0) return false
  if (decCmp(amount, limits.min) < 0) return false
  if (decCmp(amount, limits.max) > 0) return false
  return true
}

export interface OverLimitInput {
  withdrawal_times?: number | null
  withdrawal_times_limit?: number | null
  vip_tax?: AmountLike
}

/** 超次手續費：次數與上限皆非 null、上限 != -1、已超次、vip_tax 非 null */
export function showOverLimitFee(info: OverLimitInput | null | undefined): boolean {
  if (!info) return false
  const { withdrawal_times: times, withdrawal_times_limit: limit, vip_tax: vipTax } = info
  if (times === null || times === undefined) return false
  if (limit === null || limit === undefined || limit === -1) return false
  if (vipTax === null || vipTax === undefined) return false
  return times > limit
}

export function overLimitFee(amount: AmountLike, vipTax: AmountLike): string {
  return decMul(amount, vipTax)
}

/** tax_type 0 固定金額、1 百分比（tax 為小數） */
export function feeAmount(amount: AmountLike, taxType: number | null | undefined, tax: AmountLike): string {
  if (Number(taxType) === 1) return decMul(amount, tax)
  return decText(tax)
}

export function showFee(tax: AmountLike): boolean {
  return decCmp(tax, '0') > 0
}

/** 百分比手續費顯示：tax × 100 */
export function feePercentText(tax: AmountLike): string {
  return decMul(tax, '100')
}

export interface NetAmountInput {
  amount: AmountLike
  taxType: number | null | undefined
  tax: AmountLike
  vipTax?: AmountLike
  applyOverLimitFee?: boolean
}

/** 實得 = amount - 手續費 - 超次費 */
export function netAmount(input: NetAmountInput): string {
  let net = decSub(input.amount, feeAmount(input.amount, input.taxType, input.tax))
  if (input.applyOverLimitFee) net = decSub(net, overLimitFee(input.amount, input.vipTax))
  return net
}

export function platformPoints(amount: AmountLike, rate: AmountLike): string {
  return decMul(amount, bankRate({ rate }))
}

/** rate != 1 才顯示 `${rate} ${pointText} = 1 ${currency}` */
export function rateText(rate: AmountLike, pointText: string | null | undefined, currencyName: string | null | undefined): string | null {
  const r = bankRate({ rate })
  if (decCmp(r, '1') === 0) return null
  return `${r} ${pointText ?? ''} = 1 ${currencyName ?? ''}`.replace(/\s+/g, ' ').trim()
}

// ---- 銀行清單、幣別、代付方式 ---------------------------------------------------------------------------

export interface BankInfoField {
  type?: string | null
  display?: string | null
  placeholder?: string | null
  regex?: string | null
  options?: Array<{ text: string, value: string | number }> | null
}

export interface BankLike {
  bank_id: number
  bank_name: string
  bank_code: string
  bank_type: number
  bank_icon?: string | null
  bank_info?: unknown
  rate: AmountLike
  currency_id: number
  currency_name: string
  withdrawal_type_id: number
  withdrawal_type_name?: string | null
  min_amount: AmountLike
  max_amount: AmountLike
  tax_type: number
  tax: AmountLike
}

export interface BoundAccountLike {
  bank_id: number
  bank_name: string
  bank_code: string
  bank_type: number
  bank_rate?: AmountLike
  min_amount?: AmountLike
  max_amount?: AmountLike
  tax_type?: number | null
  bank_tax?: AmountLike
  account_name?: string | null
  account_no: string
}

export interface SelectOption {
  id: number
  name: string
}

/** 去重的 currency_id（排除 0），名稱取自 banks 的 currency_name */
export function currencyOptions(banks: BankLike[]): SelectOption[] {
  const seen = new Map<number, string>()
  for (const b of banks) {
    if (!b.currency_id) continue
    if (!seen.has(b.currency_id)) seen.set(b.currency_id, b.currency_name)
  }
  return [...seen].map(([id, name]) => ({ id, name }))
}

/** 該幣別下去重的 withdrawal_type_id */
export function typeOptions(banks: BankLike[], currencyId: number | null): SelectOption[] {
  const seen = new Map<number, string>()
  for (const b of banks) {
    if (currencyId !== null && b.currency_id !== currencyId) continue
    if (!seen.has(b.withdrawal_type_id)) seen.set(b.withdrawal_type_id, b.withdrawal_type_name ?? '')
  }
  return [...seen].map(([id, name]) => ({ id, name }))
}

/** 某類型下可選銀行 = withdrawal_type_id 與 currency_id 同時相符 */
export function banksFor(banks: BankLike[], currencyId: number | null, typeId: number | null): BankLike[] {
  return banks.filter(b =>
    (currencyId === null || b.currency_id === currencyId)
    && (typeId === null || b.withdrawal_type_id === typeId),
  )
}

/** withdrawal_type_id == 5 或 bank_type == 5 → 導客服 */
export function isContactCsBank(bank: { bank_type?: number | null, withdrawal_type_id?: number | null } | null | undefined): boolean {
  if (!bank) return false
  return Number(bank.bank_type) === BANK_TYPE.CONTACT_CS || Number(bank.withdrawal_type_id) === BANK_TYPE.CONTACT_CS
}

export function isContactCsType(typeId: number | null | undefined): boolean {
  return Number(typeId) === BANK_TYPE.CONTACT_CS
}

// ---- 銀行欄位（bank_info、帳號格式、戶名） ----------------------------------------------------------------

export interface NamedBankInfoField extends BankInfoField {
  name: string
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** bank_info 的動態欄位（排除只提供帳號 placeholder 的 accountNo） */
export function bankInfoFields(bankInfo: unknown): NamedBankInfoField[] {
  if (!isPlainObject(bankInfo)) return []
  return Object.entries(bankInfo)
    .filter(([name, field]) => name !== 'accountNo' && isPlainObject(field))
    .map(([name, field]) => ({ name, ...(field as BankInfoField) }))
}

/** select 欄位預設第一個 option，其餘空字串 */
export function defaultBankInfoData(bankInfo: unknown): Record<string, string> {
  const data: Record<string, string> = {}
  for (const field of bankInfoFields(bankInfo)) {
    const first = field.type === 'select' ? field.options?.[0]?.value : undefined
    data[field.name] = first === undefined || first === null ? '' : String(first)
  }
  return data
}

/** 後端 regex 壞掉時不擋使用者 */
function matchesPattern(pattern: string, value: string): boolean {
  try {
    return new RegExp(pattern).test(value)
  }
  catch {
    return true
  }
}

/** 回傳格式不符（或空值）的欄位名 */
export function validateBankInfoData(bankInfo: unknown, data: Record<string, string>): string[] {
  const failed: string[] = []
  for (const field of bankInfoFields(bankInfo)) {
    const value = (data[field.name] ?? '').trim()
    if (!value) {
      failed.push(field.name)
      continue
    }
    if (field.regex && !matchesPattern(field.regex, value)) failed.push(field.name)
  }
  return failed
}

export type AccountNoLabel = { key: string, text?: undefined } | { text: string, key?: undefined }

/** 帳號欄位名稱：bank_info.accountNo.placeholder 優先，否則依 bank_type */
export function accountNoLabel(bank: { bank_type?: number | null, bank_info?: unknown } | null | undefined): AccountNoLabel {
  const info = bank?.bank_info
  if (isPlainObject(info) && isPlainObject(info.accountNo)) {
    const placeholder = info.accountNo.placeholder
    if (typeof placeholder === 'string' && placeholder.trim()) return { text: placeholder }
  }
  switch (Number(bank?.bank_type)) {
    case BANK_TYPE.MOBILE: return { key: 'auth.common.label.phoneNumber' }
    case BANK_TYPE.CRYPTO: return { key: 'common.bank.label.walletAddress' }
    case BANK_TYPE.PAY_ID: return { text: 'Pay_Id' }
    default: return { key: 'common.bank.label.accountNo' }
  }
}

/** bank_type 2/3 不顯示戶名欄 */
export function showAccountNameField(bankType: number | null | undefined): boolean {
  const t = Number(bankType)
  return t !== BANK_TYPE.MOBILE && t !== BANK_TYPE.CRYPTO
}

/** bank_type 3 金額欄叫兌換金額 */
export function amountLabelKey(bankType: number | null | undefined): 'wallet.exchange.label.amount' | 'common.amount.label.amount' {
  return Number(bankType) === BANK_TYPE.CRYPTO ? 'wallet.exchange.label.amount' : 'common.amount.label.amount'
}

export function isBsbBank(bankType: number | null | undefined): boolean {
  return Number(bankType) === BANK_TYPE.BSB
}

/** BSB 銀行帳號送 `${bsb}__${accountNo}` */
export function joinAccountNo(bsbCode: string | null | undefined, accountNo: string): string {
  const no = accountNo.trim()
  const bsb = (bsbCode ?? '').trim()
  return bsb ? `${bsb}__${no}` : no
}

/** account_no 含 "__" 且 bank_type 6 → 拆成 bsbCode / accountNo */
export function splitAccountNo(accountNo: string | null | undefined, bankType: number | null | undefined): { bsbCode: string, accountNo: string } {
  const raw = accountNo ?? ''
  if (isBsbBank(bankType) && raw.includes('__')) {
    const idx = raw.indexOf('__')
    return { bsbCode: raw.slice(0, idx), accountNo: raw.slice(idx + 2) }
  }
  return { bsbCode: '', accountNo: raw }
}

export interface AccountNameEditInput {
  realNameVerifiedVisible: boolean
  is_first?: number | null
  account_name_modify?: number | null
}

/** real_name_verified_visible == 1 時需 is_first == 1 或 account_name_modify == 1；否則永遠可編輯 */
export function canEditAccountName(input: AccountNameEditInput): boolean {
  if (!input.realNameVerifiedVisible) return true
  return Number(input.is_first) === 1 || Number(input.account_name_modify) === 1
}

/** 已綁卡清單裡有同 bank_id 時自動帶入帳號（BSB 拆欄） */
export function prefillFromBoundAccount(
  accounts: BoundAccountLike[] | null | undefined,
  bankId: number | null,
  bankType: number | null | undefined,
): { account_name: string, accountNo: string, bsbCode: string } | null {
  if (!accounts || bankId === null) return null
  const hit = accounts.find(a => a.bank_id === bankId)
  if (!hit) return null
  const { bsbCode, accountNo } = splitAccountNo(hit.account_no, bankType)
  return { account_name: hit.account_name ?? '', accountNo, bsbCode }
}

// ---- 選定銀行與 payload 組法 -----------------------------------------------------------------------

/** 進入金額頁前存起來的銀行（withdrawalSelectedBank） */
export interface SelectedBank {
  bank_id: number
  bank_name: string
  bank_code: string
  bank_type: number
  rate: AmountLike
  min_amount: AmountLike
  max_amount: AmountLike
  tax_type: number | null
  tax: AmountLike
  currency_name: string
  bank_info: unknown
  account_name: string
  account_no: string
  bank_info_data: Record<string, string>
}

export function selectedBankFromBank(
  bank: BankLike,
  account: { account_name: string, account_no: string, bank_info_data?: Record<string, string> },
): SelectedBank {
  return {
    bank_id: bank.bank_id,
    bank_name: bank.bank_name,
    bank_code: bank.bank_code,
    bank_type: bank.bank_type,
    rate: bank.rate,
    min_amount: bank.min_amount,
    max_amount: bank.max_amount,
    tax_type: bank.tax_type,
    tax: bank.tax,
    currency_name: bank.currency_name,
    bank_info: bank.bank_info ?? null,
    account_name: account.account_name,
    account_no: account.account_no,
    bank_info_data: account.bank_info_data ?? {},
  }
}

/** 已綁卡 → 選定銀行；bank_info / currency_name 從銀行清單補 */
export function selectedBankFromAccount(account: BoundAccountLike, banks: BankLike[]): SelectedBank {
  const bank = banks.find(b => b.bank_id === account.bank_id)
  return {
    bank_id: account.bank_id,
    bank_name: account.bank_name,
    bank_code: account.bank_code,
    bank_type: account.bank_type,
    rate: account.bank_rate ?? bank?.rate ?? '1',
    min_amount: account.min_amount ?? bank?.min_amount ?? '0',
    max_amount: account.max_amount ?? bank?.max_amount ?? '0',
    tax_type: account.tax_type ?? bank?.tax_type ?? 0,
    tax: account.bank_tax ?? bank?.tax ?? '0',
    currency_name: bank?.currency_name ?? '',
    bank_info: bank?.bank_info ?? null,
    account_name: account.account_name ?? '',
    account_no: account.account_no,
    bank_info_data: {},
  }
}

export interface CreateOrderPayload {
  amount: number
  account_name: string
  account_no: string
  bank_id: number
  bank_name: string
  bank_code: string
  [key: string]: unknown
}

/** create-order payload：帳戶欄位 + bank_info_data 照 key 原樣 spread */
export function buildCreateOrderPayload(selected: SelectedBank, amount: AmountLike): CreateOrderPayload {
  return {
    ...selected.bank_info_data,
    amount: Number(amount),
    account_name: selected.account_name,
    account_no: selected.account_no,
    bank_id: selected.bank_id,
    bank_name: selected.bank_name,
    bank_code: selected.bank_code,
  }
}

export interface BindAccountRow {
  bank_id: number | null
  account_name: string
  account_no: string
  bsb_code?: string
}

export interface BindAccountPayloadItem {
  bank_id: number
  account_name: string
  account_no: string
}

export type BindRowsError = 'empty' | 'incomplete'

/** 多組綁卡：只送完整的組；有選銀行但帳號/戶名不完整不可送 */
export function buildBindAccountsPayload(rows: BindAccountRow[]): { payload: BindAccountPayloadItem[], error: BindRowsError | null } {
  const payload: BindAccountPayloadItem[] = []
  for (const row of rows) {
    if (row.bank_id === null) continue
    const name = row.account_name.trim()
    const no = row.account_no.trim()
    if (!name || !no) return { payload: [], error: 'incomplete' }
    payload.push({ bank_id: row.bank_id, account_name: name, account_no: joinAccountNo(row.bsb_code, no) })
  }
  if (payload.length === 0) return { payload, error: 'empty' }
  return { payload, error: null }
}

// ---- 驗證流程與導頁 --------------------------------------------------------------------------------

export type VerifyStep = 'payment-password' | 'sms' | 'none'

/** payment_password_visible → 支付密碼；否則 mobile_validate → 簡訊；都沒有直接建單 */
export function resolveVerifyStep(input: { paymentPasswordVisible: boolean, mobileValidate: boolean }): VerifyStep {
  if (input.paymentPasswordVisible) return 'payment-password'
  if (input.mobileValidate) return 'sms'
  return 'none'
}

export interface TopUpTypeLike {
  cf_id?: number | string | null
  popup_text?: string | null
}

/** 自助洗分 gate：找 cf_id == 6；找不到代表代理未開放 */
export function findSelfWithdrawalType(items: unknown): TopUpTypeLike | null {
  if (!Array.isArray(items)) return null
  const hit = items.find(item => isPlainObject(item) && Number(item.cf_id) === SELF_WITHDRAWAL_CF_ID)
  return (hit as TopUpTypeLike | undefined) ?? null
}

/** query redirect 只接受站內相對路徑 */
export function safeInternalPath(value: unknown): string {
  if (typeof value !== 'string') return ''
  if (!value.startsWith('/') || value.startsWith('//')) return ''
  return value
}

/** 2 位補零 */
function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** 建單成功訊息用：yyyy-MM-dd HH:mm:ss */
export function formatDateTime(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())} `
    + `${pad2(date.getHours())}:${pad2(date.getMinutes())}:${pad2(date.getSeconds())}`
}
