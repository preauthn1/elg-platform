import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { computed, ref } from 'vue'
import {
  gamesEndpoints,
  legacyPaginationSchema,
  lobbyVendorSchema,
  type GameListItem,
  type LobbyVendor,
  type VendorGameType,
} from '../../shared/api/endpoints/games'
import {
  buildGameListRequest,
  detectPlatform,
  filterGamesByType,
  filterVendorsByTag,
  filterVendorsByType,
  gameTypeLabelKey,
  getVendorPath,
  isAppVendor,
  isInDailyWindow,
  isRtpVisible,
  isVendorPasswordValid,
  parseDailyWindow,
  parseTypeId,
  pickGameUrl,
  resolveAppInfo,
  resolveGameEntry,
  resolvePushToGame,
  rootTypeIds,
  showsVendorBalanceInHeader,
  to12Hour,
  vendorMaintenanceUntil,
  vendorsPageTitleKey,
  type GameEntryContext,
} from '../../shared/utils/games'

describe('遊戲商頁頂部餘額', () => {
  it('照舊版 Navbar：場館 4 不顯示，其餘（含未指定場館）顯示', () => {
    expect(showsVendorBalanceInHeader(4)).toBe(false)
    expect(showsVendorBalanceInHeader(44)).toBe(true)
    expect(showsVendorBalanceInHeader(null)).toBe(true)
  })
})

// ── Nuxt 自動匯入的替身 ────────────────────────────────────────────────
// composable 與 store 是用 Nuxt 自動匯入呼叫 useApi／isApiOk／ref／computed 的，
// 原始碼裡沒有 import 敘述，只 mock 模組不會生效，必須掛成全域。
const post = vi.fn()
vi.stubGlobal('ref', ref)
vi.stubGlobal('computed', computed)
vi.stubGlobal('useApi', () => ({ post }))
vi.stubGlobal('isApiOk', (res: { code: number }) => res.code === 200)
vi.mock('../../app/composables/useApi', () => ({
  useApi: () => ({ post }),
  isApiOk: (res: { code: number }) => res.code === 200,
}))

const siteState = {
  site: ref<Record<string, unknown> | null>(null),
  settings: computed(() => null),
  brand: computed(() => ''),
  template: ref<{ skin: string } | null>({ skin: 'C16' }),
  flag: vi.fn(() => false),
}
vi.mock('../../app/composables/useSite', () => ({
  useSite: () => siteState,
}))

const loggedIn = ref(false)
const user = ref<{ id: number, username: string } | null>(null)
const navigateTo = vi.fn(async () => {})
vi.stubGlobal('useUserSession', () => ({ loggedIn, user }))
vi.stubGlobal('navigateTo', navigateTo)
vi.stubGlobal('useRoute', () => ({ fullPath: '/game/list/5', params: {}, query: {} }))
vi.stubGlobal('useRequestURL', () => new URL('https://example.invalid/'))

const { useGames } = await import('../../app/composables/useGames')
const { useGameLauncher } = await import('../../app/composables/useGameLauncher')
const { useGamesStore } = await import('../../app/stores/games')

// store 與跨領域 composable 同樣是自動匯入，補成全域才叫得到。
// enterVendor 是錢包領域的轉帳，這裡只驗遊戲流程，回 null 代表不自動轉入。
const enterVendor = vi.fn(async () => null)
vi.stubGlobal('useGamesStore', useGamesStore)
vi.stubGlobal('useSite', () => siteState)
vi.stubGlobal('useWallet', () => ({ enterVendor }))
vi.stubGlobal('useGames', useGames)

const ok = <T>(data: T) => ({ code: 200, message: 'ok', data })
const fail = (message = 'error') => ({ code: 400, message, data: [] })

function vendor(over: Partial<LobbyVendor> = {}): LobbyVendor {
  return lobbyVendorSchema.parse({ item_type: 1, id: 1, status: 1, name: 'V', ...over })
}

function game(over: Partial<GameListItem> = {}): GameListItem {
  return { id: 100, vendor_id: 5, type_id: 2, name: 'G', ...over } as GameListItem
}

const ctx: GameEntryContext = {
  isIOS: false,
  isAndroid: false,
  isStandalone: false,
  vendors: {},
  popupGames: false,
  vpowerAndroidNotice: false,
  redirectUrl: '',
  origin: 'https://example.invalid',
}

// ── request schema：組法 ───────────────────────────────────────────────
describe('games endpoints request schema', () => {
  it('lobby/vendors：vendor_ids 陣列與 type_id 都可省略、字串會轉數字', () => {
    const req = gamesEndpoints['lobby/vendors'].request
    expect(req.parse({})).toEqual({})
    expect(req.parse({ vendor_ids: ['22'], type_id: '3' })).toEqual({ vendor_ids: [22], type_id: 3 })
  })

  it('game/list：page / per_page 必填、vendor_id 可為單值或陣列、type_ids 才是後端讀的欄位', () => {
    const req = gamesEndpoints['game/list'].request
    expect(req.safeParse({ per_page: 30 }).success).toBe(false)
    expect(req.parse({ page: '1', per_page: '30', vendor_id: [5, '6'], type_ids: 2 })).toEqual({
      page: 1,
      per_page: 30,
      vendor_id: [5, 6],
      type_ids: 2,
    })
    expect(req.safeParse({ page: 0, per_page: 30 }).success).toBe(false)
  })

  it('game/url：exit_url 必須是含 scheme 的 URL，且 vendor_id / game_id 至少一個', () => {
    const req = gamesEndpoints['game/url'].request
    expect(req.safeParse({ game_id: 1, exit_url: '' }).success).toBe(false)
    expect(req.safeParse({ game_id: 1, exit_url: 'example.invalid/' }).success).toBe(false)
    expect(req.safeParse({ exit_url: 'https://example.invalid/' }).success).toBe(false)
    expect(req.parse({ vendor_id: '9', exit_url: 'https://example.invalid/' })).toEqual({
      vendor_id: 9,
      exit_url: 'https://example.invalid/',
    })
  })

  it('vendor/reset-password：英數 6~14 碼', () => {
    const req = gamesEndpoints['vendor/reset-password'].request
    expect(req.safeParse({ vendor_id: 1, new_password: [REDACTED]' }).success).toBe(false)
    expect(req.safeParse({ vendor_id: 1, new_password: [REDACTED]' }).success).toBe(false)
    expect(req.safeParse({ vendor_id: 1, new_password: [REDACTED]' }).success).toBe(true)
    expect(req.safeParse({ vendor_id: 1, new_password: 'a'.repeat(15) }).success).toBe(false)
  })

  it('game/list-by-types：type_counts 是 typeId → count，count 至少 1', () => {
    const req = gamesEndpoints['game/list-by-types'].request
    expect(req.parse({ type_counts: { 2: '5', 1: 3 } })).toEqual({ type_counts: { 2: 5, 1: 3 } })
    expect(req.safeParse({ type_counts: { 2: 0 } }).success).toBe(false)
  })

  it('event/vendor-games：vendor_id 至少 1；vendor/recently-vendor-ids：member_id 必填', () => {
    expect(gamesEndpoints['event/vendor-games'].request.safeParse({ vendor_id: 0 }).success).toBe(false)
    expect(gamesEndpoints['vendor/recently-vendor-ids'].request.safeParse({}).success).toBe(false)
  })

  it('auth 與 withDomain 依路由 middleware', () => {
    expect(gamesEndpoints['lobby/vendors'].auth).toBe('optional')
    expect(gamesEndpoints['game/url'].auth).toBe('member')
    expect(gamesEndpoints['game/url'].withDomain).toBe(false)
    expect(gamesEndpoints['game/list'].withDomain).toBeUndefined()
  })
})

describe('games endpoints response schema', () => {
  it('lobby/vendors 元素：0/1 旗標收成數字、maintenance_end_time 可為 null', () => {
    const item = lobbyVendorSchema.parse({
      item_type: '1', id: '22', status: 3, is_hot: '1', giftcode: null, maintenance_end_time: null, name: 'VPower', extra: 'x',
    })
    expect(item.item_type).toBe(1)
    expect(item.is_hot).toBe(1)
    expect(item.giftcode).toBe(0)
    expect(item.maintenance_end_time).toBeNull()
    expect((item as Record<string, unknown>).extra).toBe('x')
  })

  it('game/list 用舊版分頁形狀，previous / next 沒有時是空字串', () => {
    const page = legacyPaginationSchema.parse({ per_page: 30, total: 31, pages: 2, previous: '', current: 1, next: 2 })
    expect(page.previous).toBe('')
    expect(page.next).toBe(2)
    const res = gamesEndpoints['game/list'].response.parse({ games: [], pagination: page })
    expect(res.redirect_url).toBe('')
  })

  it('game/url：app_infos 只有 APP 場館才有', () => {
    const res = gamesEndpoints['game/url'].response.parse({ pc: 'https://a', mobile: null, open_window: 1 })
    expect(res.app_infos).toBeUndefined()
    const app = gamesEndpoints['game/url'].response.parse({
      pc: null, mobile: null, open_window: 0,
      app_infos: { username: 'u', password: 'p', ios_downloads: ['https://i'], ios_open: null, android_downloads: [], android_open: 'app://x', app_icon: null },
    })
    expect(app.app_infos?.android_open).toBe('app://x')
  })
})

// ── 純規則 ─────────────────────────────────────────────────────────────
describe('vendor filters', () => {
  const gameTypes: VendorGameType[] = [
    { vendor_id: 1, type_id: 2, root_type: 2 },
    { vendor_id: 1, type_id: 7, root_type: 1 },
    { vendor_id: 2, type_id: 2, root_type: 2 },
    { vendor_id: 3, type_id: 18, root_type: 18 },
  ]

  it('tag：hot / new 看旗標，all 全部', () => {
    const list = [vendor({ id: 1, is_hot: 1 }), vendor({ id: 2, is_new: 1 }), vendor({ id: 3 })]
    expect(filterVendorsByTag(list, 'hot').map(v => v.id)).toEqual([1])
    expect(filterVendorsByTag(list, 'new').map(v => v.id)).toEqual([2])
    expect(filterVendorsByTag(list, 'all')).toHaveLength(3)
  })

  it('類型：只留該 root_type 下的場館；item_type 2/3 類型不符排除；有指定類型筆時排除同 id 的 item_type 1', () => {
    const list = [
      vendor({ id: 1, item_type: 1 }),
      vendor({ id: 1, item_type: 3, type_id: 2 }),
      vendor({ id: 1, item_type: 2, type_id: 1, game_id: 9 }),
      vendor({ id: 2, item_type: 1 }),
      vendor({ id: 3, item_type: 1 }),
    ]
    const slots = filterVendorsByType(list, 2, gameTypes)
    expect(slots.map(v => `${v.id}:${v.item_type}`)).toEqual(['1:3', '2:1'])
    const live = filterVendorsByType(list, 1, gameTypes)
    expect(live.map(v => `${v.id}:${v.item_type}`)).toEqual(['1:2'])
    expect(filterVendorsByType(list, null, gameTypes)).toHaveLength(5)
  })

  it('假類型：10002 禮碼看 giftcode、10004 看 is_hot', () => {
    const list = [vendor({ id: 1, giftcode: 1 }), vendor({ id: 2, is_hot: 1 })]
    expect(filterVendorsByType(list, 10002, gameTypes).map(v => v.id)).toEqual([1])
    expect(filterVendorsByType(list, 10004, gameTypes).map(v => v.id)).toEqual([2])
  })

  it('類型 tab：root_type 去重、照固定順序、可依場館過濾', () => {
    expect(rootTypeIds(gameTypes)).toEqual([2, 1, 18])
    expect(rootTypeIds(gameTypes, 3)).toEqual([18])
    expect(rootTypeIds([{ vendor_id: 1, type_id: 77, root_type: 77 }])).toEqual([])
  })

  it('標題與類型文字 key', () => {
    expect(vendorsPageTitleKey('hot', null)).toBe('games.lobby.title.hotGame')
    expect(vendorsPageTitleKey('all', 10002)).toBe('games.vendor.label.rollingGames')
    expect(vendorsPageTitleKey('new', 18)).toBe('games.enum.category.sports')
    expect(gameTypeLabelKey(null)).toBe('games.category.option.allGames')
    expect(parseTypeId('18')).toBe(18)
    expect(parseTypeId('')).toBeNull()
  })
})

describe('getVendorPath（點場館導頁）', () => {
  it('status != 1 不可點', () => {
    expect(getVendorPath(vendor({ status: 2 }))).toBeNull()
  })

  it('VPower 22 強制進列表並帶 typeId', () => {
    expect(getVendorPath(vendor({ id: 22 }), 2)).toBe('/game/list/22?typeId=2')
    expect(getVendorPath(vendor({ id: 22 }))).toBe('/game/list/22')
  })

  it('need_login → /game/app、only_one_game → /game/statement，都帶 itemType', () => {
    expect(getVendorPath(vendor({ id: 7, need_login: 1, item_type: 3 }))).toBe('/game/app/7?itemType=3')
    expect(getVendorPath(vendor({ id: 8, only_one_game: 1 }))).toBe('/game/statement/8?itemType=1')
  })

  it('item_type 1 → 列表、2 → 遊戲、3 → 列表帶 type_id、4 → 站內連結帶 title', () => {
    expect(getVendorPath(vendor({ id: 5, item_type: 1 }), 18)).toBe('/game/list/5?typeId=18')
    expect(getVendorPath(vendor({ id: 5, item_type: 2, game_id: 66 }))).toBe('/game/66')
    expect(getVendorPath(vendor({ id: 5, item_type: 3, type_id: 4 }))).toBe('/game/list/5?typeId=4')
    expect(getVendorPath(vendor({ id: 5, item_type: 4, path: '/promotions', name: 'Promo' }))).toBe('/promotions?title=Promo')
    expect(getVendorPath(vendor({ id: 5, item_type: 4, path: null }))).toBeNull()
  })
})

/**
 * BFF 的 response zod 只發警告、回給瀏覽器的是後端原始資料，
 * lobby/vendors 的 is_new／is_hot／is_event／only_one_game／need_login 實際是 bool。
 * 用 `=== 1` 比對會讓 APP 場館、聲明頁、熱門／最新全部失效。
 */
describe('lobby/vendors 的旗標是後端原始 bool', () => {
  const raw = (over: Record<string, unknown>) => ({ item_type: 1, id: 7, status: 1, name: 'V', ...over }) as unknown as LobbyVendor

  it('need_login: true → /game/app、only_one_game: true → 聲明頁，也算 APP 角標', () => {
    expect(getVendorPath(raw({ need_login: true }))).toBe('/game/app/7?itemType=1')
    expect(getVendorPath(raw({ only_one_game: true }))).toBe('/game/statement/7?itemType=1')
    expect(isAppVendor(raw({ need_login: true }))).toBe(true)
    expect(getVendorPath(raw({ need_login: false }))).toBe('/game/list/7')
  })

  it('is_hot／is_new: true 才算熱門／最新', () => {
    const list = [raw({ id: 1, is_hot: true, is_new: false }), raw({ id: 2, is_hot: false, is_new: true })]
    expect(filterVendorsByTag(list, 'hot').map(v => v.id)).toEqual([1])
    expect(filterVendorsByTag(list, 'new').map(v => v.id)).toEqual([2])
  })

  it('resolvePushToGame：store 裡 need_login 是 true 也要進 /game/app', () => {
    expect(resolvePushToGame(game({ id: 4, vendor_id: 7 }), { ...ctx, vendors: { 7: raw({ need_login: true }) } }))
      .toEqual({ kind: 'app', path: '/game/app/7' })
  })
})

describe('isAppVendor（APP 角標）', () => {
  it('會進 /game/app 的才算；VPower 與不可點的不算', () => {
    expect(isAppVendor(vendor({ id: 7, need_login: 1 }))).toBe(true)
    expect(isAppVendor(vendor({ id: 7 }))).toBe(false)
    expect(isAppVendor(vendor({ id: 22, need_login: 1 }))).toBe(false)
    expect(isAppVendor(vendor({ id: 7, need_login: 1, status: 3 }))).toBe(false)
  })
})

describe('game list rules', () => {
  it('buildGameListRequest：全部場館不送 vendor_id、禮碼不送 type_ids、空關鍵字不送 game_name', () => {
    expect(buildGameListRequest({ vendorId: 'all', typeId: 10002, keyword: '  ', page: 2 })).toEqual({ page: 2, per_page: 30 })
    expect(buildGameListRequest({ vendorId: 5, typeId: 2, keyword: 'fish', page: 1, perPage: 500 })).toEqual({
      vendor_id: 5, type_ids: 2, game_name: 'fish', page: 1, per_page: 500,
    })
  })

  it('filterGamesByType：類型篩選；禮碼 + PGSSW 排除 6872', () => {
    const list = [game({ id: 6872, vendor_id: 50, type_id: 1 }), game({ id: 2, vendor_id: 50, type_id: 2 })]
    expect(filterGamesByType(list, 2).map(g => g.id)).toEqual([2])
    expect(filterGamesByType(list, 10002, 50).map(g => g.id)).toEqual([2])
    expect(filterGamesByType(list, 10002, 51)).toHaveLength(2)
    expect(filterGamesByType(list, null)).toHaveLength(2)
  })

  it('rtp 存在且不是 0.00 才顯示', () => {
    expect(isRtpVisible('96.50')).toBe(true)
    expect(isRtpVisible('0.00')).toBe(false)
    expect(isRtpVisible(null)).toBe(false)
  })
})

describe('resolveGameEntry（進場分流）', () => {
  it('disable / is_maintenance 不進場', () => {
    expect(resolveGameEntry(game({ disable: 1 }), ctx)).toEqual({ kind: 'blocked' })
    expect(resolveGameEntry(game({ is_maintenance: 1 }), ctx)).toEqual({ kind: 'blocked' })
  })

  it('VPower + Android 且站台要提示 → 說明彈窗', () => {
    expect(resolveGameEntry(game({ vendor_id: 22 }), { ...ctx, isAndroid: true, vpowerAndroidNotice: true })).toEqual({ kind: 'vpower-notice' })
    expect(resolveGameEntry(game({ vendor_id: 22 }), { ...ctx, isAndroid: true }).kind).toBe('game')
  })

  it('NPT 41 非真人 → 外連中轉，域名用 redirect_url', () => {
    expect(resolveGameEntry(game({ id: 3, vendor_id: 41, type_id: 2 }), { ...ctx, redirectUrl: 'example.invalid' })).toEqual({
      kind: 'npt', gameId: 3, host: 'example.invalid',
    })
    expect(resolveGameEntry(game({ vendor_id: 41, type_id: 2 }), ctx)).toMatchObject({ host: 'example.invalid' })
  })

  it('體育類型或指定場館 → 聲明頁；真人或 232 → 聲明頁 2', () => {
    expect(resolveGameEntry(game({ id: 9, vendor_id: 4, type_id: 4 }), ctx)).toEqual({ kind: 'statement', path: '/game/statement/4/9' })
    expect(resolveGameEntry(game({ id: 9, vendor_id: 5, type_id: 18 }), ctx)).toEqual({ kind: 'statement', path: '/game/statement/5/9' })
    expect(resolveGameEntry(game({ id: 9, vendor_id: 5, type_id: 2 }), ctx, { toStatementPage: true }).kind).toBe('statement')
    expect(resolveGameEntry(game({ vendor_id: 5, type_id: 1 }), ctx)).toEqual({ kind: 'statement2' })
    expect(resolveGameEntry(game({ vendor_id: 232, type_id: 2 }), ctx)).toEqual({ kind: 'statement2' })
  })

  it('pushToGame：APP 場館 → /game/app；可另開 → popup；iframe 專用場館或 iOS 獨立模式 → /game/:id', () => {
    const vendors = { 7: vendor({ id: 7, need_login: 1 }), 22: vendor({ id: 22, need_login: 1 }) }
    expect(resolvePushToGame(game({ vendor_id: 7 }), { ...ctx, vendors })).toEqual({ kind: 'app', path: '/game/app/7' })
    expect(resolvePushToGame(game({ id: 100, vendor_id: 22 }), { ...ctx, vendors })).toEqual({ kind: 'game', path: '/game/100' })
    expect(resolvePushToGame(game({ id: 100, vendor_id: 5 }), { ...ctx, popupGames: true })).toEqual({ kind: 'popup', url: 'https://example.invalid/game/100' })
    expect(resolvePushToGame(game({ id: 100, vendor_id: 50 }), { ...ctx, popupGames: true }).kind).toBe('game')
    expect(resolvePushToGame(game({ id: 100, vendor_id: 5 }), { ...ctx, popupGames: true, isIOS: true, isStandalone: true }).kind).toBe('game')
  })
})

describe('game url / app / password / 4D window', () => {
  it('pickGameUrl 依裝置選，缺一邊時互補', () => {
    expect(pickGameUrl({ pc: 'https://pc', mobile: 'https://m' }, true)).toBe('https://m')
    expect(pickGameUrl({ pc: 'https://pc', mobile: 'https://m' }, false)).toBe('https://pc')
    expect(pickGameUrl({ pc: 'https://pc', mobile: null }, true)).toBe('https://pc')
    expect(pickGameUrl({ pc: null, mobile: null }, false)).toBeNull()
  })

  it('resolveAppInfo 依平台取開啟連結與下載清單', () => {
    const info = { username: 'u', password: [REDACTED], ios_downloads: ['https://i1'], ios_open: 'ios://x', android_downloads: ['https://a1', 'https://a2'], android_open: null, app_icon: null }
    expect(resolveAppInfo(info, true)).toEqual({ openLink: 'ios://x', downloads: ['https://i1'], hasApp: true })
    expect(resolveAppInfo(info, false)).toEqual({ openLink: null, downloads: ['https://a1', 'https://a2'], hasApp: false })
  })

  it('密碥規則：英數 6~14', () => {
    expect(isVendorPasswordValid('abc123')).toBe(true)
    expect(isVendorPasswordValid('abc12')).toBe(false)
    expect(isVendorPasswordValid('abc-123')).toBe(false)
  })

  it('detectPlatform', () => {
    expect(detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)')).toEqual({ isIOS: true, isAndroid: false })
    expect(detectPlatform('Mozilla/5.0 (Linux; Android 14)')).toEqual({ isIOS: false, isAndroid: true })
  })

  it('每日維護時段：支援 JSON 字串設定與跨午夜', () => {
    expect(parseDailyWindow('{"start":"23:00","end":"01:00"}')).toEqual({ start: '23:00', end: '01:00' })
    expect(parseDailyWindow({ start: '', end: '01:00' })).toBeNull()
    expect(parseDailyWindow('bad')).toBeNull()
    const win = { start: '23:00', end: '01:00' }
    expect(isInDailyWindow(win, new Date(2026, 0, 1, 23, 30))).toBe(true)
    expect(isInDailyWindow(win, new Date(2026, 0, 1, 0, 30))).toBe(true)
    expect(isInDailyWindow(win, new Date(2026, 0, 1, 12, 0))).toBe(false)
    expect(isInDailyWindow({ start: '06:00', end: '06:30' }, new Date(2026, 0, 1, 6, 15))).toBe(true)
    expect(to12Hour('23:05')).toBe('11:05pm')
    expect(to12Hour('00:00')).toBe('12:00am')
  })
})

// ── composables（mock useApi().post）──────────────────────────────────
describe('useGames', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    post.mockReset()
    navigateTo.mockClear()
    loggedIn.value = false
    user.value = null
  })

  it('fetchLobby 並行打 lobby/vendors 與 vendor/game-types，都成功才 ready', async () => {
    post.mockImplementation(async (route: string) => {
      if (route === 'lobby/vendors') return ok([vendor({ id: 1 })])
      if (route === 'vendor/game-types') return ok([{ vendor_id: 1, type_id: 2, root_type: 2 }])
      return fail()
    })
    const games = useGames()
    const store = useGamesStore()
    expect(await games.fetchLobby()).toBe(true)
    expect(post).toHaveBeenCalledWith('lobby/vendors', {})
    expect(post).toHaveBeenCalledWith('vendor/game-types', {})
    expect(store.vendors).toHaveLength(1)
    expect(store.vendorMap[1]?.id).toBe(1)
    expect(store.lobbyReady).toBe(true)
  })

  it('fetchVendor 送 vendor_ids:[id]，回傳取 data[0]，不覆寫大廳列表', async () => {
    post.mockResolvedValue(ok([vendor({ id: 22, name: 'VPower' })]))
    const games = useGames()
    const store = useGamesStore()
    const res = await games.fetchVendor(22, 3)
    expect(post).toHaveBeenCalledWith('lobby/vendors', { vendor_ids: [22], type_id: 3 })
    expect(res.data?.name).toBe('VPower')
    expect(store.vendors).toHaveLength(0)
    post.mockResolvedValue(ok([]))
    expect((await games.fetchVendor(99)).data).toBeNull()
  })

  it('fetchGameList 依規則組參數並存 redirect_url', async () => {
    post.mockResolvedValue(ok({ games: [], redirect_url: 'example.invalid', pagination: { per_page: 30, total: 0, pages: 0, previous: '', current: 1, next: '' } }))
    const games = useGames()
    await games.fetchGameList({ vendorId: 'all', typeId: 10002, page: 1 })
    expect(post).toHaveBeenLastCalledWith('game/list', { page: 1, per_page: 30 })
    await games.fetchGameList({ vendorId: 5, typeId: 2, keyword: 'x', page: 3 })
    expect(post).toHaveBeenLastCalledWith('game/list', { vendor_id: 5, type_ids: 2, game_name: 'x', page: 3, per_page: 30 })
    expect(useGamesStore().redirectUrl).toBe('example.invalid')
  })

  it('fetchGamesByTag：hot / new 各自快取', async () => {
    post.mockResolvedValue(ok([{ ...game({ id: 1 }), rtp: '96.00' }]))
    const games = useGames()
    await games.fetchGamesByTag('hot')
    expect(post).toHaveBeenCalledWith('lobby/games', { tag: 'hot' })
    expect(useGamesStore().gamesByHotTag).toHaveLength(1)
    expect(useGamesStore().gamesByNewTag).toHaveLength(0)
  })

  it('fetchRecentlyVendorIds 只送 session 會員自己的 id', async () => {
    const games = useGames()
    expect((await games.fetchRecentlyVendorIds()).code).toBe(9998)
    expect(post).not.toHaveBeenCalled()
    user.value = { id: 123, username: 'u' }
    post.mockResolvedValue(ok([{ vendor_id: 5 }]))
    await games.fetchRecentlyVendorIds()
    expect(post).toHaveBeenCalledWith('vendor/recently-vendor-ids', { member_id: 123 })
  })

  it('fetchRecentlyGames / fetchPromotionGames 存進 store', async () => {
    post.mockImplementation(async (route: string) => {
      if (route === 'game/recently-game-ids') return ok({ games: [game({ id: 1 })], redirect_url: 'example.invalid' })
      if (route === 'game/promotion-games') return ok({ recent_games: [{ id: 1, vendor_id: 5, type_id: 2 }], recommend_games: [] })
      return fail()
    })
    const games = useGames()
    const store = useGamesStore()
    await games.fetchRecentlyGames()
    expect(post).toHaveBeenCalledWith('game/recently-game-ids', {})
    expect(store.recentGames).toHaveLength(1)
    expect(store.redirectUrl).toBe('example.invalid')
    await games.fetchPromotionGames()
    expect(store.recentPromotionGames).toHaveLength(1)
  })

  it('goToVendor：開了場館前頁時先進 /vendor-amount 並記下場館', async () => {
    const games = useGames()
    const store = useGamesStore()
    await games.goToVendor(vendor({ id: 5 }), 2)
    expect(navigateTo).toHaveBeenLastCalledWith('/game/list/5?typeId=2')
    siteState.flag.mockReturnValue(true)
    await games.goToVendor(vendor({ id: 5 }), 2)
    expect(navigateTo).toHaveBeenLastCalledWith({ path: '/vendor-amount', query: { typeId: '2' } })
    expect(store.selectedVendor?.id).toBe(5)
    // item_type 4（站內連結）一律帶 title，見上方 getVendorPath 的案例
    await games.goToVendor(vendor({ id: 6, item_type: 4, path: '/promotions' }))
    expect(navigateTo).toHaveBeenLastCalledWith('/promotions?title=V')
    siteState.flag.mockReturnValue(false)
  })
})

describe('useGameLauncher', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    post.mockReset()
    navigateTo.mockClear()
  })

  it('fetchGameUrl：exit_url 預設用站台根網址，有 app_infos 就存進 store', async () => {
    const appInfos = { username: 'u', password: 'p', ios_downloads: [], ios_open: null, android_downloads: ['https://a'], android_open: null, app_icon: null }
    post.mockResolvedValue(ok({ pc: null, mobile: null, open_window: 0, app_infos: appInfos }))
    const launcher = useGameLauncher()
    await launcher.fetchGameUrl({ vendorId: 9 })
    expect(post).toHaveBeenCalledWith('game/url', { vendor_id: 9, exit_url: 'https://example.invalid/' })
    expect(useGamesStore().vendorInfo?.username).toBe('u')
    useGamesStore().setGameFromUrl('https://example.invalid/game/list/9')
    await launcher.fetchGameUrl({ gameId: 3 })
    expect(post).toHaveBeenLastCalledWith('game/url', { game_id: 3, exit_url: 'https://example.invalid/game/list/9' })
  })

  it('fetchWidgetUrl 預設 SABA 44，exit_url 用 origin', async () => {
    post.mockResolvedValue(ok({ pc: 'https://w', mobile: 'https://wm', open_window: 0 }))
    await useGameLauncher().fetchWidgetUrl()
    // silent：首頁背景載入的區塊，失敗只是不顯示，不該彈錯誤視窗
    expect(post).toHaveBeenCalledWith('game/widget-url', { vendor_id: 44, exit_url: 'https://example.invalid/' }, { silent: true })
  })

  it('resetVendorPassword：格式不對不打 API；成功後重打 game/url 更新 app_infos', async () => {
    const launcher = useGameLauncher()
    const invalid = await launcher.resetVendorPassword(9, 'ab')
    expect(invalid.code).toBe(422)
    expect(post).not.toHaveBeenCalled()

    post.mockImplementation(async (route: string) => (route === 'vendor/reset-password' ? ok([]) : ok({ pc: null, mobile: null, open_window: 0 })))
    const res = await launcher.resetVendorPassword(9, 'Abc123')
    expect(res.code).toBe(200)
    expect(post).toHaveBeenNthCalledWith(1, 'vendor/reset-password', { vendor_id: 9, new_password: [REDACTED]' })
    expect(post).toHaveBeenNthCalledWith(2, 'game/url', { vendor_id: 9, exit_url: 'https://example.invalid/' })

    post.mockReset()
    post.mockResolvedValue(fail('Password change failed'))
    const failed = await launcher.resetVendorPassword(9, 'Abc123')
    expect(failed.code).toBe(400)
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('fetchVendorEvents 存進 store，activeVendorEvents 只留有遊戲的活動', async () => {
    post.mockResolvedValue(ok([
      { id: 1, title: 'A', detail_id: 11, games: [] },
      { id: 2, title: 'B', detail_id: 12, games: [{ id: 5, vendor_id: 9, type_id: 2, name: 'G' }] },
    ]))
    await useGameLauncher().fetchVendorEvents(9)
    expect(post).toHaveBeenCalledWith('event/vendor-games', { vendor_id: 9 })
    expect(useGamesStore().activeVendorEvents.map(e => e.id)).toEqual([2])
  })

  it('enterGame：真人 → 記下遊戲並導聲明頁 2；被擋的不導頁', async () => {
    const launcher = useGameLauncher()
    const store = useGamesStore()
    store.setVendors([vendor({ id: 5 })])
    const live = game({ id: 8, type_id: 1 })
    expect(await launcher.enterGame(live)).toEqual({ kind: 'statement2' })
    expect(store.selectedGame?.id).toBe(8)
    expect(navigateTo).toHaveBeenCalledWith('/game/statement2')
    navigateTo.mockClear()
    expect(await launcher.enterGame(game({ disable: 1 }))).toEqual({ kind: 'blocked' })
    expect(navigateTo).not.toHaveBeenCalled()
  })

  it('enterGame：iframe 專用場館在 C16 也走 /game/:id；APP 場館走 /game/app', async () => {
    const launcher = useGameLauncher()
    const store = useGamesStore()
    store.setVendors([vendor({ id: 7, need_login: 1 })])
    expect(await launcher.enterGame(game({ id: 3, vendor_id: 50 }))).toEqual({ kind: 'game', path: '/game/3' })
    expect(navigateTo).toHaveBeenLastCalledWith('/game/3')
    expect(await launcher.enterGame(game({ id: 4, vendor_id: 7 }))).toEqual({ kind: 'app', path: '/game/app/7' })
    expect(navigateTo).toHaveBeenLastCalledWith('/game/app/7')
  })

  it('enterGame：場館清單還沒載入（首頁、搜尋）時先補抓，APP 場館的遊戲才會進 /game/app', async () => {
    post.mockResolvedValueOnce(ok([vendor({ id: 7, need_login: 1 })]))
    const launcher = useGameLauncher()
    expect(await launcher.enterGame(game({ id: 4, vendor_id: 7 }))).toEqual({ kind: 'app', path: '/game/app/7' })
    expect(post).toHaveBeenCalledWith('lobby/vendors', {})
    post.mockClear()
    await launcher.enterGame(game({ id: 4, vendor_id: 7 }))
    expect(post).not.toHaveBeenCalledWith('lobby/vendors', {})
  })

  it('fourDMaintenanceTip 讀 template 的維護時段，時段外或非 4D 場館回 null', () => {
    example.invalid.value = { raw: { haolong_maintenance: { start: '06:00', end: '06:30' } } }
    const launcher = useGameLauncher()
    expect(launcher.fourDMaintenanceTip(4, new Date(2026, 0, 1, 6, 10))).toEqual({
      start24: '06:00', start12: '6:00am', end24: '06:30', end12: '6:30am',
    })
    expect(launcher.fourDMaintenanceTip(4, new Date(2026, 0, 1, 12, 0))).toBeNull()
    expect(launcher.fourDMaintenanceTip(5, new Date(2026, 0, 1, 6, 10))).toBeNull()
    example.invalid.value = null
  })
})

describe('遊戲商維護結束時間', () => {
  it('取 MM-DD HH:mm，格式不對寧可不顯示', () => {
    expect(vendorMaintenanceUntil({ maintenance_end_time: '2026-10-06 13:45:00' })).toBe('10-06 13:45')
    expect(vendorMaintenanceUntil({ maintenance_end_time: '13:45' })).toBe('')
    expect(vendorMaintenanceUntil({ maintenance_end_time: null })).toBe('')
    expect(vendorMaintenanceUntil({})).toBe('')
  })
})
