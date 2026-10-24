import { hasLoseRebate, resolveAgentMode, toNumber, type AgentMode } from '#shared/utils/agent'

/**
 * 代理模式唯一入口：template customized_setting.agent_mode_setting
 * 1 → 無限級（unlimit-hierarchy/*），2/3/4 → 三級分銷（three-hierarchy/*）。
 * 其他代理相關開關（自身回水、自動派發、全民代理文字）也從這裡讀。
 */
export function useAgentMode() {
  const { settings, flag } = useSite()

  const rawMode = computed(() => settings.value?.agent_mode_setting ?? null)
  const mode = computed<AgentMode>(() => resolveAgentMode(rawMode.value))
  const isUnlimit = computed(() => mode.value === 'unlimit')
  const isThree = computed(() => mode.value === 'three')
  const showLoseRebate = computed(() => hasLoseRebate(rawMode.value))

  const itselfRebate = computed(() => flag('is_agent_itself_rebate'))
  const itselfRebatePercent = computed(() => toNumber(settings.value?.agent_itself_rebate_percent) * 100)
  const autoPayout = computed(() => flag('agent_reward_auto_payout'))
  const textUnlimited = computed(() => settings.value?.text_unlimited ?? '')
  const textWalletPoint = computed(() => settings.value?.text_wallet_point ?? '')
  const rulesBanner = computed(() => settings.value?.unlimit_ask_banner_size_url ?? '')

  return {
    rawMode,
    mode,
    isUnlimit,
    isThree,
    showLoseRebate,
    itselfRebate,
    itselfRebatePercent,
    autoPayout,
    textUnlimited,
    textWalletPoint,
    rulesBanner,
  }
}
