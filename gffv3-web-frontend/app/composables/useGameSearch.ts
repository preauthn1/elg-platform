import type { GameListItem, LegacyPagination } from '#shared/api/endpoints/games'

const SEARCH_DEBOUNCE_MS = 300

/**
 * 遊戲搜尋：輸入停 300ms 才查、每次改關鍵字回第一頁、過期的回應丟掉。
 * 所有皮共用，畫面在 components/<皮>/Page/GameSearch.vue。
 */
export function useGameSearch() {
  const games = useGames()

  const keyword = ref('')
  const page = ref(1)
  const list = ref<GameListItem[]>([])
  const pagination = ref<LegacyPagination | null>(null)
  const pending = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null
  let requestId = 0

  /** 每次搜尋重置 page=1；用 requestId 丟掉過期回應。 */
  async function run() {
    const id = ++requestId
    pending.value = true
    const res = await games.fetchGameList({ keyword: keyword.value, page: page.value })
    if (id !== requestId) return
    pending.value = false
    if (isApiOk(res)) {
      list.value = res.data.games
      pagination.value = res.data.pagination
    }
    else {
      list.value = []
      pagination.value = null
    }
  }

  watch(keyword, () => {
    if (timer) clearTimeout(timer)
    page.value = 1
    pending.value = true
    timer = setTimeout(run, SEARCH_DEBOUNCE_MS)
  })

  function changePage(next: number | '' | undefined) {
    if (next === '' || next === undefined) return
    page.value = next
    run()
  }

  onMounted(run)
  onBeforeUnmount(() => {
    if (timer) clearTimeout(timer)
  })

  return { keyword, page, list, pagination, pending, changePage }
}
