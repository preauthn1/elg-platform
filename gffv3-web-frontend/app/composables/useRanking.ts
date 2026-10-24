import type { ApiEnvelope } from '#shared/types/api'
import type {
  GameWinnerRow,
  PlatformBettingRow,
  ProfitRow,
  RankingType,
  WinningRow,
} from '#shared/api/endpoints/competitions'
import {
  PLATFORM_BETTING_RANKING_TYPE,
  RANKING_POLL_MS,
  WINNING_RANKING_TYPE,
  splitLeaderBoard,
} from '#shared/utils/competitions'

/** 每 15 分鐘重抓；在元件內呼叫時自動掛 mounted／unmount，否則由呼叫端 start／stop */
function useRankingPoller(task: () => Promise<unknown>, intervalMs = RANKING_POLL_MS) {
  let timer: ReturnType<typeof setInterval> | null = null

  function stopPolling() {
    if (timer !== null) clearInterval(timer)
    timer = null
  }

  function startPolling() {
    stopPolling()
    timer = setInterval(() => {
      void task()
    }, intervalMs)
  }

  if (getCurrentInstance()) {
    onMounted(() => {
      void task()
      startPolling()
    })
    onBeforeUnmount(stopPolling)
  }

  return { startPolling, stopPolling }
}

export type RankingTab = 'winning' | 'platformBetting'

/** 排行榜頁：中獎排行（type 1）與平台投注排行（type 2），切頁籤重抓、每 15 分鐘輪詢。 */
export function useRanking() {
  const api = useApi()

  const tab = ref<RankingTab>('winning')
  const winning = ref<WinningRow[]>([])
  const platformBetting = ref<PlatformBettingRow[]>([])
  const loading = ref(false)

  async function fetchWinning(type: RankingType = WINNING_RANKING_TYPE): Promise<ApiEnvelope<WinningRow[]>> {
    winning.value = []
    const res = await api.post('ranking/winning', { type })
    if (isApiOk(res)) winning.value = res.data
    return res
  }

  async function fetchPlatformBetting(type: RankingType = PLATFORM_BETTING_RANKING_TYPE): Promise<ApiEnvelope<PlatformBettingRow[]>> {
    platformBetting.value = []
    const res = await api.post('ranking/platform-betting', { type })
    if (isApiOk(res)) platformBetting.value = res.data
    return res
  }

  async function fetchCurrent() {
    loading.value = true
    try {
      return tab.value === 'winning' ? await fetchWinning() : await fetchPlatformBetting()
    }
    finally {
      loading.value = false
    }
  }

  function selectTab(next: RankingTab) {
    tab.value = next
    return fetchCurrent()
  }

  const { startPolling, stopPolling } = useRankingPoller(fetchCurrent)

  return {
    tab,
    winning,
    platformBetting,
    loading,
    fetchWinning,
    fetchPlatformBetting,
    fetchCurrent,
    selectTab,
    startPolling,
    stopPolling,
  }
}

export type WinnerRankingTab = 'gameWinners' | 'profits'

/** 贏家榜：今日遊戲贏家榜（預設）與今日盈利榜；不清舊資料直接覆蓋，每 15 分鐘輪詢。 */
export function useWinnerRanking() {
  const api = useApi()

  const tab = ref<WinnerRankingTab>('gameWinners')
  const gameWinners = ref<GameWinnerRow[]>([])
  const profits = ref<ProfitRow[]>([])
  const loading = ref(false)

  const current = computed<(GameWinnerRow | ProfitRow)[]>(() =>
    tab.value === 'gameWinners' ? gameWinners.value : profits.value,
  )
  const board = computed(() => splitLeaderBoard(current.value))

  async function fetchGameWinners(): Promise<ApiEnvelope<GameWinnerRow[]>> {
    const res = await api.post('ranking/game-winners', {})
    if (isApiOk(res)) gameWinners.value = res.data
    return res
  }

  async function fetchProfits(): Promise<ApiEnvelope<ProfitRow[]>> {
    const res = await api.post('ranking/profits', {})
    if (isApiOk(res)) profits.value = res.data
    return res
  }

  async function fetchCurrent() {
    loading.value = true
    try {
      return tab.value === 'gameWinners' ? await fetchGameWinners() : await fetchProfits()
    }
    finally {
      loading.value = false
    }
  }

  function selectTab(next: WinnerRankingTab) {
    tab.value = next
    return fetchCurrent()
  }

  const { startPolling, stopPolling } = useRankingPoller(fetchCurrent)

  return {
    tab,
    gameWinners,
    profits,
    current,
    board,
    loading,
    fetchGameWinners,
    fetchProfits,
    fetchCurrent,
    selectTab,
    startPolling,
    stopPolling,
  }
}
