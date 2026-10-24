type Tab = 'competitions.ranking.nav.leaderboard' | 'competitions.ranking.nav.myRanking' | 'competitions.rules.nav.detailedRules'

/** /competitions/slot 的邏輯，所有皮共用，畫面在 components/<皮>/Page/CompetitionsSlot.vue。 */
export function useCompetitionsSlotPage() {
  const tabs: Tab[] = ['competitions.ranking.nav.leaderboard', 'competitions.ranking.nav.myRanking', 'competitions.rules.nav.detailedRules']
  const tab = ref<Tab>('competitions.ranking.nav.leaderboard')
  const podiumLabels = ['competitions.ranking.label.rank01', 'competitions.ranking.label.secondPlace', 'competitions.ranking.label.rank03'] as const

  const competition = useSlotCompetition()
  const {
    selectedSeason,
    seasonOptions,
    board,
    myRanking,
    awards,
    loading,
    receiving,
    hasSeason,
    noData,
  } = competition

  onMounted(() => {
    void competition.init()
  })

  function onSeasonChange(event: Event) {
    void competition.selectSeasonByNo(Number((event.target as HTMLSelectElement).value))
  }

  return {
    tabs,
    tab,
    podiumLabels,
    competition,
    selectedSeason,
    seasonOptions,
    board,
    myRanking,
    awards,
    loading,
    receiving,
    hasSeason,
    noData,
    onSeasonChange,
  }
}
