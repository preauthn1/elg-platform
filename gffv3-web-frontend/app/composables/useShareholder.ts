import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import {
  applyButtonKey,
  resolveApplyStatus,
  validateShareholderForm,
  type ShareholderApplyForm,
  type ShareholderFormError,
} from '#shared/utils/agent'

type Profit = ResponseOf<'shareholder/profit'>

/**
 * 聯盟股東：分潤總覽與會員申請成為代理（股東）。
 * 入口由 member_upgrade_agent_visible 控制（底部導覽，屬 layout 領域）。
 */
export function useShareholder() {
  const api = useApi()
  const { flag } = useSite()

  const visible = computed(() => flag('member_upgrade_agent_visible'))
  const profit = ref<Profit | null>(null)
  /** apis 沒回 apply_status 時視為 0（可申請） */
  const applyStatus = computed(() => resolveApplyStatus(profit.value))
  const applyButtonLabelKey = computed(() => applyButtonKey(applyStatus.value))
  const canApply = computed(() => applyStatus.value === 0)
  const formError = ref<ShareholderFormError | null>(null)

  async function fetchProfit() {
    const res = await api.post('shareholder/profit', {})
    if (isApiOk(res)) profit.value = res.data
    return res
  }

  function validate(form: ShareholderApplyForm): ShareholderFormError | null {
    formError.value = validateShareholderForm(form)
    return formError.value
  }

  /** 表單不合前端規則回 null（formError 有值）；否則回後端信封，頁面成功後自行回上一頁。 */
  async function apply(form: ShareholderApplyForm): Promise<ApiEnvelope<unknown> | null> {
    if (validate(form)) return null
    return api.post('shareholder/apply', {
      username: form.username.trim(),
      password: [REDACTED],
      password_confirmation: form.password_confirmation,
    })
  }

  return {
    visible,
    profit,
    applyStatus,
    applyButtonLabelKey,
    canApply,
    formError,
    fetchProfit,
    validate,
    apply,
  }
}
