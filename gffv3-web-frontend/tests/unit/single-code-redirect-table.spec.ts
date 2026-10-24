import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：「API 錯誤碼 → 關掉彈窗後導頁」全站只有一份（shared/utils/member.ts 的 profileRedirectFor），
 * 由 useApi 的全域錯誤處理套用，照舊版 C13 Modal.hiddenModal。
 *
 * 之前同一件事散成三份：check-profile 一份、登入一份、提款一份，324 一份導 /phone-verification、
 * 一份導 /member/phone-verification；而且各自收到就立刻導，跳過舊版「先彈訊息、關掉才導」。
 * 這裡擋住導頁碼再出現在別的檔案（要自己處理的請求請帶 silent，不要再寫一份對照表）。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const ALLOWED = new Set(['shared/utils/member.ts'])
/** 只有導頁用途、不會被當成別的數字的碼 */
const REDIRECT_CODES = [324, 9007, 326, 9008, 323, 9014, 327, 2040, 2041, 332, 334, 1095, 1096, 1956, 1938]

function sourceFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) sourceFiles(full, out)
    else if (entry.name.endsWith('.ts') || entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

describe('錯誤碼導頁只有一份對照表', () => {
  it('導頁碼只出現在 profileRedirectFor', () => {
    const pattern = new RegExp(`(?:===?\\s*|case\\s+|\\[\\s*|,\\s*)(${REDIRECT_CODES.join('|')})\\b`)
    const hits: string[] = []
    for (const file of [...sourceFiles(join(ROOT, 'app')), ...sourceFiles(join(ROOT, 'shared'))]) {
      const rel = relative(ROOT, file).replace(/\\/g, '/')
      if (ALLOWED.has(rel)) continue
      readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
        if (/^\s*(\/\/|\*)/.test(line)) return
        if (pattern.test(line)) hits.push(`${rel}:${i + 1}: ${line.trim()}`)
      })
    }
    expect(hits).toEqual([])
  })

  it('useApi 的全域錯誤處理用 profileRedirectFor 帶 redirect 給彈窗', () => {
    const code = readFileSync(join(ROOT, 'app/composables/useApi.ts'), 'utf8')
    expect(code).toMatch(/profileRedirectFor\(res\.code/)
    expect(code).toMatch(/useAlert\(\)\.show\(res\.message, 'error', \{ tid: res\.tid, \.\.\.\(redirect \? \{ redirect \} : \{\}\) \}\)/)
  })
})
