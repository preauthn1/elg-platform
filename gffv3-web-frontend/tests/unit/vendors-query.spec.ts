import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：連到 /vendors 的 query 只能用頁面實際會讀的 key（typeId、tag）。
 *
 * 選單的遊戲分類曾經帶成 `?type=`，/vendors 只讀 `typeId`，
 * 結果點哪個分類都停在「全部遊戲」，而且不會報錯（target/tickets#4328 第 9 點）。
 */

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const ALLOWED = new Set(['typeId', 'tag'])

function sourceFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) sourceFiles(full, out)
    else if (entry.name.endsWith('.ts') || entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

describe('/vendors 的 query key', () => {
  it('只用 typeId／tag', () => {
    const wrong: string[] = []
    let total = 0
    for (const file of sourceFiles(join(ROOT, 'app'))) {
      readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
        const keys = [
          ...line.matchAll(/path:\s*'\/vendors',\s*query:\s*\{\s*(\w+)/g),
          ...line.matchAll(/['"`]\/vendors\?(\w+)=/g),
        ].map(m => m[1]!)
        total += keys.length
        for (const key of keys) {
          if (!ALLOWED.has(key)) wrong.push(`${relative(ROOT, file)}:${i + 1} ${key}`)
        }
      })
    }
    expect(total).toBeGreaterThan(0)
    expect(wrong).toEqual([])
  })
})

/**
 * 守門測試：/vendors 的類型磚不能只改本地 ref，要經過 selectType 寫回網址。
 *
 * 曾經是 `@click="typeId = id"`，網址留在進來時的 typeId；從遊戲商返回時讀網址，
 * 不管切到哪個類型都回到選單點的那一個（target/tickets#4328 第 18 點）。
 */
describe('/vendors 的類型切換', () => {
  /** 每個皮的大廳畫面（components/<皮>/Page/Vendors.vue），之後加的皮自動納入檢查 */
  const views = readdirSync(join(ROOT, 'app/components'))
    .map(skin => join(ROOT, 'app/components', skin, 'Page/Vendors.vue'))
    .filter(path => existsSync(path))

  it('模板裡不直接指定 typeId', () => {
    expect(views.length).toBeGreaterThan(0)
    for (const path of views) {
      expect(readFileSync(path, 'utf8'), relative(ROOT, path)).not.toMatch(/@click="typeId\s*=/)
    }
  })

  it('selectType 會 router.replace 寫回 query', () => {
    const logic = readFileSync(join(ROOT, 'app/composables/useVendorLobby.ts'), 'utf8')
    expect(logic).toMatch(/function selectType[\s\S]*?router\.replace\(\{ query \}\)/)
  })
})
