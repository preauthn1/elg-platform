import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import type { TransferBackData } from '#shared/api/endpoints/wallet'
import {
  buildMemberTransferRequest,
  shouldAutoTransferIntoVendor,
  shouldTransferOnVendorEntry,
  WALLET_IN_FLIGHT_CODE,
  type VendorEntryKind,
} from '#shared/utils/wallet'

type TransferRoute = 'member/transfer' | 'member/transfer-all' | 'member/transfer-back-recent'
type TransferEnvelope = ApiEnvelope<TransferBackData | null>

/**
 * 主錢包 ↔ 遊戲商轉帳與餘額刷新（member/transfer 系列）、遊戲商餘額、轉帳狀態查詢。
 * 三支轉帳端點共用後端會員鎖，前端用 walletStore.transferring 防重送：進行中再觸發直接回 WALLET_IN_FLIGHT_CODE。
 */
export function useWallet() {
  const api = useApi()
  const wallet = useWalletStore()
  const member = useMemberStore()
  const { site } = useSite()

  /** transaction_all：優先看會員資料，沒有就看站台模板。 */
  const transactionAll = computed(() => member.profile?.transaction_all ?? site.value?.raw.transaction_all)
  const autoTransferEnabled = computed(() => shouldAutoTransferIntoVendor(transactionAll.value))

  /** 轉帳成功後先把回傳的 balance / rolling_chip 直接寫回會員資料，再重抓一次。 */
  function patchMemberBalance(data: TransferBackData) {
    member.patchProfile({
      balance: String(data.balance),
      rolling_chip: String(data.rolling_chip),
    })
  }

  async function refreshMemberProfile(): Promise<ApiEnvelope<ResponseOf<'member/detail'>>> {
    const res = await api.post('member/detail', {})
    if (isApiOk(res)) member.setProfile(res.data)
    return res
  }

  async function refreshFlowLimit(): Promise<ApiEnvelope<ResponseOf<'withdrawal/flow-limit'>>> {
    const res = await api.post('withdrawal/flow-limit', {})
    if (isApiOk(res)) wallet.setFlowLimit(res.data.flow_limit, res.data.reach)
    return res
  }

  async function runTransfer(route: TransferRoute, body: { vendor_id?: number }): Promise<TransferEnvelope> {
    if (wallet.transferring) {
      return { code: WALLET_IN_FLIGHT_CODE, message: 'transfer in flight', data: null }
    }
    wallet.transferring = true
    try {
      const res = await api.post(route, body)
      if (isApiOk(res)) {
        // 不帶 vendor_id 的 member/transfer 只是刷新，舊版不寫遊戲商餘額（見 applyTransferResult）
        const keepVendorBalance = route === 'member/transfer' && body.vendor_id === undefined
        wallet.applyTransferResult(res.data, { keepVendorBalance })
        patchMemberBalance(res.data)
        await refreshMemberProfile()
      }
      return res
    }
    finally {
      wallet.transferring = false
    }
  }

  /**
   * 不帶 vendor_id：轉回上一家遊戲商並刷新餘額；帶 vendor_id：再把主錢包全額轉入該遊戲商。
   * 帶 vendor_id 時照舊版 singleWalletBalance 先清掉禮碼／遊戲錢包的舊值。
   */
  function transfer(vendorId?: number | null): Promise<TransferEnvelope> {
    const body = buildMemberTransferRequest(vendorId)
    if (body.vendor_id !== undefined && !wallet.transferring) wallet.clearSecondaryBalances()
    return runTransfer('member/transfer', body)
  }

  /** 餘額元件、錢包頁載入、互轉／兌換成功後都走這支。 */
  function refreshBalance(): Promise<TransferEnvelope> {
    return transfer()
  }

  /** 「重新整理餘額」：先清掉禮碼／遊戲錢包的舊值，後端漸進式轉回，連續按會檢查更多遊戲商。 */
  function transferAll(): Promise<TransferEnvelope> {
    if (!wallet.transferring) wallet.clearSecondaryBalances()
    return runTransfer('member/transfer-all', {})
  }

  function transferBackRecent(): Promise<TransferEnvelope> {
    return runTransfer('member/transfer-back-recent', {})
  }

  /**
   * 進遊戲商頁時轉入。要不要轉由 shouldTransferOnVendorEntry 決定（遊戲頁一律轉，
   * 其餘看 transaction_all）；不轉就回 null、不打 API，錢留在主錢包。
   */
  async function enterVendor(vendorId: number, kind: VendorEntryKind = 'vendor'): Promise<TransferEnvelope | null> {
    if (!shouldTransferOnVendorEntry(kind, transactionAll.value)) return null
    return transfer(vendorId)
  }

  /** 只支援轉帳錢包遊戲商；單一錢包遊戲商請直接顯示主錢包餘額。 */
  async function getVendorBalance(vendorId: number): Promise<ApiEnvelope<ResponseOf<'member/vendor-balance'>>> {
    const res = await api.post('member/vendor-balance', { vendor_id: vendorId })
    if (isApiOk(res)) wallet.setVendorBalance(res.data.vendor_balance)
    return res
  }

  function getMemberVendors(): Promise<ApiEnvelope<ResponseOf<'member/vendors'>>> {
    return api.post('member/vendors', {})
  }

  function checkTransferStatus(vendorId: number, transactionNo: string): Promise<ApiEnvelope<ResponseOf<'member/check-transfer-status'>>> {
    return api.post('member/check-transfer-status', { vendor_id: vendorId, transaction_no: transactionNo })
  }

  return {
    autoTransferEnabled,
    transfer,
    refreshBalance,
    transferAll,
    transferBackRecent,
    enterVendor,
    getVendorBalance,
    getMemberVendors,
    checkTransferStatus,
    refreshMemberProfile,
    refreshFlowLimit,
  }
}
