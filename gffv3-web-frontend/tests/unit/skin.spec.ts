import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { DEFAULT_SKIN, resolveSkin, skinPage, skinShell } from '../../app/utils/skin'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name)
    if (statSync(full).isDirectory()) walk(full, out)
    else if (full.endsWith('.vue')) out.push(full)
  }
  return out
}

describe('依皮選元件', () => {
  it('還沒搬進來的皮與站台解析失敗都沿用 C17', () => {
    expect(resolveSkin('C17')).toBe('C17')
    expect(resolveSkin('C9')).toBe(DEFAULT_SKIN)
    expect(resolveSkin('')).toBe(DEFAULT_SKIN)
    expect(resolveSkin(undefined)).toBe(DEFAULT_SKIN)
  })

  it('有外殼就拿得到，同一個皮重複取是同一個元件（不會重掛）', () => {
    expect(skinShell('C17')).toBe(skinShell('C17'))
  })

  it('這個皮沒有這一頁回 null（呼叫端導回首頁）', () => {
    expect(skinPage('C17', 'Login')).not.toBeNull()
    expect(skinPage('C17', 'NoSuchPage')).toBeNull()
  })

  /**
   * 守門：pages/ 只放路由層，畫面一律在 components/<皮>/Page/。
   * 頁面直接寫畫面的話，換成 C9 這類其他皮時那一頁還是 C17 的樣子。
   * 只有「進來就導走、沒有畫面」的頁面例外。
   */
  it('pages/ 的模板只有 <SkinPage>', () => {
    const REDIRECT_ONLY = ['app/pages/cs.vue', 'app/pages/menu.vue', 'app/pages/record/index.vue']
    const offenders = walk(join(ROOT, 'app/pages'))
      .map(file => relative(ROOT, file).replace(/\\/g, '/'))
      .filter(rel => !REDIRECT_ONLY.includes(rel))
      .filter((rel) => {
        const src = readFileSync(join(ROOT, rel), 'utf8')
        const template = src.slice(src.indexOf('<template>')).replace(/\s+/g, ' ').trim()
        return !/^<template> <SkinPage[^>]*\/> <\/template>$/.test(template)
      })
    expect(offenders).toEqual([])
  })

  /** 守門：頁面改成 <SkinPage name="X"> 之後，C17 一定要有對應的畫面，不然 C17 的站會被導回首頁 */
  it('pages/ 裡每個 SkinPage 在 C17 都有畫面', () => {
    const missing: string[] = []
    for (const file of walk(join(ROOT, 'app/pages'))) {
      for (const [, name] of readFileSync(file, 'utf8').matchAll(/<SkinPage[\s\S]*?\bname="([^"]+)"/g)) {
        if (!existsSync(join(ROOT, 'app/components', DEFAULT_SKIN, 'Page', `${name}.vue`))) {
          missing.push(`${relative(ROOT, file)} → ${name}`)
        }
      }
    }
    expect(missing).toEqual([])
  })
})
