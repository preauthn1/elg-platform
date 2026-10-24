import { readFileSync, readdirSync } from 'node:fs'
import { join, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：入口列表的標籤 key，14 個語系都要有值。
 *
 * 首頁錢包列原本用「字印在圖裡」的素材，換成純圖示＋自己排字之後，
 * 標籤缺哪個語系就會直接露出英文給該語系的會員看。
 * 第一次換就踩到：agent.promote.action.share 只有 en／zh-tw 有值，其餘 12 個語系是空的。
 *
 * 掃的是 `i18n:` 與 `labelKey:`（這個專案的慣例：導覽／入口清單就是用這兩個欄位帶 key），
 * 不是所有 $t()。KNOWN_GAPS 是接手時就已經缺的舊帳，只能變少：
 * 補完翻譯之後請把那一行刪掉（留著會讓測試變紅，提醒清單該收斂）。
 */

const APP = fileURLToPath(new URL('../../app', import.meta.url))
const LOCALES = fileURLToPath(new URL('../../i18n/locales', import.meta.url))

/** 進場時就缺的：key -> 缺的語系。只能刪、不能加。 */
const KNOWN_GAPS: Record<string, string[]> = {
  'agent.center.label.myRebate': ['id-id', 'ms-my', 'my-mm', 'ta-in', 'th-th', 'tl-ph', 'vi-vn'],
  'agent.promote.label.myTurnover': ['id-id', 'ms-my', 'ta-in', 'th-th', 'vi-vn'],
  'auth.otp.title.emailVerification': ['bn', 'ho', 'id-id', 'my-mm', 'ru', 'ta-in', 'th-th', 'tl-ph', 'tpi', 'vi-vn'],
  'vip.overview.title.vip': ['ho', 'id-id', 'my-mm', 'ta-in', 'th-th', 'tl-ph', 'tpi', 'vi-vn'],
}

function vueFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) vueFiles(full, out)
    else if (entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

/** key -> 用到它的檔案 */
function entryLabelKeys(): Map<string, string[]> {
  const keys = new Map<string, string[]>()
  for (const file of vueFiles(APP)) {
    const rel = file.slice(APP.length + 1).split(sep).join('/')
    for (const match of readFileSync(file, 'utf8').matchAll(/\b(?:i18n|labelKey)\s*:\s*'([^']+)'/g)) {
      const key = match[1]!
      keys.set(key, [...(keys.get(key) ?? []), rel])
    }
  }
  return keys
}

const locales = readdirSync(LOCALES)
  .filter(f => f.endsWith('.json'))
  .map(f => [f.replace('.json', ''), JSON.parse(readFileSync(join(LOCALES, f), 'utf8')) as Record<string, string>] as const)

function missingIn(key: string): string[] {
  return locales.filter(([, messages]) => messages[key] === undefined).map(([code]) => code)
}

describe('入口標籤的 i18n key', () => {
  it('14 個語系都要有值', () => {
    const report: string[] = []
    for (const [key, files] of entryLabelKeys()) {
      const missing = missingIn(key)
      const allowed = KNOWN_GAPS[key] ?? []
      const unexpected = missing.filter(code => !allowed.includes(code))
      if (unexpected.length > 0) report.push(`  ${key}（${files.join(', ')}）缺：${unexpected.join(', ')}`)
    }
    expect(
      report.join('\n'),
      '入口標籤用到語系不齊的 key，該語系的會員會看到英文。\n'
      + '換一支 14 語系都有值的 key，或補上翻譯。\n',
    ).toBe('')
  })

  it('KNOWN_GAPS 不留過期項目', () => {
    const stale = Object.entries(KNOWN_GAPS)
      .map(([key, allowed]) => {
        const missing = missingIn(key)
        const fixed = allowed.filter(code => !missing.includes(code))
        return fixed.length > 0 ? `  ${key}：${fixed.join(', ')} 已經有翻譯了` : ''
      })
      .filter(Boolean)
    expect(stale.join('\n'), '翻譯補齊了就把 KNOWN_GAPS 對應的項目刪掉。\n').toBe('')
  })

  it('錢包列三顆入口的標籤語系齊全（換 key 前先確認）', () => {
    const walletBar = readFileSync(join(APP, 'components/C17/WalletBar.vue'), 'utf8')
    const keys = [...walletBar.matchAll(/\b(?:i18n|aria)\s*:\s*'([^']+)'/g)].map(m => m[1]!)
    expect(keys.length).toBeGreaterThanOrEqual(3)
    for (const key of keys) expect(missingIn(key), `${key} 缺語系`).toEqual([])
  })
})
