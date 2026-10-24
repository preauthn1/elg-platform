import { computed, ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import { useJackpotStore, type JackpotPrize } from '~/stores/jackpot'
import { decAdd } from '#shared/utils/amount'
import {
  isJackpotOk,
  jackpotAmountAt,
  jackpotPrizeCountdown,
  msUntilNextJackpotReset,
  type PrizeCountdown,
} from '~/utils/jackpot'

/**
 * 積寶活動（/jackpot）：活動資訊、自己的獎項、中獎名單、領獎。
 * 金額每秒 +1、獎項倒數每秒重算、每天 15:00 整頁重抓；計時器只在 init() 後啟動，dispose() 清掉。
 */
export function useJackpot() {
  const api = useApi()
  const store = useJackpotStore()

  const loading = ref(false)
  /** 領取成功的那顆獎項，頁面用來顯示中獎彈窗 */
  const wonPrize = ref<JackpotPrize | null>(null)
  const amount = ref('0')
  const now = ref(Date.now())

  let amountTimer: ReturnType<typeof setInterval> | null = null
  let countdownTimer: ReturnType<typeof setInterval> | null = null
  let refreshTimer: ReturnType<typeof setTimeout> | null = null

  const show = computed(() => store.show)
  const winList = computed(() => store.winList)
  const hasUnclaimedPrize = computed(() => store.hasUnclaimedPrize)

  /** 每顆獎項的倒數（只算 status 0 且有 expired_time 的） */
  const prizeCountdowns = computed<Record<number, PrizeCountdown>>(() => {
    const out: Record<number, PrizeCountdown> = {}
    const current = new Date(now.value)
    for (const prize of show.value?.prize ?? []) {
      if (Number(prize.status) !== 0 || !prize.expired_time) continue
      const countdown = jackpotPrizeCountdown(prize.expired_time, current)
      if (countdown) out[prize.id] = countdown
    }
    return out
  })

  async function fetchShow(): Promise<ApiEnvelope<ResponseOf<'event/jackpot/show'>>> {
    const res = await api.post('event/jackpot/show', {})
    if (isJackpotOk(res.code)) store.setShow(res.data ?? null)
    return res
  }

  async function fetchWinList(): Promise<ApiEnvelope<ResponseOf<'event/jackpot/win-list'>>> {
    store.setWinList([])
    const res = await api.post('event/jackpot/win-list', {})
    if (isJackpotOk(res.code)) store.setWinList(res.data ?? [])
    return res
  }

  async function refresh(showLoading = true) {
    if (showLoading) loading.value = true
    try {
      await Promise.all([fetchWinList(), fetchShow()])
    }
    finally {
      if (showLoading) loading.value = false
    }
  }

  async function receive(prize: JackpotPrize): Promise<ApiEnvelope<ResponseOf<'event/jackpot/receive'>>> {
    const res = await api.post('event/jackpot/receive', { id: prize.id })
    if (isJackpotOk(res.code)) wonPrize.value = prize
    await Promise.all([fetchWinList(), fetchShow()])
    return res
  }

  function clearWonPrize() {
    wonPrize.value = null
  }

  function startTimers() {
    stopTimers()
    if (import.meta.server) return
    amount.value = jackpotAmountAt(show.value?.jackpot ?? 0, new Date())
    now.value = Date.now()
    amountTimer = setInterval(() => {
      amount.value = decAdd(amount.value, 1)
    }, 1000)
    countdownTimer = setInterval(() => {
      now.value = Date.now()
    }, 1000)
    refreshTimer = setTimeout(() => {
      void init(false)
    }, msUntilNextJackpotReset(new Date()))
  }

  function stopTimers() {
    if (amountTimer) clearInterval(amountTimer)
    if (countdownTimer) clearInterval(countdownTimer)
    if (refreshTimer) clearTimeout(refreshTimer)
    amountTimer = null
    countdownTimer = null
    refreshTimer = null
  }

  /** 頁面 onMounted 呼叫：抓資料並啟動計時器。 */
  async function init(showLoading = true) {
    stopTimers()
    await refresh(showLoading)
    if (show.value) startTimers()
  }

  /** 頁面 onBeforeUnmount 呼叫。 */
  function dispose() {
    stopTimers()
  }

  return {
    show,
    winList,
    hasUnclaimedPrize,
    loading,
    wonPrize,
    amount,
    prizeCountdowns,
    fetchShow,
    fetchWinList,
    refresh,
    receive,
    clearWonPrize,
    init,
    dispose,
  }
}
