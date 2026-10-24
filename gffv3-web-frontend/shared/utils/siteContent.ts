import { LINK_TYPE, type BasicInfoKey } from '#shared/api/endpoints/site'
import type { LobbyVendor } from '#shared/api/endpoints/games'
import { getVendorPath } from '#shared/utils/games'

/* ---------- basic-info ---------- */

export interface BasicInfoItemDef {
  key: BasicInfoKey
  titleKey: string
}

/** 項目定義；陣列順序即為呈現順序，titleKey 為 i18n 鍵。 */
export const BASIC_INFO_ITEMS: readonly BasicInfoItemDef[] = [
  { key: 'about_us', titleKey: 'site.about.title.aboutUs' },
  { key: 'terms', titleKey: 'site.legal.title.termsConditions' },
  { key: 'faq', titleKey: 'site.support.title.commonProblem' },
  { key: 'privacy', titleKey: 'site.legal.title.privacyPolicy' },
  { key: 'responsible_gaming', titleKey: 'site.legal.title.responsibleGaming' },
]

/** 要呈現的項目：只看 template.customized_setting.basic_info[key] === true，不看內容。 */
export function visibleBasicInfoItems(flags: Record<string, unknown> | null | undefined): BasicInfoItemDef[] {
  if (!flags) return []
  return BASIC_INFO_ITEMS.filter(item => flags[item.key] === true)
}

export function basicInfoContentsFromItems(
  items: ReadonlyArray<{ key: string, content: string }> | null | undefined,
): Record<string, string> {
  const out: Record<string, string> = {}
  for (const item of items ?? []) out[item.key] = item.content ?? ''
  return out
}

export interface PolicyEntry {
  titleKey: string
  content: string
}

/**
 * 把後台內容併進版型寫死的政策清單：同 titleKey 的沿用原位置換內容（空字串保留寫死文案），
 * 清單沒有的依旗標補在最後。補項目只依旗標不依內容，索引才與標題清單一致。
 */
export function mergeBasicInfo(
  list: readonly PolicyEntry[],
  items: readonly BasicInfoItemDef[],
  contents: Record<string, string> | null | undefined,
): PolicyEntry[] {
  const merged = list.map(entry => ({ ...entry }))
  for (const item of items) {
    const content = contents?.[item.key] ?? ''
    const existing = merged.find(entry => entry.titleKey === item.titleKey)
    if (existing) {
      if (content) existing.content = content
    }
    else {
      merged.push({ titleKey: item.titleKey, content })
    }
  }
  return merged
}

/** 同上，但用於只有標題鍵的清單（About 選單）。 */
export function mergeBasicInfoTitles(titleKeys: readonly string[], items: readonly BasicInfoItemDef[]): string[] {
  const out = [...titleKeys]
  for (const item of items) {
    if (!out.includes(item.titleKey)) out.push(item.titleKey)
  }
  return out
}

/** 內容確定前不渲染會被後台覆蓋的項目；後台不供稿的項目一開始即可渲染。 */
export function isBasicInfoReady(loaded: boolean, titleKey: string, items: readonly BasicInfoItemDef[]): boolean {
  if (loaded) return true
  return !items.some(item => item.titleKey === titleKey)
}

export function replacePolicyPlaceholders(text: string, platformName: string, email = 'email'): string {
  return text.replaceAll('{name}', platformName).replaceAll('{email}', email)
}

/* ---------- information / advertise 導頁 ---------- */

export interface BannerLike {
  type: number
  game_type_id?: number | null
  game_id?: number | null
  vendor_id?: number | null
  url?: string | null
  open_window?: number
}

export type BannerTarget = { kind: 'none' }
  | { kind: 'internal', path: string }
  | { kind: 'external', url: string, newWindow: boolean }
  | { kind: 'game', gameId: number, vendorId: number | null }
  | { kind: 'vendor', vendorId: number }
  | { kind: 'modal', name: string }

export function modalNameFromUrl(url: string): string | null {
  const idx = url.indexOf('?')
  if (idx < 0) return null
  return new URLSearchParams(url.slice(idx + 1)).get('modal') || null
}

/** 依 type 決定點擊去向（information 與 advertise 共用）；type 0 或缺必要欄位不可點。 */
export function resolveBannerTarget(item: BannerLike | null | undefined): BannerTarget {
  if (!item) return { kind: 'none' }
  switch (item.type) {
    case LINK_TYPE.GAME_TYPE:
      return item.game_type_id != null
        ? { kind: 'internal', path: `/vendors?typeId=${item.game_type_id}` }
        : { kind: 'none' }
    case LINK_TYPE.GAME:
      return item.game_id != null
        ? { kind: 'game', gameId: item.game_id, vendorId: item.vendor_id ?? null }
        : { kind: 'none' }
    case LINK_TYPE.VENDOR:
      return item.vendor_id != null ? { kind: 'vendor', vendorId: item.vendor_id } : { kind: 'none' }
    case LINK_TYPE.EXTERNAL_URL:
      return item.url ? { kind: 'external', url: item.url, newWindow: item.open_window === 1 } : { kind: 'none' }
    case LINK_TYPE.INTERNAL_URL: {
      if (!item.url) return { kind: 'none' }
      const modal = modalNameFromUrl(item.url)
      if (modal) return { kind: 'modal', name: modal }
      return { kind: 'internal', path: `/${item.url.replace(/^\/+/, '')}` }
    }
    default:
      return { kind: 'none' }
  }
}

export type VendorBannerTarget = Extract<BannerTarget, { kind: 'game' | 'vendor' }>

/**
 * 型 2／3 查到場館後的去向：場館路徑是遊戲列表才可直接進 /game/:id，
 * 否則（APP 場館、聲明頁、站內連結）走場館路徑；場館關閉或查不到回 null 不導頁。
 */
export function bannerPathFromVendor(
  target: VendorBannerTarget,
  vendor: LobbyVendor | null | undefined,
): string | null {
  if (!vendor) return null
  const vendorPath = getVendorPath(vendor)
  if (!vendorPath) return null
  if (target.kind === 'game' && vendorPath.includes('/game/list')) return `/game/${target.gameId}`
  return vendorPath
}

/* ---------- marquee ---------- */

export const MARQUEE_REPEAT = 10
export const MARQUEE_SECONDS_PER_ITEM = 10

export function marqueeDurationSeconds(count: number): number {
  return Math.max(0, count) * MARQUEE_SECONDS_PER_ITEM
}

export function marqueeFullText(items: ReadonlyArray<{ content: string }>): string {
  return items.map(item => `※${item.content}`).join('\n')
}

/** 輪詢贏分跑馬燈時只把新出現的 id 推進輪播。 */
export function appendNewWinPoints<T extends { id: number }>(current: readonly T[], incoming: readonly T[]): T[] {
  const seen = new Set(current.map(item => item.id))
  const fresh = incoming.filter(item => !seen.has(item.id))
  return [...current, ...fresh]
}

export function winPointGamePath(
  item: { game_id: number, vendor_id: number },
  statementVendorIds: readonly number[] = [],
): string {
  return statementVendorIds.includes(item.vendor_id)
    ? `/game/statement/${item.vendor_id}/${item.game_id}`
    : `/game/${item.game_id}`
}

/* ---------- versions ---------- */

export type MobilePlatform = 'ios' | 'android'

export function isIOSUserAgent(userAgent: string | null | undefined): boolean {
  return /iPad|iPhone|iPod/i.test(userAgent ?? '')
}

export function platformFromUserAgent(userAgent: string | null | undefined): MobilePlatform {
  return isIOSUserAgent(userAgent) ? 'ios' : 'android'
}

interface VersionsLike {
  ios?: { downloadLink?: string | null } | null
  android?: { downloadLink?: string | null } | null
}

export function pickDownloadLink(data: VersionsLike | null | undefined, platform: MobilePlatform): string | null {
  if (!data) return null
  return data[platform]?.downloadLink || null
}

/** 是否顯示「下載 APP」：iOS 看 has_ipa、其餘看 has_apk；不看 versions 端點。 */
export function canShowAppDownload(
  raw: { has_ipa?: number, has_apk?: number } | null | undefined,
  platform: MobilePlatform,
): boolean {
  if (!raw) return false
  return platform === 'ios' ? raw.has_ipa === 1 : raw.has_apk === 1
}

/* ---------- country ---------- */

export const DEFAULT_PHONE_RULE = '^[1-9]\\d{8,9}$'

export function phoneRuleRegExp(rule: string | null | undefined): RegExp {
  const source = rule && rule.trim() ? rule.trim() : DEFAULT_PHONE_RULE
  try {
    return new RegExp(source)
  }
  catch {
    return new RegExp(DEFAULT_PHONE_RULE)
  }
}

export function findCountryByCode<T extends { country_code: string }>(
  countries: readonly T[],
  countryCode: string | null | undefined,
): T | undefined {
  if (!countryCode) return undefined
  return countries.find(country => country.country_code === String(countryCode))
}

/** 未指定國家時以 phone.startsWith(country_code) 反推，多筆命中取國碼最長者。 */
export function findCountryByPhone<T extends { country_code: string }>(
  countries: readonly T[],
  phone: string | null | undefined,
): T | undefined {
  if (!phone) return undefined
  return countries
    .filter(country => country.country_code && phone.startsWith(country.country_code))
    .sort((a, b) => b.country_code.length - a.country_code.length)[0]
}

/* ---------- currency ---------- */

/** 銀行帳號清單的 currency_id 去重（排除 0 與非數字）。 */
export function uniqueCurrencyIds(accounts: ReadonlyArray<{ currency_id?: number | string | null }>): number[] {
  const out: number[] = []
  for (const account of accounts) {
    const id = Number(account.currency_id ?? 0)
    if (id !== 0 && Number.isFinite(id) && !out.includes(id)) out.push(id)
  }
  return out
}

export function currencyName(
  currencies: ReadonlyArray<{ id: number, name: string }>,
  id: number | string | null | undefined,
): string {
  if (id == null || id === '') return '-'
  return currencies.find(currency => currency.id === Number(id))?.name ?? '-'
}
