import { isActivityDemoRoute } from '~/utils/events-core'

/**
 * 活動彈窗（紅包／輪盤／敲蛋）現在是不是預覽模式。
 *
 * 判斷規則寫在 `isActivityDemoRoute()`，這支只負責把路由接上去。
 * 頁面與 `auth.global.ts` 都走同一支，不要各自判斷一次——兩邊不一致的話，
 * 會變成「守衛放你進來，但頁面不給你預覽」這種只有特定網址才重現的狀況。
 */
export function useActivityDemo(): ComputedRef<boolean> {
  const route = useRoute()
  return computed(() => isActivityDemoRoute(route.path, route.query))
}
