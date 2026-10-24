import type {
  VipContent,
  VipInfo,
  VipInfoReward,
  VipRebateSummaryRecord,
} from '#shared/api/endpoints/vip'
import { isOn } from '#shared/utils/site'
import { decFormat } from '#shared/utils/amount'

/** vip_rewards.id：7 = 每日返水，領取走 vip/receive-rebate；其餘走 vip/receive。 */
export const VIP_REWARD_DAILY_REBATE_ID = 7

export const VIP_REWARD_STATUS = {
  NOT_REACHED: -1,
  CLAIMABLE: 0,
  CLAIMED: 1,
  EXPIRED: 2,
} as const

export const GAME_TYPE_REALITY = 1
export const GAME_TYPE_SLOT = 2

/** game_type_id → i18n key（沿用舊前台 enums.gameTypeList）。 */
export const GAME_TYPE_I18N_KEYS: Record<number, string> = {
  1: 'games.enum.category.reality',
  2: 'games.enum.category.slot',
  3: 'games.enum.category.poker',
  4: 'games.enum.category.lottery',
  5: 'games.enum.category.fishing',
  6: 'games.enum.category.cockfight',
  8: 'games.enum.category.freeplay',
  9: 'games.enum.category.texasholdem',
  15: 'games.enum.category.arcade',
  16: 'games.enum.category.horseracing',
  18: 'games.enum.category.sports',
  19: 'games.enum.category.esports',
  20: 'games.enum.category.gd',
  21: 'games.enum.category.barmachine',
  99: 'games.enum.category.multiplayer',
  100: 'games.enum.category.table',
  101: 'games.enum.category.barmachine',
}

export function gameTypeI18nKey(gameTypeId: number | string): string | undefined {
  return GAME_TYPE_I18N_KEYS[Number(gameTypeId)]
}

/** 後端金額字串轉數字；null / 空字串 / 非數字一律 0。 */
export function toAmount(value: string | number | null | undefined): number {
  if (value === null || value === undefined || value === '') return 0
  const n = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(n) ? n : 0
}

/** vip/contents 後端未排序，一律先依 level 升冪。 */
export function sortVipContents(list: readonly VipContent[]): VipContent[] {
  return [...list].sort((a, b) => Number(a.level) - Number(b.level))
}

export function findVipByLevel(list: readonly VipContent[], level: number): VipContent | undefined {
  return list.find(item => Number(item.level) === level)
}

export interface VipLevels {
  current: VipContent | undefined
  /** 最高等級時 next 就是自己。 */
  next: VipContent | undefined
  maxLevel: number
}

export function resolveVipLevels(contents: readonly VipContent[], level: number): VipLevels {
  const current = findVipByLevel(contents, level)
  const next = findVipByLevel(contents, level + 1) ?? current
  const maxLevel = contents.reduce((max, item) => Math.max(max, Number(item.level)), 0)
  return { current, next, maxLevel }
}

export function hasNextLevel(level: number, maxLevel: number): boolean {
  return level < maxLevel
}

/** 晉級差額 = max(nextVip.target_amount - info.deposit_amount, 0)。 */
export function depositDifference(
  info: Pick<VipInfo, 'deposit_amount'> | null | undefined,
  next: Pick<VipContent, 'target_amount'> | null | undefined,
): number {
  if (!info || !next) return 0
  return Math.max(toAmount(next.target_amount) - toAmount(info.deposit_amount), 0)
}

/** 保級差額 = max(currentVip.keep_amount - info.keep_amount, 0)。 */
export function relegationDifference(
  info: Pick<VipInfo, 'keep_amount'> | null | undefined,
  current: Pick<VipContent, 'keep_amount'> | null | undefined,
): number {
  if (!info || !current) return 0
  return Math.max(toAmount(current.keep_amount) - toAmount(info.keep_amount), 0)
}

/** 進度條：min 與 max 皆 0 視為 100%；value <= min 為 0%；上限 100。 */
export function progressPercent(value: number, min: number, max: number): number {
  if (min === 0 && max === 0) return 100
  if (value <= min) return 0
  if (max <= min) return 100
  return Math.min(((value - min) / (max - min)) * 100, 100)
}

export function canClaimReward(reward: Pick<VipInfoReward, 'status'>): boolean {
  return Number(reward.status) === VIP_REWARD_STATUS.CLAIMABLE
}

export function isDailyRebateReward(reward: Pick<VipInfoReward, 'id'>): boolean {
  return Number(reward.id) === VIP_REWARD_DAILY_REBATE_ID
}

/** reward.status → 按鈕文字的 i18n key。 */
export function rewardStatusKey(status: number | string): string {
  switch (Number(status)) {
    case VIP_REWARD_STATUS.CLAIMABLE:
      return 'events.claim.action.claim'
    case VIP_REWARD_STATUS.CLAIMED:
      return 'events.claim.label.claimed'
    case VIP_REWARD_STATUS.EXPIRED:
      return 'events.claim.label.expired'
    default:
      return 'events.invite.label.notAchieved'
  }
}

export interface RewardPrivilege {
  kind: 'reward'
  reward: VipInfoReward
}

export interface ServicePrivilege {
  kind: 'service'
  nameKey: string
  descriptionKey: string
}

export type PrivilegeItem = RewardPrivilege | ServicePrivilege

/**
 * 特權清單 = info.reward 全部
 *  + currentVip.vip_channel == 1 → VIP 優先服務通道
 *  + currentVip.exclusive_service == 1 → 專屬經理服務（新版 vip/contents 尚未回傳此欄，後端補上即自動顯示）。
 */
export function buildPrivilegeList(
  info: Pick<VipInfo, 'reward'> | null | undefined,
  current: VipContent | null | undefined,
): PrivilegeItem[] {
  const list: PrivilegeItem[] = (info?.reward ?? []).map(reward => ({ kind: 'reward', reward }))
  if (current && isOn(current.vip_channel)) {
    list.push({ kind: 'service', nameKey: 'vip.privilege.label.specialService', descriptionKey: 'vip.privilege.hint.priorityService' })
  }
  if (current && isOn(current.exclusive_service)) {
    list.push({ kind: 'service', nameKey: 'vip.privilege.label.specialService', descriptionKey: 'vip.privilege.hint.dedicatedManager' })
  }
  return list
}

export interface RebateType {
  game_type_id: number
  proportion: string | number
  nextProportion?: string | number
}

/** 返水頁的類型清單：以 currentVip 的 rebate_proportion 為主，把 nextVip 的比例依 game_type_id 對齊進來。 */
export function buildRebateTypes(
  current: Pick<VipContent, 'rebate_proportion'> | null | undefined,
  next: Pick<VipContent, 'rebate_proportion'> | null | undefined,
): RebateType[] {
  if (!current) return []
  const types: RebateType[] = current.rebate_proportion.map(item => ({
    game_type_id: Number(item.game_type_id),
    proportion: item.proportion,
  }))
  for (const item of next?.rebate_proportion ?? []) {
    const hit = types.find(type => type.game_type_id === Number(item.game_type_id))
    if (hit) hit.nextProportion = item.proportion
  }
  return types
}

/** 比例已是百分比數字（後端 ×100），顯示成 'NN%'；沒有比例顯示 '-'。 */
export function formatRebateProportion(proportion: string | number | null | undefined): string {
  if (proportion === null || proportion === undefined || proportion === '') return '-'
  const n = Number(proportion)
  return Number.isFinite(n) ? `${n}%` : '-'
}

export function filterRebateSummary(
  records: readonly VipRebateSummaryRecord[],
  gameTypeId: number | null | undefined,
): VipRebateSummaryRecord[] {
  if (!gameTypeId) return []
  return records.filter(item => Number(item.game_type_id) === gameTypeId)
}

export interface WeeklyRebateSummary {
  thisBet: number
  thisRebate: number
  lastBet: number
  lastRebate: number
}

/** 本週／上週投注與返水，null 視為 0。 */
export function weeklyRebateSummary(
  info: Pick<VipInfo, 'this_rebate_records' | 'last_rebate_records'> | null | undefined,
): WeeklyRebateSummary {
  return {
    thisBet: toAmount(info?.this_rebate_records?.total_bet),
    thisRebate: toAmount(info?.this_rebate_records?.rebate_amount),
    lastBet: toAmount(info?.last_rebate_records?.total_bet),
    lastRebate: toAmount(info?.last_rebate_records?.rebate_amount),
  }
}

/** 等級表格用的顯示值：要翻譯的給 i18n key，直接顯示的給 text。 */
export type DisplayValue = { i18n: string } | { text: string }

const NONE: DisplayValue = { i18n: 'common.ui.label.none' }

/**
 * 這個等級沒有這項權益。設計稿（VIP、VIP_All-Benefits_pop out）在這種列
 * 放的是灰色圓形鎖頭而不是「無」兩個字，呼叫端要靠這支分辨。
 */
export function isNoneValue(value: DisplayValue): boolean {
  return 'i18n' in value && value.i18n === 'common.ui.label.none'
}

/** 晉級門檻：-1 僅限邀請；> 0 顯示 2 位小數；其餘「無」。 */
export function describeTargetAmount(vip: Pick<VipContent, 'target_amount'> | null | undefined): DisplayValue {
  if (!vip) return NONE
  const n = toAmount(vip.target_amount)
  if (n === -1) return { i18n: 'vip.condition.label.byInvitation' }
  return n > 0 ? { text: decFormat(n, 2) } : NONE
}

/** 保級標準：-1 僅限邀請；> 0 取整數千分位；其餘「無」。 */
export function describeKeepAmount(vip: Pick<VipContent, 'keep_amount'> | null | undefined): DisplayValue {
  if (!vip) return NONE
  const n = toAmount(vip.keep_amount)
  if (n === -1) return { i18n: 'vip.condition.label.byInvitation' }
  return n > 0 ? { text: decFormat(n, 0) } : NONE
}

/** 每日交易次數：-1 無限。 */
export function describeWithdrawalTimesLimit(
  vip: Pick<VipContent, 'withdrawal_times_limit'> | null | undefined,
): DisplayValue {
  if (!vip) return NONE
  const n = Number(vip.withdrawal_times_limit)
  return n === -1 ? { i18n: 'vip.condition.label.unlimited' } : { text: String(n) }
}

/** 每日提領上限：-1 無限、0 顯示 '-'、其他 2 位小數。 */
export function describeWithdrawalAmountLimit(
  vip: Pick<VipContent, 'withdrawal_amount_limit'> | null | undefined,
): DisplayValue {
  if (!vip) return { text: '-' }
  const n = toAmount(vip.withdrawal_amount_limit)
  if (n === -1) return { i18n: 'vip.condition.label.unlimited' }
  if (n === 0) return { text: '-' }
  return { text: decFormat(n, 2) }
}

/** reward.point 含 '%' 原樣顯示（type=1）、'-' 原樣（type=2 額外返水）、其餘金額千分位。 */
export function describeRewardPoint(point: string | number | null | undefined, decimals = 0): DisplayValue {
  const s = String(point ?? '').trim()
  if (s === '' || s === '-') return NONE
  if (s.includes('%')) return { text: s }
  return { text: decFormat(s, decimals) }
}

export function findRewardPoint(
  vip: Pick<VipContent, 'reward'> | null | undefined,
  rewardId: number,
  decimals = 0,
): DisplayValue {
  const reward = vip?.reward.find(item => Number(item.id) === rewardId)
  return reward ? describeRewardPoint(reward.point, decimals) : NONE
}

/** 等級表「現場% / 電子%」，兩者缺一顯示「無」。 */
export function describeRebateProportion(
  vip: Pick<VipContent, 'rebate_proportion'> | null | undefined,
): DisplayValue {
  if (!vip || vip.rebate_proportion.length === 0) return NONE
  const reality = vip.rebate_proportion.find(item => Number(item.game_type_id) === GAME_TYPE_REALITY)
  const slot = vip.rebate_proportion.find(item => Number(item.game_type_id) === GAME_TYPE_SLOT)
  if (!reality || !slot) return NONE
  return { text: `${Number(reality.proportion)}% / ${Number(slot.proportion)}%` }
}
