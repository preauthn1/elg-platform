import { defineStore } from 'pinia'
import type { AgentTgBot, HierarchySummary, ShareLinks, WithdrawalSummary } from '#shared/utils/agent'

export interface MemberTeamInfo {
  directPlayerCount: number
  addedToday: number
  addedTodayGroupMember: number
  totalGroupMember: number
  todayCommission: number
  isPayout: boolean
  code: string
  link: string
}

/**
 * 代理中心跨頁共用狀態：總覽（/agent、/agent/qrcode、/setting 的上級帳號）、
 * 分享連結、今日流水、提領總覽、團隊資訊。資料是各 composable 正規化過的結果。
 */
export const useAgentStore = defineStore('agent', () => {
  const summary = ref<HierarchySummary | null>(null)
  const shareLinks = ref<ShareLinks | null>(null)
  const flowAmount = ref<number | null>(null)
  const withdrawalSummary = ref<WithdrawalSummary | null>(null)
  /** 申請提領成功後後端回的主錢包餘額，給錢包／會員領域刷新用 */
  const lastBalance = ref<number | null>(null)
  const teamInfo = ref<MemberTeamInfo | null>(null)
  /** TG bot 查過就不再查；查不到 bot 也算查過（null + loaded = 顯示找不到 bot） */
  const tgBot = ref<AgentTgBot | null>(null)
  const tgBotLoaded = ref(false)

  const parentAgentName = computed(() => summary.value?.topUsername ?? '')

  function setTgBot(bot: AgentTgBot | null) {
    tgBot.value = bot
    tgBotLoaded.value = true
  }

  function reset() {
    summary.value = null
    shareLinks.value = null
    flowAmount.value = null
    withdrawalSummary.value = null
    lastBalance.value = null
    teamInfo.value = null
    tgBot.value = null
    tgBotLoaded.value = false
  }

  return {
    summary,
    shareLinks,
    flowAmount,
    withdrawalSummary,
    lastBalance,
    teamInfo,
    tgBot,
    tgBotLoaded,
    parentAgentName,
    setTgBot,
    reset,
  }
})
