import { useDepositStore } from '~/stores/deposit'

/**
 * 線上存款送出後的結果頁（/deposit/image、/deposit/qrcode）：讀存在 store 的結果、複製 QR 內容。
 * 所有皮共用。沒有結果要導回 /topup 是路由層的事，在 pages/ 裡做。
 */
export function useDepositResult() {
  const store = useDepositStore()
  const result = computed(() => store.depositResult)
  const copied = ref(false)

  async function copyPayload() {
    const payload = result.value?.qrPayload
    if (!payload || !import.meta.client) return
    try {
      await navigator.clipboard.writeText(payload)
      copied.value = true
    }
    catch {
      copied.value = false
    }
  }

  return { result, copied, copyPayload }
}
