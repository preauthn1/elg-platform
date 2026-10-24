import type { ApiEnvelope } from '#shared/types/api'
import type { Awards, EventVendor, LeaderBoardRow, RefreshRankingSeason } from '#shared/api/endpoints/competitions'
import { eventGameListPath, isVendorPlayable, seasonLabel, splitLeaderBoard } from '#shared/utils/competitions'

/** 刷分排行榜主頁：期數、排行榜、活動錢包餘額、可玩遊戲商、獎項。 */
export function useRefreshRanking() {
  const api = useApi()
  const store = useRefreshRankingStore()

  const seasons = ref<RefreshRankingSeason[]>([])
  const selectedSeason = ref<RefreshRankingSeason | null>(null)
  const leaderBoard = ref<LeaderBoardRow[]>([])
  const awards = ref<Awards | null>(null)
  const walletBalance = ref<string | number | null>(null)
  const vendors = ref<EventVendor[]>([])
  const loading = ref(false)
  /** wallet-balance 沒有進行中活動時回 400（1901）：訊息由 useApi 彈窗，餘額欄改顯示 - */
  const walletFailed = ref(false)

  const seasonOptions = computed(() => seasons.value.map(season => ({
    no: season.no,
    label: seasonLabel(season),
  })))
  const board = computed(() => splitLeaderBoard(leaderBoard.value))
  const hasSeason = computed(() => selectedSeason.value !== null)
  const noData = computed(() => leaderBoard.value.length === 0)
  /** 排行榜頁籤顯示的我的投注量：取自選中期數，沒有期數為 0 */
  const myBetAmount = computed<string | number>(() => selectedSeason.value?.my_bet_amount ?? 0)

  async function fetchSeasons(): Promise<ApiEnvelope<RefreshRankingSeason[]>> {
    seasons.value = []
    const res = await api.post('event/refresh-ranking/list', {})
    if (isApiOk(res)) {
      seasons.value = res.data
      selectedSeason.value = res.data[0] ?? null
    }
    return res
  }

  async function fetchLeaderBoard(): Promise<ApiEnvelope<LeaderBoardRow[]> | null> {
    leaderBoard.value = []
    const season = selectedSeason.value
    if (!season) return null
    const res = await api.post('event/refresh-ranking/leader-board', { event_id: season.event_id, no: season.no })
    if (isApiOk(res)) leaderBoard.value = res.data
    return res
  }

  async function fetchAwards(eventId: number): Promise<ApiEnvelope<Awards>> {
    const res = await api.post('event/refresh-ranking/awards', { event_id: eventId })
    if (isApiOk(res)) awards.value = res.data
    return res
  }

  /** 活動錢包餘額；新端點不吃參數，餘額在 data.balance */
  async function fetchWalletBalance() {
    const res = await api.post('event/refresh-ranking/wallet-balance', {})
    walletFailed.value = !isApiOk(res)
    if (isApiOk(res)) walletBalance.value = res.data.balance
    return res
  }

  async function fetchVendors(): Promise<ApiEnvelope<EventVendor[]>> {
    const res = await api.post('event/refresh-ranking/vendor', {})
    // vendor 沒有活動遊戲商設定時回 400（1426）：訊息由 useApi 彈窗，頁面顯示空狀態
    if (isApiOk(res)) vendors.value = res.data
    return res
  }

  /** 首次載入：期數 → 預設第一期 → 排行榜／獎項；遊戲商與錢包餘額與期數無關，一併抓 */
  async function init() {
    loading.value = true
    try {
      await fetchSeasons()
      const season = selectedSeason.value
      const jobs: Promise<unknown>[] = [fetchVendors(), fetchWalletBalance()]
      if (season) jobs.push(fetchLeaderBoard(), fetchAwards(season.event_id))
      await Promise.all(jobs)
    }
    finally {
      loading.value = false
    }
  }

  /** 切換期數只重抓排行榜 */
  function selectSeason(season: RefreshRankingSeason) {
    selectedSeason.value = season
    return fetchLeaderBoard()
  }

  function selectSeasonByNo(no: number) {
    const season = seasons.value.find(item => item.no === no)
    return season ? selectSeason(season) : Promise.resolve(null)
  }

  /** 點遊戲商：只有 status 1 可進；記住遊戲商供遊戲頁組 exit_url */
  async function enterVendor(vendor: EventVendor): Promise<boolean> {
    if (!isVendorPlayable(vendor)) return false
    store.setVendor({ id: vendor.id, name: vendor.name })
    await navigateTo(eventGameListPath(vendor.id, vendor.name))
    return true
  }

  return {
    seasons,
    selectedSeason,
    seasonOptions,
    leaderBoard,
    board,
    awards,
    walletBalance,
    vendors,
    loading,
    walletFailed,
    hasSeason,
    noData,
    myBetAmount,
    init,
    fetchSeasons,
    fetchLeaderBoard,
    fetchAwards,
    fetchWalletBalance,
    fetchVendors,
    selectSeason,
    selectSeasonByNo,
    enterVendor,
    isVendorPlayable,
  }
}
