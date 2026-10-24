import { isOn } from '#shared/utils/site'
import { normalizeCode } from '#shared/utils/validation-code'

/**
 * Google 2FA 設定（所有皮共用，畫面在 components/<皮>/Page/MemberGoogleAuthenticator.vue），照舊版 C13 `Page/GoogleAuthenticator.vue`：
 * 說明文字＋開關、下面固定顯示 QR（inline SVG，用 v-html）與 secret。
 * 開關打開要先在彈窗驗一次 TOTP 才寫 has_2fa=1，關閉直接寫 has_2fa=0。
 */
export function useGoogleAuthSetting() {
  const member = useMember()

  const image = ref('')
  const secret = [REDACTED]'')
  const code = ref('')
  const submitting = ref(false)
  const showCodeModal = ref(false)

  const enabled = computed(() => isOn(member.profile.value?.has_2fa))

  onMounted(async () => {
    await member.ensureLoaded()
    const res = await member.getGoogleAuthImage()
    if (!isApiOk(res)) return
    image.value = res.data.image
    secret.value = res.data.secret
  })

  async function toggle() {
    if (submitting.value) return
    if (!enabled.value) {
      showCodeModal.value = true
      return
    }
    submitting.value = true
    await member.disableGoogleAuth()
    submitting.value = false
  }

  function closeModal() {
    showCodeModal.value = false
    code.value = ''
  }

  async function enable() {
    const totp = normalizeCode(code.value)
    if (totp.length !== 6 || submitting.value) return
    submitting.value = true
    const res = await member.enableGoogleAuth(totp)
    submitting.value = false
    if (isApiOk(res)) closeModal()
  }

  return {
    image,
    secret,
    code,
    submitting,
    showCodeModal,
    enabled,
    toggle,
    closeModal,
    enable,
  }
}
