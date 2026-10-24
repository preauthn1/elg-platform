import { computed, reactive, ref } from 'vue'
import { normalizeMobile, normalizeOtp } from '#shared/utils/auth'
import { useAuthStore } from '~/stores/auth'

/** /retrieve-password 的邏輯，所有皮共用，畫面在 components/<皮>/Page/RetrievePassword.vue。 */
export function useRetrievePasswordPage() {
  /** 找回密碼（type=3）：登入密碼錯誤達上限（322）後導入，帶 guest_validate_token 發碼/驗碼 → /set-password */
  const store = useAuthStore()
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
  const loading = ref(false)

  async function submit() {
    loading.value = true
    await checkCode({
      type: 3,
      token: [REDACTED],
      mobile: form.verify_by === 1 ? mobile.value : undefined,
      email: form.verify_by === 2 ? form.email : undefined,
      guest_validate_token: [REDACTED],
    })
    loading.value = false
  }

  return {
    store,
    form,
    mobile,
    loading,
    submit,
  }
}
