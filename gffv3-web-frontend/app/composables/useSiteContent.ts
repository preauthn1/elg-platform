import type { ApiEnvelope } from '#shared/types/api'
import type {
  AdvertiseItem,
  AnalyticsResponse,
  BasicInfoItem,
  InformationItem,
  MarqueeLocation,
  MarqueeText,
  WinPointItem,
} from '#shared/api/endpoints/site'
import {
  appendNewWinPoints,
  bannerPathFromVendor,
  basicInfoContentsFromItems,
  isBasicInfoReady,
  marqueeDurationSeconds,
  marqueeFullText,
  mergeBasicInfo,
  mergeBasicInfoTitles,
  resolveBannerTarget,
  visibleBasicInfoItems,
  winPointGamePath,
  type BannerLike,
  type PolicyEntry,
  type VendorBannerTarget,
} from '#shared/utils/siteContent'
import { isApiOk, useApi } from './useApi'
import { useGames } from './useGames'
import { useSite } from './useSite'
import { useBasicInfoStore } from '~/stores/basicInfo'

/**
 * 頁尾基本資訊（關於我們／條款／常見問題／隱私／博彩責任）。
 * 入口只看 template.customized_setting.basic_info 旗標；內容長文另取並依語系快取在 store。
 */
export function useBasicInfo() {
  const api = useApi()
  const { settings } = useSite()
  const store = useBasicInfoStore()
  const { locale } = useI18n()

  const items = computed(() => visibleBasicInfoItems(settings.value?.basic_info))
  const hasBasicInfo = computed(() => items.value.length > 0)
  const loaded = computed(() => store.isFresh(locale.value))

  function fetchBasicInfo(): Promise<ApiEnvelope<{ items: BasicInfoItem[] }>> {
    return api.post('basic-info', {})
  }

  /** 取各項內容；沒任何旗標為 true 時完全不呼叫。請求失敗當空內容（保留寫死文案，不彈錯誤）。 */
  async function load(): Promise<Record<string, string>> {
    if (!hasBasicInfo.value) return {}
    const current = locale.value
    if (store.isFresh(current)) return store.contents ?? {}
    if (store.locale !== current) store.invalidate()

    let pending = store.getPending()
    if (!pending) {
      pending = fetchBasicInfo()
        .then(res => basicInfoContentsFromItems(isApiOk(res) ? res.data.items : []))
        .catch(() => ({}) as Record<string, string>)
        .then((contents) => {
          // 回來時語系已切走就不寫入，讓新語系的請求接手
          if (store.locale === current) store.setContents(current, contents)
          return contents
        })
      store.setPending(current, pending)
    }
    return pending
  }

  function contentOf(key: string): string {
    return store.contentOf(key)
  }

  function isReady(titleKey: string): boolean {
    return isBasicInfoReady(loaded.value, titleKey, items.value)
  }

  function menuTitles(base: readonly string[]): string[] {
    return mergeBasicInfoTitles(base, items.value)
  }

  function mergedPolicies(base: readonly PolicyEntry[]): PolicyEntry[] {
    return mergeBasicInfo(base, items.value, store.contents)
  }

  return { items, hasBasicInfo, loaded, fetchBasicInfo, load, contentOf, isReady, menuTitles, mergedPolicies }
}

/** 大廳「資訊」區塊與 /information 教學分頁。 */
export function useInformation() {
  const api = useApi()
  const { flag } = useSite()

  const informationVisible = computed(() => flag('information_visible'))

  function fetchInformation(): Promise<ApiEnvelope<InformationItem[]>> {
    return api.post('information/list', {})
  }

  async function loadInformation(): Promise<InformationItem[]> {
    const res = await fetchInformation()
    return isApiOk(res) && Array.isArray(res.data) ? res.data : []
  }

  return { informationVisible, fetchInformation, loadInformation }
}

/** 廣告橫幅；每個 path_id 各自一份，呼叫端用不同 key 存，不互相覆蓋。 */
export function useAdvertise() {
  const api = useApi()

  function fetchAdvertise(pathId: number): Promise<ApiEnvelope<AdvertiseItem[]>> {
    return api.post('advertise/list', { path_id: pathId })
  }

  async function loadAdvertise(pathId: number): Promise<AdvertiseItem[]> {
    const res = await fetchAdvertise(pathId)
    return isApiOk(res) && Array.isArray(res.data) ? res.data : []
  }

  return { fetchAdvertise, loadAdvertise }
}

/** 文字跑馬燈：大廳 location=1、聊天室 location=2。 */
export function useMarquee() {
  const api = useApi()

  function fetchMarqueeTexts(location?: MarqueeLocation): Promise<ApiEnvelope<MarqueeText[]>> {
    return api.post('marquee/texts', location ? { location } : {})
  }

  async function loadMarqueeTexts(location?: MarqueeLocation): Promise<MarqueeText[]> {
    const res = await fetchMarqueeTexts(location)
    return isApiOk(res) && Array.isArray(res.data) ? res.data : []
  }

  function durationOf(items: readonly MarqueeText[]): number {
    return marqueeDurationSeconds(items.length)
  }

  function fullTextOf(items: readonly MarqueeText[]): string {
    return marqueeFullText(items)
  }

  return { fetchMarqueeTexts, loadMarqueeTexts, durationOf, fullTextOf }
}

/** 贏分跑馬燈：輪詢時只把新出現的 id 推進輪播。 */
export function useWinPoints(statementVendorIds: readonly number[] = []) {
  const api = useApi()
  const list = ref<WinPointItem[]>([])

  function fetchWinPoints(): Promise<ApiEnvelope<WinPointItem[]>> {
    return api.post('marquee/win-points', {})
  }

  async function refreshWinPoints(): Promise<WinPointItem[]> {
    const res = await fetchWinPoints()
    if (isApiOk(res) && Array.isArray(res.data)) {
      list.value = appendNewWinPoints(list.value, res.data)
    }
    return list.value
  }

  function pathOf(item: WinPointItem): string {
    return winPointGamePath(item, statementVendorIds)
  }

  return { list, fetchWinPoints, refreshWinPoints, pathOf }
}

/** 代理分析代碼；追蹤碼實際以 template.customized_setting.ga_code / matomo_code 為準。 */
export function useAgentAnalytics() {
  const api = useApi()

  function fetchAnalytics(): Promise<ApiEnvelope<AnalyticsResponse>> {
    return api.post('agent/analytics', {})
  }

  async function loadAnalytics(): Promise<AnalyticsResponse | null> {
    const res = await fetchAnalytics()
    return isApiOk(res) ? res.data : null
  }

  return { fetchAnalytics, loadAnalytics }
}

/** information / advertise 點擊導頁（舊 pushBanner）。 */
export function useBannerNavigation() {
  const activeModal = useState<string | null>('active-modal', () => null)
  const { fetchVendor } = useGames()

  /**
   * 型 2（遊戲）／型 3（遊戲商）先查場館：場館是遊戲列表才可直接進 /game/:id，
   * APP／聲明頁場館走場館路徑；場館關閉或查不到不導頁。沒帶 vendor_id 的遊戲直接進 /game/:id。
   */
  async function vendorTargetPath(target: VendorBannerTarget): Promise<string | null> {
    if (target.vendorId == null) return target.kind === 'game' ? `/game/${target.gameId}` : null
    const res = await fetchVendor(target.vendorId)
    return bannerPathFromVendor(target, isApiOk(res) ? res.data : null)
  }

  async function openBanner(item: BannerLike | null | undefined): Promise<void> {
    const target = resolveBannerTarget(item)
    switch (target.kind) {
      case 'internal':
        await navigateTo(target.path)
        return
      case 'game':
      case 'vendor': {
        const path = await vendorTargetPath(target)
        if (path) await navigateTo(path)
        return
      }
      case 'external':
        await navigateTo(target.url, {
          external: true,
          open: target.newWindow ? { target: '_blank' } : undefined,
        })
        return
      case 'modal':
        activeModal.value = target.name
        return
      default:
    }
  }

  function isClickable(item: BannerLike | null | undefined): boolean {
    return resolveBannerTarget(item).kind !== 'none'
  }

  return { activeModal, openBanner, isClickable }
}
