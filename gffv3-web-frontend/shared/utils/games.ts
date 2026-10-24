import type {
  GameAppInfos,
  GameListItem,
  GameUrl,
  LobbyVendor,
  VendorGameType,
} from '#shared/api/endpoints/games'
import { isOn } from '#shared/utils/site'

/** 場館狀態（lobby/vendors.status、game/show.status）。 */
export const VENDOR_STATUS = {
  OPEN: 1,
  COMING_SOON: 2,
  MAINTENANCE: 3,
  FORBIDDEN: 4,
  EVENT_V2_FORBIDDEN: 5,
} as const

/** lobby/vendors.item_type。 */
export const VENDOR_ITEM_TYPE = {
  VENDOR: 1,
  GAME: 2,
  TYPE: 3,
  LINK: 4,
} as const

/** 前端自訂的假類型 id（不是 game_types）。 */
export const PSEUDO_TYPE = {
  GIFTCODE: 10002,
  INFORMATION: 10003,
  HOT: 10004,
  RECENTLY_PLAYED: 10007,
} as const

export const VENDOR_ID = {
  VPOWER: 22,
  NPT: 41,
  SABA: 44,
  PGSSW: 50,
  /** 小艾：聲明頁同意後以 game/list 取第一款遊戲 */
  FIRST_GAME_FROM_LIST: 21,
} as const

export const GAME_TYPE_ID = {
  LIVE: 1,
  SPORTS: 18,
} as const

/** 遊戲商頁頂部**不顯示**遊戲商餘額的場館（舊版 C13 Navbar.showBalance 的 `vendorId != 4`）。 */
const NO_HEADER_BALANCE_VENDOR_IDS: readonly number[] = [4]

/** 遊戲列表／APP 場館／聲明頁的頂部要不要顯示遊戲商餘額。 */
export function showsVendorBalanceInHeader(vendorId: number | null): boolean {
  return vendorId === null || !NO_HEADER_BALANCE_VENDOR_IDS.includes(vendorId)
}

/** 進場前要先到 /game/statement/:vendorId/:gameId 的場館。 */
export const STATEMENT_VENDOR_IDS: readonly number[] = [0, 4, 18, 31, 40, 38, 21, 102, 87, 43, 181]
/** 進場前要先到 /game/statement2 的場館（真人類型 1 也走這裡）。 */
export const LIVE_STATEMENT_VENDOR_IDS: readonly number[] = [232]
/** 這些場館不另開視窗，一律 iframe 內嵌。 */
export const IFRAME_ONLY_VENDOR_IDS: readonly number[] = [77, 85, 88, 59, 25, 1, 102, 50, 61, 70, 150, 169, 172]
/** 4D 場館：聲明頁免同意、顯示 4D 提示。 */
export const FOUR_D_VENDOR_IDS: readonly number[] = [4, 104]
/** 禮碼類型下 PGSSW 要排除的遊戲。 */
export const PGSSW_HIDDEN_GAME_ID = 6872
/** 每日 4D 維護時段設定在 template 回應中的鍵名。 */
export const FOUR_D_MAINTENANCE_FIELD: Record<number, 'haolong_maintenance' | 'new4d2026_maintenance'> = {
  4: 'haolong_maintenance',
  181: 'new4d2026_maintenance',
}

/** 根類型 → i18n key（沿用舊前台 enums.gameTypeList）；不在表裡的類型不顯示。 */
export const GAME_TYPE_I18N_KEY: Record<number, string> = {
  1: 'games.enum.category.reality',
  2: 'games.enum.category.slot',
  3: 'games.enum.category.poker',
  4: 'games.enum.category.lottery',
  5: 'games.enum.category.fishing',
  6: 'games.enum.category.cockfight',
  8: 'games.enum.category.freeplay',
  9: 'games.enum.category.texasholdem',
  15: 'games.enum.category.arcade',
  16: 'games.enum.category.horseracing',
  18: 'games.enum.category.sports',
  19: 'games.enum.category.esports',
  20: 'games.enum.category.gd',
  21: 'games.enum.category.barmachine',
  99: 'games.enum.category.multiplayer',
  100: 'games.enum.category.table',
  101: 'games.enum.category.barmachine',
}

/** 類型 tab 的顯示順序（舊前台 TypeFilter）。 */
export const TYPE_TAB_ORDER: readonly number[] = [2, 1, 9, 18, 3, 4, 5, 6, 19, 15, 21, 16, 20, 8, 99, 100, 101]

export type VendorTag = 'all' | 'hot' | 'new'

export type GameLike = Pick<GameListItem, 'id' | 'vendor_id' | 'type_id'> & Partial<GameListItem>

export function normalizeVendorTag(input: unknown): VendorTag {
  return input === 'hot' || input === 'new' ? input : 'all'
}

export function parseTypeId(input: unknown): number | null {
  if (input === null || input === undefined || input === '') return null
  const n = Number(Array.isArray(input) ? input[0] : input)
  return Number.isFinite(n) && n > 0 ? n : null
}

export function gameTypeLabelKey(typeId: number | null): string {
  if (typeId === null) return 'games.category.option.allGames'
  if (typeId === PSEUDO_TYPE.GIFTCODE) return 'games.vendor.label.rollingGames'
  if (typeId === PSEUDO_TYPE.HOT) return 'games.lobby.nav.popular'
  if (typeId === PSEUDO_TYPE.INFORMATION) return 'site.nav.nav.information'
  if (typeId === PSEUDO_TYPE.RECENTLY_PLAYED) return 'games.enum.category.recentlyPlayed'
  return GAME_TYPE_I18N_KEY[typeId] ?? String(typeId)
}

/** /vendors 頁標題：typeId 優先，其次 tag。 */
export function vendorsPageTitleKey(tag: VendorTag, typeId: number | null): string {
  if (typeId !== null) return gameTypeLabelKey(typeId)
  if (tag === 'hot') return 'games.lobby.title.hotGame'
  if (tag === 'new') return 'games.category.title.latestGame'
  return 'games.category.option.allGames'
}

export function vendorStatusI18nKey(status: number): string | null {
  if (status === VENDOR_STATUS.COMING_SOON) return 'games.vendor.label.comingSoon'
  if (status === VENDOR_STATUS.MAINTENANCE) return 'common.ui.label.maintenance'
  return null
}

export function isVendorOpen(vendor: Pick<LobbyVendor, 'status'>): boolean {
  return vendor.status === VENDOR_STATUS.OPEN
}

/**
 * 遊戲商維護結束時間取「MM-DD HH:mm」。沿用舊專案的字串切法，不自己解析時間——
 * 後端格式變了寧可少顯示，也不要顯示錯的時間。
 */
export function vendorMaintenanceUntil(vendor: { maintenance_end_time?: string | null }): string {
  const t = vendor.maintenance_end_time
  return t && t.length >= 16 ? t.slice(5, 16) : ''
}

export function vendorsById(vendors: LobbyVendor[]): Record<number, LobbyVendor> {
  const map: Record<number, LobbyVendor> = {}
  for (const vendor of vendors) {
    if (!(vendor.id in map)) map[vendor.id] = vendor
  }
  return map
}

export function filterVendorsByTag(vendors: LobbyVendor[], tag: VendorTag): LobbyVendor[] {
  if (tag === 'hot') return vendors.filter(v => isOn(v.is_hot))
  if (tag === 'new') return vendors.filter(v => isOn(v.is_new))
  return vendors
}

/** 該根類型下有遊戲的場館 id（vendor/game-types）。 */
export function vendorIdsForRootType(gameTypes: VendorGameType[], rootType: number): number[] {
  return [...new Set(gameTypes.filter(t => t.root_type === rootType).map(t => t.vendor_id))]
}

/** 類型 tab 清單：root_type 去重、可再以 vendorId 過濾，只留有 i18n 對照的類型並照固定順序排。 */
export function rootTypeIds(gameTypes: VendorGameType[], vendorId?: number | null): number[] {
  const present = new Set(
    gameTypes
      .filter(t => vendorId === null || vendorId === undefined || t.vendor_id === vendorId)
      .map(t => t.root_type),
  )
  return TYPE_TAB_ORDER.filter(id => present.has(id))
}

/**
 * 依類型篩場館。
 * - 10002 禮碼：giftcode == 1；10004 熱門：is_hot == 1
 * - 其他類型：場館要出現在該 root_type 下；item_type 2/3 且 type_id 不符則排除；
 *   同 id 已有 item_type 2/3 的指定類型筆時，排除該場館的 item_type 1 筆。
 */
export function filterVendorsByType(
  vendors: LobbyVendor[],
  typeId: number | null,
  gameTypes: VendorGameType[],
): LobbyVendor[] {
  if (vendors.length === 0) return []
  if (typeId === null) return vendors
  if (typeId === PSEUDO_TYPE.GIFTCODE) return vendors.filter(v => v.giftcode === 1)
  if (typeId === PSEUDO_TYPE.HOT) return vendors.filter(v => isOn(v.is_hot))

  const allowed = new Set(vendorIdsForRootType(gameTypes, typeId))
  const isSpecified = (v: LobbyVendor) =>
    (v.item_type === VENDOR_ITEM_TYPE.GAME || v.item_type === VENDOR_ITEM_TYPE.TYPE) && v.type_id === typeId

  return vendors.filter((item) => {
    if (!allowed.has(item.id)) return false
    if ((item.item_type === VENDOR_ITEM_TYPE.GAME || item.item_type === VENDOR_ITEM_TYPE.TYPE) && item.type_id !== typeId) {
      return false
    }
    if (item.item_type === VENDOR_ITEM_TYPE.VENDOR && vendors.some(v => v.id === item.id && isSpecified(v))) {
      return false
    }
    return true
  })
}

function withQuery(path: string, query: Record<string, string | number | null | undefined>): string {
  const params = Object.entries(query)
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => `${k}=${encodeURIComponent(String(v))}`)
  return params.length ? `${path}?${params.join('&')}` : path
}

/**
 * 點場館的導頁路徑（舊前台 $getVendorPath 的 C13/C16 分支）。
 * status != 1 不可點回 null。
 */
export function getVendorPath(vendor: LobbyVendor, typeId: number | null = null): string | null {
  if (!isVendorOpen(vendor)) return null

  if (vendor.id === VENDOR_ID.VPOWER) {
    return withQuery(`/game/list/${vendor.id}`, { typeId })
  }
  if (isOn(vendor.need_login)) {
    return withQuery(`/game/app/${vendor.id}`, { itemType: vendor.item_type })
  }
  if (isOn(vendor.only_one_game)) {
    return withQuery(`/game/statement/${vendor.id}`, { itemType: vendor.item_type })
  }
  switch (vendor.item_type) {
    case VENDOR_ITEM_TYPE.VENDOR:
      return withQuery(`/game/list/${vendor.id}`, { typeId })
    case VENDOR_ITEM_TYPE.GAME:
      return vendor.game_id ? `/game/${vendor.game_id}` : null
    case VENDOR_ITEM_TYPE.TYPE:
      return withQuery(`/game/list/${vendor.id}`, { typeId: vendor.type_id })
    case VENDOR_ITEM_TYPE.LINK:
      if (!vendor.path) return null
      return withQuery(vendor.path, { title: vendor.name })
    default:
      return null
  }
}

/** 點下去會進 APP 場館頁（舊前台 VendorItem.isApp，卡片顯示 APP 角標）。 */
export function isAppVendor(vendor: LobbyVendor): boolean {
  return getVendorPath(vendor)?.startsWith('/game/app/') ?? false
}

export interface GameListQuery {
  /** 'all' 或省略 = 全部場館 */
  vendorId?: number | 'all' | null
  /** 10002 禮碼不送給後端 */
  typeId?: number | null
  keyword?: string
  page: number
  perPage?: number
}

export interface GameListRequest {
  vendor_id?: number
  type_ids?: number
  game_name?: string
  page: number
  per_page: number
}

export const GAME_LIST_PER_PAGE = 30

/** game/list 參數組法：全部場館不送 vendor_id、禮碼類型不送 type_ids、空關鍵字不送 game_name。 */
export function buildGameListRequest(query: GameListQuery): GameListRequest {
  const body: GameListRequest = {
    page: query.page,
    per_page: query.perPage ?? GAME_LIST_PER_PAGE,
  }
  if (typeof query.vendorId === 'number') body.vendor_id = query.vendorId
  if (typeof query.typeId === 'number' && query.typeId !== PSEUDO_TYPE.GIFTCODE) body.type_ids = query.typeId
  const keyword = query.keyword?.trim()
  if (keyword) body.game_name = keyword
  return body
}

export function uniqueTypeIds<T extends { type_id: number }>(games: T[]): number[] {
  return [...new Set(games.map(g => g.type_id))]
}

/** 前端類型篩選（tag 頁與列表頁共用）：禮碼類型時 PGSSW 排除指定遊戲。 */
export function filterGamesByType<T extends { id: number, type_id: number }>(
  games: T[],
  typeId: number | null,
  vendorId?: number | null,
): T[] {
  if (typeId === null) return games
  if (typeId === PSEUDO_TYPE.GIFTCODE) {
    return vendorId === VENDOR_ID.PGSSW ? games.filter(g => g.id !== PGSSW_HIDDEN_GAME_ID) : games
  }
  return games.filter(g => g.type_id === typeId)
}

export function groupGamesByType<T extends { type_id: number }>(games: T[]): Map<number, T[]> {
  const groups = new Map<number, T[]>()
  for (const game of games) {
    const list = groups.get(game.type_id) ?? []
    list.push(game)
    groups.set(game.type_id, list)
  }
  return groups
}

export function isGamePlayable(game: Pick<GameLike, 'disable' | 'is_maintenance'>): boolean {
  return game.disable !== 1 && game.is_maintenance !== 1
}

export function isRtpVisible(rtp: string | null | undefined): boolean {
  return !!rtp && rtp !== '0.00'
}

export interface Platform {
  isIOS: boolean
  isAndroid: boolean
}

export function detectPlatform(userAgent: string | null | undefined): Platform {
  const ua = (userAgent ?? '').toLowerCase()
  return {
    isIOS: /iphone|ipad|ipod/.test(ua),
    isAndroid: ua.includes('android'),
  }
}

export interface GameEntryContext extends Platform {
  /** iOS 加到主畫面的獨立視窗模式，不另開視窗 */
  isStandalone: boolean
  vendors: Record<number, LobbyVendor>
  /** 版型允許另開視窗進遊戲（舊前台 C13/C16） */
  popupGames: boolean
  /** 站台要在 Android 進 VPower 前顯示說明 */
  vpowerAndroidNotice: boolean
  /** NPT 中轉域名（game/list 的 redirect_url） */
  redirectUrl: string
  origin: string
}

export type GameEntry
  = | { kind: 'blocked' }
    | { kind: 'vpower-notice' }
    | { kind: 'npt', gameId: number, host: string }
    | { kind: 'statement', path: string }
    | { kind: 'statement2' }
    | { kind: 'app', path: string }
    | { kind: 'popup', url: string }
    | { kind: 'game', path: string }

/** 舊前台 $pushToGame：APP 場館 → /game/app；可另開則另開；其餘 /game/:id。 */
export function resolvePushToGame(game: GameLike, ctx: GameEntryContext): GameEntry {
  const vendor = ctx.vendors[game.vendor_id]
  if (isOn(vendor?.need_login) && game.vendor_id !== VENDOR_ID.VPOWER) {
    return { kind: 'app', path: `/game/app/${game.vendor_id}` }
  }
  if (ctx.popupGames && !IFRAME_ONLY_VENDOR_IDS.includes(game.vendor_id) && !(ctx.isIOS && ctx.isStandalone)) {
    return { kind: 'popup', url: `${ctx.origin}/game/${game.id}` }
  }
  return { kind: 'game', path: `/game/${game.id}` }
}

/** 舊前台 $handleGameEntry 的進場分流。 */
export function resolveGameEntry(
  game: GameLike,
  ctx: GameEntryContext,
  opts: { toStatementPage?: boolean } = {},
): GameEntry {
  if (!isGamePlayable(game)) return { kind: 'blocked' }

  if (game.vendor_id === VENDOR_ID.VPOWER && ctx.isAndroid && ctx.vpowerAndroidNotice) {
    return { kind: 'vpower-notice' }
  }
  if (game.vendor_id === VENDOR_ID.NPT && game.type_id !== GAME_TYPE_ID.LIVE) {
    return { kind: 'npt', gameId: game.id, host: ctx.redirectUrl || new URL(ctx.origin).host }
  }
  if (opts.toStatementPage || game.type_id === GAME_TYPE_ID.SPORTS || STATEMENT_VENDOR_IDS.includes(game.vendor_id)) {
    return { kind: 'statement', path: `/game/statement/${game.vendor_id}/${game.id}` }
  }
  if (game.type_id === GAME_TYPE_ID.LIVE || LIVE_STATEMENT_VENDOR_IDS.includes(game.vendor_id)) {
    return { kind: 'statement2' }
  }
  return resolvePushToGame(game, ctx)
}

/** 選 URL：行動裝置用 mobile，其餘 pc；缺的一邊互相補。 */
export function pickGameUrl(data: Pick<GameUrl, 'pc' | 'mobile'>, isMobile: boolean): string | null {
  const primary = isMobile ? data.mobile : data.pc
  const fallback = isMobile ? data.pc : data.mobile
  return primary || fallback || null
}

export interface ResolvedAppInfo {
  openLink: string | null
  downloads: string[]
  hasApp: boolean
}

export function resolveAppInfo(info: GameAppInfos, isIOS: boolean): ResolvedAppInfo {
  const openLink = (isIOS ? info.ios_open : info.android_open) || null
  const downloads = isIOS ? info.ios_downloads : info.android_downloads
  return { openLink, downloads, hasApp: !!openLink }
}

/** vendor/reset-password 的 new_password 規則：英數 6~14 碼。 */
export function isVendorPasswordValid(password: [REDACTED] boolean {
  return /^[A-Za-z0-9]{6,14}$/.test(password)
}

export interface DailyWindow {
  start: string
  end: string
}

/** template 回應的 haolong_maintenance / new4d2026_maintenance 可能是物件或 JSON 字串。 */
export function parseDailyWindow(raw: unknown): DailyWindow | null {
  let value: unknown = raw
  if (typeof value === 'string') {
    try {
      value = JSON.parse(value)
    }
    catch {
      return null
    }
  }
  if (!value || typeof value !== 'object') return null
  const { start, end } = value as Record<string, unknown>
  if (typeof start !== 'string' || typeof end !== 'string' || !start || !end) return null
  return { start, end }
}

function toMinutes(time: string): number {
  const [h = '0', m = '0'] = time.split(':')
  return Number(h) * 60 + Number(m)
}

/** 每日時段判斷，支援跨午夜（23:00 - 01:00）。 */
export function isInDailyWindow(window: DailyWindow, now: Date): boolean {
  const nowMin = now.getHours() * 60 + now.getMinutes()
  const startMin = toMinutes(window.start)
  const endMin = toMinutes(window.end)
  return startMin <= endMin
    ? nowMin >= startMin && nowMin <= endMin
    : nowMin >= startMin || nowMin <= endMin
}

export function to12Hour(time: string): string {
  const [h = 0, m = 0] = time.split(':').map(Number)
  const hour = h % 12 || 12
  return `${hour}:${String(m).padStart(2, '0')}${h >= 12 ? 'pm' : 'am'}`
}
