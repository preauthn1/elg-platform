import type { ApiEnvelope } from '#shared/types/api'
import type { EndpointKey, RequestOf, ResponseOf } from '#shared/api/registry'
import { API_CODE } from '#shared/constants/codes'

/** 後端「前端時間」固定 UTC+8；日期預設與請求日期一律以此換算。 */
export const FRONTEND_UTC_OFFSET_MINUTES = 8 * 60
/** 交易／投注／輸贏報表只開放查最近 3 天（今天、昨天、前天）。 */
export const RECORD_DAY_LIMIT = 3
/** 紀錄分頁不做前端分頁時一次撈的筆數。 */
export const RECORD_FETCH_ALL_PER_PAGE = 999
/** 投注紀錄、輸贏報表每頁筆數。 */
export const BET_RECORD_PAGE_SIZE = 10
/** 投注紀錄「全部期間」逐日撈取時的每頁筆數。 */
export const BET_RECORD_ALL_PERIOD_PER_PAGE = 1000

const DAY_MS = 86_400_000

function numberTable<T>(entries: Array<[number[], T]>): Record<number, T> {
  const table: Record<number, T> = {}
  for (const [keys, value] of entries) {
    for (const key of keys) table[key] = value
  }
  return table
}

// ---- 紀錄分頁（/record/:type） --------------------------------------------------------------------

export const RECORD_TYPES = [
  'deposit',
  'event',
  'platform-transfer',
  'withdrawal',
  'customer-service',
  'red-envelope-2',
  'red-envelope-1',
  'red-envelope-3',
] as const

export type RecordType = (typeof RECORD_TYPES)[number]

export interface RecordTab {
  type: RecordType
  titleKey: string
}

export interface RecordTabFlags {
  hasDepositRecordPage: boolean
  hasEvent: boolean
  isPgsPlatform: boolean
}

const RECORD_TAB_TITLE_KEYS: Record<RecordType, string> = {
  'deposit': 'records.tab.nav.deposit',
  'event': 'records.tab.nav.award',
  'platform-transfer': 'records.tab.nav.platformTransfer',
  'withdrawal': 'records.tab.nav.withdrawal',
  'customer-service': 'records.tab.title.csTransfer',
  'red-envelope-2': 'records.enum.transactionType.redEnvelopeReceived',
  'red-envelope-1': 'records.enum.transactionType.redEnvelope',
  'red-envelope-3': 'records.tab.nav.redEnvelopeRefund',
}

export function isRecordType(value: unknown): value is RecordType {
  return typeof value === 'string' && (RECORD_TYPES as readonly string[]).includes(value)
}

function recordTypeVisible(type: RecordType, flags: RecordTabFlags): boolean {
  switch (type) {
    case 'deposit':
      return flags.hasDepositRecordPage
    case 'event':
      return flags.hasEvent
    case 'platform-transfer':
      return !flags.isPgsPlatform
    default:
      return true
  }
}

/** 依站台開關算出要顯示的分頁，順序與 titleKey 固定。 */
export function recordTabs(flags: RecordTabFlags): RecordTab[] {
  return RECORD_TYPES
    .filter(type => recordTypeVisible(type, flags))
    .map(type => ({ type, titleKey: RECORD_TAB_TITLE_KEYS[type] }))
}

/** /record 沒帶 type 時要導去的第一個可見分頁；withdrawal 永遠可見所以一定有值。 */
export function firstVisibleRecordType(flags: RecordTabFlags): RecordType {
  return recordTabs(flags)[0]?.type ?? 'withdrawal'
}

/** red-envelope-1/2/3 對應 transaction/red-envelope 的 type。 */
export function redEnvelopeTypeOf(type: RecordType): 1 | 2 | 3 | null {
  switch (type) {
    case 'red-envelope-1':
      return 1
    case 'red-envelope-2':
      return 2
    case 'red-envelope-3':
      return 3
    default:
      return null
  }
}

export type RecordStatusKind = 'pending' | 'fail' | 'success'

/** 所有紀錄分頁共用的狀態歸類：0/1/pending 處理中、2/fail 失敗、3/success 成功。 */
export function recordStatusKind(status: number | string | null | undefined): RecordStatusKind | null {
  if (status === 0 || status === 1 || status === '0' || status === '1' || status === 'pending') return 'pending'
  if (status === 2 || status === '2' || status === 'fail') return 'fail'
  if (status === 3 || status === '3' || status === 'success') return 'success'
  return null
}

/** 狀態文字；語言檔沒有 success 的 key，先用中文字面。 */
export function recordStatusLabel(status: number | string | null | undefined, t: (key: string) => string): string {
  switch (recordStatusKind(status)) {
    case 'pending':
      return t('records.enum.orderStatus.pending')
    case 'fail':
      return t('common.misc.label.fail')
    case 'success':
      return '成功'
    default:
      return ''
  }
}

// ---- 日期（前端時間 UTC+8） -------------------------------------------------------------------------

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

/** now 換成前端時間後的 'YYYY-MM-DD'，offsetDays 可取昨天（-1）、前天（-2）。 */
export function frontendDate(now: Date, offsetDays = 0): string {
  const shifted = new Date(now.getTime() + FRONTEND_UTC_OFFSET_MINUTES * 60_000 + offsetDays * DAY_MS)
  return `${shifted.getUTCFullYear()}-${pad2(shifted.getUTCMonth() + 1)}-${pad2(shifted.getUTCDate())}`
}

/** 距離前端時間下一個 00:00 的毫秒數；日期預設清單到時要重算。 */
export function msUntilNextFrontendMidnight(now: Date): number {
  const shifted = now.getTime() + FRONTEND_UTC_OFFSET_MINUTES * 60_000
  return (Math.floor(shifted / DAY_MS) + 1) * DAY_MS - shifted
}

/** 最近 3 天的日期，今天在前。 */
export function recentFrontendDays(now: Date): string[] {
  return Array.from({ length: RECORD_DAY_LIMIT }, (_, i) => frontendDate(now, -i))
}

export interface DayPreset {
  key: string
  /** 有 labelKey 就用 $t(labelKey)，否則顯示 label（MM-DD） */
  labelKey: 'common.filter.option.allPeriod' | null
  label: string
  days: string[]
}

/** 投注紀錄（含全部期間）／輸贏報表（不含）的日期選項。 */
export function buildDayPresets(now: Date, opts: { allPeriod: boolean }): DayPreset[] {
  const days = recentFrontendDays(now)
  const singles: DayPreset[] = days.map(day => ({ key: day, labelKey: null, label: day.slice(5), days: [day] }))
  if (!opts.allPeriod) return singles
  return [{ key: 'all', labelKey: 'common.filter.option.allPeriod', label: '', days }, ...singles]
}

export interface RangePreset {
  key: string
  labelKey: 'common.filter.option.allPeriod' | null
  label: string
  start_time: string
  end_time: string
}

/** 交易紀錄的日期區間選項：全部期間（前天 00:00:00 ～ 今天 23:59:59）、今天、昨天、前天。 */
export function buildTransactionRangePresets(now: Date): RangePreset[] {
  const days = recentFrontendDays(now)
  const today = days[0] ?? frontendDate(now)
  const oldest = days[days.length - 1] ?? today
  return [
    { key: 'all', labelKey: 'common.filter.option.allPeriod', label: '', start_time: `${oldest} 00:00:00`, end_time: `${today} 23:59:59` },
    ...days.map((day): RangePreset => ({
      key: day,
      labelKey: null,
      label: day.slice(5),
      start_time: `${day} 00:00:00`,
      end_time: `${day} 23:59:59`,
    })),
  ]
}

// ---- 交易紀錄（transaction/logs） -----------------------------------------------------------------

export const TRANSACTION_TYPE_ORDER = ['all', '1', '2', '3', '4', '5', '8', '7'] as const
export type TransactionTypeKey = (typeof TRANSACTION_TYPE_ORDER)[number]

export interface TransactionTypeOption {
  type: TransactionTypeKey
  labelKey: string | null
  label: string
}

export interface TransactionTypeFlags {
  hasAgentLayout: boolean
  unlimitVisible: boolean
  /** customized_setting.text_unlimited，type 5 的顯示名稱 */
  unlimitedText: string
}

const TRANSACTION_TYPE_LABEL_KEYS: Record<Exclude<TransactionTypeKey, '5'>, string> = {
  all: 'records.filter.option.allTypes',
  1: 'records.enum.transactionType.pointsIn',
  2: 'withdrawal.entry.title.withdrawal',
  3: 'records.tab.nav.gameEntryExit',
  4: 'events.detail.label.reward',
  8: 'wallet.transfer.title.transfer',
  7: 'records.enum.transactionType.pointsAdjustment',
}

/** 類型下拉；type 5（全民代理）只在 has_agent_layout 且 is_unlimit_visible 時出現。 */
export function transactionTypeOptions(flags: TransactionTypeFlags): TransactionTypeOption[] {
  return TRANSACTION_TYPE_ORDER.flatMap((type): TransactionTypeOption[] => {
    if (type === '5') {
      if (!(flags.hasAgentLayout && flags.unlimitVisible)) return []
      return [{ type, labelKey: null, label: flags.unlimitedText }]
    }
    return [{ type, labelKey: TRANSACTION_TYPE_LABEL_KEYS[type], label: '' }]
  })
}

/** 路由參數 → 類型；不在可見清單就退回 all。 */
export function normalizeTransactionType(param: unknown, options: readonly TransactionTypeOption[]): TransactionTypeKey {
  return options.find(option => option.type === param)?.type ?? 'all'
}

export interface TransactionLogsInput {
  type: TransactionTypeKey
  range: Pick<RangePreset, 'start_time' | 'end_time'>
  page: number
  perPage: number
}

/** 類型是 all 時不送 type 鍵。 */
export function buildTransactionLogsRequest(input: TransactionLogsInput): RequestOf<'transaction/logs'> {
  const body: RequestOf<'transaction/logs'> = {
    start_time: input.range.start_time,
    end_time: input.range.end_time,
    page: input.page,
    per_page: input.perPage,
  }
  if (input.type !== 'all') body.type = Number(input.type)
  return body
}

export type TransactionIconKind
  = | 'vendor'
    | 'deposit'
    | 'withdrawal'
    | 'red-envelope'
    | 'transfer-out'
    | 'transfer-in'
    | 'points-adjustment'
    | 'agent'
    | 'event-reward'

export interface TransactionLogLike {
  is_rolling_chip: number
  type: number
  vendor_name?: string | null
}

const CHIP_ICON_KINDS = numberTable<TransactionIconKind>([
  [[1, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 39, 40, 41, 71], 'event-reward'],
  [[4], 'agent'],
  [[5], 'points-adjustment'],
])

const CASH_ICON_KINDS = numberTable<TransactionIconKind>([
  [[1, 2], 'vendor'],
  [[3], 'deposit'],
  [[5, 11, 12], 'withdrawal'],
  [[6, 7, 8, 9], 'red-envelope'],
  [[34, 35, 37, 70], 'transfer-out'],
  [[38], 'transfer-in'],
  [[4, 10, 14, 33], 'points-adjustment'],
  [[17], 'agent'],
  [[13, 15, 18, 19, 20, 21, 24, 25, 26, 30, 31, 32, 39, 40, 41, 71, 101, 102, 103, 104], 'event-reward'],
])

/** 依（是否禮碼, type）決定圖示類別；vendor 表示用 vendor_image。 */
export function transactionIconKind(item: TransactionLogLike): TransactionIconKind | null {
  const table = item.is_rolling_chip === 1 ? CHIP_ICON_KINDS : CASH_ICON_KINDS
  return table[item.type] ?? null
}

const CHIP_LABEL_KEYS = numberTable<string>([
  [[1, 39, 40, 41, 71], 'events.detail.label.reward'],
  [[4], 'records.enum.transactionType.rebate'],
  [[5], 'records.enum.transactionType.systemGift'],
  [[6], 'records.enum.transactionType.bettingEvent'],
  [[7], 'records.enum.transactionType.loginEvent'],
  [[8], 'records.enum.transactionType.reclaimLoginReward'],
  [[9], 'events.depositReward.title.firstDeposit'],
  [[10], 'records.enum.transactionType.registrationReward'],
  [[11], 'records.enum.transactionType.bindPhoneReward'],
  [[12], 'records.enum.transactionType.dailyDepositReward'],
  [[13], 'events.invite.label.reward'],
  [[16], 'competitions.ranking.nav.leaderboard'],
  [[17], 'records.enum.transactionType.lossRebate'],
  [[19], 'records.enum.transactionType.vipReward'],
  [[20], 'records.enum.transactionType.depositReward'],
  [[25], 'records.enum.transactionType.missionBonus'],
  [[70], 'records.enum.transactionType.mainToPromotionWallet'],
])

const CHIP_LABEL_LITERALS = numberTable<string>([
  [[14], 'W-Jackpot'],
  [[15], 'W-Jackpot 6D'],
])

const CASH_LABEL_KEYS = numberTable<string>([
  [[3], 'deposit.common.title.deposit'],
  [[4], 'records.enum.transactionType.systemPointsIn'],
  [[5], 'withdrawal.entry.title.withdrawal'],
  [[6], 'records.enum.transactionType.redEnvelope'],
  [[7], 'records.enum.transactionType.redEnvelopeReceived'],
  [[8], 'records.enum.transactionType.revokeRedEnvelope'],
  [[9], 'records.enum.transactionType.returnRedEnvelope'],
  [[10], 'records.enum.transactionType.systemPointsGiven'],
  [[11], 'records.enum.transactionType.refuseWashPoints'],
  [[12], 'records.enum.transactionType.returnWashPoints'],
  [[13, 39, 40, 41, 71], 'events.detail.label.reward'],
  [[14], 'records.enum.transactionType.systemPointsAdjustment'],
  [[15], 'records.enum.transactionType.loginEvent'],
  [[17], 'records.enum.transactionType.rebate'],
  [[19], 'events.depositReward.title.firstDeposit'],
  [[20], 'records.enum.transactionType.registrationReward'],
  [[21], 'records.enum.transactionType.bindPhoneReward'],
  [[24], 'records.enum.transactionType.depositReward'],
  [[25], 'records.enum.transactionType.dailyDepositReward'],
  [[26], 'events.invite.label.reward'],
  [[32], 'records.enum.transactionType.vipReward'],
  [[33], 'records.enum.transactionType.accountAdjustment'],
  [[34], 'records.enum.transactionType.mainToGameWallet'],
  [[35], 'wallet.transfer.title.gameToMain'],
  [[37], 'records.enum.transactionType.memberTransferOut'],
  [[38], 'records.enum.transactionType.memberTransferIn'],
  [[70], 'records.enum.transactionType.mainToPromotionWallet'],
  [[101], 'competitions.ranking.nav.leaderboard'],
  [[102], 'records.enum.transactionType.lossRebate'],
  [[104], 'wallet.overview.label.promotionWallet'],
  [[105, 109], 'records.enum.transactionType.missionBonus'],
])

const CASH_LABEL_LITERALS = numberTable<string>([
  [[18], 'V-Jackpot'],
  [[30], 'W-Jackpot'],
  [[31], 'W-Jackpot 6D'],
  [[103], 'Jackpot'],
])

export interface TransactionLabelContext {
  t: (key: string) => string
  /** template 的 event_jackpot_title，禮碼 type 18 用 */
  jackpotTitle: string
  /** 優惠券文案各站叫法不同，由站台設定決定 */
  couponLabelKey: 'wallet.exchange.label.promoCode' | 'wallet.exchange.title.coupon'
}

/** 交易類型名稱；找不到對應的回 '-'。 */
export function transactionTypeLabel(item: TransactionLogLike, ctx: TransactionLabelContext): string {
  if (item.is_rolling_chip === 1) {
    if (item.type === 18) return ctx.jackpotTitle
    if (item.type === 23) return ctx.t(ctx.couponLabelKey)
    const literal = CHIP_LABEL_LITERALS[item.type]
    if (literal) return literal
    const key = CHIP_LABEL_KEYS[item.type]
    return key ? ctx.t(key) : '-'
  }
  if (item.type === 1 || item.type === 2) {
    const direction = ctx.t(item.type === 1 ? 'records.enum.transactionType.vendorTransferIn' : 'records.enum.transactionType.vendorTransferOut')
    return `${direction} ${item.vendor_name ?? ''}`.trim()
  }
  if (item.type === 107) return ctx.t(ctx.couponLabelKey)
  const literal = CASH_LABEL_LITERALS[item.type]
  if (literal) return literal
  const key = CASH_LABEL_KEYS[item.type]
  return key ? ctx.t(key) : '-'
}

// ---- 金額、分頁、回應 -------------------------------------------------------------------------------

/** 金額顯示：無條件捨去到指定位數並補零（'1234.567' → '1234.56'、'' → '0.00'）。 */
export function formatRecordAmount(value: string | number | null | undefined, decimals = 2): string {
  const raw = value === null || value === undefined || value === '' ? '0' : String(value).replace(/,/g, '')
  const [intPart = '0', fraction = ''] = raw.split('.')
  if (decimals <= 0) return intPart
  return `${intPart}.${fraction.slice(0, decimals).padEnd(decimals, '0')}`
}

export function recordPageCount(total: number, perPage: number): number {
  if (perPage <= 0) return 1
  return Math.max(1, Math.ceil(total / perPage))
}

/** 前端分頁（投注紀錄全部期間把三天合併後自己切頁）。 */
export function paginateRecords<T>(list: readonly T[], page: number, perPage: number): T[] {
  const start = (Math.max(1, page) - 1) * perPage
  return list.slice(start, start + perPage)
}

/** 詳細頁用 id 在已抓回的清單裡找；後端 id 有時是數字有時是字串，一律轉字串比。 */
export function findRecordById<T extends Record<string, unknown>>(list: readonly T[], key: keyof T & string, id: string | number): T | undefined {
  const target = String(id)
  return list.find(record => String(record[key]) === target)
}

export function routeParamString(value: string | string[] | undefined | null): string {
  if (Array.isArray(value)) return value[0] ?? ''
  return value ?? ''
}

export type RecordsResult<T>
  = | { ok: true, data: T }
    | { ok: false, code: number, message: string }

/** 200 才算成功；其餘（含 3 天限制的 400）把後端訊息帶回畫面。 */
export function resolveRecordsResult<T>(res: ApiEnvelope<T>): RecordsResult<T> {
  if (res.code === API_CODE.OK) return { ok: true, data: res.data }
  return { ok: false, code: res.code, message: res.message }
}

export type ApiPost = <K extends EndpointKey>(route: K, body: RequestOf<K>) => Promise<ApiEnvelope<ResponseOf<K>>>

/**
 * 其他領域尚未登記的路由（prize/list、member/vendors）先以字串呼叫；
 * 待該領域登記後改回 post('<route>') 直接拿型別。
 */
export function postUnregisteredRoute<T>(post: ApiPost, route: string, body: Record<string, unknown>): Promise<ApiEnvelope<T | null>> {
  return post(route as EndpointKey, body as never) as unknown as Promise<ApiEnvelope<T | null>>
}

export type TotalValidBetItem = ResponseOf<'member/total-valid-bets'>['items'][number]
export type TotalValidBetsPost = (body: RequestOf<'member/total-valid-bets'>) => Promise<ApiEnvelope<ResponseOf<'member/total-valid-bets'>>>

/** 全部期間：逐日各打一次（per_page 1000）、照日期順序串起來；任一天失敗就回那次的錯誤。 */
export async function fetchBetsForDays(post: TotalValidBetsPost, days: readonly string[]): Promise<RecordsResult<TotalValidBetItem[]>> {
  const merged: TotalValidBetItem[] = []
  for (const day of days) {
    const res = await post({ search_day: day, page: 1, per_page: BET_RECORD_ALL_PERIOD_PER_PAGE })
    const result = resolveRecordsResult(res)
    if (!result.ok) return result
    merged.push(...result.data.items)
  }
  return { ok: true, data: merged }
}
