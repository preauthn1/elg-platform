import type { SendCodeInput } from '#shared/api/endpoints/member'
import { MEMBER_PATH } from '#shared/utils/member'
import {
  CODE_TYPE,
  VERIFY_BY,
  joinMobile,
  normalizeCode,
} from '#shared/utils/validation-code'

/**
 * 找回支付密碼（所有皮共用，畫面在 components/<皮>/Page/MemberResetPaymentPassword.vue），照舊版 C13 `Page/PaymentPassword.vue` 的 isReset 分支：
 *   新密碼 (1/2) → 確認 (2/2) → 驗證（切換手機／E-mail，自己輸入號碼或信箱後發碼）
 * 型別 6 發碼時就帶新支付密碼，驗碼成功後端才套用；不走 change-payment-password。
 */
export function useResetPaymentPassword() {
  const codes = useValidationCode()
  const { t } = useI18n()
  const form = useTemplateRef<{ back: () => boolean }>('form')

  /** 前兩步填好的新密碼；有值就是在驗證步驟 */
  const passwords = ref<{ next: string, confirm: string } | null>(null)
  const channel = ref<'mobile' | 'email'>('mobile')
  const number = ref('')
  const email = ref('')
  const code = ref('')
  const submitting = ref(false)
  const success = ref(false)

  const { countries, ensureCountries, defaultCountry } = useCountries()
  const countryCode = ref('')
  onMounted(async () => {
    await ensureCountries()
    if (!countryCode.value) countryCode.value = defaultCountry()?.country_code ?? ''
  })

  const target = computed(() => (channel.value === 'mobile' ? joinMobile(countryCode.value, number.value) : email.value.trim()))
  const canSend = computed(() => (channel.value === 'mobile' ? target.value.length >= 7 : /^\S+@\S+\.\S+$/.test(target.value)))

  const payload = computed<SendCodeInput>(() => ({
    type: CODE_TYPE.FORGET_PAYMENT_PASSWORD,
    verify_by: channel.value === 'mobile' ? VERIFY_BY.MOBILE : VERIFY_BY.EMAIL,
    ...(channel.value === 'mobile' ? { mobile: target.value } : { email: target.value }),
    payment_password: [REDACTED] ?? '',
    payment_password_confirmation: passwords.value?.confirm ?? '',
  }))

  async function submit() {
    const token = [REDACTED]
    if (token.length !== 6 || submitting.value || success.value) return
    submitting.value = true
    const res = await codes.checkCode({
      token,
      type: CODE_TYPE.FORGET_PAYMENT_PASSWORD,
      ...(channel.value === 'mobile' ? { mobile: target.value } : { email: target.value }),
    })
    submitting.value = false
    if (!isApiOk(res)) return
    success.value = true
    // 照舊版：成功彈提示，關掉後回會員中心
    useAlert().show(t('member.paymentPassword.msg.changeSuccess'), 'success', { redirect: MEMBER_PATH.home, replace: true })
  }

  /** 標題列返回：驗證步驟退回確認密碼，其餘交給密碼表單逐步往回退。 */
  function stepBack(): boolean {
    if (success.value) return false
    if (passwords.value) {
      passwords.value = null
      code.value = ''
      return true
    }
    return form.value?.back() ?? false
  }

  return {
    t,
    form,
    passwords,
    channel,
    number,
    email,
    code,
    submitting,
    success,
    countries,
    countryCode,
    canSend,
    payload,
    submit,
    stepBack,
  }
}
