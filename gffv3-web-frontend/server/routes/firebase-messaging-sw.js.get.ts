import { SDK_VERSION } from 'firebase/app'
import { firebaseConfigFor } from '#shared/constants/pwa'

/**
 * FCM 的 service worker，對應舊前台 static/js/firebase-messaging-sw.js。
 * service worker 不能吃打包後的模組，只能 importScripts，所以 Firebase 走 gstatic 的 compat 版，
 * 版本跟 package.json 的 firebase 對齊；設定依 ?brand= 從 shared/constants/pwa.ts 取，不另存一份。
 *
 * 背景通知由 FCM 自己顯示（舊前台的 showNotification 是註解掉的），這裡只處理點擊回首頁。
 */
export default defineEventHandler((event) => {
  const brand = getQuery(event).brand
  const config = firebaseConfigFor(typeof brand === 'string' ? brand : null)
  if (!config) throw createError({ statusCode: 404 })

  const { vapidKey: _vapidKey, ...appConfig } = config
  setResponseHeader(event, 'content-type', 'application/javascript; charset=utf-8')
  setResponseHeader(event, 'cache-control', 'no-cache')
  return `importScripts('https://example.invalid/firebasejs/${SDK_VERSION}/firebase-app-compat.js');
importScripts('https://example.invalid/firebasejs/${SDK_VERSION}/firebase-messaging-compat.js');
firebase.initializeApp(${JSON.stringify(appConfig)});
firebase.messaging();
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  event.waitUntil(clients.openWindow('/'));
});
`
})
