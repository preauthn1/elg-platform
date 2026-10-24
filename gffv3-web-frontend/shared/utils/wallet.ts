import { isOn } from './site'
// 金額運算一律走 amount.ts；這裡不重新 export，否則 Nuxt 自動匯入會撞名
import type { AmountLike } from './amount'
import { decCmp, decFloorFixed, decMax, decSub, decText, decToNumber } from './amount'

export type WalletDirection = 'main_to_game' | 'game_to_main'
export type WalletHistoryType = 'main' | 'game'

/** wallet/transfer 的 type 欄位。 */
export const WALLET_TRANSFER_TYPE = {
  MAIN_TO_GAME: 1,
  GAME_TO_MAIN: 2,
} as const

/** coupon/redeem 回傳的 type 欄位。 */
export const COUPON_REWARD_TYPE = {
  POINT: 1,
  ROLLING_CHIP: 2,
} as const

/** 前端 in-flight 防重送時回的假信封碼（不會與 apis / BFF 撞號）。 */
export const WALLET_IN_FLIGHT_CODE = -100

/** 遊戲錢包互轉前端最低金額。 */
export const WALLET_TRANSFER_MIN_AMOUNT = '1'

/** 錢包金額一律留 4 位小數（後端就是 4 位），畫面顯示時才捨到 2 位。 */
const SCALE = 4

/** 未達稽核流水 = flow_limit − reach（可為負，畫面照舊版原樣顯示）。 */
export function remainingFlowLimit(flowLimit: AmountLike, reach: AmountLike): string {
  return decFloorFixed(decSub(flowLimit, reach), SCALE)
}

export interface MaxTransferInput {
  direction: WalletDirection
  mainBalance: AmountLike
  gameBalance: AmountLike
  flowLimit: AmountLike
  reach: AmountLike
  /** customized_setting.flow_limit_display_visible */
  flowLimitDisplayVisible: boolean
}

/**
 * 遊戲錢包互轉上限：
 * 主→遊戲 = 主錢包餘額；
 * 遊戲→主 = 遊戲錢包餘額 −（flow_limit − reach），只有 flow_limit_display_visible 開才扣；負數歸零。
 */
export function walletMaxTransferAmount(input: MaxTransferInput): string {
  if (input.direction === 'main_to_game') {
    return decFloorFixed(input.mainBalance, SCALE)
  }
  const amount = input.flowLimitDisplayVisible
    ? decSub(input.gameBalance, remainingFlowLimit(input.flowLimit, input.reach))
    : decText(input.gameBalance)
  return decFloorFixed(decMax(amount, 0), SCALE)
}

/** 可送出：有值、>= 1、<= 上限。 */
export function canSubmitWalletTransfer(amount: string, max: AmountLike): boolean {
  const trimmed = amount.trim()
  if (!trimmed || !/^\d+(\.\d+)?$/.test(trimmed)) return false
  return decCmp(trimmed, WALLET_TRANSFER_MIN_AMOUNT) >= 0 && decCmp(trimmed, max) <= 0
}

export function walletTransferTypeOf(direction: WalletDirection): 1 | 2 {
  return direction === 'game_to_main' ? WALLET_TRANSFER_TYPE.GAME_TO_MAIN : WALLET_TRANSFER_TYPE.MAIN_TO_GAME
}

export function oppositeDirection(direction: WalletDirection): WalletDirection {
  return direction === 'main_to_game' ? 'game_to_main' : 'main_to_game'
}

/** wallet/transfer 的 request：type 送數字，amount 截到 4 位小數。 */
export function buildWalletTransferRequest(direction: WalletDirection, amount: string): { type: 1 | 2, amount: number } {
  return {
    type: walletTransferTypeOf(direction),
    amount: decToNumber(decFloorFixed(amount, SCALE)),
  }
}

/** member/transfer 的 request：沒有目標遊戲商就不送 vendor_id。 */
export function buildMemberTransferRequest(vendorId?: number | null): { vendor_id?: number } {
  if (vendorId === null || vendorId === undefined || !Number.isFinite(vendorId) || vendorId <= 0) return {}
  return { vendor_id: Math.trunc(vendorId) }
}

/** 進遊戲商頁只有 transaction_all == 1 才自動把主錢包轉入。 */
export function shouldAutoTransferIntoVendor(transactionAll: unknown): boolean {
  return isOn(transactionAll)
}

/**
 * 進遊戲商時從哪一種頁面進來（決定要不要轉入，規則見 shouldTransferOnVendorEntry）。
 * - game：遊戲頁 /game/:id
 * - vendor：遊戲列表、APP 場館、聲明頁
 */
export type VendorEntryKind = 'game' | 'vendor'

/**
 * 進遊戲商要不要帶 vendor_id 轉入，照舊版 C13／C16：
 * - 遊戲頁（Game.vue reInit）**一律轉**，不看 transaction_all
 * - 遊戲列表、APP 場館、聲明頁（GameList／GameApp／GameStatement）只有 transaction_all 開啟才轉
 * 場館前頁（/vendor-amount）不轉入，交給目的頁；它進頁時只打不帶參數的 member/transfer 刷新餘額。
 */
export function shouldTransferOnVendorEntry(kind: VendorEntryKind, transactionAll: unknown): boolean {
  return kind === 'game' || shouldAutoTransferIntoVendor(transactionAll)
}

export function normalizeCouponCode(code: string): string {
  return code.trim().toUpperCase()
}

/** 送出前驗證，回傳提示文案的 i18n key；通過回 null。 */
export function validateCouponCode(code: string): 'validation.code.error.redemptionRequired' | 'validation.field.error.alphanumeric' | null {
  const trimmed = code.trim()
  if (trimmed === '') return 'validation.code.error.redemptionRequired'
  if (/[^A-Za-z0-9]/.test(trimmed) || trimmed.length > 10) return 'validation.field.error.alphanumeric'
  return null
}

/** 兌換成功文案的單位：type 2 用禮碼名稱，其餘用平台點數名稱（皆取 customized_setting）。 */
export function couponUnitKey(type: number | string): 'text_rolling_chip' | 'text_wallet_point' {
  return Number(type) === COUPON_REWARD_TYPE.ROLLING_CHIP ? 'text_rolling_chip' : 'text_wallet_point'
}

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** 前台時區的 Y-m-d H:i:s（用瀏覽器本地時間）。 */
export function formatWalletDateTime(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`
    + ` ${pad2(date.getHours())}:${pad2(date.getMinutes())}:${pad2(date.getSeconds())}`
}

/** <input type="datetime-local"> 的值（Y-m-dTH:i）轉成 Y-m-d H:i:s；空值回 undefined。 */
export function datetimeLocalToApi(value: string): string | undefined {
  const trimmed = value.trim()
  if (!trimmed) return undefined
  const match = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/.exec(trimmed)
  if (!match) return undefined
  return `${match[1]} ${match[2]}:${match[3] ?? '00'}`
}

/** Y-m-d H:i:s 轉成 datetime-local 可吃的 Y-m-dTH:i:s。 */
export function apiToDatetimeLocal(value: string): string {
  return value.replace(' ', 'T')
}

export interface WalletHistoryRange {
  start_time: string
  end_time: string
}

/** 錢包紀錄預設區間：最近 7 天到現在（與後端未帶時的預設一致）。 */
export function defaultWalletHistoryRange(now: Date = new Date()): WalletHistoryRange {
  const start = new Date(now.getTime())
  start.setDate(start.getDate() - 7)
  return {
    start_time: formatWalletDateTime(start),
    end_time: formatWalletDateTime(now),
  }
}

export interface WalletHistoriesQuery {
  walletType: WalletHistoryType
  startTime?: string
  endTime?: string
  page: number
  perPage: number
}

export function buildWalletHistoriesRequest(query: WalletHistoriesQuery): {
  wallet_type: WalletHistoryType
  start_time?: string
  end_time?: string
  page: number
  per_page: number
} {
  const req: ReturnType<typeof buildWalletHistoriesRequest> = {
    wallet_type: query.walletType,
    page: Math.max(1, Math.trunc(query.page) || 1),
    per_page: Math.max(1, Math.trunc(query.perPage) || 15),
  }
  if (query.startTime) req.start_time = query.startTime
  if (query.endTime) req.end_time = query.endTime
  return req
}

export function totalPages(total: AmountLike, perPage: AmountLike): number {
  const t = Number(total) || 0
  const p = Number(perPage) || 0
  if (t <= 0 || p <= 0) return 0
  return Math.ceil(t / p)
}
