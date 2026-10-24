import { MEMBER_PATH } from '#shared/utils/member'
import { computed } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { BankAccountsData } from '#shared/api/endpoints/deposit'
import { isAcceptedImageDataUrl } from '#shared/utils/deposit'
import { useDepositStore } from '~/stores/deposit'

/** 銀行轉帳／自助上分（auto-deposit/*）。 */
export function useAutoDeposit() {
  const api = useApi()
  const store = useDepositStore()

  const bankOrder = computed(() => store.bankOrder)

  /** 不帶欄位 = 查回目前 CREATED 的訂單（bank-accounts 回 type 2 時用）。 */
  async function recoverBankOrder() {
    const res = await api.post('auto-deposit/order/create', {})
    if (isApiOk(res)) store.setBankOrder(res.data)
    return res
  }

  /** type 2 → 撈回待上傳的訂單直接進收據頁；type 1 → 回帳號列表給頁面選第一個。 */
  async function fetchBankAccounts(): Promise<{ res: ApiEnvelope<BankAccountsData>, recovered: boolean }> {
    const res = await api.post('auto-deposit/bank-accounts', {})
    if (isApiOk(res) && res.data.type === 2) {
      const recovered = await recoverBankOrder()
      if (isApiOk(recovered)) {
        await navigateTo('/topup/receipt')
        return { res, recovered: true }
      }
    }
    return { res, recovered: false }
  }

  async function createBankOrder(input: { bank_id: number, amount: number }) {
    const res = await api.post('auto-deposit/order/create', input)
    if (isApiOk(res)) {
      store.setBankOrder(res.data)
      await navigateTo('/topup/receipt')
    }
    return res
  }

  /** 取消訂單：不論結果都清掉本地訂單回 /my（舊前台也不等回應）。 */
  async function cancelBankOrder(orderId: number) {
    const res = await api.post('auto-deposit/order/delete', { order_id: orderId }).catch(() => null)
    store.clearBankOrder()
    await navigateTo(MEMBER_PATH.home)
    return res
  }

  /** 上傳憑證；沒有訂單或不是允許的 data URL 直接擋下不打 API。 */
  async function uploadReceipt(orderId: number, image: string) {
    if (!orderId || !isAcceptedImageDataUrl(image)) return null
    return api.post('auto-deposit/order/upload-image', { order_id: orderId, image })
  }

  /** 上傳成功提示關閉後：清掉訂單、replace 到 /my。 */
  async function finishReceipt() {
    store.clearBankOrder()
    await navigateTo(MEMBER_PATH.home, { replace: true })
  }

  return {
    bankOrder,
    fetchBankAccounts,
    recoverBankOrder,
    createBankOrder,
    cancelBankOrder,
    uploadReceipt,
    finishReceipt,
  }
}
