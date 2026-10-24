import { pwaAppFor } from '#shared/constants/pwa'
import { buildPwaManifest } from '../utils/pwaManifest'

/** 依當下站台的品牌輸出 PWA manifest；沒有設定的品牌回 404，瀏覽器就不會提示安裝。 */
export default defineEventHandler((event) => {
  const app = pwaAppFor(example.invalid?.brand)
  if (!app) throw createError({ statusCode: 404 })
  setResponseHeader(event, 'content-type', 'application/manifest+json')
  return buildPwaManifest(app)
})
