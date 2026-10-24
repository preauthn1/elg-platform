import type { ApiEnvelope } from '#shared/types/api'
import type { EventClaimRecord, EventListItem, EventRemind, PrizeRemind } from '#shared/api/endpoints/events-core'
import { BFF_CODE } from '#shared/constants/codes'
import { isApiOk, useApi } from './useApi'
import { useEventsStore } from '~/stores/events'
import {
  claimRecordMonthOptions,
  eventBanners,
  isOtherEvent,
  isTpEventAllowed,
  resolveEventListPath,
} from '~/utils/events-core'

/**
 * 活動中心（event/list）與首頁相關端點：提醒、紅點、領獎紀錄。
 */
export function useEvents() {
  const api = useApi()
  const store = useEventsStore()

  /** 請求本身失敗（網路／BFF 例外）收斂成 BFF_CODE.UPSTREAM_FAILED 信封。 */
  function failed<T>(error: unknown): ApiEnvelope<T> {
    return { code: BFF_CODE.UPSTREAM_FAILED, message: error instanceof Error ? error.message : String(error), data: null as T }
  }

  /**
   * event/list：同時多個呼叫端共用一個進行中的請求。
   * store.eventList：失敗 → null、成功沒活動 → []。
   */
  function listEvents(): Promise<ApiEnvelope<EventListItem[] | null | undefined>> {
    return store.dedupeEventList(async () => {
      const res = await api.post('event/list', {}).catch(failed<EventListItem[] | null>)
      store.setEventList(isApiOk(res) ? (Array.isArray(res.data) ? res.data : []) : null)
      return res
    })
  }

  const events = computed<EventListItem[]>(() => store.eventList ?? [])
  const otherEvents = computed(() => events.value.filter(isOtherEvent))
  const banners = computed(() => eventBanners(events.value))

  function eventPath(item: EventListItem): string | null {
    return resolveEventListPath(item)
  }

  /**
   * meta.tpEvent 頁面守衛：登入狀態下列表中必須有同 tp_event 的活動，否則導回 '/'（replace）。
   * 列表 null（請求失敗）不阻擋。
   */
  async function ensureTpEventAllowed(tpEvent: number): Promise<boolean> {
    const { loggedIn } = useUserSession()
    if (!loggedIn.value) return true
    await listEvents()
    const allowed = isTpEventAllowed(store.eventList, tpEvent)
    if (!allowed) await navigateTo('/', { replace: true })
    return allowed
  }

  /** event/remind：未領取獎勵數（count > 0 才顯示提醒）。 */
  async function fetchRemind(): Promise<ApiEnvelope<EventRemind>> {
    const res = await api.post('event/remind', {})
    if (isApiOk(res)) store.remind = res.data
    return res
  }

  const hasPendingPrize = computed(() => Number(store.remind?.count ?? 0) > 0)

  /** event/prize-remind：活動／VIP 入口紅點。 */
  async function fetchPrizeRemind(): Promise<ApiEnvelope<PrizeRemind>> {
    const res = await api.post('event/prize-remind', {})
    if (isApiOk(res)) store.prizeRemind = { vip: !!res.data.vip, event: !!res.data.event }
    return res
  }

  /** event/claim-records：省略月份時後端取當月。 */
  function fetchClaimRecords(yearMonth?: string): Promise<ApiEnvelope<EventClaimRecord[]>> {
    return api.post('event/claim-records', yearMonth ? { year_month: yearMonth } : {})
  }

  const claimRecordMonths = computed(() => claimRecordMonthOptions())

  return {
    store,
    listEvents,
    events,
    otherEvents,
    banners,
    eventPath,
    ensureTpEventAllowed,
    fetchRemind,
    hasPendingPrize,
    fetchPrizeRemind,
    fetchClaimRecords,
    claimRecordMonths,
  }
}
