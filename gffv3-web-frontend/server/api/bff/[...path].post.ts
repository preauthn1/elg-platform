import { getCookie, getHeader, getRouterParam } from 'h3'
import type { ApiEnvelope } from '#shared/types/api'
import { API_CODE, BFF_CODE, isReloginCode } from '#shared/constants/codes'
import { CONTENT_LOCALE_HEADER, isLocaleCode } from '#shared/constants/languages'
import { getEndpoint } from '#shared/api/registry'
import { callApi } from '../../utils/apiClient'
import { clearMemberSession, getMemberId, getMemberToken } from '../../utils/memberSession'
import { ClientCryptoError, readClientBody } from '../../utils/clientCrypto'

/**
 * 通用 BFF：/api/bff/<route> → apis frontend/api/<route>。
 * 只轉發 registry 登記過的端點；request 先過 zod；member 端點沒登入直接回 9998。
 */
export default defineEventHandler(async (event): Promise<ApiEnvelope> => {
  const route = (getRouterParam(event, 'path') ?? '').replace(/^\/+|\/+$/g, '')
  const def = getEndpoint(route)
  if (!def) {
    return { code: BFF_CODE.UNKNOWN_ENDPOINT, message: `unknown endpoint: ${route}`, data: null }
  }

  const token = [REDACTED] getMemberToken(event)
  if (def.auth === 'member' && !token) {
    return { code: API_CODE.RELOGIN, message: 'login required', data: null }
  }

  /*
   * 瀏覽器送來的必須是 { params, key } 密文；只有 SSR 在同一個程序內
   * 帶著內部標頭呼叫時才收明文。外部直接打明文會被擋下來。
   * 解密與判斷都在 readClientBody，與 server/api/auth/* 共用同一支。
   */
  let raw: Record<string, unknown>
  try {
    raw = await readClientBody(event)
  }
  catch (error) {
    return {
      code: BFF_CODE.INVALID_REQUEST,
      message: error instanceof ClientCryptoError ? error.message : 'invalid request',
      data: null,
    }
  }

  const parsed = def.request.safeParse(raw)
  if (!parsed.success) {
    return {
      code: BFF_CODE.INVALID_REQUEST,
      message: 'invalid request',
      data: { issues: parsed.error.issues.map(i => ({ path: i.path.join('.'), message: i.message })) },
    }
  }

  const payload: Record<string, unknown> = { ...(parsed.data as Record<string, unknown>) }
  const site = example.invalid
  if (def.withDomain !== false && payload.domain === undefined && site?.domain) {
    payload.domain = site.domain
  }

  // 單次請求可以指定內容語言（活動詳情彈窗切語言），格式不對就照站台的 locale cookie
  const requested = getHeader(event, CONTENT_LOCALE_HEADER)
  const locale = (isLocaleCode(requested) ? requested : getCookie(event, 'locale')) || undefined
  const actorId = token ? await getMemberId(event) : undefined
  const res = await callApi(event, def.path, payload, { token, locale, actorId })

  /*
   * token 失效要在這裡就把 session cookie 清掉。**319 與 9998 都要收**：
   * AuthMember 中介層回的是 319，只比對 9998 的話 cookie 會留著、瀏覽器的 loggedIn
   * 還是 true，前端導去 /login 會被 login 頁彈回原頁，原頁再打 API 又是 319，無限來回。
   */
  if (isReloginCode(res.code) && token) {
    await clearMemberSession(event)
  }

  const cfg = useRuntimeConfig(event)
  if (res.code === API_CODE.OK && (cfg.public.apiSchemaValidation === 'dev' ? import.meta.dev : cfg.public.apiSchemaValidation === 'on')) {
    const check = def.response.safeParse(res.data)
    if (!check.success) {
      console.warn(JSON.stringify({
        severity: 'WARNING',
        type: 'bff-response-schema',
        route,
        tid: res.tid,
        issues: check.error.issues.slice(0, 10).map(i => ({ path: i.path.join('.'), message: i.message })),
      }))
    }
  }

  return res
})
