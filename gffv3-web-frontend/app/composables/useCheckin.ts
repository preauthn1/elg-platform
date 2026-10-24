import type { ApiEnvelope } from '#shared/types/api'
import type { CheckinPrize, CheckinRecord } from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { useEventsStore } from '~/stores/events'
import { checkinStatus, isCheckinUnavailable } from '~/utils/events-core'

/**
 * 每日簽到（checkin/records、checkin）。
 */
export function useCheckin() {
  const api = useApi()
  const store = useEventsStore()

  /** checkin/records：400（Activity not found）= 該站沒有簽到活動，隱藏區塊、不跳錯誤。 */
  async function fetchRecords(): Promise<ApiEnvelope<CheckinRecord[]>> {
    const res = await api.post('checkin/records', {})
    if (isApiOk(res)) {
      store.checkinRecords = Array.isArray(res.data) ? res.data : []
      store.checkinAvailable = true
    }
    else if (isCheckinUnavailable(res)) {
      store.checkinRecords = []
      store.checkinAvailable = false
    }
    return res
  }

  const records = computed(() => store.checkinRecords)
  const available = computed(() => store.checkinAvailable)
  const status = computed(() => checkinStatus(store.checkinRecords))

  /** checkin：成功後重抓列表；文案由頁面以 checkinSuccessText(res.data) 組。 */
  async function checkin(): Promise<ApiEnvelope<CheckinPrize[]>> {
    const res = await api.post('checkin', {})
    if (isApiOk(res)) await fetchRecords()
    return res
  }

  return { store, fetchRecords, records, available, status, checkin }
}
