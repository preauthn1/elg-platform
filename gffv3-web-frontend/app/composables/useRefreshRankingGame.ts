import type { ApiEnvelope } from '#shared/types/api'
import type { EventGame, GameUrl } from '#shared/api/endpoints/competitions'
import {
  buildExitUrl,
  canEnterEventGame,
  eventGamePath,
  filterEventGamesByType,
  eventGameTypeIds,
  pickEventGameSrc,
  shouldOpenWindow,
} from '#shared/utils/competitions'

/**
 * 刷分排行榜的活動遊戲列表：無分頁、分類頁籤由 type_id 推出、不顯示餘額也不做單一錢包自動轉帳。
 * vendorName 來自路由 query，同時寫回 store 讓重新整理後的遊戲頁仍能組 exit_url。
 */
export function useRefreshRankingGameList(vendorId: number, vendorName = '') {
  const api = useApi()
  const store = useRefreshRankingStore()

  if (vendorName) store.setVendor({ id: vendorId, name: vendorName })

  const games = ref<EventGame[]>([])
  const selectedTypeId = ref<number | null>(null)
  const loading = ref(false)

  const title = computed(() => vendorName || store.vendor?.name || '')
  const typeIds = computed(() => eventGameTypeIds(games.value))
  const visibleGames = computed(() => filterEventGamesByType(games.value, selectedTypeId.value))

  async function fetchGames(): Promise<ApiEnvelope<EventGame[]>> {
    games.value = []
    loading.value = true
    try {
      const res = await api.post('event/refresh-ranking/game', { vendor_id: vendorId })
      if (isApiOk(res)) games.value = res.data
      return res
    }
    finally {
      loading.value = false
    }
  }

  function selectType(typeId: number | null) {
    selectedTypeId.value = typeId
  }

  /** 點遊戲：停用／維護中不可進；直接走活動遊戲頁，不經一般遊戲的各家啟動分支 */
  async function enterGame(game: EventGame): Promise<boolean> {
    if (!canEnterEventGame(game)) return false
    await navigateTo(eventGamePath(game.id, game.name))
    return true
  }

  return {
    title,
    games,
    typeIds,
    selectedTypeId,
    visibleGames,
    loading,
    fetchGames,
    selectType,
    enterGame,
    canEnterEventGame,
  }
}

/**
 * 刷分排行榜的活動遊戲啟動：exit_url 自己組（回該遊戲商的遊戲列表），
 * open_window 1 開新視窗（被擋時給手動開啟按鈕），否則 iframe。
 */
export function useRefreshRankingGame(gameId: number) {
  const api = useApi()
  const store = useRefreshRankingStore()
  const { site } = useSite()
  const requestUrl = useRequestURL()

  const gameSrc = ref('')
  const openWindow = ref(false)
  const popupBlocked = ref(false)
  const loading = ref(false)
  /** 取遊戲網址失敗：訊息由 useApi 彈窗，頁面只顯示重試按鈕 */
  const failed = ref(false)

  const isMobile = computed(() => (site.value?.device ?? 'pc') !== 'pc')
  const exitUrl = computed(() => buildExitUrl(requestUrl.origin, store.vendor))
  const backPath = computed(() => exitUrl.value.slice(requestUrl.origin.length))

  /** 只在瀏覽器執行；回傳是否成功開出視窗 */
  function openInNewWindow(): boolean {
    if (!import.meta.client || !gameSrc.value) return false
    const win = window.open(gameSrc.value, '_blank')
    popupBlocked.value = !win
    return Boolean(win)
  }

  async function launch(): Promise<ApiEnvelope<GameUrl>> {
    loading.value = true
    failed.value = false
    gameSrc.value = ''
    openWindow.value = false
    popupBlocked.value = false
    try {
      const res = await api.post('event/refresh-ranking/game-url', { game_id: gameId, exit_url: exitUrl.value })
      if (!isApiOk(res)) {
        failed.value = true
        return res
      }
      gameSrc.value = pickEventGameSrc(res.data, isMobile.value)
      openWindow.value = shouldOpenWindow(res.data)
      if (openWindow.value) openInNewWindow()
      return res
    }
    finally {
      loading.value = false
    }
  }

  return {
    gameSrc,
    openWindow,
    popupBlocked,
    loading,
    failed,
    isMobile,
    exitUrl,
    backPath,
    launch,
    openInNewWindow,
  }
}
