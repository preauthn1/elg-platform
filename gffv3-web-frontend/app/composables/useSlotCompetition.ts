import type { ApiEnvelope } from '#shared/types/api'
import type { Awards, LeaderBoardRow, Season, SlotRankingRow } from '#shared/api/endpoints/competitions'
import { seasonLabel, splitLeaderBoard } from '#shared/utils/competitions'

export interface UseSlotCompetitionOptions {
  /** 領獎成功後重抓會員餘額／錢包（由錢包領域提供；尚未接上時不做事） */
  refreshWallet?: () => void | Promise<void>
}

/** 老虎機爭霸賽：期數、排行榜、我的排名、獎項與領獎。 */
export function useSlotCompetition(options: UseSlotCompetitionOptions = {}) {
  const api = useApi()

  const seasons = ref<Season[]>([])
  const selectedSeason = ref<Season | null>(null)
  const leaderBoard = ref<LeaderBoardRow[]>([])
  const myRanking = ref<SlotRankingRow[]>([])
  const awards = ref<Awards | null>(null)
  const loading = ref(false)
  const receiving = ref(false)

  const seasonOptions = computed(() => seasons.value.map(season => ({
    no: season.no,
    label: seasonLabel(season),
  })))
  const board = computed(() => splitLeaderBoard(leaderBoard.value))
  const hasSeason = computed(() => selectedSeason.value !== null)
  const noData = computed(() => leaderBoard.value.length === 0)

  async function fetchSeasons(): Promise<ApiEnvelope<Season[]>> {
    seasons.value = []
    const res = await api.post('event/slot-competition/list', {})
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
    const res = await api.post('event/slot-competition/leader-board', { event_id: season.event_id, no: season.no })
    if (isApiOk(res)) leaderBoard.value = res.data
    return res
  }

  async function fetchRanking(eventId: number): Promise<ApiEnvelope<SlotRankingRow[]>> {
    const res = await api.post('event/slot-competition/ranking', { event_id: eventId })
    if (isApiOk(res)) myRanking.value = res.data
    return res
  }

  async function fetchAwards(eventId: number): Promise<ApiEnvelope<Awards>> {
    const res = await api.post('event/slot-competition/awards', { event_id: eventId })
    if (isApiOk(res)) awards.value = res.data
    return res
  }

  /** 首次載入：期數 → 預設第一期 → 排行榜／我的排名／獎項 */
  async function init() {
    loading.value = true
    try {
      await fetchSeasons()
      const season = selectedSeason.value
      if (!season) return
      await Promise.all([fetchLeaderBoard(), fetchRanking(season.event_id), fetchAwards(season.event_id)])
    }
    finally {
      loading.value = false
    }
  }

  /** 切換期數只重抓排行榜 */
  function selectSeason(season: Season) {
    selectedSeason.value = season
    return fetchLeaderBoard()
  }

  function selectSeasonByNo(no: number) {
    const season = seasons.value.find(item => item.no === no)
    return season ? selectSeason(season) : Promise.resolve(null)
  }

  /** 領獎：成功與失敗都彈出訊息（失敗由 useApi 彈）並重抓我的排名；成功時另請錢包領域刷新餘額 */
  async function receive(row: Pick<SlotRankingRow, 'event_id' | 'no'>): Promise<ApiEnvelope<unknown>> {
    receiving.value = true
    try {
      const res = await api.post('event/slot-competition/receive', { event_id: row.event_id, no: row.no })
      if (isApiOk(res)) {
        useAlert().showResponse(res)
        await options.refreshWallet?.()
      }
      await fetchRanking(row.event_id)
      return res
    }
    finally {
      receiving.value = false
    }
  }

  return {
    seasons,
    selectedSeason,
    seasonOptions,
    leaderBoard,
    board,
    myRanking,
    awards,
    loading,
    receiving,
    hasSeason,
    noData,
    init,
    fetchSeasons,
    fetchLeaderBoard,
    fetchRanking,
    fetchAwards,
    selectSeason,
    selectSeasonByNo,
    receive,
  }
}
