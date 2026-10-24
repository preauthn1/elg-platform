import type {
  CommissionRow,
  SystemNotifyItem,
} from '#shared/api/endpoints/messages'
import { decFloorFixed, toAmountLike } from './amount'

/**
 * messages 領域的純函式：標籤對照、狀態正規化、分頁與詳情回應整理。
 * 不碰 i18n 與站台設定，需要文字的地方回傳描述子，由 composable 解成字串。
 */

/** 文字描述子：i18n key、固定字串、或站台設定欄位。 */
export type NotifyLabel
  = | { kind: 'i18n', key: string }
    | { kind: 'text', text: string }
    | { kind: 'setting', key: 'event_jackpot_title' }
    | null

export interface NotifyRow {
  labelKey: string
  value: string
}

export type NotifyParty
  = | { kind: 'text', text: string }
    | { kind: 'logo' }
    | null

export interface NormalizedPagination {
  total: number
  perPage: number
  pages: number
  current: number
}

export type PlatformAnnouncementAction
  = | { kind: 'none' }
    | { kind: 'popup' }
    | { kind: 'external', url: string, newWindow: boolean }
    | { kind: 'route', path: string }

export type SystemNotifyStatus = 'pending' | 'fail' | 'success'

export type SystemNotifyDetailKind = 'commission' | 'transfer'

export interface CommissionSum {
  bet: number
  lose: number
  rebate: number
}

export type SystemNotifyDetail
  = | { kind: 'notFound' }
    | {
      kind: 'commission'
      items: CommissionRow[]
      pagination: NormalizedPagination
      sum_data: CommissionSum
      time: string
    }
    | {
      kind: 'transfer'
      sender: string
      receiver: string
      amount: number
      tax: number
      time: string
    }

const i18n = (key: string): NotifyLabel => ({ kind: 'i18n', key })
const text = (value: string): NotifyLabel => ({ kind: 'text', text: value })

function toNumber(value: unknown, fallback: number): number {
  if (value === null || value === undefined || value === '') return fallback
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

/** 金額顯示：截斷到兩位小數（不四捨五入）並補零，空值顯示 0.00。實作在 amount.ts。 */
export function formatFixed2(value: unknown): string {
  return decFloorFixed(toAmountLike(value), 2)
}

/** 群發公告：沒資料時後端回 []，統一收成 null。 */
export function resolveAnnouncementMessage<T>(data: T | unknown[] | null | undefined): T | null {
  if (data === null || data === undefined) return null
  if (Array.isArray(data)) return (data[0] as T | undefined) ?? null
  return data
}

/** apis OnlyPaginateResource（per_page/total/pages/current）與 Laravel 原生鍵都吃。 */
export function readPagination(pagination: unknown): NormalizedPagination {
  const p = (pagination && typeof pagination === 'object' ? pagination : {}) as Record<string, unknown>
  const total = Math.max(0, toNumber(p.total, 0))
  const perPage = Math.max(1, toNumber(p.per_page, 10))
  const pages = Math.max(1, toNumber(p.pages ?? p.last_page, Math.ceil(total / perPage)))
  const current = Math.max(1, toNumber(p.current ?? p.current_page, 1))
  return { total, perPage, pages, current }
}

/** 平台公告 tag → i18n key（沿用舊前台對照，非後端 docblock）。 */
export function platformAnnouncementTagKey(tag: unknown): string {
  switch (toNumber(tag, 0)) {
    case 1: return 'messages.enum.notifyType.latest'
    case 2: return 'games.lobby.nav.popular'
    case 3: return 'site.nav.nav.events'
    case 4: return 'games.common.label.game'
    case 5: return 'common.ui.label.maintenance'
    default: return ''
  }
}

export interface PlatformAnnouncementFilter {
  isP2: boolean
  tagFilter?: number | null
}

/** P2：最新消息藏 tag 3、活動消息只留 tag 3；非 P2 不過濾。 */
export function filterPlatformAnnouncements<T extends { tag: number }>(
  items: T[],
  filter: PlatformAnnouncementFilter,
): T[] {
  if (filter.tagFilter !== null && filter.tagFilter !== undefined) {
    return items.filter(item => item.tag === filter.tagFilter)
  }
  if (filter.isP2) return items.filter(item => item.tag !== 3)
  return items
}

/** 客端過濾後的總數：活動分頁 = 本頁過濾後長度；P2 最新消息 = total − 本頁 tag 3 筆數。 */
export function platformAnnouncementFilteredTotal<T extends { tag: number }>(
  items: T[],
  total: number,
  filter: PlatformAnnouncementFilter,
): number {
  if (filter.tagFilter !== null && filter.tagFilter !== undefined) {
    return items.filter(item => item.tag === filter.tagFilter).length
  }
  if (filter.isP2) {
    return Math.max(0, total - items.filter(item => item.tag === 3).length)
  }
  return total
}

/** 列表卡片內文：只有彈窗型（type 1）顯示 content。 */
export function platformAnnouncementListContent(item: { type: number, content?: string | null }): string {
  return item.type === 1 ? item.content ?? '' : ''
}

export function platformAnnouncementAction(
  item: { type: number, content?: string | null, open_window?: number | null },
): PlatformAnnouncementAction {
  switch (item.type) {
    case 1:
      return { kind: 'popup' }
    case 2:
      return { kind: 'external', url: item.content ?? '', newWindow: item.open_window === 1 }
    case 3:
      return { kind: 'route', path: item.content || '/' }
    default:
      return { kind: 'none' }
  }
}

/** 狀態同時接受字串（新 apis）與 0/2/3（舊 apis）。 */
export function systemNotifyStatus(status: unknown): SystemNotifyStatus | null {
  if (status === 'pending' || status === 0 || status === '0') return 'pending'
  if (status === 'fail' || status === 2 || status === '2') return 'fail'
  if (status === 'success' || status === 3 || status === '3') return 'success'
  return null
}

export function systemNotifyStatusKey(status: unknown): string {
  switch (systemNotifyStatus(status)) {
    case 'pending': return 'records.enum.orderStatus.pending'
    case 'fail': return 'common.misc.label.fail'
    case 'success': return 'common.ui.msg.success'
    default: return ''
  }
}

/** 幣別文字取哪個站台設定：禮碼用 text_rolling_chip，其餘 text_wallet_point。 */
export function systemNotifyCurrencySetting(item: { data_type: number }): 'text_rolling_chip' | 'text_wallet_point' {
  return item.data_type === 1 ? 'text_rolling_chip' : 'text_wallet_point'
}

/** tag → i18n key；回 null 時改顯示幣別名稱。 */
export function systemNotifyTagKey(item: { tag: string }): string | null {
  switch (item.tag) {
    case 'orders': return 'messages.enum.notifyType.orders'
    case 'reward': return 'messages.enum.notifyType.reward'
    case 'system': return 'messages.enum.notifyType.system'
    case 'transaction': return 'messages.enum.notifyType.transaction'
    default: return null
  }
}

/** 內文：綁定／實名直接顯示 content，其餘「幣別 金額」。 */
export function systemNotifyContent(
  item: { data_type: number, content?: string | null, point?: number | null },
  currencyName: string,
): string {
  if (item.data_type === 2) return item.content ?? ''
  return `${currencyName} ${formatFixed2(item.point)}`
}

const ORDER_TYPE_LABELS: Record<string, NotifyLabel> = {
  1: i18n('withdrawal.entry.title.withdrawal'),
  2: i18n('deposit.common.title.deposit'),
}

const ROLLING_CHIP_TYPE_LABELS: Record<string, NotifyLabel> = {
  1: i18n('events.detail.label.reward'),
  4: i18n('messages.enum.notifyType.commission'),
  5: i18n('records.enum.transactionType.systemGift'),
  6: i18n('records.enum.transactionType.bettingEvent'),
  7: i18n('records.enum.transactionType.loginEvent'),
  8: i18n('records.enum.transactionType.reclaimLoginReward'),
  9: i18n('events.depositReward.title.firstDeposit'),
  10: i18n('records.enum.transactionType.registrationReward'),
  11: i18n('records.enum.transactionType.bindPhoneReward'),
  12: i18n('records.enum.transactionType.dailyDepositReward'),
  13: i18n('events.invite.label.reward'),
  14: text('W-Jackpot'),
  15: text('W-Jackpot 6D'),
  16: i18n('competitions.ranking.nav.leaderboard'),
  17: i18n('records.enum.transactionType.lossRebate'),
  18: { kind: 'setting', key: 'event_jackpot_title' },
  19: i18n('records.enum.transactionType.vipReward'),
  20: i18n('records.enum.transactionType.depositReward'),
  23: i18n('wallet.exchange.title.coupon'),
  25: i18n('records.enum.transactionType.missionBonus'),
}

const BINDING_TYPE_LABELS: Record<string, NotifyLabel> = {
  1: i18n('member.bindPhone.title.changePhoneNumber'),
  2: i18n('member.bindEmail.title.changeEmail'),
  3: i18n('member.bindPhone.title.bindPhone'),
  4: i18n('member.bindEmail.title.bindEmail'),
  5: i18n('member.password.title.changePassword'),
  6: i18n('member.paymentPassword.title.changePaymentPassword'),
  7: i18n('member.profile.action.setBirthday'),
}

const CASH_TYPE_LABELS: Record<string, NotifyLabel> = {
  4: i18n('records.enum.transactionType.systemPointsIn'),
  5: i18n('records.enum.transactionType.systemWithdrawal'),
  6: i18n('records.enum.transactionType.redEnvelope'),
  7: i18n('records.enum.transactionType.redEnvelopeReceived'),
  8: i18n('records.enum.transactionType.revokeRedEnvelope'),
  9: i18n('records.enum.transactionType.returnRedEnvelope'),
  10: i18n('records.enum.transactionType.systemPointsGiven'),
  13: i18n('events.detail.label.reward'),
  14: i18n('records.enum.transactionType.systemPointsAdjustment'),
  15: i18n('records.enum.transactionType.loginEvent'),
  16: i18n('records.enum.transactionType.loginReward'),
  17: i18n('messages.enum.notifyType.commission'),
  18: text('V-Jackpot'),
  19: i18n('events.depositReward.title.firstDeposit'),
  20: i18n('records.enum.transactionType.registrationReward'),
  21: i18n('records.enum.transactionType.bindPhoneReward'),
  24: i18n('records.enum.transactionType.depositReward'),
  25: i18n('records.enum.transactionType.dailyDepositReward'),
  26: i18n('events.invite.label.reward'),
  30: text('W-Jackpot'),
  31: text('W-Jackpot 6D'),
  32: i18n('records.enum.transactionType.vipReward'),
  33: i18n('records.enum.transactionType.accountAdjustment'),
  34: i18n('records.enum.transactionType.mainToGameWallet'),
  35: i18n('wallet.transfer.title.gameToMain'),
  37: i18n('records.enum.transactionType.memberTransferOut'),
  38: i18n('records.enum.transactionType.memberTransferIn'),
  101: i18n('competitions.ranking.nav.leaderboard'),
  102: i18n('records.enum.transactionType.lossRebate'),
  103: text('Jackpot'),
  104: i18n('wallet.overview.label.promotionWallet'),
  105: i18n('records.enum.transactionType.missionBonus'),
  107: i18n('wallet.exchange.title.coupon'),
  109: i18n('records.enum.transactionType.missionBonus'),
}

/** 通知的動作名稱：訂單看 type 1/2，其餘依 data_type 0 現金／1 禮碼／2 綁定 查表。 */
export function systemNotifyTypeLabel(
  item: { tag: string, type: number | string, data_type: number },
): NotifyLabel {
  const type = String(item.type)
  if (item.tag === 'orders') return ORDER_TYPE_LABELS[type] ?? null
  if (item.data_type === 1) return ROLLING_CHIP_TYPE_LABELS[type] ?? null
  if (item.data_type === 2) return BINDING_TYPE_LABELS[type] ?? null
  return CASH_TYPE_LABELS[type] ?? null
}

/** 只有傭金（transaction_logs/17、rolling_chip_logs/4）與轉帳（transaction_logs/37、38）要打 detail。 */
export function systemNotifyDetailKind(
  item: { data_name: string, type: number | string },
): SystemNotifyDetailKind | null {
  const type = toNumber(item.type, Number.NaN)
  if (item.data_name === 'transaction_logs') {
    if (type === 17) return 'commission'
    if (type === 37 || type === 38) return 'transfer'
    return null
  }
  if (item.data_name === 'rolling_chip_logs' && type === 4) return 'commission'
  return null
}

/** detail 回應整理：找不到回 []；rolling_chip_logs/4 多包一層 data 要先拆。 */
export function unwrapSystemNotifyDetail(data: unknown): SystemNotifyDetail {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return { kind: 'notFound' }
  const outer = data as Record<string, unknown>
  const nested = outer.data
  const inner = (nested && typeof nested === 'object' && !Array.isArray(nested) ? nested : outer) as Record<string, unknown>

  if (Array.isArray(inner.items)) {
    const sum = (inner.sum_data && typeof inner.sum_data === 'object' ? inner.sum_data : {}) as Record<string, unknown>
    return {
      kind: 'commission',
      items: inner.items as CommissionRow[],
      pagination: readPagination(inner.pagination),
      sum_data: {
        bet: toNumber(sum.bet, 0),
        lose: toNumber(sum.lose, 0),
        rebate: toNumber(sum.rebate, 0),
      },
      time: typeof inner.time === 'string' ? inner.time : '',
    }
  }

  if ('sender' in inner || 'receiver' in inner) {
    return {
      kind: 'transfer',
      sender: typeof inner.sender === 'string' ? inner.sender : '',
      receiver: typeof inner.receiver === 'string' ? inner.receiver : '',
      amount: toNumber(inner.amount, 0),
      tax: toNumber(inner.tax, 0),
      time: typeof inner.time === 'string' ? inner.time : '',
    }
  }

  return { kind: 'notFound' }
}

/** 傭金摘要列：流水 > 0 才顯示、輸分 > 0 才顯示、獎勵一律顯示。 */
export function commissionSummaryRows(sum: CommissionSum, currencyName: string): NotifyRow[] {
  const rows: NotifyRow[] = []
  if (sum.bet > 0) rows.push({ labelKey: 'records.bet.label.turnover', value: `${currencyName} ${formatFixed2(sum.bet)}` })
  if (sum.lose > 0) rows.push({ labelKey: 'messages.detail.label.lose', value: `${currencyName} ${formatFixed2(sum.lose)}` })
  rows.push({ labelKey: 'common.amount.label.bonus', value: `${currencyName} ${formatFixed2(sum.rebate)}` })
  return rows
}

/** 有任何一筆依輸分計算或輸分合計 > 0 才需要「輸分」欄。 */
export function hasLoseColumn(rows: CommissionRow[], sum: CommissionSum): boolean {
  return sum.lose > 0 || rows.some(row => row.rebate_type === 2)
}

/** 表格欄：流水只在 rebate_type 1 顯示、輸分只在 rebate_type 2 顯示，否則「-」。 */
export function commissionCell(row: CommissionRow, field: 'bet' | 'lose'): string {
  const expected = field === 'bet' ? 1 : 2
  if (row.rebate_type !== expected) return '-'
  return formatFixed2(row[field])
}

export interface SystemNotifyHeader {
  statusKey: string
  actionName: NotifyLabel
  content: string
  rows: NotifyRow[]
  payer: NotifyParty
  payee: NotifyParty
}

/** 詳情頁表頭全由列表項目組出來；轉帳的付款／收款人另由 detail 覆寫。 */
export function buildSystemNotifyHeader(
  item: SystemNotifyItem,
  ctx: { username: string, currencyName: string },
): SystemNotifyHeader {
  const rows: NotifyRow[] = [{ labelKey: 'common.time.label.date', value: item.time }]
  let payer: NotifyParty = null
  let payee: NotifyParty = null
  let actionName: NotifyLabel

  if (item.tag === 'orders') {
    actionName = text(item.content ?? '')
    rows.push({ labelKey: 'common.amount.label.amount', value: `${item.currency_name ?? ''} ${formatFixed2(item.amount)}` })
    const type = toNumber(item.type, 0)
    if (type === 1) {
      payer = { kind: 'logo' }
      payee = { kind: 'text', text: ctx.username }
      rows.push({ labelKey: 'common.bank.label.accountNo', value: item.content_1 ?? '' })
      rows.push({ labelKey: 'common.bank.label.accountName', value: item.content_2 ?? '' })
    }
    else if (type === 2) {
      payer = { kind: 'text', text: ctx.username }
      payee = { kind: 'logo' }
    }
  }
  else {
    actionName = systemNotifyTypeLabel(item)
  }

  return {
    statusKey: systemNotifyStatusKey(item.status),
    actionName,
    content: systemNotifyContent(item, ctx.currencyName),
    rows,
    payer,
    payee,
  }
}

export function sumUnread(...counts: Array<number | null | undefined>): number {
  return counts.reduce<number>((sum, n) => sum + Math.max(0, toNumber(n, 0)), 0)
}

/** 鈴鐺徽章：0 不顯示、超過 99 顯示 99+。 */
export function unreadBadgeText(total: number): string {
  if (total <= 0) return ''
  return total > 99 ? '99+' : String(total)
}

/** 通知中心路由不輪詢未讀數（各分頁進入時自行歸零）。 */
export function isMessagesRoute(path: string): boolean {
  return path === '/messages' || path.startsWith('/messages/')
}

/** 分頁快速切換時只採用最後一次請求的回應。 */
export function createLatestGuard() {
  let seq = 0
  return {
    next: () => ++seq,
    isLatest: (id: number) => id === seq,
  }
}
