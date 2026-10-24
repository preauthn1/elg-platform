import { computed, ref } from 'vue'
import type { TwoFactorOption } from '#shared/utils/auth'
import { normalizeOtp } from '#shared/utils/auth'
import { useAuthStore } from '~/stores/auth'

/** /two-factor-authentication 的邏輯，所有皮共用，畫面在 components/<皮>/Page/TwoFactorAuthentication.vue。 */
export function useTwoFactorAuthenticationPage() {
  /**
   * 登入二次驗證：Google Authenticator 或 IP 變更 OTP（手機/信箱擇一，guest/send-validation-code type=10），
   * 帶 do_2fa + secret 重送登入；沒有暫存的登入參數退回 /login。
   */
  const store = useAuthStore()
  const { loginWithTwoFactor } = useAuth()

  const options = computed<TwoFactorOption[]>(() => store.pendingLogin?.options ?? [])
  const selectedIndex = ref(0)
  const selected = computed(() => options.value[selectedIndex.value] ?? null)
  const code = ref('')
  const loading = ref(false)
  const alert = useAlert()
  const locked = ref(false)

  function optionLabel(o: TwoFactorOption): string {
    if (o.type === 'google_auth') return 'Google Authenticator'
    return o.type === 'mobile' ? `+${o.target}` : `${o.target}`
  }

  async function submit() {
    if (!selected.value) return
    const secret = [REDACTED]
    if (secret.length !== 6) return
    loading.value = true
    const result = await loginWithTwoFactor(selected.value.type, secret)
    loading.value = false
    if (!result) {
      await navigateTo('/login', { replace: true })
      return
    }
    // 登入走 postRaw，不經過 useApi 的全站錯誤彈窗，要自己彈（同 login.vue）
    if (result.outcome.kind === 'locked') locked.value = true
    if (result.outcome.kind === 'locked' || result.outcome.kind === 'error') {
      alert.show(result.res.message, 'error', { tid: result.res.tid })
    }
  }

  return {
    store,
    options,
    selectedIndex,
    selected,
    code,
    loading,
    locked,
    optionLabel,
    submit,
  }
}
