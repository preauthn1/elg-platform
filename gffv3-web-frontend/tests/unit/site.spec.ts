import { describe, expect, it } from 'vitest'
import { buildSite, detectDevice, parseHostBrandMap, parseTemplateCode, resolveBrand, seoMetaFor } from '../../shared/utils/site'
import { customizedSettingSchema, flagSchema, templateResponseSchema } from '../../shared/types/site'

describe('parseTemplateCode', () => {
  it.each([
    ['C1622-1', 'C16', '22', 'P1'],
    ['C1320-3', 'C13', '20', 'P3'],
    ['C911', 'C9', '11', 'P1'],
    ['C16', 'C16', '', 'P1'],
    ['C9', 'C9', '', 'P1'],
    ['C1622-9', 'C16', '22', 'P1'],
  ])('%s → %s / %s / %s', (code, skin, variant, process) => {
    expect(parseTemplateCode(code)).toEqual({ code, skin, variant, process })
  })

  it('空值不炸', () => {
    expect(parseTemplateCode(null).skin).toBe('')
  })
})

describe('detectDevice（與 apis DetectDevice 同規則）', () => {
  it.each([
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'mobile'],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari', 'mobile'],
    ['Mozilla/5.0 (Linux; Android 14; SM-X900) Safari', 'pad'],
    ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'pad'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128', 'pc'],
    ['', 'pc'],
  ])('%s → %s', (ua, device) => {
    expect(detectDevice(ua)).toBe(device)
  })
})

describe('resolveBrand 三層解析', () => {
  const cfg = { brandField: 'brand_code', hostBrandMap: { 'example.invalid': 'tpower' }, defaultBrand: 'vw' }

  it('template 欄位優先', () => {
    expect(resolveBrand({ brand_code: 'me77' }, 'example.invalid', cfg)).toEqual({ brand: 'me77', source: 'template' })
  })

  it('沒有欄位就看 host 對照表', () => {
    expect(resolveBrand({}, 'example.invalid', cfg)).toEqual({ brand: 'tpower', source: 'hostMap' })
  })

  it('都沒有就用部署預設值', () => {
    expect(resolveBrand({}, 'example.invalid', cfg)).toEqual({ brand: 'vw', source: 'default' })
  })

  it('hostBrandMap 壞掉的 JSON 當空表', () => {
    expect(parseHostBrandMap('{bad')).toEqual({})
    expect(parseHostBrandMap('{"example.invalid":"x","example.invalid":1}')).toEqual({ 'example.invalid': 'x' })
  })

  /** 守門：Nuxt 會把 NUXT_HOST_BRAND_MAP 的 JSON 先轉成物件，只收文字的話設了也不生效 */
  it('hostBrandMap 收到已解析的物件也讀得到', () => {
    expect(parseHostBrandMap({ 'example.invalid': 'tgg', 'example.invalid': 1 })).toEqual({ 'example.invalid': 'tgg' })
    expect(parseHostBrandMap(['example.invalid'])).toEqual({})
    expect(parseHostBrandMap(null)).toEqual({})
    expect(parseHostBrandMap('')).toEqual({})
  })
})

describe('template schema 照後端原樣', () => {
  it('flag 收成 0/1', () => {
    expect(flagSchema.parse('1')).toBe(1)
    expect(flagSchema.parse(0)).toBe(0)
    expect(flagSchema.parse(null)).toBe(0)
    expect(flagSchema.parse('abc')).toBe(0)
  })

  it('customized_setting 多出來的鍵保留、缺的鍵不報錯', () => {
    const parsed = customizedSettingSchema.parse({ vip_visible: '1', brand_new_key: 'x' })
    expect(parsed.vip_visible).toBe(1)
    expect((parsed as Record<string, unknown>).brand_new_key).toBe('x')
  })

  it('buildSite 依裝置選模板並保留 raw', () => {
    const raw = templateResponseSchema.parse({
      domain: 'example.invalid',
      pc_templates: 'C1622-1',
      mobile_templates: 'C1320-2',
      title: 'Dev',
      customized_setting: { vip_visible: 1 },
    })
    const site = buildSite(raw, 'example.invalid', 'mobile', { brandField: 'brand_code', hostBrandMap: {}, defaultBrand: 'dev' })
    expect(site.template).toEqual({ code: 'C1320-2', skin: 'C13', variant: '20', process: 'P2' })
    expect(site.templates).toEqual({ pc: 'C1622-1', mobile: 'C1320-2' })
    expect(site.settings.vip_visible).toBe(1)
    expect(site.raw.title).toBe('Dev')
    expect(site.brand).toBe('dev')
  })
})

describe('seo_meta（target/tickets#4102）', () => {
  it('取不到域名時後端回 PHP 空陣列 []，schema 收成 {}', () => {
    expect(customizedSettingSchema.parse({ seo_meta: [] }).seo_meta).toEqual({})
  })

  it('照頁面代碼取三欄並去頭尾空白', () => {
    const settings = customizedSettingSchema.parse({
      seo_meta: { home: { title: ' My Site ', description: 'Desc', keywords: 'a,b' } },
    })
    expect(seoMetaFor(settings, 'home')).toEqual({ title: 'My Site', description: 'Desc', keywords: 'a,b' })
  })

  it('沒有該頁、沒有 seo_meta、settings 為 null 都回空字串', () => {
    const empty = { title: '', description: '', keywords: '' }
    expect(seoMetaFor(customizedSettingSchema.parse({ seo_meta: [] }), 'home')).toEqual(empty)
    expect(seoMetaFor(customizedSettingSchema.parse({}), 'home')).toEqual(empty)
    expect(seoMetaFor(null, 'home')).toEqual(empty)
  })

  it('schema 驗不過、settings 是後端原樣時也不炸', () => {
    const raw = { seo_meta: { home: { title: 123, description: null } } } as never
    expect(seoMetaFor(raw, 'home')).toEqual({ title: '', description: '', keywords: '' })
  })
})
