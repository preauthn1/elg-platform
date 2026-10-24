import type { ApiEnvelope } from '#shared/types/api'
import type {
  AngpowInfo,
  CheckinPrize,
  CheckinRecord,
  DrawnPrize,
  EventInviteSetting,
  EventListItem,
  EventV2Detail,
  EventV2JoinDetail,
  LegacyEventDetailRow,
  LegacyEventRow,
  Mission,
  NewbieChallengeProgress,
  PrizeContent,
} from '#shared/api/endpoints/events-core'
import { decCmp, decDiv, decFloorFixed, decFormat, decIsPositive, decToNumber, toAmountLike } from '#shared/utils/amount'
import { toAmount } from '#shared/utils/vip'

// ------------------------------------------------------------------ event/list

/** 第三方活動代號 → 頁面。 */
export const TP_EVENT_PATHS: Record<number, string> = {
  1: '/newbie-challenge',
  2: '/events/invite-bonus',
  3: '/progressive-jackpot',
}

export const EVENT_V1_PATH = '/events'
export const EVENT_V2_PATH = '/events/platform-event'
export const V_JACKPOT_PATH = '/events/v-jackpot'
export const ANGPOW_PATH = '/events/red-packet'
/** event/list 的 handler_class：紅包活動走專屬畫面 */
export const ANGPOW_HANDLER = 'AngpowService'

/** 活動卡導頁；banner 模式下 params.type 4/5 先導 V-Jackpot。 */
export function resolveEventListPath(item: EventListItem, opts: { banner?: boolean } = {}): string | null {
  if (opts.banner) {
    const type = item.params?.type
    if (type === 4 || type === 5) return V_JACKPOT_PATH
  }
  if (item.tp_event != null) {
    const tp = TP_EVENT_PATHS[Number(item.tp_event)]
    if (tp) return tp
  }
  if (item.event_v1_id != null) return `${EVENT_V1_PATH}?ids=${item.event_v1_id}`
  if (item.event_v2_id != null && item.handler_class === ANGPOW_HANDLER) return `${ANGPOW_PATH}?ids=${item.event_v2_id}`
  if (item.event_v2_id != null) return `${EVENT_V2_PATH}?ids=${item.event_v2_id}`
  return null
}

/**
 * 站上的紅包活動 id（event_v2_id）。工單規定同一時間只有一檔，列表有多檔時取第一檔（後端已排序）。
 * 給沒帶 ?ids= 的入口用：活動彈窗的紅包晶片、直接開 /events/red-packet。
 */
export function findAngpowEventId(list: readonly EventListItem[]): number | null {
  const item = list.find(row => row.handler_class === ANGPOW_HANDLER && row.event_v2_id != null)
  return item ? Number(item.event_v2_id) : null
}

export function isOtherEvent(item: EventListItem): boolean {
  return !!item.params && item.params.category === 'other'
}

export interface EventBanner {
  image: string
  title: string
  path: string
}

/** 首頁 banner（bannerType=1）：只取有圖片且導得出頁面的項目。 */
export function eventBanners(list: EventListItem[]): EventBanner[] {
  const out: EventBanner[] = []
  for (const item of list) {
    if (!item.image) continue
    const path = resolveEventListPath(item, { banner: true })
    if (!path) continue
    out.push({ image: item.image, title: item.name ?? '', path })
  }
  return out
}

/** 路由守衛：列表 null（請求失敗）不阻擋；tpEvent 可能是 0，不能用 truthy 判斷。 */
export function isTpEventAllowed(list: EventListItem[] | null, tpEvent: number): boolean {
  if (list === null) return true
  return list.some(item => item.tp_event != null && Number(item.tp_event) === Number(tpEvent))
}

// --------------------------------------------------------------- legacy (V1)

export const LEGACY_ACTION_GROUPS = {
  recharge: [51, 52, 53, 54, 55, 56, 58, 59, 61],
  agentDeposit: [60],
  loseRebate: [9],
  invite: [31],
  newInvite: [15],
  inviteRecharge: [16],
  /** 列表不畫進度條的活動（舊皮 EventItem 的 [11, 12, 17]） */
  noProgress: [11, 12, 17],
  /** 說明頁不畫進度條的活動（舊皮 EventDescription 的 [11, 12]，比列表少 17） */
  noProgressDescription: [11, 12],
} as const

export const LEGACY_TYPE_GROUPS = {
  lottery: [4, 5, 6],
  lottery6d: [7],
  wjackpot: [6],
} as const

export const LEGACY_CATEGORIES = {
  invite: 'invite',
  rebate: 'rebate',
  betSlot: 'bet_slot',
  betLive: 'bet_live',
  deposit: 'deposit',
  dailyDeposit: 'daily_deposit',
  newMember: 'new_member',
  cj7Rebate: 'cj7_rebate',
  cj7SlotLossRebate: 'cj7slotlossrebate',
  wttSports: 'sport',
  wttLive: 'wtt_live',
} as const

/** prize type：1 刮刮卡、2 輪盤、3 直接兌獎、8 自選獎項、4/5/6/7 jackpot 系列。 */
export const PRIZE_TYPE = {
  SCRATCH: 1,
  WHEEL: 2,
  DIRECT: 3,
  CHOOSE: 8,
} as const

export type LegacyEventKind
  = | 'lottery6d'
    | 'lottery'
    | 'agentDeposit'
    | 'recharge'
    | 'loseRebate'
    | 'invite'
    | 'newInvite'
    | 'inviteRecharge'
    | 'wttSports'
    | 'wttLive'
    | 'betSlot'
    | 'betLive'
    | 'deposit'
    | 'dailyDeposit'
    | 'newMember'
    | 'cj7Rebate'
    | 'cj7SlotLossRebate'
    | 'general'

export function legacyDetailActionId(event: LegacyEventRow): number | null {
  const id = event.detail?.[0]?.action_id
  return id == null ? null : Number(id)
}

function inGroup(list: readonly number[], value: number | null | undefined): boolean {
  return value != null && list.includes(Number(value))
}

/** 達成條件要不要畫進度條與目前進度；列表與說明頁的排除名單不同。 */
export function legacyShowsProgress(
  event: Pick<LegacyEventRow, 'action_id'>,
  where: 'list' | 'description' = 'list',
): boolean {
  const excluded = where === 'list' ? LEGACY_ACTION_GROUPS.noProgress : LEGACY_ACTION_GROUPS.noProgressDescription
  return !inGroup(excluded, event.action_id)
}

/** 進度條百分比：超過目標停在 100%，目標為 0 時是 0%（舊皮 BaseProgress）。 */
export function legacyProgressPercent(detail: Pick<LegacyEventDetailRow, 'point' | 'now_point'>): number {
  const target = toAmount(detail.point)
  if (target <= 0) return 0
  return Math.min(Math.max(toAmount(detail.now_point) / target, 0), 1) * 100
}

/** 依舊前台 Events 頁的分群順序判定活動卡種類。 */
export function classifyLegacyEvent(event: LegacyEventRow): LegacyEventKind {
  const type = event.type ?? null
  const detailAction = legacyDetailActionId(event)
  const category = event.category ?? ''
  if (inGroup(LEGACY_TYPE_GROUPS.lottery6d, type)) return 'lottery6d'
  if (inGroup(LEGACY_TYPE_GROUPS.lottery, type)) return 'lottery'
  if (inGroup(LEGACY_ACTION_GROUPS.agentDeposit, detailAction)) return 'agentDeposit'
  if (inGroup(LEGACY_ACTION_GROUPS.recharge, detailAction)) return 'recharge'
  if (inGroup(LEGACY_ACTION_GROUPS.loseRebate, event.action_id)) return 'loseRebate'
  if (inGroup(LEGACY_ACTION_GROUPS.newInvite, detailAction)) return 'newInvite'
  if (inGroup(LEGACY_ACTION_GROUPS.inviteRecharge, detailAction)) return 'inviteRecharge'
  if (inGroup(LEGACY_ACTION_GROUPS.invite, detailAction) || category === LEGACY_CATEGORIES.invite) return 'invite'
  if (category === LEGACY_CATEGORIES.wttSports) return 'wttSports'
  if (category === LEGACY_CATEGORIES.wttLive) return 'wttLive'
  if (category === LEGACY_CATEGORIES.betSlot) return 'betSlot'
  if (category === LEGACY_CATEGORIES.betLive) return 'betLive'
  if (category === LEGACY_CATEGORIES.deposit) return 'deposit'
  if (category === LEGACY_CATEGORIES.dailyDeposit) return 'dailyDeposit'
  if (category === LEGACY_CATEGORIES.newMember) return 'newMember'
  if (category === LEGACY_CATEGORIES.cj7Rebate) return 'cj7Rebate'
  if (category === LEGACY_CATEGORIES.cj7SlotLossRebate) return 'cj7SlotLossRebate'
  return 'general'
}

/**
 * 活動卡的種類（決定畫哪一張卡）。多個 classify 出來的類別會共用同一張卡
 * ——舊皮 Events 頁就是這樣配的：deposit／daily_deposit／new_member 走充值卡，
 * cj7slotlossrebate 走輸分回扣卡，體育、真人、cj7 返水這些沒有專屬卡的走通用卡。
 */
export type LegacyCardKind
  = | 'jackpot'
    | 'invite'
    | 'newInvite'
    | 'inviteRecharge'
    | 'agentDeposit'
    | 'recharge'
    | 'loseRebate'
    | 'general'

const CARD_BY_EVENT_KIND: Record<LegacyEventKind, LegacyCardKind> = {
  lottery6d: 'jackpot',
  lottery: 'jackpot',
  invite: 'invite',
  newInvite: 'newInvite',
  inviteRecharge: 'inviteRecharge',
  agentDeposit: 'agentDeposit',
  recharge: 'recharge',
  deposit: 'recharge',
  dailyDeposit: 'recharge',
  newMember: 'recharge',
  loseRebate: 'loseRebate',
  cj7SlotLossRebate: 'loseRebate',
  wttSports: 'general',
  wttLive: 'general',
  betSlot: 'general',
  betLive: 'general',
  cj7Rebate: 'general',
  general: 'general',
}

export function legacyCardKind(event: LegacyEventRow): LegacyCardKind {
  return CARD_BY_EVENT_KIND[classifyLegacyEvent(event)]
}

/** 舊皮 Events 頁一段一段的顯示順序。 */
export const LEGACY_CARD_ORDER: readonly LegacyCardKind[] = [
  'jackpot',
  'invite',
  'newInvite',
  'inviteRecharge',
  'agentDeposit',
  'recharge',
  'loseRebate',
  'general',
]

/** 依卡別重排；同一類之間維持後端給的順序（Array.sort 是穩定的）。 */
export function sortLegacyEvents(events: LegacyEventRow[]): LegacyEventRow[] {
  return [...events].sort(
    (a, b) => LEGACY_CARD_ORDER.indexOf(legacyCardKind(a)) - LEGACY_CARD_ORDER.indexOf(legacyCardKind(b)),
  )
}

export const LEGACY_EVENT_PRESETS = [
  'v-rebate',
  'w-rebate',
  'v-jackpot',
  'w-jackpot',
  'w-jackpot6d',
  'lose-rebate',
  'wtt-sports',
] as const
export type LegacyEventPreset = (typeof LEGACY_EVENT_PRESETS)[number]

export function isLegacyEventPreset(value: unknown): value is LegacyEventPreset {
  return typeof value === 'string' && (LEGACY_EVENT_PRESETS as readonly string[]).includes(value)
}

/** 路由 query.ids（單值或陣列）→ number[]；沒有就 null。 */
export function parseIdsQuery(raw: unknown): number[] | null {
  if (raw == null || raw === '') return null
  const values = Array.isArray(raw) ? raw : [raw]
  const ids = values.map(Number).filter(n => Number.isFinite(n))
  return ids.length ? ids : null
}

export interface LegacyEventFilter {
  ids?: number[] | null
  category?: string | null
  preset?: LegacyEventPreset | null
}

const CATEGORY_QUERY_MAP: Record<string, (event: LegacyEventRow) => boolean> = {
  [LEGACY_CATEGORIES.invite]: e => ['invite', 'newInvite', 'inviteRecharge'].includes(classifyLegacyEvent(e)),
  [LEGACY_CATEGORIES.rebate]: e => classifyLegacyEvent(e) === 'recharge',
  [LEGACY_CATEGORIES.betSlot]: e => e.category === LEGACY_CATEGORIES.betSlot,
  [LEGACY_CATEGORIES.betLive]: e => e.category === LEGACY_CATEGORIES.betLive,
  [LEGACY_CATEGORIES.deposit]: e => e.category === LEGACY_CATEGORIES.deposit,
  [LEGACY_CATEGORIES.dailyDeposit]: e => e.category === LEGACY_CATEGORIES.dailyDeposit,
  [LEGACY_CATEGORIES.newMember]: e => e.category === LEGACY_CATEGORIES.newMember,
  [LEGACY_CATEGORIES.cj7Rebate]: e => e.category === LEGACY_CATEGORIES.cj7Rebate,
  [LEGACY_CATEGORIES.cj7SlotLossRebate]: e => e.category === LEGACY_CATEGORIES.cj7SlotLossRebate,
}

const PRESET_MAP: Record<LegacyEventPreset, (event: LegacyEventRow) => boolean> = {
  'v-rebate': e => classifyLegacyEvent(e) === 'recharge',
  'w-rebate': e => classifyLegacyEvent(e) === 'recharge',
  'v-jackpot': e => inGroup(LEGACY_TYPE_GROUPS.lottery, e.type),
  'w-jackpot': e => inGroup(LEGACY_TYPE_GROUPS.lottery, e.type),
  'w-jackpot6d': e => inGroup(LEGACY_TYPE_GROUPS.lottery6d, e.type),
  'lose-rebate': e => inGroup(LEGACY_ACTION_GROUPS.loseRebate, e.action_id),
  'wtt-sports': e => e.category === LEGACY_CATEGORIES.wttSports,
}

/** Events 頁篩選：ids 優先，其次路徑別名（preset），再來 query.category。 */
export function filterLegacyEvents(events: LegacyEventRow[], filter: LegacyEventFilter = {}): LegacyEventRow[] {
  if (filter.ids && filter.ids.length) {
    const ids = filter.ids
    return events.filter(e => ids.includes(Number(e.id)))
  }
  if (filter.preset) {
    const match = PRESET_MAP[filter.preset]
    return events.filter(match)
  }
  if (filter.category) {
    const match = CATEGORY_QUERY_MAP[filter.category]
    if (match) return events.filter(match)
  }
  return events
}

/** query.ids 對到的活動 banner：image_pc_url 優先。 */
export function legacyBannerImage(events: LegacyEventRow[], ids: number[] | null): string {
  if (!ids || !ids.length) return ''
  const hit = events.find(e => ids.includes(Number(e.id)))
  return hit ? (hit.image_pc_url || hit.image_url || '') : ''
}

export function hasAgentDepositEvent(events: LegacyEventRow[]): boolean {
  return events.some(e => inGroup(LEGACY_ACTION_GROUPS.agentDeposit, legacyDetailActionId(e)))
}

export interface ExchangeButtonState {
  disabled: boolean
  /** i18n key：redeemNow / unaccomplished / redeemed */
  textKey: 'events.claim.action.redeem' | 'events.claim.label.unaccomplished' | 'events.claim.action.redeemed'
  /** type != 2 且 remaining_number > 1 → 先問是否一次領全部 */
  askClaimAll: boolean
  /** type 2 輪盤：導 /events/lucky-wheel?ids= */
  isWheel: boolean
}

export function exchangeButtonState(event: Pick<LegacyEventRow, 'show_exchange' | 'prize_is_repeatedly' | 'type' | 'remaining_number'>): ExchangeButtonState {
  const showExchange = Number(event.show_exchange ?? 0)
  let textKey: ExchangeButtonState['textKey'] = 'events.claim.action.redeemed'
  if (showExchange === 1) textKey = 'events.claim.action.redeem'
  else if (Number(event.prize_is_repeatedly ?? 0) === 1) textKey = 'events.claim.label.unaccomplished'
  const isWheel = Number(event.type) === PRIZE_TYPE.WHEEL
  return {
    disabled: showExchange === 0,
    textKey,
    askClaimAll: !isWheel && Number(event.remaining_number ?? 0) > 1,
    isWheel,
  }
}

// -------------------------------------------------- legacy (V1) 各類卡的按鈕

/**
 * 舊皮每一種活動卡的按鈕。行為逐條對齊 `_source2/src/components/C13/Components/Event/`
 * 底下對應的元件；這裡只算出「顯示什麼、能不能按、按了做什麼」，樣式留給元件。
 */
export type LegacyCardAction = 'none' | 'claim' | 'topup' | 'wheel' | 'choose'

export interface LegacyButtonState {
  /** 舊皮 eventStatus 的狀態碼，保留下來方便對照與測試 */
  status: number
  /** i18n key；狀態 4／5 的文字由後端給，這時是 null */
  textKey: string | null
  /** textKey 為 null 時要顯示的後端文字（show_exchange_text） */
  text: string
  disabled: boolean
  action: LegacyCardAction
}

/** 可兌換時按下去要做什麼：type 2 輪盤導頁、type 8 自選獎項開彈窗，其餘直接兌獎。 */
function claimAction(type: number | null | undefined, allowWheel = true): LegacyCardAction {
  if (allowWheel && Number(type) === PRIZE_TYPE.WHEEL) return 'wheel'
  if (Number(type) === PRIZE_TYPE.CHOOSE) return 'choose'
  return 'claim'
}

/**
 * 儲值類活動（EventRechargeItem）。狀態碼沿用舊皮的 eventStatus：
 *   1 可兌換 2 已兌換 3 去儲值 4 去儲值（按鈕文字用後端的）
 *   5 已取消 6 獎項已領取完畢 7 今日已達標（action 59）
 *
 * 與舊皮唯一的差別是 `prize_content` 為空時不判 6。舊皮用 `every()` 判斷
 * 「每個獎項都發完」，而空陣列的 `every` 是 true，會讓沒有獎項清單的活動
 * 一律顯示「獎項已領取完畢」。
 */
export function rechargeButtonState(event: LegacyEventRow): LegacyButtonState {
  const prizes = event.prize_content ?? []
  const showExchange = Number(event.show_exchange ?? 0)
  const repeatedly = Number(event.prize_is_repeatedly ?? 0)
  const text = event.show_exchange_text ?? ''

  const status = ((): number => {
    if (prizes.length > 0 && prizes.every(p => Number(p.number) === Number(p.sent))) return 6
    if (showExchange === 1) return 1
    if (showExchange === 2) return 4
    if (showExchange === 3) return 5
    // prize_is_repeatedly 2 是每日領取，reached_today 1 代表今天已經派過
    const claimedOnce = repeatedly === 0 && Number(event.count ?? 0) > 0
    const claimedToday = repeatedly === 2 && Number(event.reached_today) === 1
    if (claimedOnce || claimedToday) return Number(event.action_id) === 59 ? 7 : 2
    return 3
  })()

  switch (status) {
    case 1:
      return { status, textKey: 'events.claim.action.redeem', text, disabled: false, action: claimAction(event.type) }
    case 2:
      return { status, textKey: 'events.claim.action.redeemed', text, disabled: true, action: 'none' }
    case 3:
      return { status, textKey: 'deposit.common.title.deposit', text, disabled: false, action: 'topup' }
    case 4:
      return { status, textKey: null, text, disabled: false, action: 'topup' }
    case 5:
      return { status, textKey: null, text, disabled: true, action: 'none' }
    case 6:
      return { status, textKey: 'events.claim.empty.prizesFullyClaimed', text, disabled: true, action: 'none' }
    default:
      return { status, textKey: 'events.mission.label.goalReached', text, disabled: true, action: 'none' }
  }
}

/**
 * 邀請類活動（EventInviteItem／EventInviteRechargeItem，兩支的狀態機一字不差）：
 *   1 可兌換 2 已兌換 3 未達成；只有 1 可按，而且沒有輪盤這條路。
 */
export function inviteButtonState(event: LegacyEventRow): LegacyButtonState {
  const text = event.show_exchange_text ?? ''
  if (Number(event.show_exchange ?? 0) === 1) {
    return { status: 1, textKey: 'events.claim.action.redeem', text, disabled: false, action: claimAction(event.type, false) }
  }
  if (Number(event.prize_is_repeatedly ?? 0) === 0 && Number(event.count ?? 0) > 0) {
    return { status: 2, textKey: 'events.claim.action.redeemed', text, disabled: true, action: 'none' }
  }
  return { status: 3, textKey: 'events.claim.label.unaccomplished', text, disabled: true, action: 'none' }
}

/** action 31 且 auto_payout=1：獎金自動派發，整顆兌換鈕不出現。 */
export function hidesExchangeButton(event: LegacyEventRow): boolean {
  return Number(event.action_id) === 31 && Number(event.auto_payout) === 1
}

/** 文字要嘛是 i18n key、要嘛是已經算好的字串（品牌名、數值）。 */
export type DisplayText = { i18n: string, values?: Record<string, string | number> } | { text: string }

/**
 * 活動卡上的獎勵文字。舊皮三支元件各有一份 getRechargeReward，行為已經漂開
 * （實體獎勵有的印獎項名稱、有的印「實體獎勵」，隨機點數區間只有充值卡會印），
 * 這裡收成一支，取最完整的那一版（EventRechargeItem）。
 */
export function describeRewardAmount(prize: PrizeContent, names: PrizeTextNames): DisplayText {
  const type = Number(prize.type)
  if (type === 0) {
    if (Number(prize.point_type) === 3) return { i18n: 'events.claim.label.physicalPrize' }
    const unit = Number(prize.point_type) === 2 ? names.rollingChip : names.walletPoint
    const point = decFormat(prize.point ?? 0, 'trim')
    const max = prize.random_point_max
    const amount = max == null || max === '' ? point : `${point} ~ ${decFormat(max, 'trim')}`
    return { text: `${amount} ${unit}` }
  }
  if (type === 1) {
    let percentage = Number(prize.point_percentage ?? 0)
    if (percentage > 0 && percentage <= 1) percentage = Number((percentage * 100).toFixed(0))
    return { text: `${percentage}%` }
  }
  return { text: '0' }
}

// ------------------------------------------------------- legacy (V1) jackpot

export type JackpotKind = 'wjackpot' | 'wjackpot6d' | 'luckyDraw'

/** type 6 是 W-Jackpot（豪龍 4D）、7 是 6D，其餘的 type 4／5 走摸彩券。 */
export function jackpotKind(type: number | null | undefined): JackpotKind {
  if (Number(type) === 6) return 'wjackpot'
  if (Number(type) === 7) return 'wjackpot6d'
  return 'luckyDraw'
}

export const JACKPOT_ISSUES_PATH: Record<JackpotKind, string> = {
  wjackpot: '/wjackpot/issues',
  wjackpot6d: '/wjackpot/6d/issues',
  luckyDraw: '/lucky-draw/issues',
}

/** 期數標題。6D 那個是彩種名稱不是文案，所以不走 i18n。 */
export const JACKPOT_DRAW_LABEL: Record<JackpotKind, DisplayText> = {
  wjackpot: { i18n: 'events.jackpot.label.drawDate' },
  wjackpot6d: { text: 'TOTO 6D' },
  luckyDraw: { i18n: 'events.luckyDraw.label.thirdPartyIssueNo' },
}

export const JACKPOT_DESC_KEY: Record<JackpotKind, string> = {
  wjackpot: 'events.jackpot.text.desc4d',
  wjackpot6d: 'events.jackpot.text.desc6d',
  luckyDraw: 'events.luckyDraw.text.eventIntro',
}

/** 開獎參考的外部網站。 */
export const JACKPOT_RESULT_URL: Record<JackpotKind, string> = {
  wjackpot: 'http://example.invalid/results/today/',
  wjackpot6d: 'https://example.invalid.my/cn/index.asp',
  luckyDraw: 'http://example.invalid/results/today/',
}

/** 活動詳情頁 tab：invite 群 → invite，否則 bet。 */
export function legacyDetailTab(event: Pick<LegacyEventRow, 'action_id' | 'detail'>): 'invite' | 'bet' {
  const detailAction = event.detail?.[0]?.action_id
  const candidates: number[] = [
    ...LEGACY_ACTION_GROUPS.invite,
    ...LEGACY_ACTION_GROUPS.newInvite,
    ...LEGACY_ACTION_GROUPS.inviteRecharge,
  ]
  if (inGroup(candidates, detailAction == null ? null : Number(detailAction))) return 'invite'
  if (inGroup(candidates, event.action_id)) return 'invite'
  return 'bet'
}

// ------------------------------------------------ 每日活動彈窗（四張互跳）

export type ActivityKey = 'dailyCheckin' | 'redPacket' | 'luckyWheel' | 'goldenSmash'

export interface ActivityPopup {
  key: ActivityKey
  /** public/skins/c17/activity/ 底下的檔名 */
  icon: string
  chip: string
  titleKey: string
  /** 還沒實作的活動沒有頁面，晶片就不可點也不上色 */
  to: string | null
  /** 有沒有預覽模式（抽獎類才有，見 isActivityDemoRoute） */
  demo?: boolean
}

/**
 * 四張活動彈窗的共用設定。每一張的晶片列都是**另外三張**（設計稿四張互相對照過），
 * 所以清單只寫一份，由 EventsActivityShell 依 current 過濾。
 */
export const ACTIVITY_POPUPS: Record<ActivityKey, ActivityPopup> = {
  dailyCheckin: {
    key: 'dailyCheckin',
    icon: 'icon-daily-checkin',
    chip: 'chip-daily-checkin',
    titleKey: 'events.checkin.title.dailyCheckin',
    to: '/events/checkin',
  },
  redPacket: {
    key: 'redPacket',
    icon: 'icon-red-packet',
    chip: 'chip-red-packet',
    titleKey: 'events.redPacket.title.dailyRedPacket',
    // 已接活動 V2，沒有預覽模式；晶片的實際網址由 EventsActivityShell 依 event/list 補上 ?ids=
    to: ANGPOW_PATH,
  },
  luckyWheel: {
    key: 'luckyWheel',
    icon: 'icon-lucky-wheel',
    chip: 'chip-lucky-wheel',
    titleKey: 'events.luckyDraw.title.luckyWheelSpin',
    to: '/events/lucky-wheel',
    demo: true,
  },
  goldenSmash: {
    key: 'goldenSmash',
    icon: 'icon-golden-smash',
    chip: 'chip-golden-smash',
    titleKey: 'events.goldenSmash.title.goldenSmash',
    to: '/events/golden-smash',
    demo: true,
  },
}

/** 有預覽模式的那幾張彈窗的路徑。清單從 ACTIVITY_POPUPS 推，不另外維護一份。 */
export const ACTIVITY_DEMO_PATHS: string[] = Object.values(ACTIVITY_POPUPS)
  .filter(popup => popup.demo && popup.to)
  .map(popup => popup.to!)

/**
 * 這個網址要不要走預覽模式。
 *
 * 輪盤／敲蛋還接不到真資料——每天那筆可領的 `prize_members` 沒人建立，
 * 後端也認不出哪個活動是敲蛋（缺專屬 `prizes.type_id`）。
 * 紅包已接活動 V2、不在預覽清單：沒帶 `?ids=` 時頁面自己用 findAngpowEventId 找（`?demo=1` 仍可預覽）。
 * 預覽模式不打任何 API、獎項是寫死的樣本、也不會真的發獎。
 *
 * 兩種進得來的方式：
 *   1. 明確加 `?demo=1`
 *   2. **沒帶 `?ids=` 就進到抽獎彈窗**——真實資料一定要有活動 id，
 *      從選單或晶片點進來時沒有 id，不走預覽的話畫面就是一片灰的「進度未達成」
 *
 * 不分站、不分環境都開著；等設計定案、真 API 可以串之後再改成依資料判斷。
 */
export function isActivityDemoRoute(path: string, query: Record<string, unknown>): boolean {
  if (query.demo === '1') return true
  return ACTIVITY_DEMO_PATHS.includes(path) && query.ids == null
}

/**
 * 每日活動（紅包／敲蛋）的三態。來源是 `event/legacy-list` 的 show_exchange：
 *   1 當期有未領的 prize_members → 可以拆
 *   0 當期的已經領掉了          → 今天拆完了
 *   2 當期根本沒有 prize_members → 未達成（**這是預設態**）
 *
 * 不要改用 `event/legacy-detail`：那支的 remaining_number 是歷史累計，沒有當期條件，
 * 昨天沒領的會一起算進來（理由詳見 docs/daily-red-packet-spec.md §2.5）。
 */
export type DailyPrizeState = 'ready' | 'done' | 'locked'

export function dailyPrizeState(showExchange: number | null | undefined): DailyPrizeState {
  const value = Number(showExchange ?? 2)
  if (value === 1) return 'ready'
  if (value === 0) return 'done'
  return 'locked'
}

// -------------------------------------------------------------------- angpow

/**
 * 紅包的「Remaining Today」。後端沒有合併值，照對接文件取這三者中非 null 的最小值：
 * 個人本期剩餘次數、全站本期剩餘次數、⌊可用積分 ÷ 每抽扣分⌋。三者都沒設回 null（不限）。
 */
export function angpowRemaining(info: AngpowInfo): number | null {
  const candidates: number[] = []
  if (info.member_times_remaining !== null) candidates.push(info.member_times_remaining)
  if (info.site_times_remaining !== null) candidates.push(info.site_times_remaining)
  if (info.score_cost !== null && decIsPositive(info.score_cost)) {
    candidates.push(decToNumber(decDiv(info.score_balance, info.score_cost, 0)))
  }
  return candidates.length > 0 ? Math.max(0, Math.min(...candidates)) : null
}

/**
 * 個人或全站次數已用完。後端的檢查順序是次數上限在積分之前，
 * 所以兩者同時成立時要回「次數上限」，不能先跳導流存款彈窗（存了錢也抽不了）。
 */
export function angpowTimesExhausted(info: AngpowInfo): boolean {
  return info.member_times_remaining === 0 || info.site_times_remaining === 0
}

/** 積分不夠抽一次（導流存款彈窗）。score_cost 沒設是活動配錯，交給 join 回錯誤。 */
export function angpowScoreShort(info: AngpowInfo): boolean {
  return info.score_cost !== null && decCmp(info.score_balance, info.score_cost) < 0
}

// --------------------------------------------------------------------- prize

export type PrizeDrawKind = 'scratch' | 'wheel' | 'direct'

export function prizeDrawKind(type: number | null | undefined): PrizeDrawKind {
  if (Number(type) === PRIZE_TYPE.SCRATCH) return 'scratch'
  if (Number(type) === PRIZE_TYPE.WHEEL) return 'wheel'
  return 'direct'
}

export interface PrizeTextNames {
  walletPoint: string
  rollingChip: string
}

export interface PrizeParts {
  /** 數值，例如 `10`、`150%` */
  value: string
  /** 單位，例如平台點數或滾動籌碼的名稱；百分比沒有單位 */
  unit: string
}

/**
 * 兌獎結果拆成「數值」與「單位」兩層：type 0 點數（point_type 決定單位）、
 * type 1 百分比、其他 '0'。
 *
 * 三張活動彈窗的設計稿都是兩層（紅包 `RM 10` + `BONUS`、輪盤 `RM 68` + `CASH`），
 * 因為兩者各自伸縮——數值長度由獎項決定，單位長度由站台設定與語系決定。
 * 併成一行的話，泰文緬甸文那種長單位會把數字一起壓小。
 */
export function getPrizeParts(prize: DrawnPrize | PrizeContent, names: PrizeTextNames): PrizeParts {
  const type = Number(prize.type)
  if (type === 0) {
    const drawn = prize as DrawnPrize
    let unit = names.walletPoint
    if (Number(drawn.point_type) === 2) unit = names.rollingChip
    else if (Number(drawn.point_type) === 3) unit = prize.name ?? ''
    return { value: String(drawn.get_point ?? prize.point ?? ''), unit }
  }
  if (type === 1) {
    let percentage = Number(prize.point_percentage ?? 0)
    if (percentage > 0 && percentage <= 1) percentage = Number((percentage * 100).toFixed(0))
    return { value: `${percentage}%`, unit: '' }
  }
  return { value: '0', unit: '' }
}

/** 兌獎結果文案，一行版。要分開排版的用 getPrizeParts。 */
export function getPrizeText(prize: DrawnPrize | PrizeContent, names: PrizeTextNames): string {
  const { value, unit } = getPrizeParts(prize, names)
  return `${value}${unit}`
}

/** 自選獎項可選清單：全部 -1 → 全列；有任一 -1 → 留 -1 或 sent < number；否則只留 sent < number。 */
export function filterChoosablePrizeContents<T extends Pick<PrizeContent, 'number' | 'sent'>>(list: T[]): T[] {
  if (list.every(p => Number(p.number) === -1)) return list
  const hasUnlimited = list.some(p => Number(p.number) === -1)
  return list.filter((p) => {
    const unlimited = Number(p.number) === -1
    const remaining = Number(p.sent ?? 0) < Number(p.number ?? 0)
    return hasUnlimited ? (unlimited || remaining) : remaining
  })
}

export interface WheelSlot {
  id: number
  /** 獎項數值，例如 `68` */
  value: string
  /** 獎項單位，例如點數名稱 */
  unit: string
  /** 扇形底色 */
  color: string
  /** 這一格的字色 */
  ink: string
}

/**
 * 輪盤格的配色。設計稿（Lucky Wheel）是「彩色／米白」交錯，彩色依序青 → 紅 → 綠，
 * 米白格配藍字、彩色格配螢光黃字。值放在皮的 token 裡，換皮才不用改這支。
 */
const WHEEL_TINTS = ['var(--c17-wheel-cyan)', 'var(--c17-wheel-red)', 'var(--c17-wheel-green)']

/**
 * 輪盤格：id、獎項的數值與單位、交錯配色。
 *
 * **格數跟著獎項數走，不寫死六格**——設計稿畫六格，但獎項是後台設的。
 * 奇數格時最後一格會跟第一格相鄰，都是彩色，所以最後一格跳過一個色，
 * 不然接縫看起來會像一塊雙倍寬的扇形。
 */
export function wheelSlots(prizes: PrizeContent[], names: PrizeTextNames): WheelSlot[] {
  const odd = prizes.length % 2 === 1
  return prizes.map((p, i) => {
    const cream = i % 2 === 1
    const natural = Math.floor(i / 2) % WHEEL_TINTS.length
    // 奇數格的最後一格會跟第一格相鄰，剛好輪回第一個色時往後挪一個
    const tint = odd && i === prizes.length - 1 && natural === 0 ? 1 : natural
    return {
      id: Number(p.id ?? i),
      ...getPrizeParts(p, names),
      color: cream ? 'var(--c17-wheel-cream)' : WHEEL_TINTS[tint]!,
      ink: cream ? 'var(--c17-wheel-ink-cream)' : 'var(--c17-wheel-ink-tint)',
    }
  })
}

/**
 * 輪盤盤面的 CSS 值。第一格的中線要正對 12 點鐘的指針，所以整圈往回轉半格
 * （`wheelStopAngle()` 的算法建立在這個前提上，兩邊要一起改）。
 *
 * **沒有獎項時回一整片米白，不可以回 transparent**——外框那層是圖，
 * 盤面透明會變成一個空心的金圈，看起來像圖破了。
 *
 * **接縫不可以用硬色標**（前一格的結束角度 = 下一格的開始角度）：瀏覽器不會替
 * conic-gradient 的硬邊做反鋸齒，扇形交界會是一格一格的像素階梯。每格頭尾各內縮
 * WHEEL_SEAM_BLEND，讓相鄰兩色在接縫處漸變過去（在外圈約 1px，再寬就會糊）。
 * 0°／360° 那條接縫兩端各放一個兩色的中間色，過渡寬度才跟其他接縫一樣。
 */
const WHEEL_SEAM_BLEND = 0.25

export function wheelFace(slots: WheelSlot[]): string {
  if (!slots.length) return 'var(--c17-wheel-cream)'
  if (slots.length === 1) return slots[0]!.color
  const slice = 360 / slots.length
  const e = WHEEL_SEAM_BLEND
  const seam = `color-mix(in srgb, ${slots.at(-1)!.color} 50%, ${slots[0]!.color})`
  const stops = slots.map((slot, i) => `${slot.color} ${i * slice + e}deg ${(i + 1) * slice - e}deg`)
  return `conic-gradient(from ${-slice / 2}deg, ${seam} 0deg, ${stops.join(', ')}, ${seam} 360deg)`
}

/** event/legacy-validate：code 200/1000 → 可領。 */
export function validateResult(res: Pick<ApiEnvelope<unknown>, 'code' | 'message'>): { isClaim: boolean, msg: string } {
  const ok = [200, 1000].includes(Number(res.code))
  return { isClaim: ok, msg: ok ? '' : res.message }
}

/** 邀請好友（action 15）獎項按鈕：0 未到達、1 領取、2 已領取；只有 1 可按。 */
export function newInviteButtonState(showExchange: number | null | undefined): { textKey: 'events.invite.label.notAchieved' | 'events.claim.action.claim' | 'events.claim.label.claimed', enabled: boolean } {
  const s = Number(showExchange ?? 0)
  if (s === 1) return { textKey: 'events.claim.action.claim', enabled: true }
  if (s === 2) return { textKey: 'events.claim.label.claimed', enabled: false }
  return { textKey: 'events.invite.label.notAchieved', enabled: false }
}

// ------------------------------------------------------------------ event-v2

/** null 表示「符合資格可參加」（用 i18n eligibleToParticipate），否則顯示 message。 */
export function eventV2ParticipationMessage(detail: Pick<EventV2Detail, 'message' | 'validate'>): string | null {
  const message = detail.message ?? ''
  if (message === '' && detail.validate) return null
  return message
}

/**
 * 參加鈕能不能按：只看 validate（舊專案 C13 PlatformEvent 的 btnDisable = !event.validate）。
 * 不可以再加「message 為空」：驗證通過時後端也會回 message（例如「活動條件達成」）。
 */
export function eventV2CanJoin(detail: Pick<EventV2Detail, 'validate'> | null): boolean {
  return detail?.validate === true
}

export type JoinRedirect
  = | { kind: 'external', to: string }
    | { kind: 'internal', to: string }
    | { kind: 'home', to: '/' }

export function resolveJoinRedirect(redirectPath: string | null | undefined): JoinRedirect {
  if (!redirectPath) return { kind: 'home', to: '/' }
  if (/^https?:\/\//.test(redirectPath)) return { kind: 'external', to: redirectPath }
  return { kind: 'internal', to: redirectPath }
}

export interface EventV2Progress {
  progress: number
  requirement: number
  percent: number
}

/** flow_limit 0 → 看餘額門檻，否則看流水。 */
export function eventV2Progress(detail: Pick<EventV2JoinDetail, 'flow_limit' | 'balance' | 'flow' | 'balance_limit'>): EventV2Progress {
  const byBalance = Number(detail.flow_limit) === 0
  const progress = byBalance ? Number(detail.balance) : Number(detail.flow)
  const requirement = byBalance ? Number(detail.balance_limit) : Number(detail.flow_limit)
  const percent = requirement > 0 ? Math.min(100, Math.max(0, (progress / requirement) * 100)) : 0
  return { progress, requirement, percent }
}

// ------------------------------------------------------------------- records

/** 含當月往前共 N 個月（YYYY-MM）。 */
export function claimRecordMonthOptions(now: Date = new Date(), count = 6): string[] {
  const out: string[] = []
  for (let i = 0; i < count; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`)
  }
  return out
}

// ------------------------------------------------------------------- checkin

/** ToMoneyDisplayFormat_Fixed2：無條件捨去到兩位；0 顯示 0.00。實作在 shared/utils/amount.ts。 */
export function formatAmountFixed2(value: unknown): string {
  return decFloorFixed(toAmountLike(value), 2)
}

export interface CheckinTextLabels {
  /** $t('common.amount.label.platformPoint') */
  platformPoints: string
  /** 該站錢包點數名稱（text_wallet_point） */
  walletPointName: string
}

/** 簽到成功文案：null → 用 checkInSuccessful；否則回「{prize_type} {金額}」，兩筆以 ' & ' 串接。 */
export function checkinSuccessText(prizes: CheckinPrize[], labels: CheckinTextLabels): string | null {
  if (!prizes.length) return null
  const one = (p: CheckinPrize) => {
    const type = p.prize_type === labels.platformPoints && labels.walletPointName
      ? labels.walletPointName
      : p.prize_type
    return `${type} ${formatAmountFixed2(p.prize_amount)}`
  }
  const [a, b] = prizes
  if (!b) return one(a!)
  return `${one(a!)} & ${one(b)}`
}

export function checkinStatus(records: CheckinRecord[]): { checkedToday: boolean, today: CheckinRecord | null } {
  const today = records.find(r => r.checkin_day) ?? null
  return {
    checkedToday: records.some(r => r.is_checkin && r.checkin_day),
    today,
  }
}

/** checkin/records 的 400 = 該站沒有簽到活動，要吞掉不跳錯誤。 */
export function isCheckinUnavailable(res: Pick<ApiEnvelope<unknown>, 'code'>): boolean {
  return Number(res.code) === 400
}

// ------------------------------------------------------------------- mission

export interface MissionMemberInfo {
  birthday?: string | null
  mobile_validate?: number | string | null
  email_validate?: number | string | null
  realname?: { is_validated?: boolean | null, is_exists?: boolean | null } | null
  withdrawalAccountCount?: number | null
}

export interface MissionState {
  complete: boolean
  claimed: boolean
  claimable: boolean
  systemConfirming: boolean
}

export function missionState(mission: Mission, member: MissionMemberInfo = {}): MissionState {
  const complete = Number(mission.progress ?? 0) >= Number(mission.requirement ?? 0)
  const claimed = Number(mission.is_claimed ?? 0) === 1
  let systemConfirming = false
  if (!complete) {
    switch (mission.action_type) {
      case 'fill_birthday':
        systemConfirming = !!member.birthday
        break
      case 'realname_validation':
        systemConfirming = !!member.realname?.is_validated || (!!member.realname?.is_exists && !member.realname?.is_validated)
        break
      case 'bind_mobile':
        systemConfirming = Number(member.mobile_validate ?? 0) === 1
        break
      case 'bind_email':
        systemConfirming = Number(member.email_validate ?? 0) === 1
        break
      case 'bind_bank_card':
        systemConfirming = Number(member.withdrawalAccountCount ?? 0) > 0
        break
    }
  }
  return { complete, claimed, claimable: complete && !claimed, systemConfirming }
}

export const MISSION_HOME_PATH = '/missions'

/** 未完成任務按鈕導頁。 */
export function missionActionPath(mission: Mission): string {
  switch (mission.action_type) {
    case 'deposit':
      return '/topup'
    case 'bet': {
      const game = mission.game?.[0]
      const typeId = game?.game_type?.[0]
      const vendorId = game?.vendor_id?.[0]
      if (!game) return MISSION_HOME_PATH
      if (vendorId) return typeId ? `/game/list/${vendorId}?typeId=${typeId}` : `/game/list/${vendorId}`
      if (typeId) return `/game/list/all?typeId=${typeId}`
      return MISSION_HOME_PATH
    }
    case 'fill_birthday':
      return '/member/set-birthday'
    case 'realname_validation':
    case 'bind_bank_card':
      return '/member/real-name-verification'
    case 'bind_mobile':
      return '/member/phone-verification'
    case 'bind_email':
      return '/member/bind-email'
    default:
      return MISSION_HOME_PATH
  }
}

/**
 * 秒數 → HH:mm:ss（超過 24 小時仍以小時累計）。
 *
 * **名字不能叫 formatCountdown**：`shared/utils/validation-code.ts` 已經有一支同名的
 * （那支做的是「秒數補零」，回 `05`）。兩支都在自動匯入範圍內，撞名時 Nuxt 只會挑一個
 * 而且不報錯，呼叫端會默默拿到錯的格式。
 */
export function formatClock(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(Number(totalSeconds) || 0))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  return [h, m, sec].map(n => String(n).padStart(2, '0')).join(':')
}

// -------------------------------------------------------------- event-invite

export function inviteCanReceive(setting: Pick<EventInviteSetting, 'can_receive'> | null | undefined): boolean {
  return Number(setting?.can_receive ?? 0) > 0
}

// ---------------------------------------------------------- newbie-challenge

export const NEWBIE_STAGE = {
  CHALLENGING: 1,
  BONUS_PENDING: 2,
  BONUS_WAGERING: 3,
  BONUS_COMPLETED: 4,
  OPERATOR_QUIT: 5,
  QUIT: 6,
} as const

const NEWBIE_STAGE_TO_STEP: Record<number, number> = {
  [NEWBIE_STAGE.CHALLENGING]: 2,
  [NEWBIE_STAGE.BONUS_PENDING]: 3,
  [NEWBIE_STAGE.BONUS_WAGERING]: 4,
  [NEWBIE_STAGE.BONUS_COMPLETED]: 5,
}

export const NEWBIE_DEFAULT_BONUS_WINDOW_HOURS = 48

/** 後端時間字串（前端時區 "YYYY-MM-DD HH:mm:ss"）→ 剩餘秒數，最小 0。 */
export function newbieRemainingSeconds(expireAt: string | null | undefined, nowMs: number = Date.now()): number {
  if (!expireAt) return 0
  const t = new Date(String(expireAt).replace(/-/g, '/')).getTime()
  if (!Number.isFinite(t)) return 0
  return Math.max(0, Math.floor((t - nowMs) / 1000))
}

/** stage 2 且倒數歸零 → 彩金資格逾期。 */
export function newbieIsExpired(progress: NewbieChallengeProgress | null, nowMs: number = Date.now()): boolean {
  if (!progress) return false
  return Number(progress.stage) === NEWBIE_STAGE.BONUS_PENDING
    && !!progress.bonus_window_expire_at
    && newbieRemainingSeconds(progress.bonus_window_expire_at, nowMs) === 0
}

export function newbieIsEnded(progress: NewbieChallengeProgress | null, nowMs: number = Date.now()): boolean {
  if (!progress) return false
  return Number(progress.stage) === NEWBIE_STAGE.QUIT || newbieIsExpired(progress, nowMs)
}

/** 版型步驟：無 progress → 1；stage 1→2、2→3、3→4、4→5；已結束 → 0。 */
export function newbieStep(progress: NewbieChallengeProgress | null, nowMs: number = Date.now()): number {
  if (!progress) return 1
  if (newbieIsEnded(progress, nowMs)) return 0
  return NEWBIE_STAGE_TO_STEP[Number(progress.stage)] ?? 1
}

/** 提款守衛：stage 1~3 擋。 */
export function isNewbieWithdrawalBlocked(progress: NewbieChallengeProgress | null): boolean {
  if (!progress) return false
  const stage = Number(progress.stage)
  return stage === NEWBIE_STAGE.CHALLENGING || stage === NEWBIE_STAGE.BONUS_PENDING || stage === NEWBIE_STAGE.BONUS_WAGERING
}

/** 未參加（400 Newbie challenge not joined）是正常狀態。 */
export function isNewbieNotJoined(res: Pick<ApiEnvelope<unknown>, 'code'>): boolean {
  return Number(res.code) === 400
}
