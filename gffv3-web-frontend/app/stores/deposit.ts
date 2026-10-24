import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { BankOrder, DepositResult, OrderType } from '#shared/api/endpoints/deposit'
import { qrPayloadOf } from '#shared/utils/deposit'

/** 建單結果，給 /deposit/qrcode、/deposit/image 導頁後讀。 */
export interface DepositResultState {
  type: number
  url: string
  code: string
  html: string
  orderNo: string
  /** QR 內容（url 優先，退回 code） */
  qrPayload: string
}

/**
 * 存款領域跨頁狀態：支付方式列表、線上支付建單結果、銀行轉帳訂單。
 * 鍵名照後端，不轉 camelCase。
 */
export const useDepositStore = defineStore('deposit', () => {
  const paymentTypes = ref<OrderType[]>([])
  const paymentTypesLoadedAt = ref<number | null>(null)
  const depositResult = ref<DepositResultState | null>(null)
  const bankOrder = ref<BankOrder | null>(null)

  function setPaymentTypes(list: OrderType[]) {
    paymentTypes.value = list
    paymentTypesLoadedAt.value = Date.now()
  }

  function setDepositResult(result: DepositResult | null) {
    depositResult.value = result
      ? {
          type: result.type,
          url: result.url ?? '',
          code: result.code === null || result.code === undefined ? '' : String(result.code),
          html: result.html ?? '',
          orderNo: result.orderNo ?? '',
          qrPayload: qrPayloadOf(result),
        }
      : null
  }

  function setBankOrder(order: BankOrder | null) {
    bankOrder.value = order
  }

  function clearBankOrder() {
    bankOrder.value = null
  }

  function reset() {
    paymentTypes.value = []
    paymentTypesLoadedAt.value = null
    depositResult.value = null
    bankOrder.value = null
  }

  return {
    paymentTypes,
    paymentTypesLoadedAt,
    depositResult,
    bankOrder,
    setPaymentTypes,
    setDepositResult,
    setBankOrder,
    clearBankOrder,
    reset,
  }
})
