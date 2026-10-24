import { computed, ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { OrderType } from '#shared/api/endpoints/deposit'
import {
  classifyDepositResult,
  normalizeGroupItem,
  type CreateOrderBody,
  type DepositOutcome,
  type DepositResultContext,
} from '#shared/utils/deposit'
import { useDepositStore } from '~/stores/deposit'

export interface NormalizedOrderTypeGroup {
  type: string | null | undefined
  items: OrderType[]
}

function writeHtmlDocument(doc: Document, html: string) {
  doc.open()
  doc.write(html)
  doc.close()
}

/**
 * 線上支付（deposit/*）：取支付方式、子渠道、建單，並依回傳 type 分流。
 * 需要使用者確認後才能做的事（開新視窗、成功提示）交回頁面，其餘副作用在這裡完成。
 */
export function useDeposit() {
  const api = useApi()
  const store = useDepositStore()

  const paymentTypes = computed(() => store.paymentTypes)
  const submitting = ref(false)

  /** 323／9014（要先設支付密碼）由 useApi 全域處理：彈訊息、關掉後導設定支付密碼頁 */
  async function fetchTopUpTypes() {
    const res = await api.post('deposit/top-up-types', {})
    if (isApiOk(res)) store.setPaymentTypes(res.data)
    return res
  }

  /** 已有列表就不重打（/topup 進入時已抓過，子元件直接沿用）。 */
  async function ensureTopUpTypes() {
    if (store.paymentTypes.length > 0) return null
    return fetchTopUpTypes()
  }

  /** 分組版列表；items 對齊成 top-up-types 的鍵名（cf_id / PopupText）。 */
  async function fetchDepositTypes(): Promise<ApiEnvelope<NormalizedOrderTypeGroup[] | null>> {
    const res = await api.post('deposit/types', {})
    if (isApiOk(res)) {
      return { ...res, data: res.data.map(g => ({ type: g.type, items: g.items.map(normalizeGroupItem) })) }
    }
    return { ...res, data: null }
  }

  async function fetchTopUpChannels(orderTypeId: number) {
    return api.post('deposit/top-up-channels', { order_type_id: orderTypeId })
  }

  async function createOrder(body: CreateOrderBody) {
    const res = await api.post('deposit/create-order', body)
    if (isApiOk(res)) store.setDepositResult(res.data)
    return res
  }

  /**
   * 送出建單並分流：redirect / write-html / qrcode / image 直接執行；
   * open-window*、success 需使用者確認，回給頁面；error / stay / return 解除送出鎖。
   * 成功導頁的情況維持 submitting = true，避免導頁前重複送出。
   */
  async function submitDeposit(body: CreateOrderBody, ctx: DepositResultContext): Promise<DepositOutcome> {
    submitting.value = true
    let outcome: DepositOutcome
    try {
      const res = await createOrder(body)
      outcome = classifyDepositResult(res, ctx)
    }
    catch (error) {
      outcome = { kind: 'error', message: error instanceof Error ? error.message : String(error) }
    }

    switch (outcome.kind) {
      case 'qrcode':
        await navigateTo('/deposit/qrcode')
        break
      case 'image':
        await navigateTo('/deposit/image')
        break
      case 'redirect':
        if (import.meta.client) window.location.assign(outcome.url)
        break
      case 'write-html':
        if (import.meta.client) writeHtmlDocument(document, outcome.html)
        break
      case 'error':
      case 'stay':
      case 'return':
        submitting.value = false
        break
      default:
        break
    }
    return outcome
  }

  /** 使用者確認後開新視窗：cf_id 25 / order_type_id 66 開 url；cf_id 26 把表單 html 寫進新視窗。 */
  function openInNewWindow(outcome: DepositOutcome): boolean {
    if (!import.meta.client) return false
    if (outcome.kind === 'open-window') {
      return window.open(outcome.url, '_blank') !== null
    }
    if (outcome.kind === 'open-window-html') {
      const w = window.open('', '_blank')
      if (!w) return false
      writeHtmlDocument(w.document, outcome.html)
      return true
    }
    return false
  }

  return {
    paymentTypes,
    submitting,
    fetchTopUpTypes,
    ensureTopUpTypes,
    fetchDepositTypes,
    fetchTopUpChannels,
    createOrder,
    submitDeposit,
    openInNewWindow,
  }
}
