import type { SendCodeInput } from '#shared/api/endpoints/member'
import { hasValue } from '#shared/utils/member'
import { isOn } from '#shared/utils/site'
import { CODE_TYPE, VERIFY_BY, normalizeCode } from '#shared/utils/validation-code'

type Channel = 'mobile' | 'email'

/**
 * IP 變更登入驗證開關（所有皮共用，畫面在 components/<皮>/Page/MemberIpChangeVerification.vue）：切換前先用型別 11 對該管道驗一次碼，
 * 通過才打 update-profile 寫 ip_change_mobile_verify / ip_change_email_verify。
 * 版面照舊前台 C13 IPChangeVerification.vue：每個已綁定管道一列開關，點了開驗證碼彈窗。
 */
export function useIpChangeVerification() {
  const member = useMember()
  const codes = useValidationCode()

  const editing = ref<Channel | null>(null)
  const code = ref('')
  const submitting = ref(false)

  const mobile = computed(() => (hasValue(member.profile.value?.mobile) ? String(member.profile.value?.mobile) : ''))
  const email = computed(() => (hasValue(member.profile.value?.email) ? String(member.profile.value?.email) : ''))
  const mobileOn = computed(() => isOn(member.profile.value?.ip_change_mobile_verify))
  const emailOn = computed(() => isOn(member.profile.value?.ip_change_email_verify))
  /** 手機號碼存的是含國碼的純數字，舊前台顯示時補 + */
  const mobileDisplay = computed(() => (mobile.value ? `+${mobile.value}` : ''))

  /** 照舊前台：沒綁定的管道整列不顯示 */
  const rows = computed(() => [
    ...(mobile.value ? [{ channel: 'mobile' as const, labelKey: 'auth.common.label.phoneNumber', display: mobileDisplay.value, on: mobileOn.value }] : []),
    ...(email.value ? [{ channel: 'email' as const, labelKey: 'auth.common.label.email', display: email.value, on: emailOn.value }] : []),
  ])

  const payload = computed<SendCodeInput | null>(() => {
    if (editing.value === 'mobile') return { type: CODE_TYPE.VERIFY, verify_by: VERIFY_BY.MOBILE, mobile: mobile.value }
    if (editing.value === 'email') return { type: CODE_TYPE.VERIFY, verify_by: VERIFY_BY.EMAIL, email: email.value }
    return null
  })

  onMounted(() => {
    void member.ensureLoaded()
  })

  function startToggle(channel: Channel) {
    editing.value = channel
    code.value = ''
  }

  function cancel() {
    editing.value = null
    code.value = ''
  }

  async function confirmToggle() {
    const channel = editing.value
    const token = [REDACTED]
    if (!channel || token.length !== 6 || submitting.value) return
    submitting.value = true
    const check = await codes.checkCode({
      token,
      type: CODE_TYPE.VERIFY,
      ...(channel === 'mobile' ? { mobile: mobile.value } : { email: email.value }),
    })
    if (!isApiOk(check)) {
      submitting.value = false
      return
    }
    const res = await member.toggleIpChangeVerify(channel)
    submitting.value = false
    if (!isApiOk(res)) return
    cancel()
  }

  return {
    editing,
    code,
    submitting,
    email,
    mobileDisplay,
    rows,
    payload,
    startToggle,
    cancel,
    confirmToggle,
  }
}
