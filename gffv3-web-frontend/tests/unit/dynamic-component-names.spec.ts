import { readFileSync, readdirSync } from 'node:fs'
import { extname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * `<component :is>` 不可以綁**字串形式的元件名**。
 *
 * 起因：`<component :is="tab.to ? 'NuxtLink' : 'button'">`。字串不會被解析成元件，
 * Vue 直接把它當成未知的自訂元素渲染成 `<nuxtlink>`——**沒有 href、點了完全沒反應**，
 * 而且不會有任何錯誤或警告，畫面看起來還是正常的（樣式都吃得到）。
 *
 * 實際影響過三個地方，其中包含**整條底部分頁列**：遊戲／促銷活動／錢包／個人資料
 * 四個入口全部點不動，只是沒有人從 UI 點進去所以一直沒被發現。
 *
 * 正確寫法是把元件本身傳進去：
 *
 *   import { NuxtLink } from '#components'
 *   <component :is="cond ? NuxtLink : 'div'">
 *
 * 原生標籤（'div'、'button'、'span'…）用字串是對的，所以只擋大寫開頭的字串——
 * 元件名在這個專案一律是 PascalCase，原生標籤一律小寫。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const SCAN = ['app']
const SKIP = new Set(['node_modules', '.nuxt', '.output', 'dist', 'coverage'])

function vueFiles(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue
    const full = join(dir, entry.name)
    if (entry.isDirectory()) out.push(...vueFiles(full))
    else if (extname(entry.name) === '.vue') out.push(full)
  }
  return out
}

/** `:is="…"` / `v-bind:is="…"` 的內容。 */
const IS_BINDING = /(?::|v-bind:)is\s*=\s*"([^"]*)"/g
/** 內容裡出現大寫開頭的字串常值，例如 'NuxtLink'。 */
const PASCAL_STRING = /'([A-Z][A-Za-z0-9]*)'/

describe('動態元件', () => {
  it('`:is` 不綁字串形式的元件名', () => {
    const bad: string[] = []
    for (const scanDir of SCAN) {
      for (const file of vueFiles(join(ROOT, scanDir))) {
        const source = readFileSync(file, 'utf8')
        for (const match of source.matchAll(IS_BINDING)) {
          const hit = PASCAL_STRING.exec(match[1] ?? '')
          if (hit) bad.push(`${relative(ROOT, file).replace(/\\/g, '/')}：:is="${match[1]}" → '${hit[1]}' 要改成直接傳元件`)
        }
      }
    }
    expect(bad, '這些 `:is` 綁的是字串，不會被解析成元件，會渲染成沒有 href、點不動的自訂元素。\n'
    + '改成 `import { X } from \'#components\'` 之後傳 X 本身：\n' + bad.join('\n')).toEqual([])
  })
})
