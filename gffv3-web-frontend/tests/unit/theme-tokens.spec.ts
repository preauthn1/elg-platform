import { readFileSync, readdirSync } from 'node:fs'
import { extname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 顏色與字體的防呆：
 *
 *   1. app/ 與 shared/ 不准出現寫死的顏色——顏色只能從 token 來。
 *   2. 字體只能是 C17 用的那兩套，而且要真的有字檔載得到。
 *
 * 第 1 條是重點：一旦有人在元件裡寫 `#1565ff`，換皮時那顆顏色不會跟著變，
 * 而且畫面上看起來完全正常，code review 也不容易發現。
 *
 * 抓不到的：色值本身對不對（那要開設計稿比對）、版面結構、缺的區塊、文案。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

/** C17 只用這兩套字。Noto Sans 與 Segoe UI 是後備，不算。 */
const SKIN_FONTS = ['Plus Jakarta Sans', 'Inter']

/**
 * token 定義層，不受「不准寫死顏色」約束：
 * main.css 的色值是「皮沒給時的 fallback」，theme.css 是 C17 的調色盤本身。
 */
const TOKEN_LAYER = ['app/assets/css/main.css']

const COLOUR = /#[0-9a-fA-F]{3,8}\b|\brgba?\([^)]*\)|\bhsla?\([^)]*\)/g

/** 把 /* *\/ 與 // 註解拿掉，註解裡引用色值是說明不是實作。 */
function stripComments(source: string, isVue: boolean): string {
  let out = source.replace(/\/\*[\s\S]*?\*\//g, ' ')
  if (isVue) out = out.replace(/<!--[\s\S]*?-->/g, ' ')
  return out
    .split('\n')
    .map(line => line.replace(/(^|\s)\/\/.*$/, '$1'))
    .join('\n')
}

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) walk(full, out)
    else if (['.vue', '.ts', '.css'].includes(extname(entry.name))) out.push(full)
  }
  return out
}

describe('設計 token 防呆', () => {
  it('app/ 與 shared/ 不出現寫死的顏色', () => {
    const offenders: string[] = []
    for (const dir of ['app', 'shared']) {
      for (const file of walk(join(ROOT, dir))) {
        const rel = relative(ROOT, file).replace(/\\/g, '/')
        if (TOKEN_LAYER.includes(rel)) continue
        const hits = stripComments(readFileSync(file, 'utf8'), file.endsWith('.vue')).match(COLOUR) ?? []
        if (hits.length) offenders.push(`${rel} → ${[...new Set(hits)].join(' ')}`)
      }
    }
    expect(offenders, `顏色只能從 token 來（bg-surface / var(--c17-*)），不要寫死：\n${offenders.join('\n')}`)
      .toEqual([])
  })

  it('只用這個皮的那兩套字', () => {
    const src = stripComments(readFileSync(join(ROOT, 'skins/C17/theme.css'), 'utf8'), false)
    const declared = [...src.matchAll(/--c17-font[\w-]*\s*:\s*([^;]+);/g)].map(m => m[1]!)
    const quoted = new Set(declared.flatMap(v => [...v.matchAll(/"([^"]+)"/g)].map(m => m[1]!)))
    const strays = [...quoted].filter(f => !SKIN_FONTS.includes(f) && !f.startsWith('Noto Sans') && f !== 'Segoe UI')
    expect(strays, `只該用 ${SKIN_FONTS.join(' 與 ')}；多出來的：${strays.join(', ')}`).toEqual([])
    for (const font of SKIN_FONTS) {
      expect([...quoted], `${font} 沒有被宣告成任何一個字體 token`).toContain(font)
    }
  })

  it('兩套字都真的有字檔可以載', () => {
    const fonts = readFileSync(join(ROOT, 'app/assets/css/fonts.css'), 'utf8')
    for (const font of SKIN_FONTS) {
      expect(fonts, `fonts.css 沒有 ${font} 的 @font-face`).toContain(`'${font}'`)
    }
    const files = [...fonts.matchAll(/url\("\/fonts\/([^"]+)"\)/g)].map(m => m[1]!)
    expect(files.length, 'fonts.css 沒有指向任何本地字檔').toBeGreaterThan(0)
    const shipped = new Set(readdirSync(join(ROOT, 'public/fonts')))
    const missing = files.filter(f => !shipped.has(f))
    expect(missing, `fonts.css 指到 public/fonts 裡不存在的檔案：${missing.join(', ')}`).toEqual([])
  })
})
