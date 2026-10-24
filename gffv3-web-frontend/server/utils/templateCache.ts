import type { H3Event } from 'h3'
import type { TemplateResponse } from '#shared/types/site'
import { templateResponseSchema } from '#shared/types/site'
import { API_CODE } from '#shared/constants/codes'
import { callApi } from './apiClient'

export interface TemplateEntry {
  raw: TemplateResponse | null
  error: { code: number, message: string } | null
  fetchedAt: number
  expiresAt: number
}

/** 每個 pod 一份記憶體快取；多 pod 一致性需要時再換 Nitro storage（Redis）。 */
const cache = new Map<string, TemplateEntry>()
const inflight = new Map<string, Promise<TemplateEntry>>()

const ERROR_TTL_MS = 10_000

async function fetchTemplate(event: H3Event, host: string, ttlMs: number): Promise<TemplateEntry> {
  const pending = inflight.get(host)
  if (pending) return pending

  const task = (async () => {
    const now = Date.now()
    const res = await callApi(event, 'agent/template', { domain: host })
    let entry: TemplateEntry
    if (res.code === API_CODE.OK) {
      const parsed = templateResponseSchema.safeParse(res.data)
      if (parsed.success) {
        entry = { raw: parsed.data, error: null, fetchedAt: now, expiresAt: now + ttlMs }
      }
      else {
        console.warn(JSON.stringify({ severity: 'WARNING', type: 'template-schema', host, issues: parsed.error.issues.slice(0, 10) }))
        // schema 不合仍照用原始資料，避免因為後端多一個欄位就整站掛掉
        entry = { raw: res.data as TemplateResponse, error: null, fetchedAt: now, expiresAt: now + ttlMs }
      }
    }
    else {
      entry = { raw: null, error: { code: res.code, message: res.message }, fetchedAt: now, expiresAt: now + ERROR_TTL_MS }
    }
    cache.set(host, entry)
    return entry
  })()

  inflight.set(host, task)
  try {
    return await task
  }
  finally {
    inflight.delete(host)
  }
}

/** 取 Host 的模板：新鮮就直接回；過期先回舊值並在背景更新（SWR）；沒有才同步抓。 */
export async function getTemplateForHost(event: H3Event, host: string): Promise<TemplateEntry> {
  const ttlMs = (Number(useRuntimeConfig(event).templateCacheTtl) || 60) * 1000
  const now = Date.now()
  const current = cache.get(host)
  if (current && current.expiresAt > now) return current
  if (current && current.raw) {
    void fetchTemplate(event, host, ttlMs).catch(() => undefined)
    return current
  }
  return fetchTemplate(event, host, ttlMs)
}

/** 測試與除錯用。 */
export function clearTemplateCache(host?: string) {
  if (host) cache.delete(host)
  else cache.clear()
}
