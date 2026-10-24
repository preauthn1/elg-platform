import type { FirebaseApp } from 'firebase/app'
import type { Messaging } from 'firebase/messaging'
import { FIREBASE_SW_PATH, firebaseConfigFor } from '#shared/constants/pwa'

/**
 * FCM 推播，對應舊前台 utils/fcmService.js 的 initFCM / setupForegroundListener。
 * Firebase 只在瀏覽器、而且品牌有設定時才動態載入，其他站不會多打包。
 * 拿到的 token 舊前台只印在 console、沒有送後端，這裡照搬（推播由 Firebase 後台對整個 App 發送）。
 */
// 存 Promise 而不是結果：啟動與授權後可能同時呼叫，不能 initializeApp 兩次
let appPromise: Promise<FirebaseApp | null> | null = null
let messagingPromise: Promise<Messaging | null> | null = null
let swRegistration: ServiceWorkerRegistration | null = null
let foregroundBound = false

export function canUseNotification(): boolean {
  return import.meta.client
    && 'Notification' in window
    && 'serviceWorker' in navigator
    && window.isSecureContext !== false
}

export function usePushNotification() {
  const { brand } = useSite()

  /** 舊前台一初始化就順便開 Firebase Analytics，不管瀏覽器支不支援推播。 */
  function ensureApp(): Promise<FirebaseApp | null> {
    appPromise ??= (async () => {
      const config = firebaseConfigFor(brand.value)
      if (!import.meta.client || !config) return null

      const { vapidKey: _vapidKey, ...appConfig } = config
      const [{ initializeApp }, { getAnalytics }] = await Promise.all([
        import('firebase/app'),
        import('firebase/analytics'),
      ])
      const app = initializeApp(appConfig)
      getAnalytics(app)
      return app
    })()
    return appPromise
  }

  function ensureMessaging(): Promise<Messaging | null> {
    messagingPromise ??= (async () => {
      const app = await ensureApp()
      if (!app || !canUseNotification()) return null
      const { getMessaging } = await import('firebase/messaging')
      return getMessaging(app)
    })()
    return messagingPromise
  }

  /** 已授權才註冊 service worker 並取得 token；未授權直接略過，不主動跳權限視窗。 */
  async function init(): Promise<string | null> {
    try {
      const instance = await ensureMessaging()
      const config = firebaseConfigFor(brand.value)
      if (!instance || !config || Notification.permission !== 'granted') return null

      swRegistration = await navigator.serviceWorker.register(
        `${FIREBASE_SW_PATH}?brand=${encodeURIComponent(brand.value)}`,
        { updateViaCache: 'none' },
      )
      const { getToken } = await import('firebase/messaging')
      const token = [REDACTED] getToken(instance, { vapidKey: config.vapidKey, serviceWorkerRegistration: swRegistration })
      return token || null
    }
    catch {
      // 推播是加值功能，失敗不影響頁面
      return null
    }
  }

  /**
   * 頁面開著時收到推播，FCM 不會自己顯示，改由 service worker 跳系統通知。
   * 只綁一次：舊前台啟動時與授權後各綁一次，同一則推播會跳兩個通知。
   */
  async function listenForeground(): Promise<void> {
    if (foregroundBound) return
    const instance = await ensureMessaging()
    if (!instance) return
    foregroundBound = true
    const { onMessage } = await import('firebase/messaging')
    onMessage(instance, async (payload) => {
      const { title, body, image } = payload.notification ?? {}
      const reg = swRegistration ?? await navigator.serviceWorker.ready
      await reg.showNotification(title ?? '', { body, data: payload.data, ...(image ? { image } : {}) })
    })
  }

  return { init, listenForeground }
}
