import { defineStore } from 'pinia'

export interface RefreshRankingVendorRef {
  id: number
  name: string
}

/**
 * 刷分排行榜跨頁狀態：目前進入的活動遊戲商。
 * 遊戲頁組 exit_url（回到該遊戲商的遊戲列表）時要用，遊戲頁路由本身只有 game id。
 */
export const useRefreshRankingStore = defineStore('refreshRanking', () => {
  const vendor = ref<RefreshRankingVendorRef | null>(null)

  function setVendor(next: RefreshRankingVendorRef | null) {
    vendor.value = next
  }

  function reset() {
    vendor.value = null
  }

  return { vendor, setVendor, reset }
})
