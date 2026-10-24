import type { ApiEnvelope } from '#shared/types/api'
import type {
  LegacyEventDetail,
  LegacyEventList,
  NewInviteEvent,
} from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { useEventsStore } from '~/stores/events'
import {
  filterLegacyEvents,
  hasAgentDepositEvent,
  legacyBannerImage,
  type LegacyEventFilter,
} from '~/utils/events-core'

export const LEGACY_LIST_PAGE = 1
export const LEGACY_LIST_PER_PAGE = 30

/**
 * 舊版（V1）活動列表、詳情、可領獎狀態與邀請好友（action 15）。
 */
export function useLegacyEvents() {
  const api = useApi()
  const store = useEventsStore()

  function applyList(data: LegacyEventList | null | undefined) {
    const items = data?.items ?? []
    store.setLegacyEvents(items, Number(data?.pagination?.total ?? 0), data?.is_reddeem ?? 0)
    // 有下線儲值活動（action 60）就通知各卡片重打 legacy-validate
    if (hasAgentDepositEvent(items)) store.bumpRefreshEventStatus()
  }

  /** 登入走 event/legacy-list，未登入走 event/legacy-list-by-domain；回應處理共用。活動詳情彈窗選了內容語言時照那個語言抓。 */
  async function fetchLegacyEvents(page = LEGACY_LIST_PAGE, perPage = LEGACY_LIST_PER_PAGE): Promise<ApiEnvelope<LegacyEventList>> {
    const { loggedIn } = useUserSession()
    const body = { page, per_page: perPage }
    const res = loggedIn.value
      ? await api.post('event/legacy-list', body, { locale: store.contentLocale ?? undefined })
      : await api.post('event/legacy-list-by-domain', body, { locale: store.contentLocale ?? undefined })
    if (isApiOk(res)) applyList(res.data)
    return res
  }

  const legacyEvents = computed(() => store.legacyEvents)
  const legacyTotal = computed(() => store.legacyTotal)
  const hasRedeemable = computed(() => store.legacyIsReddeem === 1)

  function filtered(filter: LegacyEventFilter) {
    return filterLegacyEvents(store.legacyEvents, filter)
  }

  function bannerImage(ids: number[] | null) {
    return legacyBannerImage(store.legacyEvents, ids)
  }

  /** event/legacy-detail：發請求前先重置，避免殘留上一個活動；活動詳情彈窗選了內容語言時照那個語言抓。 */
  async function fetchLegacyDetail(id: number): Promise<ApiEnvelope<LegacyEventDetail[]>> {
    store.legacyDetail = null
    const res = await api.post('event/legacy-detail', { id }, { locale: store.contentLocale ?? undefined })
    if (isApiOk(res)) store.legacyDetail = res.data?.[0] ?? null
    return res
  }

  const legacyDetail = computed(() => store.legacyDetail)

  /** event/legacy-validate（action 60）：呼叫端用 validateResult(res) 取 isClaim / msg。 */
  function validateEvent(eventId: number): Promise<ApiEnvelope<unknown>> {
    return api.post('event/legacy-validate', { event_id: eventId })
  }

  /** event/new-invite（action 15）。 */
  function fetchNewInvite(eventId: number): Promise<ApiEnvelope<NewInviteEvent>> {
    return api.post('event/new-invite', { event_id: eventId })
  }

  /** event/new-invite/receive：成功後呼叫端以同一 event_id 重抓 fetchNewInvite。 */
  function receiveNewInvite(prizeContentId: number): Promise<ApiEnvelope<unknown>> {
    return api.post('event/new-invite/receive', { prize_content_id: prizeContentId })
  }

  return {
    store,
    fetchLegacyEvents,
    legacyEvents,
    legacyTotal,
    hasRedeemable,
    filtered,
    bannerImage,
    fetchLegacyDetail,
    legacyDetail,
    validateEvent,
    fetchNewInvite,
    receiveNewInvite,
  }
}
