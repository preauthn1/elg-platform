import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  BANNER_PATH,
  MARQUEE_LOCATION,
  advertiseItemSchema,
  basicInfoResponseSchema,
  countryItemSchema,
  currencyItemSchema,
  informationItemSchema,
  marqueeTextSchema,
  siteEndpoints,
  versionsResponseSchema,
  winPointItemSchema,
} from '../../shared/api/endpoints/site'
import {
  BASIC_INFO_ITEMS,
  appendNewWinPoints,
  bannerPathFromVendor,
  basicInfoContentsFromItems,
  canShowAppDownload,
  currencyName,
  findCountryByPhone,
  isBasicInfoReady,
  isIOSUserAgent,
  marqueeDurationSeconds,
  marqueeFullText,
  mergeBasicInfo,
  mergeBasicInfoTitles,
  phoneRuleRegExp,
  pickDownloadLink,
  replacePolicyPlaceholders,
  resolveBannerTarget,
  uniqueCurrencyIds,
  visibleBasicInfoItems,
  winPointGamePath,
} from '../../shared/utils/siteContent'
import {
  useAdvertise,
  useAgentAnalytics,
  useBannerNavigation,
  useBasicInfo,
  useInformation,
  useMarquee,
  useWinPoints,
} from '../../app/composables/useSiteContent'
import { useCountries, useCurrencies, useVersions, useVideos } from '../../app/composables/useSiteReference'

/*
 * composable 依賴 Nuxt 自動匯入（ref / computed / useState / useI18n / navigateTo），
 * 這裡用最小替身：ref = { value }、computed = 每次取值重算；store 以同契約的假物件取代 Pinia。
 */
const mocks = vi.hoisted(() => {
  const ref = <T>(value: T) => ({ value })
  const computed = <T>(getter: () => T) => ({
    get value() {
      return getter()
    },
  })

  function createBasicInfoStore() {
    let pending: Promise<Record<string, string>> | null = null
    const store = {
      locale: null as string | null,
      contents: null as Record<string, string> | null,
      get loaded() {
        return store.contents !== null
      },
      isFresh(locale: string) {
        return store.contents !== null && store.locale === locale
      },
      setContents(locale: string, next: Record<string, string>) {
        store.locale = locale
        store.contents = next
        pending = null
      },
      invalidate() {
        store.locale = null
        store.contents = null
        pending = null
      },
      getPending() {
        return pending
      },
      setPending(locale: string, next: Promise<Record<string, string>> | null) {
        store.locale = locale
        pending = next
      },
      contentOf(key: string) {
        return store.contents?.[key] ?? ''
      },
    }
    return store
  }

  function createReferenceStore() {
    const store = {
      countries: [] as Array<Record<string, unknown>>,
      currencies: [] as Array<Record<string, unknown>>,
      selectedCurrencyId: null as number | null,
      setCountries(list: Array<Record<string, unknown>>) {
        store.countries = list
      },
      setCurrencies(list: Array<Record<string, unknown>>) {
        store.currencies = list
      },
      setCurrency(id: number | null) {
        store.selectedCurrencyId = id
      },
    }
    return store
  }

  return {
    ref,
    computed,
    createBasicInfoStore,
    createReferenceStore,
    basicInfoStore: createBasicInfoStore(),
    referenceStore: createReferenceStore(),
    post: vi.fn(),
    fetchVendor: vi.fn(),
    navigateTo: vi.fn(),
    siteSettings: null as Record<string, unknown> | null,
    siteRaw: {} as Record<string, unknown>,
  }
})

vi.mock('../../app/composables/useApi', () => ({
  useApi: () => ({ post: mocks.post }),
  isApiOk: (res: { code: number }) => res.code === 200,
}))

vi.mock('../../app/composables/useGames', () => ({
  useGames: () => ({ fetchVendor: mocks.fetchVendor }),
}))

vi.mock('../../app/composables/useSite', () => ({
  useSite: () => ({
    site: mocks.computed(() => ({ raw: mocks.siteRaw, settings: mocks.siteSettings })),
    settings: mocks.computed(() => mocks.siteSettings),
    flag: (key: string) => mocks.siteSettings?.[key] === 1,
  }),
}))

vi.mock('../../app/stores/basicInfo', () => ({
  useBasicInfoStore: () => mocks.basicInfoStore,
}))

vi.mock('../../app/stores/siteReference', () => ({
  useSiteReferenceStore: () => mocks.referenceStore,
}))

const i18nLocale = { value: 'en' }
const stateMap = new Map<string, { value: unknown }>()

function ok<T>(data: T) {
  return { code: 200, message: 'ok', data }
}

function fail(code = 1213) {
  return { code, message: 'Domain name does not exist', data: [] }
}

beforeEach(() => {
  mocks.post.mockReset()
  mocks.fetchVendor.mockReset()
  mocks.navigateTo.mockReset()
  mocks.siteSettings = null
  mocks.siteRaw = {}
  mocks.basicInfoStore = mocks.createBasicInfoStore()
  mocks.referenceStore = mocks.createReferenceStore()
  i18nLocale.value = 'en'
  stateMap.clear()
  vi.stubGlobal('ref', mocks.ref)
  vi.stubGlobal('computed', mocks.computed)
  vi.stubGlobal('useI18n', () => ({ locale: i18nLocale }))
  vi.stubGlobal('navigateTo', mocks.navigateTo)
  vi.stubGlobal('useState', (key: string, init: () => unknown) => {
    if (!stateMap.has(key)) stateMap.set(key, mocks.ref(init()))
    return stateMap.get(key)
  })
})

describe('siteEndpoints 登記', () => {
  it('登記本領域端點且不重複登記別領域的路由', () => {
    expect(Object.keys(siteEndpoints).sort()).toEqual([
      'advertise/list',
      'agent/analytics',
      'basic-info',
      'country/list',
      'currency/list',
      'information/list',
      'marquee/texts',
      'marquee/win-points',
      'versions',
      'videos',
    ])
    expect(siteEndpoints).not.toHaveProperty('agent/template')
    expect(siteEndpoints).not.toHaveProperty('agent/downline-code')
  })

  it('auth 依 routes.json middleware：videos 需登入，其餘 optional', () => {
    expect(siteEndpoints.videos.auth).toBe('member')
    expect(siteEndpoints.videos.withDomain).toBe(false)
    expect(siteEndpoints['currency/list'].withDomain).toBe(false)
    for (const key of ['basic-info', 'information/list', 'advertise/list', 'marquee/texts', 'country/list'] as const) {
      expect(siteEndpoints[key].auth).toBe('optional')
    }
  })

  it('advertise/list 的 path_id 必填且收成整數', () => {
    const req = siteEndpoints['advertise/list'].request
    expect(req.parse({ path_id: '7' })).toEqual({ path_id: 7 })
    expect(req.safeParse({}).success).toBe(false)
    expect(req.safeParse({ path_id: -1 }).success).toBe(false)
  })

  it('marquee/texts 的 location 只接受 1/2，可不帶', () => {
    const req = siteEndpoints['marquee/texts'].request
    expect(req.parse({ location: 1 })).toEqual({ location: 1 })
    expect(req.parse({})).toEqual({})
    expect(req.safeParse({ location: 3 }).success).toBe(false)
  })

  it('response schema 照後端原樣、0/1 收成 flag', () => {
    const info = informationItemSchema.parse({
      id: '3', type: 4, game_type_id: null, game_id: null, vendor_id: null,
      url: 'https://x', image: 'a.png', image_pc: 'b.png', description: 'd', open_window: '1',
    })
    expect(info.open_window).toBe(1)
    expect(info.id).toBe(3)

    const ad = advertiseItemSchema.parse({ id: 1, path_id: 1, type: 0, image: null, image_pc: null, url: null, open_window: 0 })
    expect(ad.open_window).toBe(0)

    expect(marqueeTextSchema.parse({ id: 1, type: 1, location: 1, content: 'hi' }).content).toBe('hi')
    expect(basicInfoResponseSchema.parse({ items: [{ key: 'faq', content: '' }] }).items[0]?.key).toBe('faq')
    expect(basicInfoResponseSchema.safeParse({ items: [{ key: 'other', content: '' }] }).success).toBe(false)
    expect(winPointItemSchema.parse({ id: 9, win: 12.34, username: 'u', game_name: 'g', game_id: 5, vendor_id: 36, image: '' }).win).toBe('12.34')
    expect(versionsResponseSchema.parse(null)).toBeNull()
    expect(versionsResponseSchema.parse({ ios: { version: null, downloadLink: null }, android: { version: '1', downloadLink: 'https://a' } })?.android.downloadLink).toBe('https://a')
    expect(countryItemSchema.parse({ id: 1, code: 'MY', name: 'Malaysia', country_code: 60, phone_rule: '^1\\d{8,9}$' }).country_code).toBe('60')
    expect(currencyItemSchema.parse({ id: 1, code: 'MYR', name: 'Ringgit', rate: '1.0000' }).rate).toBe('1.0000')
  })

  it('常數對齊後端 enum', () => {
    expect(MARQUEE_LOCATION).toEqual({ LOBBY: 1, CHAT: 2 })
    expect(BANNER_PATH.FULL).toBe(1)
    expect(BANNER_PATH.HOT_TAGS).toBe(7)
    expect(BANNER_PATH.AGENT_POPUP).toBe(10)
  })
})

describe('basic-info 純邏輯', () => {
  it('入口只看旗標 === true，順序固定', () => {
    expect(visibleBasicInfoItems(undefined)).toEqual([])
    expect(visibleBasicInfoItems({ faq: true, about_us: true, terms: false, privacy: 1 }).map(i => i.key)).toEqual(['about_us', 'faq'])
    expect(BASIC_INFO_ITEMS.map(i => i.titleKey)).toEqual(['site.about.title.aboutUs', 'site.legal.title.termsConditions', 'site.support.title.commonProblem', 'site.legal.title.privacyPolicy', 'site.legal.title.responsibleGaming'])
  })

  it('mergeBasicInfo：同標題換內容、空內容保留寫死文案、沒有的補在最後且不改原陣列', () => {
    const base = [
      { titleKey: 'site.support.title.commonProblem', content: 'hard-faq' },
      { titleKey: 'site.legal.title.termsConditions', content: 'hard-terms' },
    ]
    const items = visibleBasicInfoItems({ faq: true, terms: true, about_us: true })
    const merged = mergeBasicInfo(base, items, { faq: 'backend-faq', terms: '', about_us: 'backend-about' })
    expect(merged).toEqual([
      { titleKey: 'site.support.title.commonProblem', content: 'backend-faq' },
      { titleKey: 'site.legal.title.termsConditions', content: 'hard-terms' },
      { titleKey: 'site.about.title.aboutUs', content: 'backend-about' },
    ])
    expect(base[0]?.content).toBe('hard-faq')
  })

  it('補項目只依旗標：內容還沒回來也先佔位，索引才對得上', () => {
    const items = visibleBasicInfoItems({ about_us: true })
    expect(mergeBasicInfo([], items, null)).toEqual([{ titleKey: 'site.about.title.aboutUs', content: '' }])
    expect(mergeBasicInfoTitles(['site.support.title.commonProblem', 'site.about.title.aboutUs'], items)).toEqual(['site.support.title.commonProblem', 'site.about.title.aboutUs'])
    expect(mergeBasicInfoTitles(['site.support.title.commonProblem'], items)).toEqual(['site.support.title.commonProblem', 'site.about.title.aboutUs'])
  })

  it('內容未定案前不渲染會被覆蓋的項目；後台不供稿的一開始就可渲染', () => {
    const items = visibleBasicInfoItems({ faq: true })
    expect(isBasicInfoReady(false, 'site.support.title.commonProblem', items)).toBe(false)
    expect(isBasicInfoReady(false, 'site.legal.title.cookiesPolicy', items)).toBe(true)
    expect(isBasicInfoReady(true, 'site.support.title.commonProblem', items)).toBe(true)
  })

  it('佔位字替換', () => {
    expect(basicInfoContentsFromItems([{ key: 'faq', content: 'x' }, { key: 'terms', content: '' }])).toEqual({ faq: 'x', terms: '' })
    expect(replacePolicyPlaceholders('Hi {name}, mail {email}', 'ACME')).toBe('Hi ACME, mail email')
  })
})

describe('resolveBannerTarget（pushBanner 分流）', () => {
  it.each([
    [{ type: 0 }, { kind: 'none' }],
    [{ type: 1, game_type_id: 5 }, { kind: 'internal', path: '/vendors?typeId=5' }],
    [{ type: 1, game_type_id: null }, { kind: 'none' }],
    [{ type: 2, game_id: 77, vendor_id: 3 }, { kind: 'game', gameId: 77, vendorId: 3 }],
    [{ type: 3, vendor_id: 3 }, { kind: 'vendor', vendorId: 3 }],
    [{ type: 4, url: 'https://example.invalid', open_window: 1 }, { kind: 'external', url: 'https://example.invalid', newWindow: true }],
    [{ type: 4, url: 'https://example.invalid', open_window: 0 }, { kind: 'external', url: 'https://example.invalid', newWindow: false }],
    [{ type: 4, url: null }, { kind: 'none' }],
    [{ type: 5, url: 'promotions' }, { kind: 'internal', path: '/promotions' }],
    [{ type: 5, url: '/promotions' }, { kind: 'internal', path: '/promotions' }],
    [{ type: 5, url: 'lobby?modal=deposit' }, { kind: 'modal', name: 'deposit' }],
    [{ type: 9 }, { kind: 'none' }],
  ])('%j → %j', (item, target) => {
    expect(resolveBannerTarget(item)).toEqual(target)
  })

  it('null 不可點', () => {
    expect(resolveBannerTarget(null)).toEqual({ kind: 'none' })
  })
})

describe('bannerPathFromVendor（型 2／3 查場館後的去向）', () => {
  const listVendor = { id: 4, item_type: 1, status: 1 }
  const appVendor = { id: 4, item_type: 1, status: 1, need_login: 1 as const }
  const closedVendor = { id: 4, item_type: 1, status: 3 }
  const game = { kind: 'game', gameId: 8, vendorId: 4 } as const
  const vendor = { kind: 'vendor', vendorId: 4 } as const

  it('遊戲列表場館可直接進遊戲；APP 場館改走場館路徑', () => {
    expect(bannerPathFromVendor(game, listVendor)).toBe('/game/8')
    expect(bannerPathFromVendor(game, appVendor)).toBe('/game/app/4?itemType=1')
  })

  it('遊戲商一律走場館路徑', () => {
    expect(bannerPathFromVendor(vendor, listVendor)).toBe('/game/list/4')
    expect(bannerPathFromVendor(vendor, appVendor)).toBe('/game/app/4?itemType=1')
  })

  it('場館關閉或查不到不導頁', () => {
    expect(bannerPathFromVendor(game, closedVendor)).toBeNull()
    expect(bannerPathFromVendor(vendor, null)).toBeNull()
  })
})

describe('marquee / win-points 純邏輯', () => {
  it('duration = 筆數×10 秒、全文 ※ 串接', () => {
    expect(marqueeDurationSeconds(3)).toBe(30)
    expect(marqueeDurationSeconds(0)).toBe(0)
    expect(marqueeFullText([{ content: 'a' }, { content: 'b' }])).toBe('※a\n※b')
  })

  it('只推進新出現的 id', () => {
    const current = [{ id: 1 }, { id: 2 }]
    expect(appendNewWinPoints(current, [{ id: 2 }, { id: 3 }])).toEqual([{ id: 1 }, { id: 2 }, { id: 3 }])
    expect(appendNewWinPoints([], [])).toEqual([])
  })

  it('指定 vendor 走聲明頁', () => {
    expect(winPointGamePath({ game_id: 5, vendor_id: 36 }, [36])).toBe('/game/statement/36/5')
    expect(winPointGamePath({ game_id: 5, vendor_id: 1 }, [36])).toBe('/game/5')
  })
})

describe('versions / country / currency 純邏輯', () => {
  it('下載連結依平台取，null 不動作', () => {
    const data = { ios: { version: '1', downloadLink: 'https://ios' }, android: { version: null, downloadLink: null } }
    expect(pickDownloadLink(data, 'ios')).toBe('https://ios')
    expect(pickDownloadLink(data, 'android')).toBeNull()
    expect(pickDownloadLink(null, 'ios')).toBeNull()
    expect(isIOSUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)')).toBe(true)
    expect(isIOSUserAgent('Mozilla/5.0 (Linux; Android 14)')).toBe(false)
  })

  it('下載按鈕看 has_ipa / has_apk 不看 versions', () => {
    expect(canShowAppDownload({ has_ipa: 1, has_apk: 0 }, 'ios')).toBe(true)
    expect(canShowAppDownload({ has_ipa: 1, has_apk: 0 }, 'android')).toBe(false)
    expect(canShowAppDownload(null, 'ios')).toBe(false)
  })

  it('phone_rule 轉 RegExp，無規則或壞規則走預設', () => {
    expect(phoneRuleRegExp('^1\\d{8,9}$').test('123456789')).toBe(true)
    expect(phoneRuleRegExp(null).source).toBe('^[1-9]\\d{8,9}$')
    expect(phoneRuleRegExp('[').source).toBe('^[1-9]\\d{8,9}$')
    expect(phoneRuleRegExp(null).test('0123456789')).toBe(false)
  })

  it('以 startsWith(country_code) 反推國家，取國碼最長者', () => {
    const countries = [{ country_code: '6' }, { country_code: '60' }, { country_code: '886' }]
    expect(findCountryByPhone(countries, '60123456789')).toEqual({ country_code: '60' })
    expect(findCountryByPhone(countries, '886912345678')).toEqual({ country_code: '886' })
    expect(findCountryByPhone(countries, '1234')).toBeUndefined()
  })

  it('currency_id 去重排除 0，找不到名稱顯示 -', () => {
    expect(uniqueCurrencyIds([{ currency_id: 0 }, { currency_id: '2' }, { currency_id: 2 }, { currency_id: 5 }, {}])).toEqual([2, 5])
    const currencies = [{ id: 2, name: 'MYR' }]
    expect(currencyName(currencies, '2')).toBe('MYR')
    expect(currencyName(currencies, 9)).toBe('-')
    expect(currencyName(currencies, null)).toBe('-')
  })
})

describe('useBasicInfo', () => {
  it('沒任何旗標為 true 時不呼叫端點', async () => {
    mocks.siteSettings = { basic_info: { faq: false } }
    const { load, hasBasicInfo, isReady } = useBasicInfo()
    expect(hasBasicInfo.value).toBe(false)
    expect(await load()).toEqual({})
    expect(mocks.post).not.toHaveBeenCalled()
    expect(isReady('site.support.title.commonProblem')).toBe(true)
  })

  it('多個畫面同時等待共用同一個請求，取回後快取在 store', async () => {
    mocks.siteSettings = { basic_info: { faq: true, about_us: true } }
    mocks.post.mockResolvedValue(ok({ items: [{ key: 'faq', content: 'F' }, { key: 'about_us', content: 'A' }, { key: 'terms', content: '' }] }))
    const a = useBasicInfo()
    const b = useBasicInfo()
    expect(a.isReady('site.support.title.commonProblem')).toBe(false)
    const [ca, cb] = await Promise.all([a.load(), b.load()])
    expect(mocks.post).toHaveBeenCalledTimes(1)
    expect(mocks.post).toHaveBeenCalledWith('basic-info', {})
    expect(ca).toEqual({ faq: 'F', about_us: 'A', terms: '' })
    expect(cb).toBe(ca)
    expect(a.isReady('site.support.title.commonProblem')).toBe(true)
    expect(a.contentOf('faq')).toBe('F')
    expect(a.mergedPolicies([{ titleKey: 'site.support.title.commonProblem', content: 'hard' }])).toEqual([
      { titleKey: 'site.support.title.commonProblem', content: 'F' },
      { titleKey: 'site.about.title.aboutUs', content: 'A' },
    ])
    expect(a.menuTitles(['site.legal.title.cookiesPolicy'])).toEqual(['site.legal.title.cookiesPolicy', 'site.about.title.aboutUs', 'site.support.title.commonProblem'])

    await useBasicInfo().load()
    expect(mocks.post).toHaveBeenCalledTimes(1)
  })

  it('切語系使快取失效並重抓', async () => {
    mocks.siteSettings = { basic_info: { faq: true } }
    mocks.post.mockResolvedValue(ok({ items: [{ key: 'faq', content: 'EN' }] }))
    await useBasicInfo().load()
    i18nLocale.value = 'zh-tw'
    mocks.post.mockResolvedValue(ok({ items: [{ key: 'faq', content: 'ZH' }] }))
    const contents = await useBasicInfo().load()
    expect(mocks.post).toHaveBeenCalledTimes(2)
    expect(contents).toEqual({ faq: 'ZH' })
  })

  it('非 200 或請求丟出時當空內容，寫死文案保留', async () => {
    mocks.siteSettings = { basic_info: { faq: true } }
    mocks.post.mockResolvedValue(fail())
    const info = useBasicInfo()
    expect(await info.load()).toEqual({})
    expect(info.mergedPolicies([{ titleKey: 'site.support.title.commonProblem', content: 'hard' }])).toEqual([{ titleKey: 'site.support.title.commonProblem', content: 'hard' }])
    expect(info.isReady('site.support.title.commonProblem')).toBe(true)

    mocks.basicInfoStore = mocks.createBasicInfoStore()
    mocks.post.mockRejectedValue(new Error('network'))
    expect(await useBasicInfo().load()).toEqual({})
  })
})

describe('useInformation / useAdvertise / useMarquee', () => {
  it('information：旗標與清單，非 200 回空陣列', async () => {
    mocks.siteSettings = { information_visible: 1 }
    mocks.post.mockResolvedValue(ok([{ id: 1, type: 0 }]))
    const { informationVisible, loadInformation } = useInformation()
    expect(informationVisible.value).toBe(true)
    expect(await loadInformation()).toEqual([{ id: 1, type: 0 }])
    expect(mocks.post).toHaveBeenCalledWith('information/list', {})
    mocks.post.mockResolvedValue(fail())
    expect(await loadInformation()).toEqual([])
  })

  it('advertise：以 path_id 呼叫', async () => {
    mocks.post.mockResolvedValue(ok([{ id: 2, path_id: 10, type: 0 }]))
    const { loadAdvertise } = useAdvertise()
    expect(await loadAdvertise(BANNER_PATH.AGENT_POPUP)).toHaveLength(1)
    expect(mocks.post).toHaveBeenCalledWith('advertise/list', { path_id: 10 })
  })

  it('marquee：帶 location 與不帶', async () => {
    mocks.post.mockResolvedValue(ok([{ id: 1, type: 1, location: 1, content: 'a' }]))
    const { loadMarqueeTexts, durationOf, fullTextOf } = useMarquee()
    const items = await loadMarqueeTexts(MARQUEE_LOCATION.LOBBY)
    expect(mocks.post).toHaveBeenCalledWith('marquee/texts', { location: 1 })
    await loadMarqueeTexts()
    expect(mocks.post).toHaveBeenLastCalledWith('marquee/texts', {})
    expect(durationOf(items)).toBe(10)
    expect(fullTextOf(items)).toBe('※a')
  })
})

describe('useWinPoints / useAgentAnalytics', () => {
  it('輪詢只推進新 id', async () => {
    const { list, refreshWinPoints, pathOf } = useWinPoints([36])
    mocks.post.mockResolvedValue(ok([{ id: 1, win: '1.00', username: 'u', game_id: 5, vendor_id: 36 }]))
    await refreshWinPoints()
    mocks.post.mockResolvedValue(ok([
      { id: 1, win: '1.00', username: 'u', game_id: 5, vendor_id: 36 },
      { id: 2, win: '2.00', username: 'v', game_id: 6, vendor_id: 1 },
    ]))
    await refreshWinPoints()
    expect(list.value.map(i => i.id)).toEqual([1, 2])
    expect(pathOf(list.value[0]!)).toBe('/game/statement/36/5')
    expect(pathOf(list.value[1]!)).toBe('/game/6')
    mocks.post.mockResolvedValue(fail())
    await refreshWinPoints()
    expect(list.value).toHaveLength(2)
  })

  it('analytics 非 200 回 null', async () => {
    const { loadAnalytics } = useAgentAnalytics()
    mocks.post.mockResolvedValue(ok({ type: 'ga', code: 'G-1' }))
    expect(await loadAnalytics()).toEqual({ type: 'ga', code: 'G-1' })
    expect(mocks.post).toHaveBeenCalledWith('agent/analytics', {})
    mocks.post.mockResolvedValue(fail())
    expect(await loadAnalytics()).toBeNull()
  })
})

describe('useBannerNavigation', () => {
  it('依 type 導頁：內部／外部（新視窗）／modal／不可點', async () => {
    const { openBanner, isClickable, activeModal } = useBannerNavigation()
    await openBanner({ type: 1, game_type_id: 3 })
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/vendors?typeId=3')
    await openBanner({ type: 4, url: 'https://x', open_window: 1 })
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('https://x', { external: true, open: { target: '_blank' } })
    await openBanner({ type: 4, url: 'https://y', open_window: 0 })
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('https://y', { external: true, open: undefined })
    await openBanner({ type: 5, url: 'x?modal=deposit' })
    expect(activeModal.value).toBe('deposit')
    const calls = mocks.navigateTo.mock.calls.length
    await openBanner({ type: 0 })
    expect(mocks.navigateTo.mock.calls.length).toBe(calls)
    expect(isClickable({ type: 0 })).toBe(false)
    expect(isClickable({ type: 5, url: 'promotions' })).toBe(true)
    expect(mocks.fetchVendor).not.toHaveBeenCalled()
  })

  it('型 2 遊戲：先查場館，遊戲列表場館進 /game/:id、APP 場館走場館路徑', async () => {
    const { openBanner } = useBannerNavigation()
    mocks.fetchVendor.mockResolvedValue(ok({ id: 1, item_type: 1, status: 1 }))
    await openBanner({ type: 2, game_id: 8, vendor_id: 1 })
    expect(mocks.fetchVendor).toHaveBeenCalledWith(1)
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/game/8')

    mocks.fetchVendor.mockResolvedValue(ok({ id: 1, item_type: 1, status: 1, need_login: 1 }))
    await openBanner({ type: 2, game_id: 8, vendor_id: 1 })
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/game/app/1?itemType=1')
  })

  it('型 2 沒帶 vendor_id 直接進遊戲；型 3 走場館路徑', async () => {
    const { openBanner } = useBannerNavigation()
    await openBanner({ type: 2, game_id: 8, vendor_id: null })
    expect(mocks.fetchVendor).not.toHaveBeenCalled()
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/game/8')

    mocks.fetchVendor.mockResolvedValue(ok({ id: 4, item_type: 1, status: 1 }))
    await openBanner({ type: 3, vendor_id: 4 })
    expect(mocks.fetchVendor).toHaveBeenCalledWith(4)
    expect(mocks.navigateTo).toHaveBeenLastCalledWith('/game/list/4')
  })

  it('場館關閉或查詢失敗不導頁', async () => {
    const { openBanner } = useBannerNavigation()
    mocks.fetchVendor.mockResolvedValue(ok({ id: 4, item_type: 1, status: 3 }))
    await openBanner({ type: 3, vendor_id: 4 })
    mocks.fetchVendor.mockResolvedValue(fail())
    await openBanner({ type: 2, game_id: 8, vendor_id: 4 })
    mocks.fetchVendor.mockResolvedValue(ok(null))
    await openBanner({ type: 3, vendor_id: 4 })
    expect(mocks.navigateTo).not.toHaveBeenCalled()
  })
})

describe('useCountries / useCurrencies', () => {
  it('只在 store 為空時呼叫，預設第一筆、query 國碼優先', async () => {
    mocks.post.mockResolvedValue(ok([
      { id: 1, code: 'MY', name: 'Malaysia', country_code: '60', phone_rule: '^1\\d{8,9}$' },
      { id: 2, code: 'TW', name: 'Taiwan', country_code: '886', phone_rule: null },
    ]))
    const c = useCountries()
    await c.ensureCountries()
    await c.ensureCountries()
    expect(mocks.post).toHaveBeenCalledTimes(1)
    expect(mocks.post).toHaveBeenCalledWith('country/list', {})
    expect(c.countries.value).toHaveLength(2)
    expect(c.defaultCountry()?.code).toBe('MY')
    expect(c.defaultCountry('886')?.code).toBe('TW')
    expect(c.defaultCountry('999')?.code).toBe('MY')
    expect(c.countryForPhone('886912345678')?.code).toBe('TW')
    expect(c.isValidPhone('123456789', c.countries.value[0])).toBe(true)
    expect(c.isValidPhone('0123456789', c.countries.value[0])).toBe(false)
    expect(c.isValidPhone('912345678', c.countries.value[1])).toBe(true)
    expect(c.isValidPhone('912345678', null)).toBe(true)
  })

  it('後端 country_code 是數字（60）時轉成字串，註冊 payload 與國碼比對才不會壞', async () => {
    mocks.post.mockResolvedValue(ok([
      { id: 1, code: 'MY', name: 'Malaysia', country_code: 60, phone_rule: '^[1-9]\\d{8,9}$' },
    ]))
    const c = useCountries()
    await c.ensureCountries()
    expect(c.defaultCountry()?.country_code).toBe('60')
    expect(c.defaultCountry('60')?.code).toBe('MY')
  })

  it('country 非 200 時 store 維持空', async () => {
    mocks.post.mockResolvedValue(fail())
    const c = useCountries()
    expect(await c.ensureCountries()).toEqual([])
    expect(c.defaultCountry()).toBeUndefined()
  })

  it('currency：取回後以帳號清單第一個 currency_id 當目前幣種', async () => {
    mocks.post.mockResolvedValue(ok([{ id: 2, code: 'MYR', name: 'Ringgit', rate: '1' }]))
    const c = useCurrencies()
    await c.loadCurrencies([{ currency_id: 0 }, { currency_id: 2 }, { currency_id: 3 }])
    expect(mocks.post).toHaveBeenCalledWith('currency/list', {})
    expect(c.selectedCurrencyId.value).toBe(2)
    expect(c.nameOf(2)).toBe('Ringgit')
    expect(c.nameOf(3)).toBe('-')
    await c.loadCurrencies()
    expect(c.selectedCurrencyId.value).toBeNull()
  })
})

describe('useVersions / useVideos', () => {
  it('顯示看 has_ipa/has_apk，下載取到連結才跳轉', async () => {
    mocks.siteRaw = { has_ipa: 1, has_apk: 0 }
    const v = useVersions()
    expect(v.platformFor('Mozilla/5.0 (iPhone)')).toBe('ios')
    expect(v.platformFor('Mozilla/5.0 (Linux; Android 14)')).toBe('android')
    expect(v.canShowDownload('ios')).toBe(true)
    expect(v.canShowDownload('android')).toBe(false)

    mocks.post.mockResolvedValue(ok({ ios: { version: '1', downloadLink: 'https://ios' }, android: { version: null, downloadLink: null } }))
    expect(await v.download('ios')).toBe('https://ios')
    expect(mocks.post).toHaveBeenCalledWith('versions', {})
    expect(mocks.navigateTo).toHaveBeenCalledWith('https://ios', { external: true })

    mocks.navigateTo.mockClear()
    expect(await v.download('android')).toBeNull()
    expect(mocks.navigateTo).not.toHaveBeenCalled()

    mocks.post.mockResolvedValue(ok(null))
    expect(await v.download('ios')).toBeNull()
    mocks.post.mockResolvedValue(fail())
    expect(await v.downloadLink('ios')).toBeNull()
    expect(mocks.navigateTo).not.toHaveBeenCalled()
  })

  it('videos 需登入；9998 當空陣列', async () => {
    const { loadVideos } = useVideos()
    mocks.post.mockResolvedValue(ok([{ username: 'a', password: 'b', ios_download: 'u', ios_open: 'u', android_download: 'u', android_open: 'u', app_icon: '' }]))
    expect(await loadVideos()).toHaveLength(1)
    expect(mocks.post).toHaveBeenCalledWith('videos', {})
    mocks.post.mockResolvedValue(fail(9998))
    expect(await loadVideos()).toEqual([])
  })
})
