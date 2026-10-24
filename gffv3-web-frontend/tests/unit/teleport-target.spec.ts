import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * 守門測試：Teleport 一律送到 Nuxt 的 `#teleports`，不可以送到 `body`。
 *
 * SSR／hydration 時送到 body 的內容會被插在 body 最前面（`#__nuxt` 之前），
 * 跟頁面裡同樣 z-50 的 fixed 外殼比，DOM 順序在前就被蓋住。
 * 紅包頁積分不足的存款彈窗就是這樣跑到紅包後面的。
 */

const APP = fileURLToPath(new URL('../../app', import.meta.url))
const ROOT = fileURLToPath(new URL('../..', import.meta.url))

function vueFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)
    if (entry.isDirectory()) vueFiles(full, out)
    else if (entry.name.endsWith('.vue')) out.push(full)
  }
  return out
}

describe('Teleport 目標', () => {
  it('不可以 Teleport 到 body', () => {
    const offenders = vueFiles(APP)
      .filter(file => /<Teleport\s[^>]*to=["']body["']/.test(readFileSync(file, 'utf8')))
      .map(file => relative(ROOT, file))
    expect(offenders).toEqual([])
  })
})
