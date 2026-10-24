import { isActivityDemoRoute } from '~/utils/events-core'

/**
 * 頁面用 definePageMeta({ requiresAuth: true }) 標示需登入；
 * 沒登入導到 /login 並帶 redirect。
 */
export default defineNuxtRouteMiddleware((to) => {
  if (!to.meta.requiresAuth) return
  /**
   * 活動彈窗的預覽模式不打任何 API，所以也不需要登入態。
   * 條件與頁面共用 `isActivityDemoRoute()`。
   */
  if (isActivityDemoRoute(to.path, to.query)) return
  const { loggedIn } = useUserSession()
  if (!loggedIn.value) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }
})

declare module '#app' {
  interface PageMeta {
    requiresAuth?: boolean
  }
}
