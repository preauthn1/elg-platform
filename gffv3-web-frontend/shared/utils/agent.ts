import { decFormat } from '#shared/utils/amount'
/**
 * 代理領域的純函式：模式判斷、分享連結、提領上限、規則排序、分頁正規化。
 * 不碰 Nuxt 自動匯入，composable 與測試都直接 import。
 */

export type AgentMode = 'unlimit' | 'three'

/** agent_mode_setting：2/3/4 走三級分銷，其餘（含 1、空值）走無限級。 */
export function resolveAgentMode(value: unknown): AgentMode {
  const n = Number(value)
  return n === 2 || n === 3 || n === 4 ? 'three' : 'unlimit'
}

/** 三級分銷中 3/4 兩種模式才有輸分返利欄位。 */
export function hasLoseRebate(value: unknown): boolean {
  const n = Number(value)
  return n === 3 || n === 4
}

/** 後端金額欄位有時是字串（"12.00"）有時是數字，統一轉成有限數字，轉不了回 0。 */
export function toNumber(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0
  if (typeof value === 'string' && value.trim() !== '') {
    const n = Number(value)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}

// ---- 代理總覽（unlimit-hierarchy / three-hierarchy） ----------------------------------------------

export interface HierarchySummary {
  mode: AgentMode
  topUsername: string
  username: string
  /** 三級分銷沒有這個欄位 */
  teamCount: number | null
  directPlayerCount: number
  /** 無限級沒有這個欄位 */
  doubleDirectPlayerCount: number | null
  addedToday: number
  activeToday: number
  code: string
  link: string
}

export function normalizeHierarchySummary(raw: Record<string, unknown> | null | undefined, mode: AgentMode): HierarchySummary {
  const r = raw ?? {}
  return {
    mode,
    topUsername: typeof r.topUsername === 'string' ? r.topUsername : '',
    username: typeof r.username === 'string' ? r.username : '',
    teamCount: mode === 'unlimit' ? toNumber(r.teamCount) : null,
    directPlayerCount: toNumber(r.directPlayerCount),
    doubleDirectPlayerCount: mode === 'three' ? toNumber(r.doubleDirectPlayerCount) : null,
    addedToday: toNumber(r.addedToday),
    activeToday: toNumber(r.activeToday),
    code: r.code == null ? '' : String(r.code),
    link: typeof r.link === 'string' ? r.link : '',
  }
}

export function buildSignupLink(origin: string, code: string): string {
  return `${origin.replace(/\/+$/, '')}/Signup/${code}`
}

export function buildTgStartappLink(botUsername: string, code: string): string {
  return `https://example.invalid/${botUsername}/startapp?startapp=${code}`
}

export function buildTgQrLink(origin: string, tgLink: string): string {
  return `${origin.replace(/\/+$/, '')}/?tg_redirect=${tgLink}`
}

export function buildTgShareUrl(link: string, text: string): string {
  return `https://example.invalid/share/url?url=${encodeURIComponent(link)}&text=${encodeURIComponent(text)}`
}

export interface ShareLinkInput {
  /** 後端組好的完整推薦網址 */
  link: string
  code: string
  /** 瀏覽器 origin；SSR 或拿不到時給 null */
  origin?: string | null
  /** 開發環境改用 origin/Signup/code（舊前台白牌/開發環境行為） */
  preferOrigin?: boolean
  /** TG 使用者帶 bot 帳號，分享連結改成 example.invalid startapp */
  tgBotUsername?: string | null
}

export interface ShareLinks {
  /** 複製／分享用 */
  copyLink: string
  /** 產 QR 用；TG 使用者是 origin/?tg_redirect=… */
  qrLink: string
}

export function buildShareLinks(input: ShareLinkInput): ShareLinks {
  const base = input.preferOrigin && input.origin ? buildSignupLink(input.origin, input.code) : input.link
  if (input.tgBotUsername) {
    const tg = buildTgStartappLink(input.tgBotUsername, input.code)
    return { copyLink: tg, qrLink: input.origin ? buildTgQrLink(input.origin, tg) : tg }
  }
  return { copyLink: base, qrLink: base }
}

// ---- 提領（withdrawal/record + withdrawals，create 前端上限） ----------------------------------------

export interface WithdrawalSummary {
  username: string
  totalRewardPoints: number
  withdrawnPoints: number
  withdrawablePoints: number
  /** 三級分銷端點沒有以下三欄，一律補 0 */
  flowAmount: number
  itselfWithdrawnPoints: number
  itselfWithdrawablePoints: number
}

export function normalizeWithdrawalSummary(raw: Record<string, unknown> | null | undefined): WithdrawalSummary {
  const r = raw ?? {}
  return {
    username: typeof r.username === 'string' ? r.username : '',
    totalRewardPoints: toNumber(r.totalRewardPoints),
    withdrawnPoints: toNumber(r.withdrawnPoints),
    withdrawablePoints: toNumber(r.withdrawablePoints),
    flowAmount: toNumber(r.flowAmount),
    itselfWithdrawnPoints: toNumber(r.itselfWithdrawnPoints),
    itselfWithdrawablePoints: toNumber(r.itselfWithdrawablePoints),
  }
}

/** 可提領上限：無限級且開自身回水 = 可提 + 自身可提；其餘只算可提。 */
export function calcWithdrawableLimit(summary: WithdrawalSummary, opts: { mode: AgentMode, itselfRebate: boolean }): number {
  if (opts.mode === 'unlimit' && opts.itselfRebate) {
    return summary.withdrawablePoints + summary.itselfWithdrawablePoints
  }
  return summary.withdrawablePoints
}

export type WithdrawAmountError = 'required' | 'min' | 'format' | 'exceed'

/** 舊前台輸入規則 required|max:12|min_value:0.01|decimal:4，再加前端擋可提領上限。 */
export function validateWithdrawAmount(input: unknown, limit: number): WithdrawAmountError | null {
  const text = String(input ?? '').trim()
  if (!text) return 'required'
  if (text.length > 12 || !/^\d+(\.\d{1,4})?$/.test(text)) return 'format'
  const n = Number(text)
  if (!Number.isFinite(n) || n < 0.01) return 'min'
  if (n > limit) return 'exceed'
  return null
}

export interface WithdrawalHistoryItem {
  date: string
  no: number | string
  amount: number
  /** 狀態文字（已派發／未派發） */
  status: string
  /** 提領方式文字（積分／洗碼量） */
  withdrawType: string
}

export function normalizeWithdrawalHistory(items: ReadonlyArray<Record<string, unknown>>): WithdrawalHistoryItem[] {
  return items.map(item => ({
    date: typeof item.date === 'string' ? item.date : '',
    no: typeof item.no === 'number' || typeof item.no === 'string' ? item.no : '',
    amount: toNumber(item.amount),
    status: typeof item.type === 'string' ? item.type : '',
    withdrawType: typeof item.withdrawType === 'string' ? item.withdrawType : '',
  }))
}

// ---- 無限級規則頁（list + vendor/rate-list） --------------------------------------------------------

/** 最高一級 max 為 0/空 → 顯示「無上限」文字（呼叫端傳 Common_None 的翻譯）。 */
export function ruleLevelMaxText(max: unknown, noneText: string): string {
  const n = toNumber(max)
  return n <= 0 ? noneText : decFormat(n, 0)
}

export function formatRebateRate(rate: unknown): string {
  return `${rate == null ? '' : String(rate)}%`
}

export type VendorRateNote = { kind: 'sales', rate: number, sales: number } | { kind: 'none' }

/** 規則頁註釋：倍率 > 0 顯示 10000 × 倍率 的業績範例，否則顯示「不計算業績」。 */
export function vendorRateNote(rate: unknown): VendorRateNote {
  const r = toNumber(rate)
  return r > 0 ? { kind: 'sales', rate: r, sales: 10000 * r } : { kind: 'none' }
}

// ---- 我的推廣（direct-players / reward / three achievements / rebates） ---------------------------

/** Resource 只給 memberType（2=代理），型別文字前端翻譯。 */
export function memberTypeKind(memberType: unknown): 'agent' | 'member' {
  return Number(memberType) === 2 ? 'agent' : 'member'
}

/** 「我的流水」分頁只顯示 flow 或 itself_reward 大於 0 的列。 */
export function filterItselfRewardRows<T extends { flow?: unknown, itself_reward?: unknown }>(rows: ReadonlyArray<T>): T[] {
  return rows.filter(row => toNumber(row.flow) > 0 || toNumber(row.itself_reward) > 0)
}

/** three-hierarchy/achievements 的 rebate_type：1 投注返利、2 輸分返利。 */
export function awardTypeKey(type: unknown): 'agent.rebate.label.bettingRebate' | 'agent.rebate.label.lostRebate' | '' {
  const n = Number(type)
  if (n === 1) return 'agent.rebate.label.bettingRebate'
  if (n === 2) return 'agent.rebate.label.lostRebate'
  return ''
}

// ---- 三級分銷返利規則（three-hierarchy/rule） ---------------------------------------------------------

export interface RuleItem {
  vendor_id: number
  vendor_name: string
  vendor_image: string
  game_type_name: string
  game_type_id: number
  type: number
  proportion_1: string | number
  proportion_2: string | number
  proportion_3: string | number
}

export function uniqueGameTypes(items: ReadonlyArray<RuleItem>): string[] {
  return Array.from(new Set(items.map(item => item.game_type_name)))
}

/** 同一遊戲類型內依自身比例倒序，NaN 排最後。 */
export function ruleItemsForType(items: ReadonlyArray<RuleItem>, gameType: string | null): RuleItem[] {
  return items
    .filter(item => item.game_type_name === gameType)
    .slice()
    .sort((a, b) => {
      const pa = Number.parseFloat(String(a.proportion_1))
      const pb = Number.parseFloat(String(b.proportion_1))
      if (Number.isNaN(pa) && Number.isNaN(pb)) return 0
      if (Number.isNaN(pa)) return 1
      if (Number.isNaN(pb)) return -1
      return pb - pa
    })
}

export function ruleItemKey(item: RuleItem): string {
  return `${item.game_type_name}${item.type}${item.vendor_id}`
}

export function formatProportion(value: unknown): string {
  const n = Number.parseFloat(String(value))
  return `${Number.isNaN(n) ? 0 : n}%`
}

// ---- 會員團隊（member-team/*） --------------------------------------------------------------------

/** 業績查詢的兩期：type 1 = 昨日、2 = 前天（後端以 14:00 為結算界）。 */
export const ACHIEVEMENT_PERIODS = [
  { type: 1, labelKey: 'common.time.option.yesterday' },
  { type: 2, labelKey: 'common.time.option.dayBeforeYesterday' },
] as const

export type AchievementPeriodType = (typeof ACHIEVEMENT_PERIODS)[number]['type']

export interface TeamManagementResult {
  username: string
  achievements: number
}

/** 查不到直屬會員時後端回 code 200 但 data 是 []，一律視為查無。 */
export function teamManagementResult(data: unknown): TeamManagementResult | null {
  if (!data || Array.isArray(data) || typeof data !== 'object') return null
  const r = data as Record<string, unknown>
  return {
    username: typeof r.username === 'string' ? r.username : '',
    achievements: toNumber(r.achievements),
  }
}

// ---- 股東（shareholder/*） ----------------------------------------------------------------------

export type ShareholderApplyStatus = 0 | 1 | 2

/** apis 新版回應沒有 apply_status，缺失時視為 0（可申請），與舊前台一致。 */
export function resolveApplyStatus(data: unknown): ShareholderApplyStatus {
  const status = data && typeof data === 'object' ? Number((data as Record<string, unknown>).apply_status) : 0
  return status === 1 || status === 2 ? status : 0
}

export function applyButtonKey(status: ShareholderApplyStatus): 'agent.shareholder.action.apply' | 'agent.shareholder.action.approved' | 'common.misc.label.pendingApproval' {
  if (status === 1) return 'agent.shareholder.action.approved'
  if (status === 2) return 'common.misc.label.pendingApproval'
  return 'agent.shareholder.action.apply'
}

/** 與 apis App\Rules\Username 同一條：只能英數，且至少一個英文字母。 */
export const SHAREHOLDER_USERNAME_PATTERN = /^[A-Za-z0-9]*[A-Za-z]+[A-Za-z0-9]*$/

export interface ShareholderApplyForm {
  username: string
  password: [REDACTED]
  password_confirmation: string
}

export type ShareholderFormError = 'username' | 'password' | 'inconsistentPassword'

export function validateShareholderForm(form: ShareholderApplyForm): ShareholderFormError | null {
  const username = form.username.trim()
  if (username.length < 4 || username.length > 20 || !SHAREHOLDER_USERNAME_PATTERN.test(username)) return 'username'
  if (form.password.length < 4 || form.password.length > 15) return 'password'
  if (form.password !== form.password_confirmation) return 'inconsistentPassword'
  return null
}

// ---- 分頁（apis successWithPagination：per_page,total,pages,previous,current,next） --------------

export interface PageState {
  current: number
  pages: number
  total: number
  perPage: number
  hasPrev: boolean
  hasNext: boolean
}

export function emptyPageState(perPage = 10): PageState {
  return { current: 1, pages: 1, total: 0, perPage, hasPrev: false, hasNext: false }
}

export function normalizePagination(raw: Record<string, unknown> | null | undefined, fallback: { page: number, perPage: number }): PageState {
  const r = raw ?? {}
  const current = toNumber(r.current ?? r.current_page) || fallback.page
  const pages = Math.max(1, toNumber(r.pages ?? r.last_page) || 1)
  const perPage = toNumber(r.per_page) || fallback.perPage
  return {
    current,
    pages,
    total: toNumber(r.total),
    perPage,
    hasPrev: current > 1,
    hasNext: current < pages,
  }
}

// ---- TG 使用者分享（tg-bot/get-brand-by-domain 的第一筆） ----------------------------------------------

export interface AgentTgBot {
  botUsername: string
  /** tg_share_text，沒有就空字串 */
  shareText: string
}

/** 沒有 bot_username 視為找不到 bot（頁面顯示 Can't find bot）。 */
export function tgBotFromBrand(brand: Record<string, unknown> | null | undefined): AgentTgBot | null {
  const botUsername = brand?.bot_username == null ? '' : String(brand.bot_username).trim()
  if (!botUsername) return null
  return {
    botUsername,
    shareText: typeof brand?.tg_share_text === 'string' ? brand.tg_share_text : '',
  }
}
