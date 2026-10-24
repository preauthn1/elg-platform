/**
 * 修改登入密碼：長度 6～12、兩次一致才能送出，成功後強制登出重新登入。
 * 所有皮共用，畫面在 components/<皮>/Page/MemberChangePassword.vue。
 */
export function useChangePasswordForm() {
  const member = useMember()
  const { clear } = useUserSession()

  const password = [REDACTED]'')
  const newPassword = [REDACTED]'')
  const confirmation = ref('')
  const submitting = ref(false)
  const success = ref(false)

  const lengthOk = (v: string) => v.length >= 6 && v.length <= 12
  const canSubmit = computed(() => lengthOk(password.value) && lengthOk(newPassword.value) && newPassword.value === confirmation.value)

  async function submit() {
    if (!canSubmit.value || submitting.value) return
    submitting.value = true
    const res = await member.changePassword({
      password: [REDACTED],
      new_password: [REDACTED],
      new_password_confirmation: confirmation.value,
    })
    submitting.value = false
    if (!isApiOk(res)) return
    success.value = true
  }

  /** 照舊版 C13 `Page/ChangePassword.vue`：成功彈「密碼已更新，請重新登入」，關掉就強制登出。 */
  async function logout() {
    await clear()
    await navigateTo('/login')
  }

  return {
    password,
    newPassword,
    confirmation,
    submitting,
    success,
    canSubmit,
    submit,
    logout,
  }
}
