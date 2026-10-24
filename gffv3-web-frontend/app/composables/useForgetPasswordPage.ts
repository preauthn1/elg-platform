import { computed, reactive, ref } from 'vue'
import { normalizeMobile, normalizeOtp } from '#shared/utils/auth'

/** /forget-password 的邏輯，所有皮共用，畫面在 components/<皮>/Page/ForgetPassword.vue。 */
export function useForgetPasswordPage() {
  const { t } = useI18n()
  const { checkCode } = useGuestValidationCode()

  const form = reactive({
    verify_by: 1 as 1 | 2,
    country_code: '',
    phone: '',
    email: '',
    username: '',
    token: '',
  })
  const mobile = computed(() => normalizeMobile(form.country_code, form.phone))
  const sent = ref(false)
  const loading = ref(false)
  const message = ref('')

  /** 國碼清單與註冊表單同一支，預設選第一筆。 */
  const { countries, ensureCountries, defaultCountry } = useCountries()
  onMounted(async () => {
    await ensureCountries()
    if (!form.country_code) form.country_code = defaultCountry()?.country_code ?? ''
  })

  const canSubmit = computed(() => !loading.value && normalizeOtp(form.token).length === 6)

  async function submit() {
    if (!canSubmit.value) return
    loading.value = true
    message.value = ''
    await checkCode({
      type: 2,
      token: [REDACTED],
      mobile: form.verify_by === 1 ? mobile.value : undefined,
      email: form.verify_by === 2 ? form.email : undefined,
    })
    loading.value = false
  }

  /** 已登入卻打訪客型發碼：顯示 illegalLoginStatus 並回首頁 */
  async function onIllegal() {
    message.value = t('auth.session.error.illegalLogin')
    await navigateTo('/')
  }

  const TABS = [
    { value: 1 as const, labelKey: 'auth.otp.label.phoneVerification' },
    { value: 2 as const, labelKey: 'auth.otp.title.emailVerification' },
  ]

  return {
    form,
    mobile,
    sent,
    message,
    countries,
    canSubmit,
    submit,
    onIllegal,
    TABS,
  }
}
