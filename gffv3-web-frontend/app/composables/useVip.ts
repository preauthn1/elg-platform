import type { ApiEnvelope } from '#shared/types/api'
import type { VipContent, VipInfo, VipInfoReward } from '#shared/api/endpoints/vip'
import { BFF_CODE } from '#shared/constants/codes'
import { canClaimReward, isDailyRebateReward, sortVipContents } from '#shared/utils/vip'
import { isApiOk, useApi } from './useApi'
import { useVipStore } from '~/stores/vip'

/** 舊 store 的 vipInfo 形狀：info 併上 contents 當 vip_content。 */
export type VipInfoWithContents = VipInfo & { vip_content: VipContent[] }

export interface VipFlowLimitQuery {
  page?: number
  per_page?: number
}

export function useVip() {
  const api = useApi()
  const store = useVipStore()
  const loading = ref(false)
  const updating = ref(false)
  /** 正在領取的 reward.id，沒有則 null。 */
  const claimingId = ref<number | null>(null)

  /**
   * 同時打 vip/info 與 vip/contents；任一非 200 即整體失敗（回傳失敗的那支信封、store 不動）。
   * 成功時 contents 依 level 升冪後寫進 store，並依舊版形狀併成 vip_content 回傳。
   */
  async function fetchInfo(): Promise<ApiEnvelope<VipInfoWithContents | null>> {
    loading.value = true
    try {
      const [infoRes, contentsRes] = await Promise.all([
        api.post('vip/info', {}),
        api.post('vip/contents', {}),
      ])
      if (!isApiOk(infoRes)) return { ...infoRes, data: null }
      if (!isApiOk(contentsRes)) return { ...contentsRes, data: null }
      const contents = sortVipContents(contentsRes.data)
      store.setInfo(infoRes.data, contents)
      return { ...infoRes, data: { ...infoRes.data, vip_content: contents } }
    }
    finally {
      loading.value = false
    }
  }

  /** 返水頁進入時：已有 currentVip 就不重抓。 */
  async function ensureInfo(): Promise<ApiEnvelope<VipInfoWithContents | null> | null> {
    if (store.currentVip) return null
    return fetchInfo()
  }

  /** 手動更新；更新中再按不重複送。 */
  async function refresh(): Promise<ApiEnvelope<VipInfoWithContents | null> | null> {
    if (updating.value) return null
    updating.value = true
    try {
      return await fetchInfo()
    }
    finally {
      updating.value = false
    }
  }

  /** 領獎紀錄：失敗保留上一版列表。 */
  async function fetchRewardHistory() {
    const res = await api.post('vip/reward-history', {})
    if (isApiOk(res)) store.setRewardHistory(res.data)
    return res
  }

  /** 稽核流水列表：page / per_page 必填（預設 1 / 50），重抓前先清空既有列表。 */
  async function fetchFlowLimit(query: VipFlowLimitQuery = {}) {
    const body = { page: query.page ?? 1, per_page: query.per_page ?? 50 }
    store.clearFlowLimitItems()
    const res = await api.post('vip/flow-limit', body)
    if (isApiOk(res)) store.setFlowLimit({ items: res.data.items, pagination: res.data.pagination })
    return res
  }

  /** 領取單筆獎勵（id = reward.reward_id），成功後重抓 vip/info。 */
  async function receive(id: number) {
    const res = await api.post('vip/receive', { id })
    if (isApiOk(res)) await fetchInfo()
    return res
  }

  /** 領取所有待領每日返水，成功後重抓 vip/info。 */
  async function receiveRebate() {
    const res = await api.post('vip/receive-rebate', {})
    if (isApiOk(res)) await fetchInfo()
    return res
  }

  /**
   * 特權領取分流：只有 status 0 可領；reward.id 7（每日返水）走 receive-rebate，
   * 其他帶 reward_id 走 receive。失敗信封原樣回傳，呼叫端只顯示 message。
   */
  async function claim(reward: VipInfoReward): Promise<ApiEnvelope<unknown>> {
    if (!canClaimReward(reward)) {
      return { code: BFF_CODE.INVALID_REQUEST, message: 'reward not claimable', data: null }
    }
    claimingId.value = Number(reward.id)
    try {
      return isDailyRebateReward(reward)
        ? await receiveRebate()
        : await receive(Number(reward.reward_id))
    }
    finally {
      claimingId.value = null
    }
  }

  return {
    store,
    loading,
    updating,
    claimingId,
    fetchInfo,
    ensureInfo,
    refresh,
    fetchRewardHistory,
    fetchFlowLimit,
    receive,
    receiveRebate,
    claim,
  }
}
