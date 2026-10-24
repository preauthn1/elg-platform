import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：API 回的 message（成功或失敗）一律走彈窗（useAlert），不可以存進 ref 印在頁面上。
 *
 * 以前各頁把 `res.message` 塞進 `message`／`error`／`notice` 再印成一行字：
 * 每頁長得不一樣、捲到下面就看不到，非 silent 的請求還會跟 useApi 自動彈的錯誤窗重複。
 * 修改手機號頁送出後在表單下印一行綠色「成功」就是這樣被退回的。
 *
 * 失敗：非 silent 請求 useApi 已經彈過，什麼都不用做；silent 請求自己 `useAlert().showResponse(res)`。
 * 成功要告知：`useAlert().showResponse(res)` 或 `show(t('common.ui.msg.success'), 'success')`。
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

/**
 * 把回應的 message 存進 ref：`x.value = res.message`、`x.value = isApiOk(res) ? '' : res.message`、
 * `x.value = { ok: false, text: res.message }`。`error.message`（JS 例外，不是 API 回應）不算。
 */
const ASSIGN_MESSAGE_TO_REF = /\.value\s*=.*\b(?!error\b)[\w$]+\.message\b/
/** template 直接印回應的 message：`{{ res.message }}`、`{{ data.message }}` */
const PRINT_MESSAGE_IN_TEMPLATE = /\{\{\s*(?:res|data|result)\.message\s*\}\}/

/** 例外：317 維護時後端的 message 就是 /maintain 整頁的內容，不是操作結果的提示 */
const ALLOWED = new Set(['app/composables/useApi.ts:maintainMessage'])

describe('API message 一律走彈窗', () => {
  it('不可以把回應的 message 存進 ref 給頁面印', () => {
    const offenders: string[] = []
    for (const file of sourceFiles(join(ROOT, 'app'))) {
      readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
        if (!ASSIGN_MESSAGE_TO_REF.test(line) && !PRINT_MESSAGE_IN_TEMPLATE.test(line)) return
        const path = relative(ROOT, file).replaceAll('\\', '/')
        const target = line.trim().split('.value')[0] ?? ''
        if (ALLOWED.has(`${path}:${target}`)) return
        offenders.push(`${path}:${i + 1}`)
      })
    }
    expect(offenders).toEqual([])
  })
})
