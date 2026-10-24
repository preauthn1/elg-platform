import { defineStore } from 'pinia'
import type { ResponseOf } from '#shared/api/registry'
import type { SelectedBank } from '#shared/utils/withdrawal'

export type FlowLimitData = ResponseOf<'withdrawal/flow-limit'>
export type CheckBankAccountData = ResponseOf<'withdrawal/check-bank-account'>
export type WithdrawalBank = ResponseOf<'withdrawal/banks'>[number]
export type BoundBankAccount = NonNullable<CheckBankAccountData['account']>[number]

/**
 * 提款流程跨頁狀態：稽核流水、提款資訊、銀行清單、所選幣別/代付方式、選定銀行。
 * 鍵名照後端；選定銀行（withdrawalSelectedBank）在進入金額頁前寫入。
 */
export const useWithdrawalStore = defineStore('withdrawal', () => {
  const flowLimit = ref<FlowLimitData | null>(null)
  const checkInfo = ref<CheckBankAccountData | null>(null)
  const banks = ref<WithdrawalBank[]>([])
  const currencyId = ref<number | null>(null)
  const typeId = ref<number | null>(null)
  const selectedBank = ref<SelectedBank | null>(null)

  const boundAccounts = computed<BoundBankAccount[]>(() => checkInfo.value?.account ?? [])
  const hasBankCardSetting = computed(() => checkInfo.value?.has_bank_card_setting === 1)

  function setFlowLimit(next: FlowLimitData | null) {
    flowLimit.value = next
  }

  function setCheckInfo(next: CheckBankAccountData | null) {
    checkInfo.value = next
  }

  function setBanks(next: WithdrawalBank[]) {
    banks.value = next
  }

  function setCurrencyId(next: number | null) {
    currencyId.value = next
  }

  function setTypeId(next: number | null) {
    typeId.value = next
  }

  function setSelectedBank(next: SelectedBank | null) {
    selectedBank.value = next
  }

  function reset() {
    flowLimit.value = null
    checkInfo.value = null
    banks.value = []
    currencyId.value = null
    typeId.value = null
    selectedBank.value = null
  }

  return {
    flowLimit,
    checkInfo,
    banks,
    currencyId,
    typeId,
    selectedBank,
    boundAccounts,
    hasBankCardSetting,
    setFlowLimit,
    setCheckInfo,
    setBanks,
    setCurrencyId,
    setTypeId,
    setSelectedBank,
    reset,
  }
})
