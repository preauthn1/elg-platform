/**
 * 啟動時接上推播，對應舊前台 App.vue mounted 的 initializePushNotification：
 * 已經授權過的會員重開網站時要重新註冊 service worker 並綁前景通知。
 */
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.hook('app:mounted', () => {
    const push = usePushNotification()
    void push.init()
    void push.listenForeground()
  })
})
