import type { ApiEnvelope } from '#shared/types/api'
import {
  calcWithdrawableLimit,
  emptyPageState,
  normalizePagination,
  normalizeWithdrawalHistory,
  normalizeWithdrawalSummary,
  toNumber,
  validateWithdrawAmount,
  type PageState,
  type WithdrawAmountError,
  type WithdrawalHistoryItem,
} from '#shared/utils/agent'

/**
 * 代理提領：總覽／紀錄／申請。
 * 無限級走 unlimit-hierarchy/withdrawal/*，三級分銷走 three-hierarchy/withdrawals/*。
 */
export function useAgentWithdrawal() {
  const api = useApi()
  const store = useAgentStore()
  const { mode, isThree, itselfRebate, autoPayout } = useAgentMode()

  const summary = computed(() => store.withdrawalSummary)
  const history = ref<WithdrawalHistoryItem[]>([])
  const historyPage = ref<PageState>(emptyPageState())
  const applyError = ref<WithdrawAmountError | null>(null)

  /** 前端擋的可提領上限；後端同樣以總和判 1022。 */
  const withdrawableLimit = computed(() => (
    summary.value
      ? calcWithdrawableLimit(summary.value, { mode: mode.value, itselfRebate: itselfRebate.value })
      : 0
  ))

  /** agent_reward_auto_payout==1 自動派發，不顯示申請按鈕。 */
  const canApply = computed(() => !autoPayout.value)

  async function fetchRecord(): Promise<ApiEnvelope<unknown>> {
    const res = isThree.value
      ? await api.post('three-hierarchy/withdrawals', {})
      : await api.post('unlimit-hierarchy/withdrawal/record', { page: 1, per_page: 1 })
    if (isApiOk(res)) store.withdrawalSummary = normalizeWithdrawalSummary(res.data as Record<string, unknown>)
    return res
  }

  async function fetchHistory(page = 1, perPage = 10): Promise<ApiEnvelope<unknown>> {
    const body = { page, per_page: perPage }
    const res = isThree.value
      ? await api.post('three-hierarchy/withdrawals/histories', body)
      : await api.post('unlimit-hierarchy/withdrawal/history', body)
    if (isApiOk(res)) {
      history.value = normalizeWithdrawalHistory(res.data?.items ?? [])
      historyPage.value = normalizePagination(res.data?.pagination, { page, perPage })
    }
    return res
  }

  function validateAmount(amount: unknown): WithdrawAmountError | null {
    applyError.value = validateWithdrawAmount(amount, withdrawableLimit.value)
    return applyError.value
  }

  /**
   * 申請提領。前端先擋格式與上限（回 null 並設 applyError）；
   * 成功（code 200）後重抓總覽與紀錄，並把回應的 balance 放進 store 供餘額刷新。
   */
  async function apply(amount: unknown): Promise<ApiEnvelope<unknown> | null> {
    if (validateAmount(amount)) return null
    const body = { amount: Number(amount) }
    const res = isThree.value
      ? await api.post('three-hierarchy/withdrawals/create', body)
      : await api.post('unlimit-hierarchy/withdrawal/create', body)
    if (isApiOk(res)) {
      store.lastBalance = toNumber(res.data?.balance)
      await Promise.all([fetchRecord(), fetchHistory(historyPage.value.current, historyPage.value.perPage)])
    }
    return res
  }

  return {
    mode,
    itselfRebate,
    summary,
    history,
    historyPage,
    applyError,
    withdrawableLimit,
    canApply,
    fetchRecord,
    fetchHistory,
    validateAmount,
    apply,
  }
}
