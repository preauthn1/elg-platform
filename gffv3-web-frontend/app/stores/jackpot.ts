import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { ResponseOf } from '#shared/api/registry'
import { hasUnclaimedJackpotPrize } from '~/utils/jackpot'

export type JackpotShow = NonNullable<ResponseOf<'event/jackpot/show'>>
export type JackpotPrize = JackpotShow['prize'][number]
export type JackpotWinner = ResponseOf<'event/jackpot/win-list'>[number]

/**
 * 積寶活動資料（event/jackpot/show 與 win-list 的 data，鍵名照後端）。
 * 放 Pinia 是因為底部分頁的紅點也要讀「有沒有未領取獎項」。
 */
export const useJackpotStore = defineStore('jackpot', () => {
  const show = ref<JackpotShow | null>(null)
  const winList = ref<JackpotWinner[]>([])
  const loadedAt = ref<number | null>(null)

  const hasUnclaimedPrize = computed(() => hasUnclaimedJackpotPrize(show.value?.prize))

  function setShow(next: JackpotShow | null) {
    show.value = next
    loadedAt.value = next ? Date.now() : null
  }

  function setWinList(next: JackpotWinner[]) {
    winList.value = next
  }

  function reset() {
    setShow(null)
    setWinList([])
  }

  return { show, winList, loadedAt, hasUnclaimedPrize, setShow, setWinList, reset }
})
