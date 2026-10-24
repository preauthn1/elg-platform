import type { CustomizedSetting } from '#shared/types/site'

/**
 * 依站台旗標決定頁面存不存在：definePageMeta({ middleware: 'site-flag', siteFlag: '<key>' })。
 *
 * 對齊舊前台 router/C13.js 的 `...(sessionStorage.getItem('<key>') == 1 && [...])`——
 * 旗標關閉時根本不註冊那條路由，網址會被萬用路由導回首頁，不是進頁後顯示一段「暫無資料」。
 */
export default defineNuxtRouteMiddleware((to) => {
  const key = to.meta.siteFlag
  if (!key) return
  if (!useSite().flag(key)) return navigateTo('/', { replace: true })
})

declare module '#app' {
  interface PageMeta {
    siteFlag?: keyof CustomizedSetting
  }
}

declare module 'vue-router' {
  interface RouteMeta {
    siteFlag?: keyof CustomizedSetting
  }
}
