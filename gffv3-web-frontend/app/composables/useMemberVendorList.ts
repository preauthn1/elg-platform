import type { ResponseOf } from '#shared/api/registry'
import { decFormat } from '#shared/utils/amount'

/**
 * 會員的遊戲商清單（/wallet/vendors）：列出遊戲商、逐一查遊戲商餘額、用交易編號查轉帳狀態。
 * 所有皮共用，畫面在 components/<皮>/Page/WalletVendors.vue。
 */
export function useMemberVendorList() {
  const { getMemberVendors, getVendorBalance, checkTransferStatus } = useWallet()

  const vendors = ref<ResponseOf<'member/vendors'>>([])
  const balances = ref<Record<number, string>>({})
  const loading = ref(false)

  const statusVendorId = ref<number | null>(null)
  const transactionNo = ref('')
  const statusResult = ref('')
  const checking = ref(false)

  async function load() {
    loading.value = true
    try {
      const res = await getMemberVendors()
      if (isApiOk(res)) vendors.value = res.data
    }
    finally {
      loading.value = false
    }
  }

  async function queryBalance(vendorId: number) {
    const res = await getVendorBalance(vendorId)
    if (!isApiOk(res)) return
    balances.value = {
      ...balances.value,
      [vendorId]: decFormat(res.data.vendor_balance, 2),
    }
  }

  async function onCheckStatus() {
    const no = transactionNo.value.trim()
    if (!statusVendorId.value || !no) return
    checking.value = true
    statusResult.value = ''
    try {
      const res = await checkTransferStatus(statusVendorId.value, no)
      if (isApiOk(res)) statusResult.value = res.data.result
    }
    finally {
      checking.value = false
    }
  }

  onMounted(() => {
    void load()
  })

  return {
    vendors,
    balances,
    loading,
    statusVendorId,
    transactionNo,
    statusResult,
    checking,
    queryBalance,
    onCheckStatus,
  }
}
