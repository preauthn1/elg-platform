import { bankOrderStatusKey, isImageFile, splitBsbAccountNo } from '#shared/utils/deposit'
import { downscaleReceiptImage, readReceiptFileAsDataUrl } from '~/utils/depositImage'

/**
 * 轉帳存款的單據（/topup/receipt）：顯示收款帳號、上傳轉帳截圖、取消訂單。
 * 所有皮共用，畫面在 components/<皮>/Page/TopupReceipt.vue。沒有訂單要導走是路由層的事。
 */
export function useDepositReceipt() {
  const { t } = useI18n()
  const { settings, flag } = useSite()
  const { bankOrder, uploadReceipt, cancelBankOrder, finishReceipt } = useAutoDeposit()

  const receiptDataUrl = ref<string | null>(null)
  const submitting = ref(false)
  const message = ref<string | null>(null)
  const showCancel = ref(false)
  const showSuccess = ref(false)
  const submittedAt = ref('')

  const enableBsb = computed(() => flag('enable_bsb_code'))
  const bsbAlias = computed(() => settings.value?.bsb_code_alias || 'BSB')
  const accountNo = computed(() => splitBsbAccountNo(bankOrder.value?.account_no, enableBsb.value))
  const statusKey = computed(() => bankOrderStatusKey(bankOrder.value?.status ?? -1))

  async function onFileChange(ev: Event) {
    const input = ev.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return
    if (!isImageFile(file.type)) {
      message.value = t('common.file.error.uploadFailed')
      input.value = ''
      return
    }
    try {
      receiptDataUrl.value = await readReceiptFileAsDataUrl(file)
    }
    catch {
      message.value = t('common.file.error.uploadFailed')
      input.value = ''
    }
  }

  async function submit() {
    const order = bankOrder.value
    if (!order || !order.order_id || !receiptDataUrl.value || submitting.value) return
    submitting.value = true
    try {
      const image = await downscaleReceiptImage(receiptDataUrl.value)
      const res = await uploadReceipt(order.order_id, image)
      if (!res) {
        message.value = t('common.file.error.uploadFailed')
        return
      }
      if (!isApiOk(res)) return
      submittedAt.value = new Date().toLocaleString()
      showSuccess.value = true
    }
    catch {
      message.value = t('common.file.error.uploadFailed')
    }
    finally {
      submitting.value = false
    }
  }

  async function confirmCancel() {
    showCancel.value = false
    const id = bankOrder.value?.order_id
    if (id) await cancelBankOrder(id)
  }

  async function onSuccessClose() {
    showSuccess.value = false
    await finishReceipt()
  }

  return {
    bankOrder,
    receiptDataUrl,
    submitting,
    message,
    showCancel,
    showSuccess,
    submittedAt,
    bsbAlias,
    accountNo,
    statusKey,
    onFileChange,
    submit,
    confirmCancel,
    onSuccessClose,
  }
}
