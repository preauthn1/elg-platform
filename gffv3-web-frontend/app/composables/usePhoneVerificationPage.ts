import { computed, reactive, ref } from 'vue'
import { normalizeMobile, normalizeOtp } from '#shared/utils/auth'

/** /phone-verification 的邏輯，所有皮共用，畫面在 components/<皮>/Page/PhoneVerification.vue。 */
export function usePhoneVerificationPage() {
  /**
   * 手機/信箱驗證（type=1）：登入後綁定（member/send-validation-code）或註冊後驗證；
   * 驗證後已登入回 /、未登入用註冊密碼自動登入。?verify_by=2 為信箱。
   */
  const route = useRoute()
  const { loggedIn } = useUserSession()
  const { checkCode } = useGuestValidationCode()

  const form = reactive({
    verify_by: (route.query.verify_by === '2' ? 2 : 1) as 1 | 2,
    country_code: '',
    phone: '',
    email: '',
    token: '',
  })
  const mobile = computed(() => normalizeMobile(form.country_code, form.phone))
  const loading = ref(false)

  async function submit() {
    loading.value = true
    await checkCode({
      type: 1,
      token: [REDACTED],
      mobile: form.verify_by === 1 ? mobile.value : undefined,
      email: form.verify_by === 2 ? form.email : undefined,
    })
    loading.value = false
  }

  return {
    loggedIn,
    form,
    mobile,
    loading,
    submit,
  }
}
