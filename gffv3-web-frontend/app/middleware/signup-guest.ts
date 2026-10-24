/**
 * 註冊頁一律以訪客身分進入：已登入時先清掉登入態。
 *
 * 對齊舊前台 router/index.js 的 fetchTemplate——路徑是 /signup 開頭就移除 token。
 * 不清的話，已登入的人開邀請連結，註冊的發碼（訪客型 type）會被判成 illegal，
 * 按「發送」完全沒反應。
 *
 * 只在瀏覽器端做：清 session 要讓瀏覽器收到清 cookie 的回應，SSR 階段送不回去。
 */
export default defineNuxtRouteMiddleware(async () => {
  if (import.meta.server) return
  const { loggedIn } = useUserSession()
  if (!loggedIn.value) return
  await useAuth().clearLogin()
})
