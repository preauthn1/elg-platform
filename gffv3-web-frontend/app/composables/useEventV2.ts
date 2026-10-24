import type { ApiEnvelope } from '#shared/types/api'
import type { EventV2Detail, EventV2Join, EventV2JoinDetail } from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { useEventsStore } from '~/stores/events'
import { eventV2Progress, eventV2ParticipationMessage } from '~/utils/events-core'

/**
 * 活動 V2：詳情／參加（頁面），參加詳細／領取／放棄（帳戶狀態列與守衛）。
 */
export function useEventV2() {
  const api = useApi()
  const store = useEventsStore()

  const detail = ref<EventV2Detail | null>(null)

  /** event-v2/detail。活動詳情彈窗選了內容語言時照那個語言抓 */
  async function fetchDetail(eventDetailId: number): Promise<ApiEnvelope<EventV2Detail>> {
    const res = await api.post('event-v2/detail', { event_detail_id: eventDetailId }, { locale: store.contentLocale ?? undefined })
    if (isApiOk(res)) detail.value = res.data
    return res
  }

  /** null → 顯示 eligibleToParticipate；否則顯示後端 message。 */
  const participationMessage = computed(() => (detail.value ? eventV2ParticipationMessage(detail.value) : null))
  const hasBonusCodes = computed(() => (detail.value?.bonus_code?.length ?? 0) > 0)

  const joining = ref(false)

  /** event-v2/join：不論成功與否都先重抓詳情；只有 code 200 才開結果彈窗（呼叫端判斷）。 */
  async function join(eventDetailId: number): Promise<ApiEnvelope<EventV2Join> | null> {
    if (joining.value) return null
    joining.value = true
    try {
      const res = await api.post('event-v2/join', { event_detail_id: eventDetailId })
      await fetchDetail(eventDetailId)
      return res
    }
    finally {
      joining.value = false
    }
  }

  /**
   * event-v2/join-detail：非 200 一律當作未參加（null），不跳錯誤。
   *
   * **一定要帶 silent**：後端在「沒參加任何活動」時回的是 501 加一句
   * 「您尚未參加任何活動」，那是空狀態不是錯誤，少了 silent 就會每次進頁面都彈一次窗。
   */
  async function fetchJoinDetail(): Promise<ApiEnvelope<EventV2JoinDetail>> {
    const res = await api.post('event-v2/join-detail', {}, { silent: true })
    store.eventV2JoinDetail = isApiOk(res) ? res.data : null
    return res
  }

  const joinDetail = computed(() => store.eventV2JoinDetail)
  /**
   * 登入且有參加中的活動。要帶 loggedIn：登出時 store 不會清 join-detail，
   * 不帶的話首頁會對訪客顯示上一個帳號的活動進度、把錢包列藏起來。
   */
  const { loggedIn } = useUserSession()
  const joined = computed(() => loggedIn.value && store.eventV2JoinDetail !== null)
  const progress = computed(() => (store.eventV2JoinDetail ? eventV2Progress(store.eventV2JoinDetail) : null))

  /** 路由守衛用：登入且目標為錢包／儲值／提款／客服時，已參加要擋。 */
  async function isJoined(): Promise<boolean> {
    await fetchJoinDetail()
    return joined.value
  }

  /** event-v2/withdraw：不論成功失敗都重抓 join-detail；324 由呼叫端導 /phone-verification。 */
  async function withdraw(): Promise<ApiEnvelope<{ withdraw?: number }>> {
    const res = await api.post('event-v2/withdraw', {})
    await fetchJoinDetail()
    return res
  }

  /** event-v2/quit：成功後呼叫端 1 秒後 reload；這裡重抓 join-detail。 */
  async function quit(): Promise<ApiEnvelope<unknown>> {
    const res = await api.post('event-v2/quit', {})
    await fetchJoinDetail()
    return res
  }

  return {
    store,
    detail,
    fetchDetail,
    participationMessage,
    hasBonusCodes,
    joining,
    join,
    fetchJoinDetail,
    joinDetail,
    joined,
    progress,
    isJoined,
    withdraw,
    quit,
  }
}
