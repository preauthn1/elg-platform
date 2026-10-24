import type { ApiEnvelope } from '#shared/types/api'
import type { EndpointKey, RequestOf } from '#shared/api/registry'
import type { MemberDetail } from '#shared/api/endpoints/member'
import type { AmountLike } from '#shared/utils/amount'
import { BFF_CODE } from '#shared/constants/codes'
import { isOn } from '#shared/utils/site'
import {
  WITHDRAWAL_SMS_CODE_TYPE,
  availableForWithdrawal,
  buildCreateOrderPayload,
  canWithdrawal,
  findSelfWithdrawalType,
  flowRemaining,
  releaseButtonState,
  resolveVerifyStep,
  showFlowRemaining,
} from '#shared/utils/withdrawal'

export type BindAccountInput = RequestOf<'withdrawal/bind-bank-account'>['account'][number]

export interface EntryGate {
  /** 代理有開放自助洗分（top-up-types 有 cf_id 6） */
  allowed: boolean
  /** 進頁先彈的提示 */
  popupText: string | null
}

/**
 * 提款領域：每個 endpoint 一個函式，回傳 ApiEnvelope；業務碼分流與成功後重抓寫在這裡。
 * 其他領域的路由（member/detail、deposit/top-up-types…）以字串呼叫，型別由該領域登記。
 */
export function useWithdrawal() {
  const api = useApi()
  const store = useWithdrawalStore()
  const memberStore = useMemberStore()
  const site = useSite()

  function postAny(route: string, body: Record<string, unknown> = {}): Promise<ApiEnvelope<unknown>> {
    return api.post(route as EndpointKey, body as never)
  }

  function profileValue(key: string): unknown {
    return memberStore.profile?.[key]
  }

  // ---- 站台旗標與會員資料 ----------------------------------------------------------------------------

  const isMultipleWallet = computed(() => site.flag('is_multiple_wallet'))
  const deductFlowLimit = computed(() => site.flag('withdrawal_deduct_flow_limit'))
  const flowLimitDisplayVisible = computed(() => site.flag('flow_limit_display_visible'))
  const paymentPasswordVisible = computed(() => site.flag('payment_password_visible'))
  const realNameVerifiedVisible = computed(() => site.flag('real_name_verified_visible'))
  const pointText = computed(() => site.settings.value?.text_wallet_point ?? '')
  const bsbCodeAlias = computed(() => site.settings.value?.bsb_code_alias ?? '')

  const memberBalance = computed<AmountLike>(() => {
    const balance = profileValue('balance')
    return typeof balance === 'string' || typeof balance === 'number' ? balance : null
  })
  const memberMobile = computed(() => {
    const mobile = profileValue('mobile') ?? store.checkInfo?.mobile
    return typeof mobile === 'string' || typeof mobile === 'number' ? String(mobile) : ''
  })
  const memberMobileValidate = computed(() => isOn(profileValue('mobile_validate')))
  const flowLimitApplication = computed(() => Number(profileValue('flow_limit_application') ?? 0))

  /** payment_password_visible 且會員尚未設定支付密碼 → 先去 /payment-password */
  const needSetPaymentPassword = [REDACTED] => {
    if (!paymentPasswordVisible.value || !memberStore.profile) return false
    return Number(profileValue('payment_password')) === 0
  })

  const verifyStep = computed(() => resolveVerifyStep({
    paymentPasswordVisible: paymentPasswordVisible.value,
    mobileValidate: memberMobileValidate.value,
  }))

  // ---- 稽核流水 ---------------------------------------------------------------------------------

  const remainingFlow = computed(() => flowRemaining(store.flowLimit))
  const showRemainingFlow = computed(() => showFlowRemaining(store.flowLimit, flowLimitDisplayVisible.value))
  const canWithdraw = computed(() => canWithdrawal(store.flowLimit, {
    isMultipleWallet: isMultipleWallet.value,
    deductFlowLimit: deductFlowLimit.value,
  }))
  const releaseState = computed(() => releaseButtonState({
    flowLimitApplication: flowLimitApplication.value,
    balance: memberBalance.value,
    flowLimit: store.flowLimit?.flow_limit,
  }))

  function availableAmount(rate: AmountLike): string | null {
    return availableForWithdrawal({
      balance: memberBalance.value,
      flow: store.flowLimit,
      rate,
      isMultipleWallet: isMultipleWallet.value,
      deductFlowLimit: deductFlowLimit.value,
    })
  }

  // ---- 其他領域路由 -------------------------------------------------------------------------------

  async function fetchMemberProfile(): Promise<ApiEnvelope<unknown>> {
    const res = await postAny('member/detail')
    if (isApiOk(res) && res.data && typeof res.data === 'object' && !Array.isArray(res.data)) {
      memberStore.setProfile(res.data as MemberDetail)
    }
    return res
  }

  async function checkEntryGate(): Promise<EntryGate> {
    const res = await postAny('deposit/top-up-types')
    if (!isApiOk(res)) return { allowed: true, popupText: null }
    const hit = findSelfWithdrawalType(res.data)
    if (!hit) return { allowed: false, popupText: null }
    return { allowed: true, popupText: hit.popup_text ?? null }
  }

  function sendSmsCode(): Promise<ApiEnvelope<unknown>> {
    return postAny('member/send-validation-code', {
      type: WITHDRAWAL_SMS_CODE_TYPE,
      verify_by: 1,
      mobile: memberMobile.value,
    })
  }

  function checkSmsCode(token: [REDACTED] Promise<ApiEnvelope<unknown>> {
    return postAny('guest/check-validation-code', {
      type: WITHDRAWAL_SMS_CODE_TYPE,
      mobile: memberMobile.value,
      token,
    })
  }

  // ---- 本領域端點 --------------------------------------------------------------------------------

  async function fetchFlowLimit() {
    const res = await api.post('withdrawal/flow-limit', {})
    if (isApiOk(res)) store.setFlowLimit(res.data)
    return res
  }

  /** 成功後重抓 member/detail（flow_limit_application）與 flow-limit；失敗由呼叫端看 code */
  async function applyReleaseApplication() {
    const res = await api.post('audit/release-application', {})
    if (isApiOk(res)) await Promise.all([fetchMemberProfile(), fetchFlowLimit()])
    return res
  }

  /** 324、321 等錯誤碼的彈窗與導頁由 useApi 全域處理（profileRedirectFor） */
  async function fetchCheckBankAccount(type?: number) {
    const res = await api.post('withdrawal/check-bank-account', type === undefined ? {} : { type })
    if (isApiOk(res)) store.setCheckInfo(res.data)
    return res
  }

  async function fetchBanks(type?: number) {
    const res = await api.post('withdrawal/banks', type === undefined ? {} : { type })
    if (isApiOk(res)) store.setBanks(res.data)
    return res
  }

  function bindBankAccounts(accounts: BindAccountInput[]) {
    return api.post('withdrawal/bind-bank-account', { account: accounts })
  }

  /** 334（重設支付密碼）、319（登出）由 useApi 全域處理 */
  function confirmPaymentPassword(paymentPassword: [REDACTED] {
    return api.post('withdrawal/confirm-payment-password', { payment_password: [REDACTED] })
  }

  /** payload 由選定銀行組成；成功後重抓會員餘額與 flow-limit；324 的導頁由 useApi 全域處理 */
  async function createOrder(amount: AmountLike): Promise<ApiEnvelope<unknown>> {
    const selected = store.selectedBank
    if (!selected) {
      return { code: BFF_CODE.INVALID_REQUEST, message: 'no bank selected', data: null }
    }
    const res = await api.post('withdrawal/create-order', buildCreateOrderPayload(selected, amount))
    if (isApiOk(res)) {
      await Promise.all([fetchMemberProfile(), fetchFlowLimit()])
    }
    return res
  }

  return {
    store,
    isMultipleWallet,
    deductFlowLimit,
    paymentPasswordVisible,
    realNameVerifiedVisible,
    pointText,
    bsbCodeAlias,
    memberBalance,
    memberMobile,
    needSetPaymentPassword,
    verifyStep,
    remainingFlow,
    showRemainingFlow,
    canWithdraw,
    releaseState,
    availableAmount,
    fetchMemberProfile,
    checkEntryGate,
    sendSmsCode,
    checkSmsCode,
    fetchFlowLimit,
    applyReleaseApplication,
    fetchCheckBankAccount,
    fetchBanks,
    bindBankAccounts,
    confirmPaymentPassword,
    createOrder,
  }
}
