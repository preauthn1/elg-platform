import { computed, ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import { API_CODE } from '#shared/constants/codes'
import { isJackpotOk } from '~/utils/jackpot'
import {
  GROWTH_TP_EVENT,
  growthBuildTiers,
  growthCurrentTier,
  growthHasCountdown,
  growthHasTpEvent,
  growthIsRestricted,
  growthMergeDetail,
  growthNewlyOpenedLevels,
  growthNotifiedCookieName,
  growthSecondsUntil,
  growthTierClaimable,
  growthToNumber,
} from '~/utils/progressive-jackpot'

export type GrowthDetail = ResponseOf<'event/progressive-jackpot/list'>

/**
 * 成長彩金計劃（/progressive-jackpot）：活動設定、當前檔位總覽、檔位列表、領取。
 * 每次進頁都重抓；領取失敗也重抓（後端可能先把檔位鎖定再拋錯）。
 */
export function useProgressiveJackpot() {
  const api = useApi()
  const session = useUserSession()
  const account = String(session.user.value?.username ?? '')
  /** 已提示過「恭喜開啟成長彩金」的檔位，依帳號分開記 */
  const notifiedLevels = useCookie<number[]>(growthNotifiedCookieName(account), {
    default: () => [],
    maxAge: 60 * 60 * 24 * 365,
  })

  const detail = ref<GrowthDetail | null>(null)
  const loading = ref(false)
  const claimingLevel = ref<number | null>(null)
  /** 這次新開啟、要跳一次提示的檔位 */
  const openedLevels = ref<number[]>([])
  const now = ref(Date.now())
  let countdownTimer: ReturnType<typeof setInterval> | null = null

  const setting = computed(() => detail.value?.setting ?? null)
  const summary = computed(() => detail.value?.summary ?? null)
  const hasDetail = computed(() => !!setting.value)
  const tiers = computed(() => growthBuildTiers(detail.value?.levels ?? []))
  const currentTier = computed(() => growthCurrentTier(tiers.value, summary.value?.level))
  const hasCurrentTier = computed(() => summary.value?.level !== null && summary.value?.level !== undefined)
  const currentReward = computed(() => summary.value?.reward ?? currentTier.value?.reward ?? '0')
  const isRestricted = computed(() => growthIsRestricted(tiers.value))
  const summaryUnlimited = computed(() => !!summary.value?.unlimited_time)
  const currentRemainingSeconds = computed(() =>
    summaryUnlimited.value ? 0 : growthSecondsUntil(summary.value?.expire_time, now.value),
  )
  const hasCountdown = computed(() => growthHasCountdown(summaryUnlimited.value, tiers.value))
  const ratios = computed(() =>
    (detail.value?.bet_settings ?? []).map(item => ({
      gameTypeId: Number(item.game_type_id),
      rate: growthToNumber(item.rate),
    })),
  )

  /** 路由守衛：events 領域的 event/list 有 tp_event 3 才可進；列表拿不到不阻擋。 */
  async function checkEntryAllowed(): Promise<boolean> {
    const res = await api.post('event/list', {})
    if (res.code !== API_CODE.OK) return true
    if (growthHasTpEvent(res.data, GROWTH_TP_EVENT)) return true
    await navigateTo('/', { replace: true })
    return false
  }

  function noticeOpenedTiers() {
    const fresh = growthNewlyOpenedLevels(tiers.value, notifiedLevels.value ?? [])
    if (!fresh.length) return
    notifiedLevels.value = [...(notifiedLevels.value ?? []), ...fresh]
    openedLevels.value = fresh
  }

  function dismissOpenedNotice() {
    openedLevels.value = []
  }

  async function fetchList(): Promise<ApiEnvelope<ResponseOf<'event/progressive-jackpot/list'>>> {
    loading.value = true
    try {
      const res = await api.post('event/progressive-jackpot/list', {})
      if (isJackpotOk(res.code)) {
        detail.value = res.data ?? null
      }
      else {
        detail.value = null
      }
      noticeOpenedTiers()
      return res
    }
    finally {
      loading.value = false
    }
  }

  async function claim(level: number): Promise<ApiEnvelope<ResponseOf<'event/progressive-jackpot/receive'>> | null> {
    const tier = tiers.value.find(item => item.level === level)
    if (claimingLevel.value !== null || !tier || tier.claimable <= 0 || !growthTierClaimable(tier)) return null
    claimingLevel.value = level
    try {
      const res = await api.post('event/progressive-jackpot/receive', { level })
      if (isJackpotOk(res.code)) {
        if (res.data) detail.value = growthMergeDetail(detail.value, res.data)
        noticeOpenedTiers()
      }
      else {
        await fetchList()
      }
      return res
    }
    finally {
      claimingLevel.value = null
    }
  }

  function tierSeconds(expireAt: string | null): number {
    return growthSecondsUntil(expireAt, now.value)
  }

  function startCountdown() {
    stopCountdown()
    if (import.meta.server) return
    countdownTimer = setInterval(() => {
      if (hasCountdown.value) now.value = Date.now()
    }, 1000)
  }

  function stopCountdown() {
    if (countdownTimer) clearInterval(countdownTimer)
    countdownTimer = null
  }

  /** 頁面 onMounted 呼叫：守衛、抓列表、啟動倒數。 */
  async function init() {
    if (!(await checkEntryAllowed())) return
    await fetchList()
    startCountdown()
  }

  function dispose() {
    stopCountdown()
  }

  return {
    detail,
    setting,
    summary,
    hasDetail,
    tiers,
    currentTier,
    hasCurrentTier,
    currentReward,
    isRestricted,
    summaryUnlimited,
    currentRemainingSeconds,
    hasCountdown,
    ratios,
    loading,
    claimingLevel,
    openedLevels,
    notifiedLevels,
    checkEntryAllowed,
    fetchList,
    claim,
    tierSeconds,
    dismissOpenedNotice,
    init,
    dispose,
  }
}
