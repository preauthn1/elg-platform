/** /competitions/refresh-ranking/game/[id] 的邏輯，所有皮共用，畫面在 components/<皮>/Page/CompetitionsRefreshRankingGame.vue。 */
export function useCompetitionsRefreshRankingGamePage() {
  const route = useRoute()
  const gameId = Number(route.params.id)
  const title = computed(() => (typeof route.query.name === 'string' ? route.query.name : ''))

  const game = useRefreshRankingGame(gameId)
  const { gameSrc, openWindow, popupBlocked, loading, failed, backPath } = game

  onMounted(() => {
    void game.launch()
  })

  return {
    title,
    game,
    gameSrc,
    openWindow,
    popupBlocked,
    loading,
    failed,
    backPath,
  }
}
