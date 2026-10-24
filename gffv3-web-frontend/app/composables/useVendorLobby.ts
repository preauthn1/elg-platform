import type { LobbyVendor } from '#shared/api/endpoints/games'
import {
  filterVendorsByTag,
  filterVendorsByType,
  isVendorOpen,
  normalizeVendorTag,
  parseTypeId,
  PSEUDO_TYPE,
  rootTypeIds,
} from '#shared/utils/games'

/**
 * 遊戲大廳（遊戲商列表）：網址的 ?tag=、?typeId= 篩選，切類型寫回網址，
 * 點遊戲商先查領獎提醒再進場館。所有皮共用，畫面在 components/<皮>/Page/Vendors.vue。
 *
 * 要 await：資料在 SSR 就要抓好。所有 composable 都在 await 之前建立，await 之後不能再呼叫。
 */
export async function useVendorLobby() {
  const route = useRoute()
  const router = useRouter()
  const games = useGames()
  const store = useGamesStore()
  const site = useSite()

  const tag = computed(() => normalizeVendorTag(route.query.tag))
  const queryTypeId = computed(() => parseTypeId(route.query.typeId))
  const typeId = ref<number | null>(queryTypeId.value)
  watch(queryTypeId, (v) => {
    typeId.value = v
  })

  /**
   * 切類型要寫回網址（replace，不多一筆歷史）：從遊戲商返回時讀的是網址上的 typeId，
   * 只改 ref 的話，返回一律回到進來時那個類型（例如選單點的捕魚）。
   */
  function selectType(id: number | null) {
    typeId.value = id
    const query = { ...route.query }
    if (id === null) delete query.typeId
    else query.typeId = String(id)
    void router.replace({ query })
  }

  const asyncData = useAsyncData('games-lobby', () => games.fetchLobby())
  const { status } = asyncData

  const typeTabs = computed(() => {
    const list = rootTypeIds(store.gameTypes)
    if (site.flag('rolling_chip_visible') && store.vendors.some(v => v.giftcode === 1)) {
      list.push(PSEUDO_TYPE.GIFTCODE)
    }
    return list
  })

  const vendors = computed(() =>
    filterVendorsByType(filterVendorsByTag(store.vendors, tag.value), typeId.value, store.gameTypes),
  )

  /**
   * 點遊戲商照舊版 VendorListGrid：先查領獎提醒，沒有待領（或放棄領取）才進場館；
   * 有待領時彈窗，前往領取／關閉都不進場館。檢查中再點不重複觸發。
   * 畫面要把 EventsPrizeReminder 綁到 prizeReminder，並在它放行時呼叫 openPendingVendor。
   */
  const prizeReminder = ref<{ check: () => Promise<void> } | null>(null)
  const pendingVendor = ref<LobbyVendor | null>(null)
  const checkingPrize = ref(false)

  async function clickVendor(vendor: LobbyVendor) {
    if (!isVendorOpen(vendor) || checkingPrize.value || !prizeReminder.value) return
    checkingPrize.value = true
    pendingVendor.value = vendor
    try {
      await prizeReminder.value.check()
    }
    finally {
      checkingPrize.value = false
    }
  }

  function openPendingVendor() {
    const vendor = pendingVendor.value
    pendingVendor.value = null
    if (vendor) void games.goToVendor(vendor, typeId.value)
  }

  await asyncData

  return {
    tag,
    typeId,
    selectType,
    status,
    typeTabs,
    vendors,
    prizeReminder,
    pendingVendor,
    clickVendor,
    openPendingVendor,
  }
}
