import type { GamesByTagItem } from '#shared/api/endpoints/games'
import { filterGamesByType, uniqueTypeIds } from '#shared/utils/games'

/** 標籤頁（熱門／最新／推薦）的標題 key。標籤清單頁與標籤頁共用。 */
export const GAME_TAGS = [
  { tag: 'hot', titleKey: 'games.lobby.title.hotGame' },
  { tag: 'new', titleKey: 'games.category.title.latestGame' },
  { tag: 'recommend', titleKey: 'games.lobby.title.recommended' },
] as const

/**
 * 標籤遊戲列表：依網址的 :tag 抓遊戲，可再依類型篩。
 * 所有皮共用，畫面在 components/<皮>/Page/GameTag.vue。
 *
 * 要 await：資料在 SSR 就要抓好。所有 composable 都在 await 之前建立，await 之後不能再呼叫。
 */
export async function useTagGames() {
  const route = useRoute()
  const games = useGames()

  const tag = computed(() => String(route.params.tag))
  const titleKey = computed(() => GAME_TAGS.find(t => t.tag === tag.value)?.titleKey ?? 'site.nav.nav.games')

  const asyncData = useAsyncData(`games-tag-${tag.value}`, () => games.fetchGamesByTag(tag.value))
  const { data, status } = asyncData

  const list = computed<GamesByTagItem[]>(() => (data.value && isApiOk(data.value) ? data.value.data : []))
  const typeId = ref<number | null>(null)
  const typeTabs = computed(() => uniqueTypeIds(list.value))
  const visibleGames = computed(() => filterGamesByType(list.value, typeId.value))

  await asyncData

  return { tag, titleKey, status, typeId, typeTabs, visibleGames }
}
