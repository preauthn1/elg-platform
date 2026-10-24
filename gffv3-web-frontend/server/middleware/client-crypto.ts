import { getClientCryptoKeys } from '../utils/clientCrypto'
import { internalToken } from '../utils/internalToken'

/**
 * 每個頁面請求都把加密金鑰放進 event.context，讓
 * app/plugins/client-crypto.ts 帶進 SSR payload，前端才加密得了請求。
 * 靜態資源與 BFF 自己的請求不需要（那邊收到請求時自己再讀一次設定）。
 */
const SKIP_PREFIXES = ['/_nuxt', '/__nuxt', '/@', '/_ipx', '/api/', '/favicon', '/robots.txt', '/.well-known', '/skins/', '/pwa/', '/manifest.webmanifest', '/firebase-messaging-sw.js']

export default defineEventHandler((event) => {
  const path = event.path || '/'
  if (SKIP_PREFIXES.some(prefix => path.startsWith(prefix))) return

  // 只給 SSR 用，不會進 payload，前端拿不到
  event.context.internalToken = [REDACTED]

  // 金鑰沒設齊就是 null，不擋頁面；SSR 走內部標頭仍可送明文
  event.context.clientCrypto = getClientCryptoKeys(event)
})
