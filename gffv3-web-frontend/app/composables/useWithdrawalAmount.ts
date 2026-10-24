import { MEMBER_PATH } from '#shared/utils/member'
import {
  amountLabelKey,
  bankRate,
  feeAmount,
  feePercentText,
  formatDateTime,
  isAmountValid,
  netAmount,
  overLimitFee,
  platformPoints,
  rateText,
  resolveAmountLimits,
  showFee,
  showOverLimitFee,
} from '#shared/utils/withdrawal'

type Step = 'input' | 'confirm' | 'reward-notice' | 'verify' | 'done'

/**
 * 提款金額（/withdrawal/amount）：輸入金額 → 確認 → （登入活動獎勵提示）→ 支付密碼或簡訊驗證 → 建單。
 * 所有皮共用，畫面在 components/<皮>/Page/WithdrawalAmount.vue。
 */
export function useWithdrawalAmount() {
  const w = useWithdrawal()
  const store = w.store

  const selected = computed(() => store.selectedBank)
  const amount = ref('')
  const step = ref<Step>('input')
  const paymentPassword = [REDACTED]'')
  const smsCode = ref('')
  const smsSent = ref(false)
  const busy = ref(false)
  const successAt = ref('')

  const limits = computed(() => resolveAmountLimits(selected.value, store.checkInfo))
  const amountValid = computed(() => isAmountValid(amount.value, limits.value))
  const rate = computed(() => bankRate(selected.value))
  const rateLabel = computed(() => rateText(rate.value, w.pointText.value, selected.value?.currency_name))
  const amountOrZero = computed(() => amount.value || '0')
  const points = computed(() => platformPoints(amountOrZero.value, rate.value))
  const hasFee = computed(() => showFee(selected.value?.tax))
  const fee = computed(() => feeAmount(amountOrZero.value, selected.value?.tax_type, selected.value?.tax))
  const feePercent = computed(() => feePercentText(selected.value?.tax))
  const isPercentFee = computed(() => Number(selected.value?.tax_type) === 1)
  const overLimit = computed(() => showOverLimitFee(store.checkInfo))
  const overFee = computed(() => overLimitFee(amountOrZero.value, store.checkInfo?.vip_tax))
  const net = computed(() => netAmount({
    amount: amountOrZero.value,
    taxType: selected.value?.tax_type,
    tax: selected.value?.tax,
    vipTax: store.checkInfo?.vip_tax,
    applyOverLimitFee: overLimit.value,
  }))
  const available = computed(() => w.availableAmount(rate.value))
  const amountLabel = computed(() => amountLabelKey(selected.value?.bank_type))

  function submitAmount() {
    if (!amountValid.value) return
    step.value = 'confirm'
  }

  async function doCreate() {
    busy.value = true
    const res = await w.createOrder(amount.value)
    busy.value = false
    if (!isApiOk(res)) return
    successAt.value = formatDateTime(new Date())
    step.value = 'done'
  }

  async function sendSms() {
    const res = await w.sendSmsCode()
    if (isApiOk(res)) smsSent.value = true
  }

  async function startVerify() {
    const kind = w.verifyStep.value
    if (kind === 'none') {
      await doCreate()
      return
    }
    step.value = 'verify'
    if (kind === 'sms' && !smsSent.value) await sendSms()
  }

  async function confirmOrder() {
    if (store.checkInfo?.has_login_event_reward === 1 && step.value !== 'reward-notice') {
      step.value = 'reward-notice'
      return
    }
    await startVerify()
  }

  async function verifyPaymentPassword() {
    busy.value = true
    const res = await w.confirmPaymentPassword(paymentPassword.value)
    busy.value = false
    if (!isApiOk(res)) return
    await doCreate()
  }

  async function verifySms() {
    busy.value = true
    const res = await w.checkSmsCode(smsCode.value)
    busy.value = false
    if (!isApiOk(res)) return
    await doCreate()
  }

  async function finish() {
    store.setSelectedBank(null)
    await navigateTo(MEMBER_PATH.home, { replace: true })
  }

  onMounted(async () => {
    if (!selected.value) {
      await navigateTo(MEMBER_PATH.home, { replace: true })
      return
    }
    if (!store.flowLimit) await w.fetchFlowLimit()
    if (!store.checkInfo) await w.fetchCheckBankAccount()
  })

  return {
    w,
    selected,
    amount,
    step,
    paymentPassword,
    smsCode,
    smsSent,
    busy,
    successAt,
    limits,
    amountValid,
    rateLabel,
    points,
    hasFee,
    fee,
    feePercent,
    isPercentFee,
    overLimit,
    overFee,
    net,
    available,
    amountLabel,
    submitAmount,
    sendSms,
    confirmOrder,
    verifyPaymentPassword,
    verifySms,
    finish,
  }
}
