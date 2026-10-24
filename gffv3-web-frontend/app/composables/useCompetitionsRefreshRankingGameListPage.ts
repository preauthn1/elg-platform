/** /competitions/refresh-ranking/game/list/[vendorId] 的邏輯，所有皮共用，畫面在 components/<皮>/Page/CompetitionsRefreshRankingGameList.vue。 */
export function useCompetitionsRefreshRankingGameListPage() {
  const route = useRoute()
  const vendorId = Number(route.params.vendorId)
  const vendorName = typeof route.query.vendorName === 'string' ? route.query.vendorName : ''

  const list = useRefreshRankingGameList(vendorId, vendorName)
  const { title, typeIds, selectedTypeId, visibleGames, loading } = list

  onMounted(() => {
    void list.fetchGames()
  })

  return {
    list,
    title,
    typeIds,
    selectedTypeId,
    visibleGames,
    loading,
  }
}
