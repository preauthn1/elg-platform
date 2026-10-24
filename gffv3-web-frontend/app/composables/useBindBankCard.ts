import {
  banksFor,
  buildBindAccountsPayload,
  isContactCsBank,
  prefillFromBoundAccount,
  safeInternalPath,
  type BindAccountRow,
} from '#shared/utils/withdrawal'

/**
 * 綁定提款帳號（/withdrawal/bind-bank-card）：依站台上限開列數、選銀行帶入已綁資料、送出後導回。
 * 所有皮共用，畫面在 components/<皮>/Page/BindBankCard.vue。
 */
export function useBindBankCard() {
  const route = useRoute()
  const { t } = useI18n()
  const w = useWithdrawal()
  const store = w.store

  const redirectTo = computed(() => safeInternalPath(route.query.redirect))
  const loading = ref(true)
  const submitting = ref(false)
  const errorMessage = ref('')
  const contactCs = ref(false)
  const rows = ref<Required<BindAccountRow>[]>([])

  const maxRows = computed(() => Math.max(1, store.checkInfo?.bank_card_amount ?? 1))
  const banks = computed(() => banksFor(store.banks, store.currencyId, store.typeId))

  function bankOf(row: BindAccountRow) {
    return banks.value.find(b => b.bank_id === row.bank_id) ?? null
  }

  function newRow(): Required<BindAccountRow> {
    return { bank_id: null, account_name: store.checkInfo?.account_name ?? '', account_no: '', bsb_code: '' }
  }

  async function onBankChange(row: Required<BindAccountRow>) {
    contactCs.value = false
    const bank = bankOf(row)
    if (isContactCsBank(bank)) {
      row.bank_id = null
      contactCs.value = true
      return
    }
    const prefill = prefillFromBoundAccount(store.boundAccounts, row.bank_id, bank?.bank_type)
    if (prefill) {
      row.account_no = prefill.accountNo
      row.bsb_code = prefill.bsbCode
      if (prefill.account_name) row.account_name = prefill.account_name
    }
  }

  async function submit() {
    errorMessage.value = ''
    const { payload, error } = buildBindAccountsPayload(rows.value)
    if (error === 'incomplete') {
      errorMessage.value = t('withdrawal.bankAccount.error.incomplete')
      return
    }
    if (error === 'empty') {
      errorMessage.value = t('common.bank.placeholder.select')
      return
    }
    submitting.value = true
    const res = await w.bindBankAccounts(payload)
    submitting.value = false
    if (!isApiOk(res)) return
    if (redirectTo.value) {
      await navigateTo(redirectTo.value, { replace: true })
      return
    }
    await w.fetchCheckBankAccount(store.typeId ?? undefined)
    await navigateTo('/withdrawal', { replace: true })
  }

  onMounted(async () => {
    if (!store.checkInfo) await w.fetchCheckBankAccount(store.typeId ?? undefined)
    if (!store.banks.length) await w.fetchBanks()
    rows.value = Array.from({ length: maxRows.value }, newRow)
    loading.value = false
  })

  return {
    w,
    loading,
    submitting,
    errorMessage,
    contactCs,
    rows,
    maxRows,
    banks,
    bankOf,
    onBankChange,
    submit,
  }
}
