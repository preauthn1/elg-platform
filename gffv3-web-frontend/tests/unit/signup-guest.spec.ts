import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：每個註冊頁都要掛 signup-guest middleware（已登入先清登入態）。
 *
 * 已登入開邀請連結 /signup/:code 時，註冊發碼是訪客型 type，會被判成 illegal，
 * 按「發送」完全沒反應（target/tickets#4328 第 14 點）。舊前台是 /signup 開頭就清 token。
 */
const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const SIGNUP_DIR = join(ROOT, 'app/pages/signup')

describe('註冊頁以訪客身分進入', () => {
  const pages = readdirSync(SIGNUP_DIR).filter(name => name.endsWith('.vue'))

  it('有註冊頁', () => {
    expect(pages.length).toBeGreaterThan(0)
  })

  it.each(pages)('%s 掛了 signup-guest', (name) => {
    const source = readFileSync(join(SIGNUP_DIR, name), 'utf8')
    expect(source).toMatch(/definePageMeta\(\{[^}]*middleware:\s*'signup-guest'/)
  })
})
