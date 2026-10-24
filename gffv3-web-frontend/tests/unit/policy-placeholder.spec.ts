import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { replacePolicyPlaceholders } from '../../shared/utils/siteContent'

/**
 * 共用語言檔不准寫死站台品牌名。
 *
 * 兩條規則：
 * 1. `i18n/locales/` 是所有站台共用的，文案裡要用 {name} 佔位，不能寫 T-POWER、me77 這類品牌名。
 *    （`i18n/overrides/<brand>/` 是該品牌專屬的覆寫，寫自己的名字是對的，不在此限。）
 * 2. 這些文案交給 vue-i18n 時，{name} 必須**當參數傳給 t()**：
 *    t() 在回傳前就會把 {name} 插值掉（沒給參數就變空字串），
 *    先 t() 再自己字串取代是無效的。replacePolicyPlaceholders 只給「後台供稿的內容」用。
 */

const LOCALE_DIR = path.join(process.cwd(), 'i18n/locales')

/**
 * 曾經寫死在共用語言檔裡的站台品牌名。新增品牌時一併加進來。
 * 注意不要放遊戲商名稱（V-POWER、BWG、ASTAR…），那些是供應商不是站台，本來就該寫死。
 */
const SITE_BRANDS = [
  'T-POWER', 'T-Power', 'TPOWER', 'TPower', 'Tpower',
  'V-World', 'VWorld', 'V-WORLD',
  'Alfawin', 'alfawin', 'ALFAWIN',
  'WINTOTO', 'Wintoto', 'wintoto',
  'YES2WIN', 'Yes2Win', 'yes2win',
  'SBS77', 'sbs77', 'AUK99', 'auk99', 'CJ7',
  'Casino365', 'casino365', 'me77', 'ME77',
  'vplus33', 'Vplus33', 'Betigo', 'betigo', 'BETIGO',
]

const localeFiles = fs.readdirSync(LOCALE_DIR).filter(f => f.endsWith('.json')).map(f => f.replace('.json', ''))

function readLocale(name: string): Record<string, unknown> {
  return JSON.parse(fs.readFileSync(path.join(LOCALE_DIR, `${name}.json`), 'utf8'))
}

describe('共用語言檔不可寫死站台品牌名', () => {
  it.each(localeFiles)('%s 沒有任何寫死的品牌名', (locale) => {
    const messages = readLocale(locale)
    const offenders: string[] = []
    for (const [key, value] of Object.entries(messages)) {
      if (typeof value !== 'string') continue
      for (const brand of SITE_BRANDS) {
        if (value.includes(brand)) offenders.push(`${key} 含 "${brand}"`)
      }
    }
    expect(offenders).toEqual([])
  })

  it('用戶協議與站台介紹改用 {name} 佔位', () => {
    const en = readLocale('en')
    for (const key of ['site.legal.text.fullUserAgreement', 'site.brand.text.whyChooseUs']) {
      expect(String(en[key] ?? ''), key).toContain('{name}')
    }
  })

  it('遊戲商名稱不算品牌，不應該被換掉', () => {
    // whyChooseUs 裡列了合作的遊戲供應商，那些是第三方公司名，要原樣保留
    const tw = readLocale('zh-tw')
    expect(String(tw['site.brand.text.whyChooseUs'] ?? '')).toContain('V-POWER')
  })
})

describe('replacePolicyPlaceholders：只給後台供稿的內容用', () => {
  it('把 {name} 與 {email} 換掉', () => {
    expect(replacePolicyPlaceholders('歡迎來到 {name}，聯絡 {email}', 'ACME', 'user@example.invalid'))
      .toBe('歡迎來到 ACME，聯絡 user@example.invalid')
  })

  it('同一個佔位字出現多次全部換掉', () => {
    expect(replacePolicyPlaceholders('{name} 與 {name}', 'X')).toBe('X 與 X')
  })

  it('沒有佔位字就原樣回傳', () => {
    expect(replacePolicyPlaceholders('沒有佔位字', 'X')).toBe('沒有佔位字')
  })

  it('站名為空時會留下空白——所以呼叫端必須確保站名有值', () => {
    expect(replacePolicyPlaceholders('由 {name} 提供', '')).toBe('由  提供')
  })
})
