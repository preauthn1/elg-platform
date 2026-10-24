import { describe, expect, it } from 'vitest'
import { SSR_FORWARDED_HEADERS } from '~/composables/useApi'

/**
 * 守門測試：SSR 呼叫 BFF 一定要把原請求的 host 帶過去。
 *
 * Nitro 內部 fetch 沒有 host 時補成 `localhost`，site middleware 會用 localhost 查 agent/template，
 * BFF 補的 domain 就變成別的代理。未登入時首頁出現其他代理的活動就是這樣來的（target/tickets#4328 第 21 點）。
 */
describe('SSR 轉給 BFF 的標頭', () => {
  it('包含 host 與 x-forwarded-host', () => {
    expect(SSR_FORWARDED_HEADERS).toContain('host')
    expect(SSR_FORWARDED_HEADERS).toContain('x-forwarded-host')
  })
})
