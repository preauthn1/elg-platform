type Tab = 'competitions.ranking.nav.leaderboard' | 'competitions.common.nav.betting' | 'competitions.rules.nav.detailedRules'

/** /competitions/refresh-ranking 的邏輯，所有皮共用，畫面在 components/<皮>/Page/CompetitionsRefreshRanking.vue。 */
export function useCompetitionsRefreshRankingPage() {
  const tabs: Tab[] = ['competitions.ranking.nav.leaderboard', 'competitions.common.nav.betting', 'competitions.rules.nav.detailedRules']
  const tab = ref<Tab>('competitions.ranking.nav.leaderboard')
  const podiumLabels = ['competitions.ranking.label.rank01', 'competitions.ranking.label.secondPlace', 'competitions.ranking.label.rank03'] as const

  const ranking = useRefreshRanking()
  const {
    selectedSeason,
    seasonOptions,
    board,
    awards,
    walletBalance,
    vendors,
    loading,
    walletFailed,
    hasSeason,
    noData,
    myBetAmount,
  } = ranking

  onMounted(() => {
    void ranking.init()
  })

  function onSeasonChange(event: Event) {
    void ranking.selectSeasonByNo(Number((event.target as HTMLSelectElement).value))
  }

  return {
    tabs,
    tab,
    podiumLabels,
    ranking,
    selectedSeason,
    seasonOptions,
    board,
    awards,
    walletBalance,
    vendors,
    loading,
    walletFailed,
    hasSeason,
    noData,
    myBetAmount,
    onSeasonChange,
  }
}
