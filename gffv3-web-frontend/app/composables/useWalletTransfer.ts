import type { ApiEnvelope } from '#shared/types/api'
import {
  buildWalletTransferRequest,
  canSubmitWalletTransfer,
  oppositeDirection,
  remainingFlowLimit,
  walletMaxTransferAmount,
  type WalletDirection,
} from '#shared/utils/wallet'
import { toAmountLike, type AmountLike } from '#shared/utils/amount'

/**
 * 主錢包 ↔ 遊戲錢包互轉（wallet/transfer）。只在 customized_setting.is_multiple_wallet 開啟時可用。
 * 成功後依序：member/transfer 刷新餘額 → 重抓會員資料 → 重抓稽核流水 → 清空金額。
 */
export function useWalletTransfer() {
  const api = useApi()
  const wallet = useWalletStore()
  const member = useMemberStore()
  const { flag } = useSite()
  const { refreshBalance, refreshMemberProfile, refreshFlowLimit } = useWallet()

  const enabled = computed(() => flag('is_multiple_wallet'))
  const flowLimitDisplayVisible = computed(() => flag('flow_limit_display_visible'))

  const direction = ref<WalletDirection>('main_to_game')
  const amount = ref('')
  const submitting = ref(false)

  const mainBalance = computed<AmountLike>(() => wallet.balance ?? toAmountLike(member.profile?.balance))
  const gameBalance = computed<AmountLike>(() => wallet.gameWalletBalance)

  const maxTransferAmount = computed(() => walletMaxTransferAmount({
    direction: direction.value,
    mainBalance: mainBalance.value,
    gameBalance: gameBalance.value,
    flowLimit: wallet.flowLimit,
    reach: wallet.flowReach,
    flowLimitDisplayVisible: flowLimitDisplayVisible.value,
  }))

  /** 畫面顯示的「轉帳限制流水」= flow_limit − reach。 */
  const remainingFlow = computed(() => remainingFlowLimit(wallet.flowLimit, wallet.flowReach))

  const canSubmit = computed(() =>
    enabled.value && !submitting.value && canSubmitWalletTransfer(amount.value, maxTransferAmount.value),
  )

  function toggleDirection() {
    direction.value = oppositeDirection(direction.value)
    amount.value = ''
  }

  /** 進頁：刷新四個餘額與稽核流水。 */
  async function load() {
    await refreshBalance()
    await refreshFlowLimit()
  }

  async function submit(): Promise<ApiEnvelope<unknown> | null> {
    if (!canSubmit.value) return null
    submitting.value = true
    try {
      const res = await api.post('wallet/transfer', buildWalletTransferRequest(direction.value, amount.value))
      if (isApiOk(res)) {
        await refreshBalance()
        await refreshMemberProfile()
        await refreshFlowLimit()
        amount.value = ''
      }
      return res
    }
    finally {
      submitting.value = false
    }
  }

  return {
    enabled,
    flowLimitDisplayVisible,
    direction,
    amount,
    submitting,
    mainBalance,
    gameBalance,
    maxTransferAmount,
    remainingFlow,
    canSubmit,
    toggleDirection,
    load,
    submit,
  }
}
