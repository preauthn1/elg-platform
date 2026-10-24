import { readFileSync, readdirSync } from 'node:fs'
import { extname, join, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 靜態資源的防呆。
 *
 * 點陣圖一律 WebP：C17 的 100 張 PNG／JPG 轉檔後從 5057 KB 降到 1355 KB（省 73%），
 * 之後有人補一張 PNG 進來不會有任何報錯，只是把省下來的量再吐回去，所以用測試擋。
 * 規則與轉檔設定寫在 CLAUDE.md 第 7 條。
 */
const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const PUBLIC = join(ROOT, 'public')

/** 向量圖留著，動畫 GIF 沒有等價格式也留著（目前沒有） */
const ALLOWED = ['.webp', '.svg', '.ico', '.json', '.txt', '.xml', '.woff2', '.woff', '.mp4', '.webm']
const RASTER = ['.png', '.jpg', '.jpeg', '.bmp', '.tiff']

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) walk(full, out)
    else out.push(full)
  }
  return out
}

const files = walk(PUBLIC)
const rel = (p: string) => p.replace(PUBLIC, '').split(sep).join('/')

describe('靜態資源', () => {
  it('public 底下沒有 png / jpg', () => {
    const offenders = files.filter(f => RASTER.includes(extname(f).toLowerCase())).map(rel)
    expect(offenders).toEqual([])
  })

  it('public 底下沒有預期外的副檔名', () => {
    const offenders = files
      .filter(f => !ALLOWED.includes(extname(f).toLowerCase()))
      .map(rel)
    expect(offenders).toEqual([])
  })

  /**
   * 程式碼不可以再指到 png / jpg。動態組路徑（`${key}.webp`）改錯副檔名時
   * 上一條測不到，這條才會紅。第三方回傳的網址（後端給的圖）不在管轄範圍。
   */
  it('程式碼指向的皮資源都是 webp 或 svg', () => {
    const offenders: string[] = []
    const walkSrc = (dir: string, out: string[] = []): string[] => {
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name)
        if (entry.isDirectory()) walkSrc(full, out)
        else if (['.vue', '.ts'].includes(extname(entry.name))) out.push(full)
      }
      return out
    }
    for (const file of [...walkSrc(join(ROOT, 'app')), ...walkSrc(join(ROOT, 'shared'))]) {
      const source = readFileSync(file, 'utf8')
      for (const line of source.split('\n')) {
        if (!line.includes('/skins/')) continue
        if (/\.(png|jpe?g)\b/.test(line)) offenders.push(`${file.replace(ROOT, '').split(sep).join('/')}: ${line.trim()}`)
      }
    }
    expect(offenders).toEqual([])
  })
})
