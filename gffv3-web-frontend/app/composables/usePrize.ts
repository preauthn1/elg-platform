import type { ApiEnvelope, Pagination } from '#shared/types/api'
import type {
  PrizeDraw,
  PrizeDrawAll,
  PrizeRecord,
} from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { useLegacyEvents } from './useLegacyEvents'
import { useEventsStore } from '~/stores/events'
import { prizeDrawKind, PRIZE_TYPE } from '~/utils/events-core'

export interface DrawPrizeInput {
  event_id: number
  /** prize type：1 刮刮卡、2 輪盤、3 直接兌獎 */
  type?: number | null
  action_id?: number | null
  reward_point?: string | number | null
  /** true → 改打 prize/draw-all */
  getAllPrize?: boolean
}

export const PRIZE_RECORDS_PER_PAGE = 20

/**
 * 兌獎相關端點。抽獎結果放 store.lastDraw／store.rewardPoint，由頁面開結果彈窗。
 */
export function usePrize() {
  const api = useApi()
  const store = useEventsStore()
  const legacy = useLegacyEvents()

  /** prize/set：後端相容空殼，不等回應、不處理結果。 */
  function setPrize(prizeMemberId: number | null | undefined) {
    if (prizeMemberId == null) return
    void api.post('prize/set', { prize_member_id: prizeMemberId }, { silent: true }).catch(() => undefined)
  }

  /** prize/draw-all：一次領完，結果為多筆。 */
  async function drawAll(eventId: number): Promise<ApiEnvelope<PrizeDrawAll>> {
    const res = await api.post('prize/draw-all', { event_id: eventId })
    if (isApiOk(res)) {
      store.rewardPoint = null
      store.lastDraw = { kind: 'all', prizes: res.data.prize ?? [] }
    }
    return res
  }

  /**
   * prize/draw：成功先清 reward_point 並存下結果；action 17 且帶 reward_point 時改顯示該金額。
   * type 2 輪盤／3 直接兌獎成功後 fire-and-forget prize/set。
   */
  async function drawPrize(input: DrawPrizeInput): Promise<ApiEnvelope<PrizeDraw | PrizeDrawAll>> {
    if (input.getAllPrize) return drawAll(input.event_id)
    const res = await api.post('prize/draw', { event_id: input.event_id })
    if (isApiOk(res)) {
      store.rewardPoint = null
      const drawKind = prizeDrawKind(input.type)
      store.lastDraw = { kind: 'single', drawKind, data: res.data }
      if (Number(input.action_id) === 17 && input.reward_point != null) store.rewardPoint = input.reward_point
      if (Number(input.type) === PRIZE_TYPE.WHEEL || Number(input.type) === PRIZE_TYPE.DIRECT || input.type == null) {
        setPrize(res.data.prize?.prize_member)
      }
    }
    return res
  }

  /** prize/draw-by-detail（輸分回扣 action 9）：成功不看內容，直接重抓列表。 */
  async function drawByDetail(eventId: number, detailId: number): Promise<ApiEnvelope<unknown>> {
    const res = await api.post('prize/draw-by-detail', { event_id: eventId, detail_id: detailId })
    if (isApiOk(res)) await legacy.fetchLegacyEvents()
    return res
  }

  /** prize/choose（type 8 自選獎項）：成功後重抓列表；成功失敗都由呼叫端顯示 message。 */
  async function choosePrize(eventId: number, prizeContentId: number): Promise<ApiEnvelope<unknown>> {
    const res = await api.post('prize/choose', { event_id: eventId, prize_content_id: prizeContentId })
    if (isApiOk(res)) await legacy.fetchLegacyEvents()
    return res
  }

  /** prize/cancel：放棄未領取的儲值類獎勵。 */
  function cancelPrize(): Promise<ApiEnvelope<unknown>> {
    return api.post('prize/cancel', {})
  }

  /** prize/list：兌獎紀錄（可真分頁）。 */
  function listPrizeRecords(page = 1, perPage = PRIZE_RECORDS_PER_PAGE): Promise<ApiEnvelope<{ items: PrizeRecord[], pagination: Pagination }>> {
    return api.post('prize/list', { page, per_page: perPage })
  }

  /** 兌獎彈窗關閉後重抓列表（詳情頁改重抓詳情）。 */
  function clearDraw() {
    store.lastDraw = null
    store.rewardPoint = null
  }

  return {
    store,
    drawPrize,
    drawAll,
    drawByDetail,
    setPrize,
    choosePrize,
    cancelPrize,
    listPrizeRecords,
    clearDraw,
  }
}
