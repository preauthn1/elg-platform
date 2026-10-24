import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { API_CODE, isReloginCode, isResetPasswordCode } from '#shared/constants/codes'

/**
 * 守門測試：後端同一件事會給兩個碼，前端不可以只認其中一個。
 *
 * token 失效時 `AuthMember` 中介層回的是 `HttpCode::HTTP_REDIRECT_LOGOUT`（319），
 * 但訊息帶的是 `error_message.9998`（「Token錯誤」）。BFF 原本只比對 9998，
 * 收到 319 就不會清 session cookie，瀏覽器的 `loggedIn` 還是 true；
 * 前端把人導去 /login，login 頁看到已登入又把人彈回原頁，原頁再打 API 又是 319——
 * `member/detail`、`member/check-profile` 會被無限重打。
 *
 * 所以這幾組碼一律走 `isReloginCode` / `isResetPasswordCode`，
 * 不可以在任何地方直接比對單一個碼。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

/** 有處理這些碼的檔案；新增處理點時一起列進來。 */
const FILES = [
  'server/api/bff/[...path].post.ts',
  'app/composables/useApi.ts',
]

/** 直接比對單一個碼的寫法，會漏掉成對的另一半。 */
const SINGLE_CODE_COMPARISON = [
  /===\s*API_CODE\.RELOGIN\b(?!_)/,
  /===\s*API_CODE\.RELOGIN_LOGOUT\b/,
  /===\s*API_CODE\.RESET_PASSWORD_REQUIRED\b(?!_)/,
  /===\s*API_CODE\.RESET_PASSWORD_REQUIRED_ALT\b/,
]

describe('成對的回應碼', () => {
  it('319 與 9998 都算 token 失效', () => {
    expect(isReloginCode(API_CODE.RELOGIN)).toBe(true)
    expect(isReloginCode(API_CODE.RELOGIN_LOGOUT)).toBe(true)
    expect(isReloginCode(API_CODE.OK)).toBe(false)
  })

  it('322 與 9006 都算需先重設密碼', () => {
    expect(isResetPasswordCode(API_CODE.RESET_PASSWORD_REQUIRED)).toBe(true)
    expect(isResetPasswordCode(API_CODE.RESET_PASSWORD_REQUIRED_ALT)).toBe(true)
    expect(isResetPasswordCode(API_CODE.OK)).toBe(false)
  })

  it.each(FILES)('%s 不直接比對單一個碼', (file) => {
    const source = readFileSync(new URL(file, `file://${ROOT}`), 'utf8')
    for (const pattern of SINGLE_CODE_COMPARISON) {
      expect(source, `${file} 請改用 isReloginCode() / isResetPasswordCode()`).not.toMatch(pattern)
    }
  })
})
