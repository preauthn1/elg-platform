import { createError, getRequestHeader } from 'h3'

/**
 * 反跨站偽造（CSRF）：對 /api/bff 與 /api/auth 的寫入請求檢查 Origin。
 * 合法前端與伺服器同源，Origin 的 host 必須等於請求的 host；對不上就 403。
 *
 * 作法對齊 agent-backend 的 apps/gateway/middleware/01.origin-check.ts：
 * - 只擋有帶 Origin 的請求。跨站偽造一定會帶 Origin；沒帶的非瀏覽器請求
 *   交給 token 驗證把關（沒有有效 session 本來就會被拒）。
 * - localhost / 0.0.0.0 放行，讓本機開發與測試工具正常。
 */

const GUARDED_PREFIXES = ['/api/bff', '/api/auth']
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

function hostOf(url: string): string {
  try {
    return new URL(url).host
  }
  catch {
    return ''
  }
}

export default defineEventHandler((event) => {
  const path = event.path ?? ''
  if (!GUARDED_PREFIXES.some(prefix => path.startsWith(prefix))) return
  if (SAFE_METHODS.has(event.method)) return

  const origin = getRequestHeader(event, 'origin')
  if (!origin) return

  const originHost = hostOf(origin)
  if (originHost.startsWith('localhost') || originHost.startsWith('0.0.0.0')) return

  const host = getRequestHeader(event, 'x-forwarded-host')
    ?? getRequestHeader(event, 'host')
    ?? ''

  if (originHost && host && originHost === host) return

  throw createError({ statusCode: 403, statusMessage: 'Cross-origin request forbidden' })
})
