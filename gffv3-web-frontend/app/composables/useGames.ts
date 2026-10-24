import type { ApiEnvelope } from '#shared/types/api'
import type { RequestOf } from '#shared/api/registry'
import type {
  GamesByTagItem,
  LobbyVendor,
  PromotionGame,
  VendorGameType,
} from '#shared/api/endpoints/games'
import { API_CODE } from '#shared/constants/codes'
import { buildGameListRequest, getVendorPath, VENDOR_ITEM_TYPE, type GameListQuery } from '#shared/utils/games'

export type { GameListQuery }

/**
 * 大廳／列表類端點。可在 SSR 的 useAsyncData 內呼叫（composable 本身要在 setup 建立）。
 * 成功後該存進 store 的資料（場館、類型對照、hot/new 快取、redirect_url）都在這裡寫。
 */
export function useGames() {
  const api = useApi()
  const store = useGamesStore()
  const site = useSite()
  const session = useUserSession()

  async function fetchLobbyVendors(): Promise<ApiEnvelope<LobbyVendor[]>> {
    const res = await api.post('lobby/vendors', {})
    if (isApiOk(res)) store.setVendors(res.data ?? [])
    return res
  }

  /** 取單一場館：vendor_ids 帶一筆，回傳陣列取第一筆；不覆寫大廳列表。 */
  async function fetchVendor(vendorId: number, typeId: number | null = null): Promise<ApiEnvelope<LobbyVendor | null>> {
    const body: RequestOf<'lobby/vendors'> = { vendor_ids: [vendorId] }
    if (typeId !== null) body.type_id = typeId
    const res = await api.post('lobby/vendors', body)
    return { ...res, data: isApiOk(res) ? (res.data?.[0] ?? null) : null }
  }

  async function fetchGameTypes(): Promise<ApiEnvelope<VendorGameType[]>> {
    const res = await api.post('vendor/game-types', {})
    if (isApiOk(res)) store.setGameTypes(res.data ?? [])
    return res
  }

  /** 大廳資料：場館與類型對照並行請求，兩者都成功才算就緒。 */
  async function fetchLobby(): Promise<boolean> {
    const [vendors, types] = await Promise.all([fetchLobbyVendors(), fetchGameTypes()])
    const ok = isApiOk(vendors) && isApiOk(types)
    if (ok) store.markLobbyLoaded()
    return ok
  }

  async function fetchGamesByTag(tag: string): Promise<ApiEnvelope<GamesByTagItem[]>> {
    const res = await api.post('lobby/games', { tag })
    if (isApiOk(res)) store.setGamesByTag(tag, res.data ?? [])
    return res
  }

  async function fetchGameList(query: GameListQuery) {
    const res = await api.post('game/list', buildGameListRequest(query))
    if (isApiOk(res)) store.setRedirectUrl(res.data.redirect_url ?? '')
    return res
  }

  async function fetchGameListByTypes(typeCounts: Record<number, number>, vendorIds?: number[]) {
    const body: RequestOf<'game/list-by-types'> = {
      type_counts: Object.fromEntries(Object.entries(typeCounts).map(([k, v]) => [String(k), v])),
    }
    if (vendorIds?.length) body.vendor_ids = vendorIds
    const res = await api.post('game/list-by-types', body)
    if (isApiOk(res)) store.setRedirectUrl(res.data.redirect_url ?? '')
    return res
  }

  async function fetchRecentlyGames() {
    const res = await api.post('game/recently-game-ids', {})
    if (isApiOk(res)) {
      store.setRecentGames(res.data.games ?? [])
      if (res.data.redirect_url) store.setRedirectUrl(res.data.redirect_url)
    }
    return res
  }

  /** 後端直接信任 member_id，這裡只送 session 會員自己的 id。 */
  async function fetchRecentlyVendorIds(): Promise<ApiEnvelope<{ vendor_id: number }[]>> {
    const memberId = Number(session.user.value?.id)
    if (!Number.isFinite(memberId) || memberId <= 0) {
      return { code: API_CODE.RELOGIN, message: 'login required', data: [] }
    }
    return api.post('vendor/recently-vendor-ids', { member_id: memberId })
  }

  async function fetchPromotionGames(): Promise<ApiEnvelope<{ recent_games: PromotionGame[], recommend_games: PromotionGame[] }>> {
    const res = await api.post('game/promotion-games', {})
    if (isApiOk(res)) store.setPromotionGames(res.data.recent_games ?? [], res.data.recommend_games ?? [])
    return res
  }

  function vendorPath(vendor: LobbyVendor, typeId: number | null = null): string | null {
    return getVendorPath(vendor, typeId)
  }

  /** 點場館：站台開了場館前頁且非站內連結時先到 /vendor-amount（錢包領域）。 */
  async function goToVendor(vendor: LobbyVendor, typeId: number | null = null) {
    const path = getVendorPath(vendor, typeId)
    if (!path) return
    if (site.flag('has_game_provider_front_page') && vendor.item_type !== VENDOR_ITEM_TYPE.LINK) {
      store.selectVendor(vendor)
      await navigateTo({ path: '/vendor-amount', query: typeId === null ? {} : { typeId: String(typeId) } })
      return
    }
    await navigateTo(path)
  }

  return {
    fetchLobbyVendors,
    fetchVendor,
    fetchGameTypes,
    fetchLobby,
    fetchGamesByTag,
    fetchGameList,
    fetchGameListByTypes,
    fetchRecentlyGames,
    fetchRecentlyVendorIds,
    fetchPromotionGames,
    vendorPath,
    goToVendor,
  }
}
