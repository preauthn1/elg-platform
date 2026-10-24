import { isStaleOnNavigation } from '~/composables/useAlert'

/**
 * 換頁時收掉上一頁留下的提示彈窗。彈窗有遮罩擋住畫面，會換頁多半是瀏覽器返回；
 * 不收的話舊訊息會蓋在新頁面上。只清掉不執行 close()——使用者已經離開，不該再依 redirect 導頁。
 */
export default defineNuxtPlugin(() => {
  const router = useRouter()
  const { state } = useAlert()
  let navigationStartedAt = 0

  router.beforeEach(() => {
    navigationStartedAt = Date.now()
  })

  router.afterEach((to, from, failure) => {
    // from 沒有 matched＝hydrate 的第一次路由，SSR 時跳的彈窗要留著
    if (failure || from.matched.length === 0 || to.fullPath === from.fullPath) return
    if (isStaleOnNavigation(state.value, navigationStartedAt)) state.value = null
  })
})
