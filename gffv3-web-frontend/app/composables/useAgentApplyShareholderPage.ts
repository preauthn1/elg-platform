/** /agent/apply-shareholder 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AgentApplyShareholder.vue。 */
export function useAgentApplyShareholderPage() {
  /** 會員申請成為代理（股東）：username／password／password_confirmation → shareholder/apply，成功後回上一頁。 */
  const router = useRouter()
  const { t } = useI18n()
  const { formError, apply } = useShareholder()

  const form = reactive({ username: '', password: '', password_confirmation: '' })
  const submitted = ref(false)

  const formErrorText = computed(() => {
    switch (formError.value) {
      case 'username':
        return `${t('agent.shareholder.label.account')} 4-20`
      case 'password':
        return `${t('auth.common.label.password')} 4-15`
      case 'inconsistentPassword':
        return t('validation.password.error.mismatch')
      default:
        return ''
    }
  })

  async function submit() {
    const res = await apply({ ...form })
    if (!res || !isApiOk(res)) return
    submitted.value = true
    useAlert().show(t('common.ui.msg.success'), 'success')
  }

  function back() {
    router.back()
  }

  return {
    form,
    submitted,
    formErrorText,
    submit,
    back,
  }
}
