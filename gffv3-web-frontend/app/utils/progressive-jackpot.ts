/** 檔位狀態：levels[].status null = 未達標；2 與 5 畫面上都是已完成。 */
export type GrowthTierStatus = 'pending' | 'ongoing' | 'reached' | 'done' | 'expired' | 'locked'

const STATUS_BY_CODE: Record<number, GrowthTierStatus> = {
  0: 'ongoing',
  1: 'reached',
  2: 'done',
  3: 'expired',
  4: 'locked',
  5: 'done',
}

/** 不會再往前推進的狀態。 */
export const GROWTH_SETTLED_STATUS: readonly GrowthTierStatus[] = ['done', 'expired', 'locked']
export const GROWTH_REWARD_TYPE_PERCENTAGE = 2
/** event/list 的 tp_event 值：成長彩金計劃 */
export const GROWTH_TP_EVENT = 3

export function growthToNumber(value: unknown): number {
  const parsed = Number(String(value ?? 0).replace(/,/g, ''))
  return Number.isFinite(parsed) ? parsed : 0
}

export function growthTierStatus(code: number | null | undefined): GrowthTierStatus {
  if (code === null || code === undefined) return 'pending'
  return STATUS_BY_CODE[Number(code)] ?? 'pending'
}

export interface GrowthLevelLike {
  level: number
  status: number | null
  reward: string
  point: string
  receive: string
  remaining: string | null
  progress: string
  expire_time?: string | null
  deposit_point: string
  reward_type: number
  reward_percentage: string
  max_reward: string
  expire_day: number
}

export interface GrowthTier {
  level: number
  status: GrowthTierStatus
  isFirstDeposit: boolean
  /** 「首充 N% 最高 X」只給首儲檔且獎勵類型為百分比 */
  showRateRule: boolean
  percent: number
  reward: string
  maxReward: string
  depositPoint: string
  unlocked: string
  claimed: string
  /** 可領取 = 已解鎖 − 已領取，不小於 0 */
  claimable: number
  remaining: string | null
  progress: number
  limitDays: number
  expireAt: string | null
}

export function growthBuildTiers(levels: readonly GrowthLevelLike[] | null | undefined): GrowthTier[] {
  return (levels ?? []).map((level) => {
    const levelNo = Number(level.level)
    return {
      level: levelNo,
      status: growthTierStatus(level.status),
      isFirstDeposit: levelNo === 0,
      showRateRule: levelNo === 0 && Number(level.reward_type) === GROWTH_REWARD_TYPE_PERCENTAGE,
      percent: growthToNumber(level.reward_percentage),
      reward: level.reward,
      maxReward: level.max_reward,
      depositPoint: level.deposit_point,
      unlocked: level.point,
      claimed: level.receive,
      claimable: Math.max(0, growthToNumber(level.point) - growthToNumber(level.receive)),
      remaining: level.remaining,
      progress: growthToNumber(level.progress),
      limitDays: Number(level.expire_day) || 0,
      expireAt: level.expire_time ?? null,
    }
  })
}

/** 當前檔位以 summary.level 為準；null 時退回第一個未結算的檔位，再退回最後一檔。 */
export function growthCurrentTier(tiers: readonly GrowthTier[], summaryLevel: number | null | undefined): GrowthTier | null {
  if (summaryLevel !== null && summaryLevel !== undefined) {
    const matched = tiers.find(tier => tier.level === Number(summaryLevel))
    if (matched) return matched
  }
  return tiers.find(tier => !GROWTH_SETTLED_STATUS.includes(tier.status)) ?? tiers[tiers.length - 1] ?? null
}

/** 後端只允許進行中(0)與已完成(2)領取；已完成要還有餘額。 */
export function growthTierClaimable(tier: GrowthTier): boolean {
  if (tier.status === 'ongoing') return true
  return tier.status === 'done' && tier.claimable > 0
}

export function growthTierExpandable(tier: GrowthTier): boolean {
  return tier.status === 'reached' || tier.status === 'ongoing' || tier.status === 'done'
}

export function growthTierHasProgressBar(tier: GrowthTier): boolean {
  return tier.status === 'ongoing' || tier.status === 'done'
}

/** 資格被限制：任何檔位被鎖定（IP／手機／實名重複）。 */
export function growthIsRestricted(tiers: readonly GrowthTier[]): boolean {
  return tiers.some(tier => tier.status === 'locked')
}

export function growthSecondsUntil(expireAt: string | null | undefined, nowMs: number): number {
  if (!expireAt) return 0
  const end = new Date(String(expireAt).replace(/-/g, '/')).getTime()
  if (!Number.isFinite(end)) return 0
  return Math.max(0, Math.floor((end - nowMs) / 1000))
}

/** 有沒有倒數在跑：總覽限時就有；全不限時則看有沒有任何檔位帶到期時間。 */
export function growthHasCountdown(summaryUnlimited: boolean, tiers: readonly GrowthTier[]): boolean {
  if (!summaryUnlimited) return true
  return tiers.some(tier => !!tier.expireAt)
}

export interface GrowthCountdownParts {
  days: number
  hours: string
  minutes: string
  seconds: string
}

export function growthCountdownParts(totalSeconds: number): GrowthCountdownParts {
  const total = Math.max(0, Math.floor(Number(totalSeconds) || 0))
  return {
    days: Math.floor(total / 86400),
    hours: String(Math.floor((total % 86400) / 3600)).padStart(2, '0'),
    minutes: String(Math.floor((total % 3600) / 60)).padStart(2, '0'),
    seconds: String(total % 60).padStart(2, '0'),
  }
}

/** 這次才變成進行中、還沒提示過的檔位。 */
export function growthNewlyOpenedLevels(tiers: readonly GrowthTier[], notified: readonly number[]): number[] {
  const known = new Set(notified.map(Number))
  return tiers.filter(tier => tier.status === 'ongoing' && !known.has(tier.level)).map(tier => tier.level)
}

/** receive 只回 summary 與 levels，setting／bet_settings 要保留 list 的內容。next 為空時不動原資料。 */
export function growthMergeDetail<T extends object, U extends object>(prev: T | null, next: U | null | undefined): (T & U) | T | null {
  if (!next) return prev
  return { ...(prev ?? {}), ...next } as T & U
}

/** 路由守衛：event/list 裡要有 tp_event 3 才能進成長彩金頁。 */
export function growthHasTpEvent(list: unknown, tpEvent = GROWTH_TP_EVENT): boolean {
  if (!Array.isArray(list)) return false
  return list.some(item => item && typeof item === 'object' && Number((item as { tp_event?: unknown }).tp_event) === tpEvent)
}

export function growthNotifiedCookieName(account: string): string {
  return account ? `wf_growth_notified_${account}` : 'wf_growth_notified'
}

export function growthStatusTextKey(status: GrowthTierStatus): string {
  return {
    pending: 'events.enum.growthPlanStatus.pending',
    reached: 'events.enum.growthPlanStatus.reached',
    ongoing: 'events.enum.growthPlanStatus.ongoing',
    done: 'events.enum.growthPlanStatus.done',
    expired: 'events.enum.growthPlanStatus.expired',
    locked: 'events.enum.growthPlanStatus.locked',
  }[status]
}
