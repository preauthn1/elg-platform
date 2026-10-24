import type { H3Event } from 'h3'
import { getRequestHeader, getRequestIP } from 'h3'
import type { ApiEnvelope } from '#shared/types/api'
import { BFF_CODE } from '#shared/constants/codes'
import { buildEncryptedForm } from './crypto'
import { readSecretEnv } from './env'
import { logForwardRequest, logForwardResponse } from './forwardLogger'

export interface CallApiOptions {
  /** 會員 token，帶成 Authorization: Bearer */
  token?: string
  /** 覆寫 Accept-Language（BFF 會傳 i18n 的 locale cookie） */
  locale?: string
  /** 登入會員 id，寫進轉發 log 的 message 尾端 */
  actorId?: number | string
}

/**
 * 呼叫 apis frontend/api/<route>。加密、簽章、標頭都在這裡補，瀏規器端拿不到金鑰。
 * 回傳一律是 ApiEnvelope；連線失敗或非 JSON 回應收斂成 BFF_CODE.UPSTREAM_FAILED。
 */
export async function callApi<T = unknown>(
  event: H3Event | null,
  route: string,
  payload: Record<string, unknown>,
  opts: CallApiOptions = {},
): Promise<ApiEnvelope<T>> {
  const cfg = useRuntimeConfig(event ?? undefined)
  const keys = {
    aesKey: readSecretEnv('NUXT_API_AES_KEY', cfg.apiAesKey),
    aesIv: readSecretEnv('NUXT_API_AES_IV', cfg.apiAesIv),
    md5Key: readSecretEnv('NUXT_API_MD5_KEY', cfg.apiMd5Key),
  }
  const baseUrl = readSecretEnv('NUXT_API_BASE_URL', cfg.apiBaseUrl)
  if (!baseUrl || !keys.aesKey || !keys.aesIv || !keys.md5Key) {
    return { code: BFF_CODE.NOT_CONFIGURED, message: 'API not configured (NUXT_API_*)', data: null as T }
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/x-www-form-urlencoded',
    'Accept-Language': opts.locale
      ?? (event ? getRequestHeader(event, 'accept-language') : undefined)
      ?? 'en',
  }
  const ip = event ? getRequestIP(event, { xForwardedFor: true }) : undefined
  const trace = event ? getRequestHeader(event, 'x-cloud-trace-context') : undefined
  if (event) {
    const ua = getRequestHeader(event, 'user-agent')
    if (ua) headers['User-Agent'] = ua
    if (ip) headers['X-Forwarded-For'] = ip
    const orientation = getRequestHeader(event, 'x-orientation')
    if (orientation) headers['X-Orientation'] = orientation
    if (trace) headers['X-Cloud-Trace-Context'] = trace
  }
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`

  const url = `${baseUrl.replace(/\/+$/, '')}/frontend/api/${route.replace(/^\/+/, '')}`
  const timeout = Number(cfg.apiTimeoutMs) || 15000
  const logBase = {
    actorId: opts.actorId,
    clientIp: ip ?? '',
    gcpTrace: trace?.split('/')[0] || undefined,
    method: 'POST',
    path: route,
    payload,
    requestHeaders: headers,
    url,
  }

  let form: { params: string, key: string }
  try {
    form = buildEncryptedForm(payload, keys)
  }
  catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    logForwardResponse({ ...logBase, error, startedAt: performance.now() })
    return { code: BFF_CODE.NOT_CONFIGURED, message: `encrypt failed: ${message}`, data: null as T }
  }
  const body = new URLSearchParams(form)

  // 請求紀錄先寫（fetch 之前），掛住或沒回應也有紀錄
  logForwardRequest(logBase)
  const startedAt = performance.now()

  try {
    const res = await fetch(url, {
      method: 'POST',
      body,
      headers,
      signal: AbortSignal.timeout(timeout),
    })
    const text = await res.text()
    let parsed: ApiEnvelope<T>
    try {
      parsed = JSON.parse(text) as ApiEnvelope<T>
    }
    catch {
      parsed = {
        code: BFF_CODE.UPSTREAM_FAILED,
        message: `apis response is not JSON (HTTP ${res.status})`,
        data: null as T,
      }
    }
    logForwardResponse({
      ...logBase,
      httpStatus: res.status,
      responseBody: parsed,
      responseHeaders: Object.fromEntries(res.headers),
      startedAt,
    })
    return parsed
  }
  catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    logForwardResponse({ ...logBase, error, startedAt })
    return { code: BFF_CODE.UPSTREAM_FAILED, message: `apis request failed: ${message}`, data: null as T }
  }
}
