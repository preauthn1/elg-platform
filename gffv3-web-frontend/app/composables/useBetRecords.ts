import type { ResponseOf } from '#shared/api/registry'
import {
  BET_RECORD_PAGE_SIZE,
  fetchBetsForDays,
  postUnregisteredRoute,
  resolveRecordsResult,
} from '~/utils/records'

export type TotalValidBetsResponse = ResponseOf<'member/total-valid-bets'>
export type WinlosesResponse = ResponseOf<'member/winloses'>
export type AccumulatedBets = ResponseOf<'bet-record/accumulated-bets'>

/** member/vendors（wallet 領域）的回應項目，欄位照後端；該領域登記後改用 ResponseOf。 */
export interface MemberVendor {
  vendor_id: number
  vendor_name: string
}

/**
 * 投注紀錄（member/total-valid-bets）、輸贏報表（member/winloses）、累積投注（bet-record/accumulated-bets）。
 */
export function useBetRecords() {
  const api = useApi()

  /** 單日：交給後端分頁。 */
  async function fetchTotalValidBets(input: { searchDay: string, page: number, perPage?: number }) {
    const res = await api.post('member/total-valid-bets', {
      search_day: input.searchDay,
      page: input.page,
      per_page: input.perPage ?? BET_RECORD_PAGE_SIZE,
    })
    return resolveRecordsResult(res)
  }

  /** 全部期間：三天各打一次後合併，前端自己分頁。 */
  function fetchAllPeriodBets(days: readonly string[]) {
    return fetchBetsForDays(body => api.post('member/total-valid-bets', body), days)
  }

  async function fetchWinloses(input: { vendorId: number, searchDay: string, page: number, perPage?: number }) {
    const res = await api.post('member/winloses', {
      vendor_id: input.vendorId,
      search_day: input.searchDay,
      page: input.page,
      per_page: input.perPage ?? BET_RECORD_PAGE_SIZE,
    })
    return resolveRecordsResult(res)
  }

  async function fetchMemberVendors() {
    const res = await postUnregisteredRoute<MemberVendor[]>(api.post, 'member/vendors', {})
    const result = resolveRecordsResult(res)
    if (!result.ok) return result
    return { ok: true as const, data: Array.isArray(result.data) ? result.data : [] }
  }

  async function fetchAccumulatedBets(vendorId: number) {
    const res = await api.post('bet-record/accumulated-bets', { vendor_id: vendorId })
    return resolveRecordsResult(res)
  }

  return {
    fetchTotalValidBets,
    fetchAllPeriodBets,
    fetchWinloses,
    fetchMemberVendors,
    fetchAccumulatedBets,
  }
}
