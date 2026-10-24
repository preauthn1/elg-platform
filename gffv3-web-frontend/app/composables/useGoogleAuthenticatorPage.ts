import { onMounted, ref } from 'vue'
import { normalizeOtp } from '#shared/utils/auth'

/** /google-authenticator 的邏輯，所有皮共用，畫面在 components/<皮>/Page/GoogleAuthenticator.vue。 */
export function useGoogleAuthenticatorPage() {
  /** 會員綁定/解除 Google 2FA：顯示 QRCode 與 secret，輸入動態碼驗證後更新 has_2fa */
  const { t } = useI18n()
  const { has2fa, ensureProfile, fetchImage, enable, disable } = useGoogleAuth()
  const { logout } = useAuth()

  const image = ref('')
  const secret = [REDACTED]'')
  const code = ref('')
  const loading = ref(false)

  onMounted(async () => {
    await ensureProfile()
    const res = await fetchImage()
    if (!isApiOk(res)) return
    image.value = res.data.image
    secret.value = res.data.secret
  })

  async function onEnable() {
    const otp = normalizeOtp(code.value)
    if (otp.length !== 6) return
    loading.value = true
    const { res, status } = await enable(otp)
    loading.value = false
    if (status === 'ok') {
      useAlert().show(t('common.ui.label.enabled'), 'success')
      code.value = ''
      return
    }
    // google-auth/auth 是 silent，驗碼失敗要自己彈；update-profile 失敗 useApi 已彈過同一則，覆蓋不會多一個
    useAlert().showResponse(res)
    // 驗證碼錯誤達上限帳號已鎖定，後端撤銷 token，這裡直接登出
    if (status === 'locked') await logout()
  }

  async function onDisable() {
    loading.value = true
    await disable()
    loading.value = false
  }

  return {
    has2fa,
    image,
    secret,
    code,
    loading,
    onEnable,
    onDisable,
  }
}
