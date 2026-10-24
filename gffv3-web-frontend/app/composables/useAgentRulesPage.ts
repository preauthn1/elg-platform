import { decFormat } from '#shared/utils/amount'
import { formatRebateRate, ruleLevelMaxText, vendorRateNote } from '#shared/utils/agent'

/** /agent/rules 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AgentRules.vue。 */
export function useAgentRulesPage() {
  /** 無限級規則頁：層級表（unlimit-hierarchy/list）與場館倍率表（vendor/rate-list）。 */
  const { t } = useI18n()
  const { textUnlimited, textWalletPoint } = useAgentMode()
  const { ruleLevels, vendorRates, fetchRuleLevels, fetchVendorRates } = useAgentCenter()

  const params = computed(() => ({ v_reward: textUnlimited.value, v_point: textWalletPoint.value }))

  /** 倍率 > 0 顯示「10000 流水 × 倍率 = 業績」範例，否則顯示不計算業績。 */
  const vendorRows = computed(() => vendorRates.value.map((row) => {
    const note = vendorRateNote(row.unlimit_rate)
    return {
      ...row,
      note: note.kind === 'sales'
        ? t('agent.enum.turnoverRate.sales', { rate: note.rate, sales: note.sales })
        : t('agent.enum.turnoverRate.noSales'),
    }
  }))

  const levelColumns = computed(() => [
    t('agent.tier.label.level'),
    t('agent.tier.label.teamSalesMin'),
    t('agent.tier.label.teamSalesMax'),
    t('agent.commission.label.proportion'),
    t('agent.performance.label.note'),
  ])
  const levelRows = computed(() => ruleLevels.value.map(row => [
    String(row.level),
    decFormat(row.minAmount, 0),
    ruleLevelMaxText(row.maxAmount, t('common.ui.label.none')),
    `${t('agent.tier.hint.maxRebate')} ${formatRebateRate(row.rebateRate)}`,
    `${t('agent.tier.hint.per10000')} ${decFormat(row.bounsAmount, 0)}`,
  ]))

  const vendorColumns = computed(() => [t('games.common.label.category'), t('agent.rules.label.conversionRate'), t('agent.rules.label.note')])
  const vendorTableRows = computed(() => vendorRows.value.map(row => [String(row.name ?? ''), `${row.unlimit_rate}X`, row.note]))

  onMounted(async () => {
    await Promise.all([fetchRuleLevels(), fetchVendorRates()])
  })

  return {
    params,
    levelColumns,
    levelRows,
    vendorColumns,
    vendorTableRows,
  }
}
