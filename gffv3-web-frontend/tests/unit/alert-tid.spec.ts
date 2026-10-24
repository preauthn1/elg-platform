import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：把後端錯誤訊息丟進提示彈窗時，一定要把 tid 一起帶上。
 *
 * 舊前台的錯誤彈窗在訊息下方固定顯示 `ErrorCode: <tid>`，客服靠這串對後端 log。
 * 新前台的 `useAlert().show()` 只有呼叫端傳了 `tid` 才會顯示，漏傳不會報錯，
 * 只是畫面上默默少一行——登入失敗就這樣漏過一次。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

function sourceFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) sourceFiles(full, out)
    else if (entry.name.endsWith('.ts') || entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

describe('錯誤彈窗帶 tid', () => {
  it('每個 .show(res.message …) 都傳了 tid: res.tid', () => {
    const missing: string[] = []
    let total = 0
    for (const file of sourceFiles(join(ROOT, 'app'))) {
      readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
        if (!/\.show\(\s*res\.message\b/.test(line)) return
        total++
        if (!/tid:\s*res\.tid\b/.test(line)) missing.push(`${relative(ROOT, file)}:${i + 1}`)
      })
    }
    expect(total).toBeGreaterThan(0)
    expect(missing).toEqual([])
  })
})
