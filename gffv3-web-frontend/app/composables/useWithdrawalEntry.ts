import { MEMBER_PATH } from '#shared/utils/member'
import {
  accountNoLabel,
  bankInfoFields,
  banksFor,
  canEditAccountName,
  currencyOptions,
  defaultBankInfoData,
  isBsbBank,
  isContactCsBank,
  isContactCsType,
  joinAccountNo,
  prefillFromBoundAccount,
  safeInternalPath,
  selectedBankFromAccount,
  selectedBankFromBank,
  showAccountNameField,
  typeOptions,
  validateBankInfoData,
} from '#shared/utils/withdrawal'
import type { BoundBankAccount } from '~/stores/withdrawal'

/**
 * 提款入口（/withdrawal）：進頁閘門、選幣別／類型／銀行、綁卡或選已綁的卡、申請解除流水。
 * 所有皮共用，畫面在 components/<皮>/Page/Withdrawal.vue。
 */
export function useWithdrawalEntry() {
  const route = useRoute()
  const { t } = useI18n()
  const w = useWithdrawal()
  const store = w.store
  const alert = useAlert()

  const redirectTo = computed(() => safeInternalPath(route.query.redirect))
  const bindLink = computed(() => ({
    path: '/withdrawal/bind-bank-card',
    query: redirectTo.value ? { redirect: redirectTo.value } : {},
  }))

  const loading = ref(true)
  const notice = ref('')
  const errorMessage = ref('')
  const releaseConfirming = ref(false)
  const releaseDone = ref(false)
  const submitting = ref(false)
  const fieldErrors = ref<string[]>([])

  const currencies = computed(() => currencyOptions(store.banks))
  const types = computed(() => typeOptions(store.banks, store.currencyId))
  const availableBanks = computed(() => banksFor(store.banks, store.currencyId, store.typeId))
  const contactCs = computed(() => isContactCsType(store.typeId))
  const showTimes = computed(() =>
    store.checkInfo?.withdrawal_times !== null && store.checkInfo?.withdrawal_times !== undefined
    && store.checkInfo?.withdrawal_times_limit !== null && store.checkInfo?.withdrawal_times_limit !== undefined,
  )

  const form = reactive({
    bankId: null as number | null,
    accountName: '',
    accountNo: '',
    bsbCode: '',
    bankInfoData: {} as Record<string, string>,
  })
  const selectedFormBank = computed(() => availableBanks.value.find(b => b.bank_id === form.bankId) ?? null)

  const infoFields = computed(() => bankInfoFields(selectedFormBank.value?.bank_info))
  const accountLabel = computed(() => accountNoLabel(selectedFormBank.value))
  const accountNameEditable = computed(() => canEditAccountName({
    realNameVerifiedVisible: w.realNameVerifiedVisible.value,
    is_first: store.checkInfo?.is_first,
    account_name_modify: store.checkInfo?.account_name_modify,
  }))

  async function selectType(id: number | null) {
    store.setTypeId(id)
    form.bankId = null
    if (id === null || isContactCsType(id)) return
    await w.fetchCheckBankAccount(id)
  }

  async function selectCurrency(id: number | null) {
    store.setCurrencyId(id)
    const first = typeOptions(store.banks, id)[0]
    await selectType(first ? first.id : null)
  }

  async function load() {
    loading.value = true
    errorMessage.value = ''
    await w.fetchMemberProfile()

    const gate = await w.checkEntryGate()
    if (!gate.allowed) {
      await navigateTo(MEMBER_PATH.home, { replace: true })
      return
    }
    if (gate.popupText) notice.value = gate.popupText

    // 實名認證在 middleware/realname.global.ts 擋，進得來就是已通過或站台不要求
    if (w.needSetPaymentPassword.value) {
      await navigateTo(MEMBER_PATH.paymentPassword)
      return
    }

    const [, , checkRes] = await Promise.all([w.fetchFlowLimit(), w.fetchBanks(), w.fetchCheckBankAccount()])
    if (!isApiOk(checkRes)) {
      loading.value = false
      return
    }
    form.accountName = store.checkInfo?.account_name ?? ''

    const firstCurrency = currencies.value[0]
    await selectCurrency(firstCurrency ? firstCurrency.id : null)
    loading.value = false
  }

  watch(() => form.bankId, (bankId) => {
    const bank = selectedFormBank.value
    form.bankInfoData = defaultBankInfoData(bank?.bank_info)
    fieldErrors.value = []
    const prefill = prefillFromBoundAccount(store.boundAccounts, bankId, bank?.bank_type)
    if (prefill) {
      form.accountNo = prefill.accountNo
      form.bsbCode = prefill.bsbCode
      if (prefill.account_name) form.accountName = prefill.account_name
      return
    }
    form.accountNo = ''
    form.bsbCode = ''
  })

  async function submitInputBank() {
    errorMessage.value = ''
    const bank = selectedFormBank.value
    if (!bank) {
      errorMessage.value = t('common.bank.placeholder.select')
      return
    }
    if (isContactCsBank(bank)) {
      await navigateTo('/chat')
      return
    }
    fieldErrors.value = validateBankInfoData(bank.bank_info, form.bankInfoData)
    if (fieldErrors.value.length) return

    const accountNo = joinAccountNo(isBsbBank(bank.bank_type) ? form.bsbCode : '', form.accountNo)
    if (!accountNo) {
      errorMessage.value = t('withdrawal.bankAccount.error.incomplete')
      return
    }
    const accountName = showAccountNameField(bank.bank_type) ? form.accountName.trim() : ''

    submitting.value = true
    const res = await w.bindBankAccounts([{ bank_id: bank.bank_id, account_name: accountName || null, account_no: accountNo }])
    submitting.value = false
    if (!isApiOk(res)) return
    store.setSelectedBank(selectedBankFromBank(bank, {
      account_name: accountName,
      account_no: accountNo,
      bank_info_data: { ...form.bankInfoData },
    }))
    await navigateTo('/withdrawal/amount')
  }

  async function chooseCard(card: BoundBankAccount) {
    if (isContactCsBank(card)) {
      await navigateTo('/chat')
      return
    }
    store.setSelectedBank(selectedBankFromAccount(card, store.banks))
    await navigateTo('/withdrawal/amount')
  }

  async function applyRelease() {
    releaseConfirming.value = false
    errorMessage.value = ''
    const res = await w.applyReleaseApplication()
    if (!isApiOk(res)) return
    releaseDone.value = true
    alert.show(t('common.ui.msg.applySuccess'), 'success')
  }

  onMounted(load)

  return {
    w,
    store,
    redirectTo,
    bindLink,
    loading,
    notice,
    errorMessage,
    releaseConfirming,
    releaseDone,
    submitting,
    fieldErrors,
    currencies,
    types,
    availableBanks,
    contactCs,
    showTimes,
    form,
    selectedFormBank,
    infoFields,
    accountLabel,
    accountNameEditable,
    selectType,
    selectCurrency,
    submitInputBank,
    chooseCard,
    applyRelease,
  }
}
