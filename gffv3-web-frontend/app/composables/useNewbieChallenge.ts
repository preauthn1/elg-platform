import type { ApiEnvelope } from '#shared/types/api'
import type { NewbieChallengeDetail, NewbieChallengeProgress } from '#shared/api/endpoints/events-core'
import { BFF_CODE } from '#shared/constants/codes'
import { isApiOk, useApi } from './useApi'
import { useNewbieChallengeStore } from '~/stores/newbieChallenge'
import {
  isNewbieWithdrawalBlocked,
  NEWBIE_DEFAULT_BONUS_WINDOW_HOURS,
  NEWBIE_STAGE,
  newbieIsEnded,
  newbieIsExpired,
  newbieRemainingSeconds,
  newbieStep,
} from '~/utils/events-core'

export const NEWBIE_TP_EVENT = 1

type ProgressRoute
  = | 'newbie-challenge/unlock-bonus'
    | 'newbie-challenge/confirm-deposit'
    | 'newbie-challenge/claim-bonus'
    | 'newbie-challenge/quit'

/**
 * 新人專享挑戰（newbie-challenge/*）。所有按鈕以後端旗標為準，不在前端算流水。
 */
export function useNewbieChallenge() {
  const api = useApi()
  const store = useNewbieChallengeStore()

  /** newbie-challenge/detail：失敗存 {}、不拋錯。 */
  async function fetchDetail(): Promise<ApiEnvelope<NewbieChallengeDetail>> {
    try {
      const res = await api.post('newbie-challenge/detail', {})
      store.detail = isApiOk(res) ? (res.data ?? {}) : {}
      return res
    }
    catch (error) {
      store.detail = {}
      return { code: BFF_CODE.UPSTREAM_FAILED, message: error instanceof Error ? error.message : String(error), data: {} }
    }
  }

  /** newbie-challenge/progress：未參加（400）與其他非 200 一律存 null、不跳錯誤。 */
  async function fetchProgress(): Promise<ApiEnvelope<NewbieChallengeProgress>> {
    try {
      const res = await api.post('newbie-challenge/progress', {})
      store.setProgress(isApiOk(res) ? (res.data ?? null) : null)
      return res
    }
    catch (error) {
      store.setProgress(null)
      return { code: BFF_CODE.UPSTREAM_FAILED, message: error instanceof Error ? error.message : String(error), data: null as unknown as NewbieChallengeProgress }
    }
  }

  /** 進頁時活動介紹與進度並行抓。 */
  function fetchAll() {
    return Promise.all([fetchDetail(), fetchProgress()])
  }

  const detail = computed(() => store.detail)
  const progress = computed(() => store.progress)
  const bonusWindowHours = computed(() => Number(store.detail.bonus_window_hours) || NEWBIE_DEFAULT_BONUS_WINDOW_HOURS)

  // 倒數：stage 2 每秒重算，歸零當下重抓 progress
  const remainingSeconds = ref(newbieRemainingSeconds(store.progress?.bonus_window_expire_at))
  watch(() => store.progress?.bonus_window_expire_at, (expireAt) => {
    remainingSeconds.value = newbieRemainingSeconds(expireAt)
  })

  function tick(nowMs: number = Date.now()) {
    if (Number(store.progress?.stage) !== NEWBIE_STAGE.BONUS_PENDING) return
    const previous = remainingSeconds.value
    remainingSeconds.value = newbieRemainingSeconds(store.progress?.bonus_window_expire_at, nowMs)
    if (previous > 0 && remainingSeconds.value === 0) void fetchProgress()
  }

  const step = computed(() => newbieStep(store.progress))
  const expired = computed(() => newbieIsExpired(store.progress))
  const ended = computed(() => newbieIsEnded(store.progress))
  const canCompleteChallenge = computed(() => !!store.progress?.can_complete_challenge)
  const canConfirmDeposit = computed(() => !!store.progress?.can_confirm_deposit)
  const canClaimBonus = computed(() => !!store.progress?.can_claim_bonus)

  const joining = ref(false)

  /** newbie-challenge/join：成功後重抓 progress；仍停在步驟 1（失敗）才解除 joining。 */
  async function join(): Promise<ApiEnvelope<unknown> | null> {
    if (joining.value) return null
    joining.value = true
    try {
      const res = await api.post('newbie-challenge/join', {})
      if (isApiOk(res)) await fetchProgress()
      return res
    }
    finally {
      if (step.value === 1) joining.value = false
    }
  }

  const busy = ref<ProgressRoute | null>(null)

  async function request(route: ProgressRoute): Promise<ApiEnvelope<NewbieChallengeProgress> | null> {
    if (busy.value) return null
    busy.value = route
    try {
      const res = await api.post(route, {})
      if (isApiOk(res)) await fetchProgress()
      return res
    }
    finally {
      busy.value = null
    }
  }

  /** 只有 can_complete_challenge 才可呼叫。 */
  function unlockBonus() {
    if (!canCompleteChallenge.value) return Promise.resolve(null)
    return request('newbie-challenge/unlock-bonus')
  }

  /** stage 2 倒數頁「下一步」：只有 can_confirm_deposit 才可呼叫。 */
  function confirmDeposit() {
    if (!canConfirmDeposit.value) return Promise.resolve(null)
    return request('newbie-challenge/confirm-deposit')
  }

  /** 只有 can_claim_bonus 才可呼叫。 */
  function claimBonus() {
    if (!canClaimBonus.value) return Promise.resolve(null)
    return request('newbie-challenge/claim-bonus')
  }

  /** 確認彈窗後才呼叫。 */
  function quit() {
    return request('newbie-challenge/quit')
  }

  /** 提款守衛（C13）：stage 1~3 擋。 */
  async function isWithdrawalBlocked(): Promise<boolean> {
    await fetchProgress()
    return isNewbieWithdrawalBlocked(store.progress)
  }

  /** 頁面掛載後每秒倒數、回到前景重抓進度；只在瀏覽器執行。 */
  function setupAutoRefresh() {
    let timer: ReturnType<typeof setInterval> | null = null
    const onVisibility = () => {
      if (document.visibilityState === 'visible') void fetchProgress()
    }
    onMounted(() => {
      timer = setInterval(() => tick(), 1000)
      document.addEventListener('visibilitychange', onVisibility)
    })
    onBeforeUnmount(() => {
      if (timer) clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisibility)
    })
  }

  return {
    store,
    fetchDetail,
    fetchProgress,
    fetchAll,
    detail,
    progress,
    bonusWindowHours,
    remainingSeconds,
    tick,
    step,
    expired,
    ended,
    canCompleteChallenge,
    canConfirmDeposit,
    canClaimBonus,
    joining,
    busy,
    join,
    unlockBonus,
    confirmDeposit,
    claimBonus,
    quit,
    isWithdrawalBlocked,
    setupAutoRefresh,
  }
}
