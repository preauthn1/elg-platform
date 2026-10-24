import { reactive, ref } from 'vue'
import { useAuthStore } from '~/stores/auth'

/** /set-password 的邏輯，所有皮共用，畫面在 components/<皮>/Page/SetPassword.vue。 */
export function useSetPasswordPage() {
  const store = useAuthStore()
  const { resetPassword } = useGuestValidationCode()

  const form = reactive({ new_password: '', new_password_confirmation: '' })
  const loading = ref(false)
  const mismatch = ref(false)
  const shown = reactive({ pw: false, confirm: false })

  const canSubmit = computed(() =>
    form.new_password.length > 0 && form.new_password_confirmation.length > 0 && !loading.value,
  )

  async function submit() {
    mismatch.value = form.new_password !== form.new_password_confirmation
    if (mismatch.value) return
    loading.value = true
    await resetPassword({ ...form })
    loading.value = false
  }

  return {
    store,
    form,
    loading,
    mismatch,
    shown,
    canSubmit,
    submit,
  }
}
