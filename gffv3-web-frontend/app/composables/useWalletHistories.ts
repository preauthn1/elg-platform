import type { ApiEnvelope } from '#shared/types/api'
import type { RequestOf, ResponseOf } from '#shared/api/registry'
import type { WalletLogItem } from '#shared/api/endpoints/wallet'
import {
  buildWalletHistoriesRequest,
  defaultWalletHistoryRange,
  totalPages,
  type WalletHistoryType,
} from '#shared/utils/wallet'

const DEFAULT_PER_PAGE = 15

/**
 * 錢包交易紀錄。新專案只接 wallet/histories，用 wallet_type 切主錢包／遊戲錢包，做真分頁；
 * 舊的 wallet/main-logs、wallet/game-logs 也各留一支函式可直接呼叫。
 */
export function useWalletHistories() {
  const api = useApi()
  const { flag } = useSite()

  const gameWalletAvailable = computed(() => flag('is_multiple_wallet'))

  const walletType = ref<WalletHistoryType>('main')
  const range = ref<{ start_time?: string, end_time?: string }>(defaultWalletHistoryRange())
  const page = ref(1)
  const perPage = ref(DEFAULT_PER_PAGE)

  const items = ref<WalletLogItem[]>([])
  const total = ref(0)
  const loading = ref(false)

  const pages = computed(() => totalPages(total.value, perPage.value))
  const hasPagination = computed(() => total.value > perPage.value)

  function currentRequest() {
    return buildWalletHistoriesRequest({
      walletType: walletType.value,
      startTime: range.value.start_time,
      endTime: range.value.end_time,
      page: page.value,
      perPage: perPage.value,
    })
  }

  async function fetchHistories(): Promise<ApiEnvelope<ResponseOf<'wallet/histories'>>> {
    loading.value = true
    try {
      const res = await api.post('wallet/histories', currentRequest())
      if (isApiOk(res)) {
        items.value = res.data.items
        total.value = Number(res.data.pagination.total ?? 0)
      }
      else {
        items.value = []
        total.value = 0
      }
      return res
    }
    finally {
      loading.value = false
    }
  }

  async function setWalletType(type: WalletHistoryType) {
    if (type === 'game' && !gameWalletAvailable.value) return null
    walletType.value = type
    page.value = 1
    return fetchHistories()
  }

  async function search(startTime?: string, endTime?: string) {
    range.value = { start_time: startTime, end_time: endTime }
    page.value = 1
    return fetchHistories()
  }

  async function goToPage(target: number) {
    if (target < 1 || (pages.value > 0 && target > pages.value)) return null
    page.value = target
    return fetchHistories()
  }

  function mainLogs(req: RequestOf<'wallet/main-logs'> = {}) {
    return api.post('wallet/main-logs', req)
  }

  function gameLogs(req: RequestOf<'wallet/game-logs'> = {}) {
    return api.post('wallet/game-logs', req)
  }

  return {
    gameWalletAvailable,
    walletType,
    range,
    page,
    perPage,
    items,
    total,
    pages,
    hasPagination,
    loading,
    currentRequest,
    fetchHistories,
    setWalletType,
    search,
    goToPage,
    mainLogs,
    gameLogs,
  }
}
