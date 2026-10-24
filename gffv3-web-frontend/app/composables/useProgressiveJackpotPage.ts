import { formatEventAmount } from '~/utils/jackpot'
import {
  growthCountdownParts,
  growthTierClaimable,
  growthTierExpandable,
  type GrowthTier,
} from '~/utils/progressive-jackpot'

/** /progressive-jackpot 的邏輯，所有皮共用，畫面在 components/<皮>/Page/ProgressiveJackpot.vue。 */
export function useProgressiveJackpotPage() {
  const { t } = useI18n()
  const growth = useProgressiveJackpot()
  const {
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
    ratios,
    loading,
    claimingLevel,
    openedLevels,
  } = growth

  const openTier = ref<number | null>(null)
  const showFormula = ref(false)
  const showRule = ref(false)

  const eventName = computed(() => setting.value?.title || t('events.growthPlan.title.growthBonusPlan'))
  const completedText = computed(() =>
    t('events.growthPlan.label.completedCount', {
      done: Number(summary.value?.completed_count ?? 0),
      total: Number(summary.value?.total_count ?? 0) || tiers.value.length,
    }),
  )

  function tierName(tier: GrowthTier): string {
    return tier.isFirstDeposit ? t('events.growthPlan.label.firstDeposit') : `Lv ${tier.level}`
  }

  /** 倒數格式：N天 HH : mm : ss，不足一天省略天數。 */
  function countdownText(seconds: number): string {
    const parts = growthCountdownParts(seconds)
    const clock = `${parts.hours} : ${parts.minutes} : ${parts.seconds}`
    return parts.days ? `${t('events.growthPlan.unit.day', { value: parts.days })} ${clock}` : clock
  }

  const summaryDeadlineText = computed(() =>
    summaryUnlimited.value ? t('events.growthPlan.hint.tierNoTimeLimit') : countdownText(currentRemainingSeconds.value),
  )

  function tierLimitText(tier: GrowthTier): string {
    return tier.limitDays ? t('events.growthPlan.unit.dayLimit', { days: tier.limitDays }) : t('events.growthPlan.label.noTimeLimit')
  }

  function tierRewardText(tier: GrowthTier): string {
    if (tier.showRateRule) {
      return `${t('events.growthPlan.label.firstDepositRate', { percent: tier.percent })} ${t('events.growthPlan.label.firstDepositMax', { value: formatEventAmount(tier.maxReward) })}`
    }
    return t('events.growthPlan.label.tierBonus', { value: formatEventAmount(tier.reward) })
  }

  function toggleTier(tier: GrowthTier) {
    if (!growthTierExpandable(tier)) return
    openTier.value = openTier.value === tier.level ? null : tier.level
  }

  function claimDisabled(tier: GrowthTier): boolean {
    return claimingLevel.value !== null || !growthTierClaimable(tier) || tier.claimable <= 0
  }

  watch(currentTier, (tier) => {
    if (openTier.value === null && tier && growthTierExpandable(tier)) openTier.value = tier.level
  })

  onMounted(() => {
    void growth.init()
  })
  onBeforeUnmount(() => growth.dispose())

  return {
    growth,
    setting,
    summary,
    hasDetail,
    tiers,
    currentTier,
    hasCurrentTier,
    currentReward,
    isRestricted,
    ratios,
    loading,
    openedLevels,
    openTier,
    showFormula,
    showRule,
    eventName,
    completedText,
    tierName,
    countdownText,
    summaryDeadlineText,
    tierLimitText,
    tierRewardText,
    toggleTier,
    claimDisabled,
  }
}
