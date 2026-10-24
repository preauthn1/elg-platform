/** 抽獎券三變體共用一套頁面，只差端點與開獎號碼呈現。 */
export type LotteryVariant = 'lucky-draw' | 'wjackpot' | 'wjackpot6d'

export const LOTTERY_VARIANTS = {
  'lucky-draw': {
    variant: 'lucky-draw',
    pagePrefix: '/lucky-draw',
    /** 未開獎的佔位字，三變體各不相同 */
    placeholder: '-------',
    resultGroups: false,
    routes: {
      issues: 'event/lucky-draw/issues',
      detail: 'event/lucky-draw/issues/detail',
      tickets: 'event/lucky-draw/issues/tickets',
      receive: 'event/lucky-draw/issues/receive',
      receiveAll: 'event/lucky-draw/issues/receive-all',
    },
  },
  'wjackpot': {
    variant: 'wjackpot',
    pagePrefix: '/wjackpot',
    placeholder: '----',
    resultGroups: true,
    routes: {
      issues: 'event/wjackpot/issues',
      detail: 'event/wjackpot/issues/detail',
      tickets: 'event/wjackpot/issues/tickets',
      receive: 'event/wjackpot/issues/receive',
      receiveAll: 'event/wjackpot/issues/receive-all',
    },
  },
  'wjackpot6d': {
    variant: 'wjackpot6d',
    pagePrefix: '/wjackpot/6d',
    placeholder: '------',
    resultGroups: false,
    routes: {
      issues: 'event/wjackpot6d/issues',
      detail: 'event/wjackpot6d/issues/detail',
      tickets: 'event/wjackpot6d/issues/tickets',
      receive: 'event/wjackpot6d/issues/receive',
      receiveAll: 'event/wjackpot6d/issues/receive-all',
    },
  },
} as const

export type LotteryVariantConfig = (typeof LOTTERY_VARIANTS)[LotteryVariant]

export const LOTTERY_PER_PAGE = 10
export const LOTTERY_DETAIL_REFRESH_DELAY_MS = 1000

export function lotteryPagePath(variant: LotteryVariant, page: 'issues' | 'detail' | 'tickets', issueId?: number | string): string {
  const prefix = LOTTERY_VARIANTS[variant].pagePrefix
  if (page === 'issues') return `${prefix}/issues`
  return `${prefix}/${page}/${issueId ?? ''}`
}

export interface LotteryTicketLike {
  reward_amount: string | number
  reward_type: number | null
  reward_level: number
  reward_name: string
  status: number
  statusText: string
}

/** 可領：有獎（金額 > 0 或實體獎品）且已開獎未領（status 2）。 */
export function lotteryTicketClaimable(ticket: LotteryTicketLike): boolean {
  const hasReward = Number(ticket.reward_amount) > 0 || Number(ticket.reward_type) === 2
  return hasReward && Number(ticket.status) === 2
}

/** 按鈕文字：可領現金顯示金額、可領實體顯示獎品名，其餘顯示後端狀態文字。 */
export function lotteryTicketLabel(ticket: LotteryTicketLike): string {
  if (lotteryTicketClaimable(ticket)) {
    if (Number(ticket.reward_type) === 1) return String(ticket.reward_amount)
    if (Number(ticket.reward_type) === 2) return ticket.reward_name
  }
  return ticket.statusText
}

export function lotteryTicketShowLevelIcon(ticket: LotteryTicketLike): boolean {
  return lotteryTicketClaimable(ticket) && Number(ticket.reward_level) > 0
}

/** 列表序號（1 起算）。 */
export function lotteryRowNumber(page: number, perPage: number, index: number): number {
  return (page - 1) * perPage + index + 1
}

export function lotteryShowPagination(total: number, perPage: number): boolean {
  return total > perPage
}

export function lotteryCanReceiveAll(summary: { totalCount_unclaimed: string | number } | null | undefined): boolean {
  return Number(summary?.totalCount_unclaimed ?? 0) > 0
}

/** 期數列表只顯示開獎日期（前 10 碼）。 */
export function lotteryDrawDate(drawTime: string | null | undefined): string {
  return drawTime ? String(drawTime).substring(0, 10) : ''
}

export interface LotteryResultGroup {
  /** i18n key wjackpot5-1 .. wjackpot5-5 */
  key: string
  value: string
}

/** W-Jackpot 五組開獎：第一組用 result_number，其餘 _2.._5；陣列以空白串接。 */
export function wjackpotResultGroups(detail: Record<string, unknown> & { result_number: string }): LotteryResultGroup[] {
  const pick = (index: number): string => {
    const raw = index === 1 ? detail.result_number : detail[`result_number_${index}`]
    if (Array.isArray(raw)) return raw.map(String).join(' ')
    return raw == null ? '' : String(raw)
  }
  return [1, 2, 3, 4, 5].map(index => ({ key: `wjackpot5-${index}`, value: pick(index) }))
}

/** 領取成功訊息 = successfully + '！' + Msg_AnyQuestionPleaseAskCS。 */
export function lotteryReceiveMessage(t: (key: string) => string): string {
  return `${t('common.ui.msg.success')}！${t('events.common.msg.contactCs')}`
}
