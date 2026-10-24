import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { C17_LANGUAGES, isLocaleCode, languageFlag } from '../../shared/constants/languages'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))

describe('C17 開放的語言', () => {
  it('側邊欄與活動詳情彈窗都是這 5 個，順序照指定清單', () => {
    expect(C17_LANGUAGES.map(l => l.code)).toEqual(['zh-cn', 'en', 'ms-my', 'th-th', 'vi-vn'])
  })

  /** 守門：加了語言卻沒放國旗會變成破圖 */
  it('每個語言都有國旗素材', () => {
    for (const { code } of C17_LANGUAGES) {
      expect(existsSync(join(ROOT, 'public', languageFlag(code))), code).toBe(true)
    }
  })

  it('國旗沿用舊前台 supportLangs 的 image_file', () => {
    expect(C17_LANGUAGES.map(l => languageFlag(l.code))).toEqual([
      '/country/cn.svg',
      '/country/uk.svg',
      '/country/my.svg',
      '/country/th.svg',
      '/country/vn.svg',
    ])
  })

  /** 守門：清單裡的代碼必須是 i18n 設定過的語系，setLocale 才切得過去 */
  it('每個語言都在 nuxt.config 的 i18n locales 裡', () => {
    const config = readFileSync(join(ROOT, 'nuxt.config.ts'), 'utf8')
    for (const { code } of C17_LANGUAGES) {
      expect(config, code).toContain(`code: '${code}'`)
    }
  })
})

describe('單次請求的內容語言標頭', () => {
  it('只接受語系代碼格式，其餘不轉送給後端', () => {
    expect(isLocaleCode('zh-cn')).toBe(true)
    expect(isLocaleCode('en')).toBe(true)
    expect(isLocaleCode('tpi')).toBe(true)
    expect(isLocaleCode('')).toBe(false)
    expect(isLocaleCode(undefined)).toBe(false)
    expect(isLocaleCode('zh-cn\r\nX-Evil: 1')).toBe(false)
    expect(isLocaleCode('en,zh;q=0.9')).toBe(false)
  })
})
