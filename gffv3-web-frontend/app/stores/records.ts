import { defineStore } from 'pinia'
import type { ResponseOf } from '#shared/api/registry'

export type DepositRecord = ResponseOf<'deposit/orders'>[number]
export type WithdrawalRecord = ResponseOf<'withdrawal/orders'>['items'][number]

/** prize/list（events 領域）的中獎紀錄，欄位照後端；該領域登記後改用 ResponseOf。 */
export interface PrizeRecord {
  id: number | string
  event_name?: string | null
  name?: string | null
  point?: string | number | null
  status?: number | string | null
  updated_at?: string | null
  [key: string]: unknown
}

/**
 * 紀錄列表快取：列表頁抓回來、詳細頁用 id 查；詳細頁直接進來時清單是空的就重抓。
 */
export const useRecordsStore = defineStore('records', () => {
  const depositRecords = ref<DepositRecord[]>([])
  const withdrawalRecords = ref<WithdrawalRecord[]>([])
  const prizeRecords = ref<PrizeRecord[]>([])

  function setDepositRecords(list: DepositRecord[]) {
    depositRecords.value = list
  }

  function setWithdrawalRecords(list: WithdrawalRecord[]) {
    withdrawalRecords.value = list
  }

  function setPrizeRecords(list: PrizeRecord[]) {
    prizeRecords.value = list
  }

  function reset() {
    depositRecords.value = []
    withdrawalRecords.value = []
    prizeRecords.value = []
  }

  return {
    depositRecords,
    withdrawalRecords,
    prizeRecords,
    setDepositRecords,
    setWithdrawalRecords,
    setPrizeRecords,
    reset,
  }
})
