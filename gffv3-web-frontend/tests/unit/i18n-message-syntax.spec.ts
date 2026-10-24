import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 語系檔必須能被 vue-i18n 的訊息編譯器吃下去。
 *
 * 起因：`en.json` 的 `site.legal.text.memberTerms` 裡有一段 `Tele: @cskhv88`。
 * vue-i18n 把「`@` 後面接文字」當成 **linked message** 語法（`@:foo` 那一族），
 * 解析不出來就整份 `en.json` 編譯失敗 —— 結果是：
 *
 *   - dev server 蓋一層 Vite error overlay，整頁點不動（連登入鈕都點不到）；
 *   - `en.json` 的模組 404，前端載不到訊息，畫面整片退回印原始 key
 *     （`site.nav.action.viewAll` 這種）；
 *   - 英文是 defaultLocale，所以預設語系直接掛掉。
 *
 * 這種錯不會讓單元測試變紅、也不會讓 build 失敗在看得懂的地方，只會在瀏覽器上爆。
 * 要印出字面的 `@`，寫成 `{'@'}`。
 *
 * 順便擋住另外兩個同族的坑：未閉合的 `{}` 佔位符、以及落單的 `@:`。
 */

/**
 * 舊帳**已經還完，這裡要保持是空的**。清單原本掛著 my-mm／ta-in／vi-vn 三個語系共 21 筆，
 * 那三份語系檔在 dev server 上整個載不到、會員看到的是原始 key。
 *
 * 當時判斷「每一筆都要翻譯決定」，實際對照 `en.json` 之後發現不必：全部都是
 * **機器翻譯把佔位符的變數名一起翻掉、或吞掉右大括號**，修法是把變數名還原成英文原樣，
 * 不涉及譯文內容：
 *   - `{ဆု}`（my-mm）→ `{prize}`、`{Tên}`（vi-vn）→ `{name}`、`{நிமிடம்}`（ta-in）→ `{min}`
 *   - `{v_reward from `、`{v_point into `、`{புள்ளி aches `（ta-in）少一個 `}`，
 *     而且把英文介系詞吸進佔位符裡；補上 `}` 並移除那個殘留的英文字。
 *   - `{name} name}`、`{name} }`（my-mm）是重複貼上的殘留符號。
 *
 * **不要往這裡加新項目。** 佔位符壞掉不是「還沒翻譯」，是那個語系整份掛掉，
 * 補上去等於把一個語系關掉還讓測試是綠的。
 */
const KNOWN_GAPS: Record<string, string[]> = {}

const isKnown = (file: string, key: string) => KNOWN_GAPS[file]?.includes(key) ?? false

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const LOCALES = join(ROOT, 'i18n/locales')
const OVERRIDES = join(ROOT, 'i18n/overrides')

function localeFiles(): Array<{ file: string, messages: Record<string, unknown> }> {
  const out: Array<{ file: string, messages: Record<string, unknown> }> = []
  for (const f of readdirSync(LOCALES)) {
    if (!f.endsWith('.json')) continue
    out.push({ file: `i18n/locales/${f}`, messages: JSON.parse(readFileSync(join(LOCALES, f), 'utf8')) })
  }
  for (const brand of readdirSync(OVERRIDES, { withFileTypes: true })) {
    if (!brand.isDirectory()) continue
    for (const f of readdirSync(join(OVERRIDES, brand.name))) {
      if (!f.endsWith('.json')) continue
      out.push({
        file: `i18n/overrides/${brand.name}/${f}`,
        messages: JSON.parse(readFileSync(join(OVERRIDES, brand.name, f), 'utf8')),
      })
    }
  }
  return out
}

/** 走訪巢狀或扁平的訊息物件，回傳 [完整 key, 字串值]。 */
function entries(obj: Record<string, unknown>, prefix = ''): Array<[string, string]> {
  const out: Array<[string, string]> = []
  for (const [k, v] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${k}` : k
    if (typeof v === 'string') out.push([path, v])
    else if (v && typeof v === 'object') out.push(...entries(v as Record<string, unknown>, path))
  }
  return out
}

describe('語系檔的訊息語法', () => {
  it('沒有會被當成 linked message 的裸 @', () => {
    const bad: string[] = []
    for (const { file, messages } of localeFiles()) {
      for (const [key, value] of entries(messages)) {
        // `{'@'}` 是正確的跳脫寫法，先拿掉再檢查
        const stripped = value.replace(/\{'@'\}/g, '')
        const hit = stripped.match(/@[A-Za-z0-9_:.]/)
        if (hit) {
          const at = stripped.indexOf(hit[0])
          bad.push(`${file} → ${key}：…${stripped.slice(Math.max(0, at - 20), at + 20)}…`)
        }
      }
    }
    expect(bad, '這些訊息裡的 `@` 會被 vue-i18n 當成 linked message，整份語系檔會編譯失敗。\n'
    + '要印字面的 @ 請寫成 {\'@\'}：\n' + bad.join('\n')).toEqual([])
  })

  /**
   * `{...}` 裡面只能放佔位符名稱。放別的東西——沒關的大括號、或是把大括號當標點用而
   * 裡面是一般文字——編譯器都會爆，整份語系檔就載不到。實際踩到的三種：
   *
   *   vi-vn  site.legal.text.termsConditions01   Invalid token in placeholder: 'Tên'
   *   ta-in  agent.faq.hint.restriction          Unterminated closing brace（`{v_point` 沒關）
   *   my-mm  events.jackpot.label.prize01        Invalid token in placeholder: 'ဆု'（`{ဆု}`）
   *
   * 合法的內容：識別字（`{name}`）、數字（`{0}`）、以及字面跳脫（`{'@'}`）。
   */
  it('大括號裡面是合法的佔位符', () => {
    const VALID = /^(?:[A-Za-z_$][\w$]*|\d+|'.*')$/
    const bad: string[] = []
    for (const { file, messages } of localeFiles()) {
      for (const [key, value] of entries(messages)) {
        // 沒有關起來的大括號
        const open = (value.match(/\{/g) ?? []).length
        const close = (value.match(/\}/g) ?? []).length
        if (open !== close) {
          if (!isKnown(file, key)) bad.push(`${file} → ${key}：大括號沒成對（{ ${open} 個、} ${close} 個）`)
          continue
        }
        for (const m of value.matchAll(/\{([^{}]*)\}/g)) {
          const inner = m[1]!.trim()
          if (!VALID.test(inner) && !isKnown(file, key)) bad.push(`${file} → ${key}：{${m[1]!.slice(0, 24)}} 不是合法的佔位符`)
        }
      }
    }
    expect(bad, '這些 `{}` 不是合法佔位符，vue-i18n 編譯會失敗、整份語系檔載不到：\n'
    + `${bad.join('\n')}`).toEqual([])
  })
})
