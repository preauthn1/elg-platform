import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：背景請求一定要帶 `{ silent: true }`。
 *
 * `useApi().post` 預設會把後端的錯誤訊息丟進全站提示彈窗。對使用者按了按鈕的請求
 * 這是對的，但**畫面自己在背景抓的資料**失敗時沒有任何使用者動作在等結果，
 * 彈出來只會變成莫名其妙的錯誤視窗：
 *
 *   event-v2/join-detail  沒參加任何活動時後端回 501「您尚未參加任何活動」，
 *                         那是正常狀態，不是錯誤。
 *   game/widget-url       首頁 SABA 快速投注區塊，拿不到就整段不顯示。
 *
 * 這兩支都實際彈過，所以鎖起來。之後再發現同類的請求就加進 BACKGROUND_ROUTES。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const DIRS = ['app', 'shared']

/** 這些端點的每一個呼叫端都必須傳 silent。 */
const BACKGROUND_ROUTES = [
  'event-v2/join-detail',
  'game/widget-url',
  // 每次換頁背景抓的未讀數（底部列／選單的紅點）
  'platform-announcement/unread',
  'system-notify/unread',
  'member-mail/unread',
]

function sourceFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue
      sourceFiles(full, out)
    }
    else if (entry.name.endsWith('.ts') || entry.name.endsWith('.vue')) {
      out.push(full)
    }
  }
  return out
}

const files = DIRS.flatMap(d => sourceFiles(join(ROOT, d)))

describe('背景請求不彈錯誤視窗', () => {
  it.each(BACKGROUND_ROUTES)('%s 的每個呼叫端都帶 silent', (route) => {
    // post('<route>', <body>, <opts>) —— body 允許跨行，opts 必須出現且含 silent: true
    const call = new RegExp(`post\\(\\s*['"\`]${route.replace('/', '\\/')}['"\`]\\s*,([\\s\\S]{0,400}?)\\)\\s*$`, 'gm')
    const callers: string[] = []

    for (const file of files) {
      const source = readFileSync(file, 'utf8')
      if (!source.includes(route)) continue
      for (const match of source.matchAll(call)) {
        callers.push(`${file.slice(ROOT.length)}: ${match[0].replace(/\s+/g, ' ').slice(0, 120)}`)
      }
    }

    expect(callers.length, `找不到 ${route} 的呼叫端，端點改名了就一起更新這支測試`).toBeGreaterThan(0)
    for (const caller of callers) {
      expect(caller, `${caller}\n背景請求要傳 { silent: true }，否則失敗時會彈錯誤視窗`).toContain('silent: true')
    }
  })
})
