import type { RealnameValidationInput } from '#shared/api/endpoints/member'
import {
  normalizeRealnameFields,
  realnamePrefillFromBankInfo,
  resolveRealnameForm,
  type BankOptionLike,
  type RealnameFieldRule,
  type RealnameForm,
  type RealnamePrefill,
} from '#shared/utils/member'

/**
 * 實名認證申請（建立審核單），與 member/set-realname 直接寫 first_name 不同。
 */
export function useRealnameValidation() {
  const api = useApi()

  /** 實名頁用：fields 決定表單多收哪些欄位（能不能進頁由 middleware/realname.global.ts 判斷）。 */
  async function check(): Promise<{
    ok: boolean
    message: string
    fields: Record<string, RealnameFieldRule>
    form: RealnameForm
  }> {
    const res = await api.post('realname-validation/check', {})
    const fields = isApiOk(res) ? normalizeRealnameFields(res.data.fields) : {}
    return {
      ok: isApiOk(res),
      message: res.message,
      fields,
      form: resolveRealnameForm(fields),
    }
  }

  /** 路由守衛用：只要審核狀態。查詢失敗回 null（錯誤訊息照常彈出），守衛放行。 */
  async function fetchStatus(): Promise<{ is_exists: boolean, is_validated: boolean } | null> {
    const res = await api.post('realname-validation/check', {})
    return isApiOk(res) ? { is_exists: res.data.is_exists, is_validated: res.data.is_validated } : null
  }

  /** 銀行清單與已綁卡預填都來自提款域；任一失敗就當沒有，表單退回手填。 */
  async function loadBankContext(): Promise<{ banks: BankOptionLike[], prefill: RealnamePrefill | null }> {
    const [banksRes, infoRes] = await Promise.all([
      api.post('withdrawal/banks', {}),
      api.post('withdrawal/check-bank-account', {}),
    ])
    const banks: BankOptionLike[] = isApiOk(banksRes) ? banksRes.data : []
    const prefill = isApiOk(infoRes) ? realnamePrefillFromBankInfo(infoRes.data, banks) : null
    return { banks, prefill }
  }

  /** 送出後一律當「已送出待審」，自動核准與否前端不區分。 */
  function submit(input: RealnameValidationInput) {
    return api.post('realname-validation', input)
  }

  return { check, fetchStatus, loadBankContext, submit }
}
