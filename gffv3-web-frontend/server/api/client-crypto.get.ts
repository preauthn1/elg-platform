import { getClientCryptoKeys } from '../utils/clientCrypto'

/**
 * 取請求加密金鑰。正常情況由 SSR 帶進 payload，但 nuxt.config 有幾組 `ssr: false`
 * 的路由（/wallet、/record、/agent、/game/app），那些頁面 payload 裡沒有金鑰，
 * 之後送出的請求會全是明文、BFF 一律回 422。所以補這支讓瀏覽器要得到。
 *
 * 金鑰本來就必須讓瀏覽器讀得到，理由見 server/utils/clientCrypto.ts。
 */
export default defineEventHandler((event) => {
  setHeader(event, 'cache-control', 'no-store')
  const keys = getClientCryptoKeys(event)
  if (!keys) {
    throw createError({ statusCode: 503, statusMessage: 'API keys not configured (NUXT_API_*)' })
  }
  return keys
})
