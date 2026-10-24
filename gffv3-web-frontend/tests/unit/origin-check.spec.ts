import { describe, expect, it, vi } from 'vitest'

/** h3 的 defineEventHandler 在 Nitro 是自動匯入，測試裡自己給一個透傳的替身。 */
vi.stubGlobal('defineEventHandler', (fn: unknown) => fn)

const { default: handler } = await import('../../server/middleware/origin-check')

interface FakeEvent {
  path: string
  method: string
  headers: Record<string, string>
}

function run(event: FakeEvent): 'pass' | number {
  const h3Event = {
    path: event.path,
    method: event.method,
    node: { req: { headers: event.headers } },
  }
  try {
    ;(handler as (e: unknown) => void)(h3Event)
    return 'pass'
  }
  catch (error) {
    return (error as { statusCode?: number }).statusCode ?? -1
  }
}

const bffPost = (headers: Record<string, string>): FakeEvent => ({
  path: '/api/bff/member/detail',
  method: 'POST',
  headers,
})

describe('origin-check middleware', () => {
  it('同源放行', () => {
    expect(run(bffPost({ origin: 'https://example.invalid', host: 'example.invalid' }))).toBe('pass')
  })

  it('跨站擋 403', () => {
    expect(run(bffPost({ origin: 'https://example.invalid', host: 'example.invalid' }))).toBe(403)
  })

  it('沒帶 Origin 放行，交給 token 驗證', () => {
    expect(run(bffPost({ host: 'example.invalid' }))).toBe('pass')
  })

  it('本機開發放行', () => {
    expect(run(bffPost({ origin: 'http://localhost:8080', host: 'localhost:3000' }))).toBe('pass')
    expect(run(bffPost({ origin: 'http://0.0.0.0:8080', host: 'localhost:8080' }))).toBe('pass')
  })

  it('走 LB 時比對 x-forwarded-host', () => {
    expect(run(bffPost({
      'origin': 'https://example.invalid',
      'x-forwarded-host': 'example.invalid',
      'host': 'internal-svc:3000',
    }))).toBe('pass')
  })

  it('只擋寫入請求與受保護前綴', () => {
    expect(run({ path: '/api/bff/member/detail', method: 'GET', headers: { origin: 'https://example.invalid', host: 'example.invalid' } })).toBe('pass')
    expect(run({ path: '/api/_health', method: 'POST', headers: { origin: 'https://example.invalid', host: 'example.invalid' } })).toBe('pass')
    expect(run({ path: '/vip', method: 'POST', headers: { origin: 'https://example.invalid', host: 'example.invalid' } })).toBe('pass')
  })

  it('登入端點也受保護', () => {
    expect(run({ path: '/api/auth/login', method: 'POST', headers: { origin: 'https://example.invalid', host: 'example.invalid' } })).toBe(403)
  })
})
