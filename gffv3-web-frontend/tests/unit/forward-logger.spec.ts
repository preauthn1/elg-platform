import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDevSummary, logForwardRequest, logForwardResponse } from '../../server/utils/forwardLogger'

const base = {
  actorId: 4386,
  clientIp: '0.0.0.0',
  gcpTrace: 'abc123',
  method: 'POST',
  path: 'lobby/games',
  payload: { domain: 'example.invalid', password: [REDACTED]' },
  requestHeaders: { 'Authorization': 'Bearer tkn', 'Accept-Language': 'en' },
  url: 'https://example.invalid/frontend/api/lobby/games',
}

describe('forwardLogger：格式對齊 agent-backend 的 forward-logger', () => {
  let lines: ReturnType<typeof JSON.parse>[]
  beforeEach(() => {
    lines = []
    vi.spyOn(process.stdout, 'write').mockImplementation((chunk: string | Uint8Array) => {
      lines.push(JSON.parse(String(chunk)))
      return true
    })
  })
  afterEach(() => {
    vi.restoreAllMocks()
    Reflect.deleteProperty(process.env, 'SITE_NAME')
  })

  it('請求紀錄：message 為 `[app] path 請求紀錄 (id)`，敏感鍵遮罩', () => {
    logForwardRequest(base)
    const [e] = lines
    expect(e.message).toBe('[web-frontend] lobby/games 請求紀錄 (4386)')
    expect(e.severity).toBe('INFO')
    expect(e['remote-address']).toBe('0.0.0.0')
    expect(e.time).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{9}Z$/)
    expect(example.invalid).toEqual({ apiType: 'apis', gcp_trace: 'abc123' })
    expect(e.payload.context.request.params).toEqual({ domain: 'example.invalid', password: [REDACTED]' })
    expect(e.payload.context.request.header.Authorization).toBe('***')
    expect(base.payload.password).toBe('secret-pw')
  })

  it('有 SITE_NAME 時前綴為 `[site-app]`；未登入不帶 `(id)`', () => {
    process.env.SITE_NAME = 'gff'
    logForwardRequest({ ...base, actorId: undefined })
    expect(lines[0].message).toBe('[gff-web-frontend] lobby/games 請求紀錄')
  })

  it('響應紀錄：帶耗時秒數與 response；body 內的 token 也遮罩', () => {
    logForwardResponse({
      ...base,
      httpStatus: 200,
      responseBody: { code: 200, data: { token: 'x' } },
      responseHeaders: {},
      startedAt: performance.now(),
    })
    const [e] = lines
    expect(e.message).toMatch(/^\[web-frontend\] lobby\/games 響應紀錄, Time [\d.]+ sec \(4386\)$/)
    expect(e.severity).toBe('INFO')
    expect(e.payload.context.response).toEqual({ body: { code: 200, data: { token: [REDACTED]' } }, header: {}, statusCode: 200 })
    expect(e.payload.context.handler_stats).toBeUndefined()
  })

  it('連線失敗：severity ERROR、response 為 null、附 handler_stats', () => {
    logForwardResponse({ ...base, error: new TypeError('fetch failed'), startedAt: performance.now() })
    const [e] = lines
    expect(e.severity).toBe('ERROR')
    expect(e.payload.context.response).toBeNull()
    expect(e.payload.context.handler_stats.error.message).toBe('fetch failed')
  })

  it('body 過大時只留 code／message 摘要', () => {
    logForwardResponse({
      ...base,
      httpStatus: 200,
      responseBody: { code: 200, message: 'ok', data: 'x'.repeat(20_000) },
      startedAt: performance.now(),
    })
    expect(lines[0].payload.context.response.body).toMatchObject({ _truncated: true, code: 200, message: 'ok' })
  })

  it('dev 摘要：一行帶 HTTP 狀態、業務 code、耗時與會員 id；連線失敗帶原因', () => {
    expect(formatDevSummary({ ...base, httpStatus: 200, responseBody: { code: 317 }, startedAt: 0 }, 0.207))
      .toBe('[web-frontend] lobby/games 200 code=317 0.207s (4386)')
    expect(formatDevSummary({ ...base, actorId: undefined, error: new TypeError('fetch failed'), startedAt: 0 }, 1.5))
      .toBe('[web-frontend] lobby/games ERROR fetch failed 1.5s')
  })
})
