import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import {
  couponUnitKey,
  normalizeCouponCode,
  validateCouponCode,
} from '#shared/utils/wallet'
import { decFormat } from '#shared/utils/amount'

type CouponRedeemData = ResponseOf<'coupon/redeem'>
type CouponPromptKey = NonNullable<ReturnType<typeof validateCouponCode>>

/**
 * 兌換優惠碼（coupon/redeem）。頁面只在 customized_setting.coupon_code_visible 開啟時才可用；
 * 成功後刷新餘額與稽核流水；code 324 由 useApi 全域處理（彈訊息、關掉後導去綁手機）。
 */
export function useCoupon() {
  const api = useApi()
  const { flag, settings } = useSite()
  const { refreshBalance, refreshFlowLimit } = useWallet()

  const enabled = computed(() => flag('coupon_code_visible'))

  const submitting = ref(false)
  /** 舊版失敗時重設為 0。 */
  const result = ref<CouponRedeemData | 0>(0)
  const promptKey = ref<CouponPromptKey | null>(null)

  async function redeem(rawCode: string): Promise<ApiEnvelope<CouponRedeemData> | null> {
    promptKey.value = null
    const invalid = validateCouponCode(rawCode)
    if (invalid) {
      promptKey.value = invalid
      return null
    }
    if (submitting.value) return null
    submitting.value = true
    try {
      const res = await api.post('coupon/redeem', { code: normalizeCouponCode(rawCode) })
      if (isApiOk(res)) {
        result.value = res.data
        await refreshBalance()
        await refreshFlowLimit()
      }
      else {
        // 錯誤訊息的彈窗（含 324 綁手機關掉後導頁）由 useApi 全域處理，跟舊版一樣
        result.value = 0
      }
      return res
    }
    finally {
      submitting.value = false
    }
  }

  /** 成功文案：金額 2 位小數 + 單位（type 2 禮碼名稱，其餘平台點數名稱）。 */
  const successText = computed(() => {
    if (!result.value) return null
    const unit = settings.value?.[couponUnitKey(result.value.type)] ?? ''
    return {
      amount: decFormat(result.value.amount, 2),
      unit: unit ?? '',
      imageUrl: result.value.image_url ?? null,
    }
  })

  function clearResult() {
    result.value = 0
  }

  return {
    enabled,
    submitting,
    result,
    promptKey,
    redeem,
    successText,
    clearResult,
  }
}
