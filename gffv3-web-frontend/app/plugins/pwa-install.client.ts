/**
 * 攔下瀏覽器的安裝提示（beforeinstallprompt）存起來，給「下載 APP」按鈕用。
 *
 * 瀏覽器只在自己判斷「這站可以安裝」時才丟這個事件，而且事件物件只能用一次；
 * 不先 preventDefault 存下來，等使用者真的按下按鈕時就叫不出安裝視窗了。
 * 作法沿用舊前台 _source2/src/main.js 的全域監聽。
 */
export default defineNuxtPlugin((nuxtApp) => {
  const prompt = useState<BeforeInstallPromptEvent | null>('pwa-prompt', () => null)
  const installed = useState<boolean>('pwa-installed', () => false)

  // 已經從主畫面開啟的就不用再裝。等水合完才改：SSR 讀不到這個狀態一律當未安裝，
  // 水合前就改成 true 會讓「下載 APP」列兩邊渲染不一致（hydration mismatch）。
  // 不能用 app:mounted：layout 是非同步元件，那時還沒水合完
  nuxtApp.hook('app:suspense:resolve', () => {
    installed.value = window.matchMedia?.('(display-mode: standalone)').matches === true
      || (window.navigator as { standalone?: boolean }).standalone === true
  })

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    prompt.value = event as BeforeInstallPromptEvent
  })

  window.addEventListener('appinstalled', () => {
    installed.value = true
    prompt.value = null
  })
})
