import type { GameListItem } from '#shared/api/endpoints/games'
import { filterGamesByType, gameTypeLabelKey, parseTypeId, rootTypeIds } from '#shared/utils/games'

/**
 * 遊戲商的遊戲列表（/game/list/:vendorId，all＝全部遊戲商）：類型篩選、分頁、進列表時轉入該場館。
 * 所有皮共用，畫面在 components/<皮>/Page/GameList.vue。
 *
 * 要 await：資料在 SSR 就要抓好。所有 composable 都在 await 之前建立，await 之後不能再呼叫。
 */
export async function useVendorGameList() {
  const route = useRoute()
  const games = useGames()
  const launcher = useGameLauncher()
  const store = useGamesStore()

  const vendorParam = computed(() => String(route.params.vendorId))
  const vendorId = computed<number | 'all'>(() => (vendorParam.value === 'all' ? 'all' : Number(vendorParam.value)))
  const numericVendorId = computed(() => (vendorId.value === 'all' ? null : vendorId.value))
  const queryTypeId = computed(() => parseTypeId(route.query.typeId))
  const queryVendorName = computed(() => (typeof route.query.vendorName === 'string' ? route.query.vendorName : ''))

  const typeId = ref<number | null>(queryTypeId.value)
  const page = ref(1)

  const vendorAsync = useAsyncData(`games-list-vendor-${vendorParam.value}`, async () => {
    const [vendor] = await Promise.all([
      numericVendorId.value !== null && !queryVendorName.value
        ? games.fetchVendor(numericVendorId.value, queryTypeId.value)
        : Promise.resolve(null),
      store.lobbyReady ? Promise.resolve(null) : games.fetchGameTypes(),
    ])
    return vendor?.data ?? null
  })
  const vendorData = vendorAsync.data

  const listAsync = useAsyncData(
    `games-list-${vendorParam.value}-${queryTypeId.value ?? 'all'}`,
    () => games.fetchGameList({ vendorId: vendorId.value, typeId: typeId.value, page: page.value }),
    { watch: [page, typeId] },
  )
  const { data: listData, status } = listAsync

  // 用 computed 不用 watch：watch 建在 await 之前，SSR 只會跑那一次（當時還沒資料），
  // 伺服器渲染出空列表、瀏覽器卻有資料，會 hydration mismatch
  const list = computed<GameListItem[]>(() => (listData.value && isApiOk(listData.value) ? listData.value.data.games : []))

  const pagination = computed(() => (listData.value && isApiOk(listData.value) ? listData.value.data.pagination : null))
  const vendorName = computed(() => queryVendorName.value || vendorData.value?.name || '')
  const giftcode = computed(() => vendorData.value?.giftcode ?? 0)
  /** 全部遊戲商時的標題 key（依類型）；指定遊戲商時是空字串，標題用 vendorName */
  const title = computed(() => (vendorId.value === 'all' ? gameTypeLabelKey(typeId.value) : ''))

  const typeTabs = computed(() => rootTypeIds(store.gameTypes, numericVendorId.value))
  const visibleGames = computed(() => filterGamesByType(list.value, typeId.value, numericVendorId.value))

  /**
   * 進列表時轉入該場館，只在指定場館時做（要不要轉看 transaction_all，見 shouldTransferOnVendorEntry）。
   * 跟舊版 GameList 的 handlePrizeReminderReady 一樣等領獎提醒放行（ready）才轉。
   */
  function syncWallet() {
    if (numericVendorId.value !== null) void launcher.syncSingleWallet(numericVendorId.value)
  }

  function selectType(id: number | null) {
    typeId.value = id
    page.value = 1
  }

  // 兩支同時發出；原本是先等遊戲商資料再發遊戲列表，兩者沒有相依
  await Promise.all([vendorAsync, listAsync])

  return {
    vendorId,
    numericVendorId,
    queryTypeId,
    typeId,
    page,
    status,
    pagination,
    vendorName,
    giftcode,
    title,
    typeTabs,
    visibleGames,
    syncWallet,
    selectType,
  }
}
