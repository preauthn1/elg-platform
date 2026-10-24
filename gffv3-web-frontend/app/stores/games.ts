import { defineStore } from 'pinia'
import type {
  GameAppInfos,
  GameListItem,
  GamesByTagItem,
  LobbyVendor,
  PromotionGame,
  VendorEvent,
  VendorGameType,
} from '#shared/api/endpoints/games'
import { vendorsById, type GameLike } from '#shared/utils/games'

/**
 * 遊戲領域跨頁狀態（鍵名照後端）：大廳場館與類型對照、hot/new 標籤快取、
 * NPT 中轉域名、聲明頁選中的遊戲、進遊戲前的來源網址、APP 場館登入資訊。
 */
export const useGamesStore = defineStore('games', () => {
  const vendors = ref<LobbyVendor[]>([])
  const gameTypes = ref<VendorGameType[]>([])
  const lobbyLoadedAt = ref<number | null>(null)
  const gamesByHotTag = ref<GamesByTagItem[]>([])
  const gamesByNewTag = ref<GamesByTagItem[]>([])
  const redirectUrl = ref('')
  const selectedGame = ref<GameLike | null>(null)
  const selectedVendor = ref<LobbyVendor | null>(null)
  const gameFromUrl = ref('')
  const recentGames = ref<GameListItem[]>([])
  const vendorInfo = ref<GameAppInfos | null>(null)
  const vendorEvents = ref<VendorEvent[]>([])
  const recentPromotionGames = ref<PromotionGame[]>([])
  const recommendPromotionGames = ref<PromotionGame[]>([])

  /** 以場館 id 為 key，供快捷投注元件、轉點頁查場館狀態。 */
  const vendorMap = computed(() => vendorsById(vendors.value))
  const lobbyReady = computed(() => lobbyLoadedAt.value !== null)
  /** APP 場館頁只列有遊戲的活動。 */
  const activeVendorEvents = computed(() => vendorEvents.value.filter(e => (e.games?.length ?? 0) > 0))

  function setVendors(list: LobbyVendor[]) {
    vendors.value = list
  }

  function setGameTypes(list: VendorGameType[]) {
    gameTypes.value = list
  }

  function markLobbyLoaded() {
    lobbyLoadedAt.value = Date.now()
  }

  function setGamesByTag(tag: string, list: GamesByTagItem[]) {
    if (tag === 'hot') gamesByHotTag.value = list
    if (tag === 'new') gamesByNewTag.value = list
  }

  function setRedirectUrl(url: string) {
    redirectUrl.value = url
  }

  function selectGame(game: GameLike | null) {
    selectedGame.value = game
  }

  function selectVendor(vendor: LobbyVendor | null) {
    selectedVendor.value = vendor
  }

  function setGameFromUrl(url: string) {
    gameFromUrl.value = url
  }

  function setRecentGames(list: GameListItem[]) {
    recentGames.value = list
  }

  function setVendorInfo(info: GameAppInfos | null) {
    vendorInfo.value = info
  }

  function setVendorEvents(list: VendorEvent[]) {
    vendorEvents.value = list
  }

  function setPromotionGames(recent: PromotionGame[], recommend: PromotionGame[]) {
    recentPromotionGames.value = recent
    recommendPromotionGames.value = recommend
  }

  function reset() {
    vendors.value = []
    gameTypes.value = []
    lobbyLoadedAt.value = null
    gamesByHotTag.value = []
    gamesByNewTag.value = []
    redirectUrl.value = ''
    selectedGame.value = null
    selectedVendor.value = null
    gameFromUrl.value = ''
    recentGames.value = []
    vendorInfo.value = null
    vendorEvents.value = []
    recentPromotionGames.value = []
    recommendPromotionGames.value = []
  }

  return {
    vendors,
    gameTypes,
    lobbyLoadedAt,
    gamesByHotTag,
    gamesByNewTag,
    redirectUrl,
    selectedGame,
    selectedVendor,
    gameFromUrl,
    recentGames,
    vendorInfo,
    vendorEvents,
    recentPromotionGames,
    recommendPromotionGames,
    vendorMap,
    lobbyReady,
    activeVendorEvents,
    setVendors,
    setGameTypes,
    markLobbyLoaded,
    setGamesByTag,
    setRedirectUrl,
    selectGame,
    selectVendor,
    setGameFromUrl,
    setRecentGames,
    setVendorInfo,
    setVendorEvents,
    setPromotionGames,
    reset,
  }
})
