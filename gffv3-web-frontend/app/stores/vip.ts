import { defineStore } from 'pinia'
import type { Pagination } from '#shared/types/api'
import type {
  VipContent,
  VipFlowLimitItem,
  VipInfo,
  VipRewardHistoryItem,
} from '#shared/api/endpoints/vip'
import { buildPrivilegeList, resolveVipLevels, sortVipContents } from '#shared/utils/vip'

export interface VipFlowLimitPage {
  items: VipFlowLimitItem[]
  pagination: Pagination
}

/**
 * VIP 資料（vip/info + vip/contents 合併後的結果，鍵名照後端）。
 * /vip、/vip/rebate、/my 的使用者卡共用 currentVip / nextVip。
 */
export const useVipStore = defineStore('vip', () => {
  const info = ref<VipInfo | null>(null)
  /** 已依 level 升冪排序。 */
  const contents = ref<VipContent[]>([])
  const rewardHistory = ref<VipRewardHistoryItem[]>([])
  const flowLimit = ref<VipFlowLimitPage | null>(null)
  const loadedAt = ref<number | null>(null)

  const level = computed(() => Number(info.value?.level ?? 0))
  const levels = computed(() => resolveVipLevels(contents.value, level.value))
  const currentVip = computed(() => levels.value.current ?? null)
  const nextVip = computed(() => levels.value.next ?? null)
  const maxLevel = computed(() => levels.value.maxLevel)
  const privileges = computed(() => buildPrivilegeList(info.value, currentVip.value))

  function setInfo(nextInfo: VipInfo, nextContents: readonly VipContent[]) {
    info.value = nextInfo
    contents.value = sortVipContents(nextContents)
    loadedAt.value = Date.now()
  }

  function setRewardHistory(list: VipRewardHistoryItem[]) {
    rewardHistory.value = list
  }

  function setFlowLimit(page: VipFlowLimitPage | null) {
    flowLimit.value = page
  }

  /** 重抓前先清空列表，分頁資訊保留。 */
  function clearFlowLimitItems() {
    if (flowLimit.value) flowLimit.value = { ...flowLimit.value, items: [] }
  }

  function reset() {
    info.value = null
    contents.value = []
    rewardHistory.value = []
    flowLimit.value = null
    loadedAt.value = null
  }

  return {
    info,
    contents,
    rewardHistory,
    flowLimit,
    loadedAt,
    level,
    currentVip,
    nextVip,
    maxLevel,
    privileges,
    setInfo,
    setRewardHistory,
    setFlowLimit,
    clearFlowLimitItems,
    reset,
  }
})
