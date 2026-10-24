import type {
  EventGame,
  EventVendor,
  GameUrl,
  Season,
  SlotRankingRow,
} from '#shared/api/endpoints/competitions'

/** 排行榜頁輪詢間隔：15 分鐘（後端快取 300 秒） */
export const RANKING_POLL_MS = 900_000
/** 中獎排行固定打即時榜 */
export const WINNING_RANKING_TYPE = 1 as const
/** 平台投注排行固定打前一日榜 */
export const PLATFORM_BETTING_RANKING_TYPE = 2 as const

export const SLOT_COMPETITION_PATH = '/competitions/slot'
export const REFRESH_RANKING_PATH = '/competitions/refresh-ranking'

/** 後端時間字串只取日期部分 */
export function seasonDate(value: string | null | undefined): string {
  return (value ?? '').substring(0, 10)
}

/** 期數下拉文字：`no (start - end)` */
export function seasonLabel(season: Pick<Season, 'no' | 'start_time' | 'end_time'>): string {
  return `${season.no} (${seasonDate(season.start_time)} - ${seasonDate(season.end_time)})`
}

/**
 * 金額顯示：無條件捨去到指定位數、不四捨五入、不加千分位；空值顯示 0.00。
 * 非數字字串原樣回傳。
 */
export function truncateAmount(value: string | number | null | undefined, decimals = 2): string {
  const raw = value == null || value === '' ? '0' : String(value).replace(/,/g, '')
  if (!/^-?\d*(\.\d*)?$/.test(raw)) return raw
  const negative = raw.startsWith('-')
  const [intPartRaw = '', fracRaw = ''] = raw.replace(/^-/, '').split('.')
  const intPart = intPartRaw || '0'
  const body = decimals === 0 ? intPart : `${intPart}.${fracRaw.substring(0, decimals).padEnd(decimals, '0')}`
  return negative && Number(body) !== 0 ? `-${body}` : body
}

export type Podium<T> = [T | null, T | null, T | null]

/** 前三名頒獎台（不足補 null）＋其餘列表 */
export function splitLeaderBoard<T>(items: readonly T[]): { podium: Podium<T>, rest: T[] } {
  return {
    podium: [items[0] ?? null, items[1] ?? null, items[2] ?? null],
    rest: items.slice(3),
  }
}

export type ClaimState = 'claimable' | 'claimed' | 'expired' | 'none'

/** 我的排名列的領獎狀態：type 1 為得獎；status 0 未領、1 已領、2 逾期 */
export function claimState(row: Pick<SlotRankingRow, 'type' | 'status'>): ClaimState {
  if (row.status === 2) return 'expired'
  if (row.type === 1 && row.status === 0) return 'claimable'
  if (row.type === 1 && row.status === 1) return 'claimed'
  return 'none'
}

/** 名次顯示；沒有名次（null / 0）顯示 '-' */
export function rankingText(ranking: number | string | null | undefined): string {
  if (ranking == null || ranking === 0 || ranking === '') return '-'
  return String(ranking)
}

export function isVendorPlayable(vendor: Pick<EventVendor, 'status'>): boolean {
  return vendor.status === 1
}

export function canEnterEventGame(game: Pick<EventGame, 'disable' | 'is_maintenance'>): boolean {
  return game.disable !== 1 && game.is_maintenance !== 1
}

/** 分類頁籤：回傳遊戲中出現過的 type_id（依出現順序去重） */
export function eventGameTypeIds(games: readonly Pick<EventGame, 'type_id'>[]): number[] {
  return [...new Set(games.map(game => game.type_id))]
}

export function filterEventGamesByType<T extends Pick<EventGame, 'type_id'>>(games: readonly T[], typeId: number | null): T[] {
  return typeId == null ? [...games] : games.filter(game => game.type_id === typeId)
}

export function eventGameListPath(vendorId: number | string, vendorName: string): string {
  return `${REFRESH_RANKING_PATH}/game/list/${vendorId}?vendorName=${encodeURIComponent(vendorName)}`
}

export function eventGamePath(gameId: number | string, name: string): string {
  return `${REFRESH_RANKING_PATH}/game/${gameId}?name=${encodeURIComponent(name)}`
}

/** 遊戲商離開遊戲時的回跳網址：回到該活動遊戲商的遊戲列表；沒有遊戲商資訊就回活動主頁 */
export function buildExitUrl(origin: string, vendor: { id: number | string, name: string } | null | undefined): string {
  const base = origin.replace(/\/+$/, '')
  return vendor ? `${base}${eventGameListPath(vendor.id, vendor.name)}` : `${base}${REFRESH_RANKING_PATH}`
}

export function pickEventGameSrc(data: Pick<GameUrl, 'pc' | 'mobile'>, isMobile: boolean): string {
  return (isMobile ? data.mobile : data.pc) ?? ''
}

export function shouldOpenWindow(data: Pick<GameUrl, 'open_window'>): boolean {
  return data.open_window === 1
}
