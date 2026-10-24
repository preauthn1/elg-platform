import { readdirSync, readFileSync } from 'node:fs'
import { join, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：template 裡不可以再多出沒走 i18n 的中文。
 *
 * 這個站有 14 個語系，template 裡硬寫中文＝非中文會員會直接看到中文。
 * 目前還有一批舊帳（重寫期間暫時寫死的），舊專案語言檔裡也沒有對應 key，
 * 要補就是 ~30 支新 key × 14 語系，得等翻譯。**在那之前至少不要再變多。**
 *
 * 清掉幾筆之後請把 BASELINE 一起調降，不要讓它留在虛高的數字。
 */

const APP = fileURLToPath(new URL('../../app', import.meta.url))
const CJK = /[一-鿿]/

/** 這個數字只能往下調。新增硬寫中文會讓測試變紅。 */
const BASELINE = 18

interface Hit {
  file: string
  line: number
  text: string
}

function vueFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) vueFiles(full, out)
    else if (entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

/** 只看 <template>；HTML 註解與 $t('…') / t('…') 的內容都不算。 */
function scan(): Hit[] {
  const hits: Hit[] = []
  for (const file of vueFiles(APP)) {
    const src = readFileSync(file, 'utf8')
    const start = src.indexOf('<template>')
    if (start < 0) continue
    const before = src.slice(0, start).split(/\r?\n/).length
    const tpl = src.slice(start).replace(/<!--[\s\S]*?-->/g, '')
    tpl.split(/\r?\n/).forEach((line, index) => {
      if (!CJK.test(line)) return
      const stripped = line
        .replace(/\$?t\(\s*'[^']*'/g, '')
        .replace(/\$?t\(\s*"[^"]*"/g, '')
        .replace(/\$?t\(\s*`[^`]*`/g, '')
      if (!CJK.test(stripped)) return
      hits.push({
        file: file.slice(APP.length + 1).split(sep).join('/'),
        line: before + index,
        text: line.trim().slice(0, 80),
      })
    })
  }
  return hits
}

describe('template 不可以再多出硬寫的中文', () => {
  it(`目前欠 ${BASELINE} 筆，不能再增加`, () => {
    const hits = scan()
    const report = hits.map(h => `  ${h.file}:${h.line}  ${h.text}`).join('\n')
    expect(
      hits.length,
      `template 裡沒走 i18n 的中文變成 ${hits.length} 筆（上限 ${BASELINE}）。\n`
      + '新增的請改用 $t()；若是清掉舊帳，請把 BASELINE 調降成新的數字。\n'
      + report,
    ).toBeLessThanOrEqual(BASELINE)
  })
})
