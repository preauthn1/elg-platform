/**
 * 關掉「疊在別的頁面上」的畫面（彈窗式路由、全螢幕遊戲頁）時要去哪裡。
 *
 * 有上一頁就退回去——使用者是從某個列表點進來的，關掉本來就該回到那個列表，
 * 而且捲動位置會保留。直接開網址進來的沒有上一頁（`history.length <= 1`），
 * 這時才用 fallback，不然會退出整個站。
 *
 * SSR 期間沒有 window，一律走 fallback。
 */
export function backOr(fallback: string) {
  const router = useRouter()
  if (import.meta.client && window.history.length > 1) return router.back()
  return navigateTo(fallback)
}
