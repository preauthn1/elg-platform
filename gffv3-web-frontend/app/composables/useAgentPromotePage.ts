import { decFormat } from '#shared/utils/amount'
import type { ApiEnvelope } from '#shared/types/api'
import { awardTypeKey } from '#shared/utils/agent'

type Tab = 'players' | 'rewards' | 'rebates' | 'achievements' | 'turnover'

/** /agent/promote 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AgentPromote.vue。 */
export function useAgentPromotePage() {
  /**
   * 我的推廣／我的返利：
   * - 無限級：我的玩家（direct-players）、我的業績（reward）
   * - 三級分銷：我的返利（rebates）、我的業績（achievements）
   * - 開自身回水時多一個「我的流水」分頁（reward 過濾 flow / itself_reward > 0）
   *
   * 表格走 UiTable（同邀請好友頁的三張表）；欄位多的分頁給最小寬度讓它橫向捲，不硬擠成一行一字。
   */
  const { t } = useI18n()
  const { isThree, showLoseRebate, itselfRebate } = useAgentMode()
  const {
    directPlayers,
    directPlayersPage,
    rewards,
    rewardsPage,
    turnoverRows,
    threeAchievements,
    threeAchievementsPage,
    threeRebates,
    threeRebatesPage,
    fetchDirectPlayers,
    fetchRewards,
    fetchThreeAchievements,
    fetchThreeRebates,
  } = useAgentCenter()

  const titleKey = computed(() => (isThree.value ? 'agent.center.label.myRebate' : 'agent.center.label.myPromote'))

  const tabItems = computed<Array<{ key: Tab, label: string }>>(() => {
    const list: Array<{ key: Tab, label: string }> = isThree.value
      ? [{ key: 'rebates', label: t('agent.center.label.myRebate') }, { key: 'achievements', label: t('agent.center.label.mySales') }]
      : [{ key: 'players', label: t('agent.center.label.myMembers') }, { key: 'rewards', label: t('agent.center.label.mySales') }]
    if (itselfRebate.value) list.push({ key: 'turnover', label: t('agent.promote.label.myTurnover') })
    return list
  })

  const tab = ref<Tab>('players')
  const lastUpdated = ref('')

  /** 目前分頁的欄名與內容；欄數超過 4 時給 UiTable 最小寬度、外層橫向捲動 */
  const table = computed<{ columns: string[], rows: string[][] }>(() => {
    switch (tab.value) {
      case 'players':
        return {
          columns: [
            t('agent.center.label.account'),
            t('agent.performance.label.todayTeam'),
            t('agent.performance.label.todayMember'),
            t('agent.team.label.teamMember'),
            t('agent.team.label.directMember'),
            t('common.ui.label.type'),
          ],
          rows: directPlayers.value.map(row => [
            row.member,
            decFormat(row.teamContribution, 0),
            decFormat(row.memberContribution, 0),
            String(row.teamCount),
            String(row.memberCount),
            row.typeKind === 'agent' ? '代理' : '會員',
          ]),
        }
      case 'rewards':
        return {
          columns: [
            t('agent.performance.label.sendTime'),
            t('agent.performance.label.dailyTotalSales'),
            t('agent.performance.label.dailyPromoteSales'),
            t('agent.performance.label.dailyDirectSales'),
            t('common.amount.label.bonus'),
            t('common.ui.label.status'),
            t('agent.performance.label.note'),
          ],
          rows: rewards.value.map(row => [
            row.date,
            decFormat(row.totalContribution),
            decFormat(row.teamContribution),
            decFormat(row.memberContribution),
            decFormat(row.reward),
            String(row.type ?? ''),
            String(row.memo ?? ''),
          ]),
        }
      case 'rebates':
        return {
          columns: [
            t('common.time.label.date'),
            t('agent.rebate.label.selfRebate'),
            t('agent.rebate.label.downlineRebate'),
            t('agent.rebate.label.level3Rebate'),
            ...(showLoseRebate.value
              ? [t('agent.rebate.label.selfLostRebate'), t('agent.rebate.label.downlineLostRebate'), t('agent.rebate.label.level3LostRebate')]
              : []),
            t('common.amount.label.bonus'),
          ],
          rows: threeRebates.value.map(row => [
            row.date,
            decFormat(row.rebate_1),
            decFormat(row.rebate_2),
            decFormat(row.rebate_3),
            ...(showLoseRebate.value ? [decFormat(row.lose_rebate_1), decFormat(row.lose_rebate_2), decFormat(row.lose_rebate_3)] : []),
            decFormat(row.reward),
          ]),
        }
      case 'achievements':
        return {
          columns: [
            t('common.time.label.date'),
            t('agent.center.label.account'),
            ...(showLoseRebate.value ? [t('agent.rebate.label.awardType')] : []),
            t('games.vendor.label.gameVendor'),
            t('common.filter.label.type'),
            t('common.amount.label.betAmount'),
            t('common.amount.label.bonus'),
          ],
          rows: threeAchievements.value.map((row) => {
            const award = awardTypeKey(row.rebate_type)
            return [
              row.date,
              row.username,
              ...(showLoseRebate.value ? [award ? t(award) : ''] : []),
              row.vendor,
              row.game_type,
              decFormat(row.bet),
              decFormat(row.reward),
            ]
          }),
        }
      case 'turnover':
        return {
          columns: [
            t('agent.performance.label.sendTime'),
            t('agent.performance.label.dailyTurnover'),
            t('common.amount.label.bonus'),
          ],
          rows: turnoverRows.value.map(row => [row.date, decFormat(row.flow), decFormat(row.itself_reward)]),
        }
      default:
        return { columns: [], rows: [] }
    }
  })

  /** 每欄約 90px：4 欄以內剛好塞滿 358，再多就橫向捲 */
  const tableMinWidth = computed(() => (table.value.columns.length > 4 ? `${table.value.columns.length * 90}px` : undefined))

  onMounted(async () => {
    tab.value = isThree.value ? 'rebates' : 'players'
    lastUpdated.value = new Date().toLocaleString()
    // 各分頁端點回應形狀不同，收斂成 unknown 只為了一起等
    const jobs: Array<Promise<ApiEnvelope<unknown>>> = [fetchRewards(1)]
    if (isThree.value) jobs.push(fetchThreeAchievements(1), fetchThreeRebates(1))
    else jobs.push(fetchDirectPlayers(1))
    await Promise.all(jobs)
  })

  return {
    directPlayersPage,
    rewardsPage,
    threeAchievementsPage,
    threeRebatesPage,
    fetchDirectPlayers,
    fetchRewards,
    fetchThreeAchievements,
    fetchThreeRebates,
    titleKey,
    tabItems,
    tab,
    lastUpdated,
    table,
    tableMinWidth,
  }
}
