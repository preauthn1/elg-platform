import { defineStore } from 'pinia'
import type { TransferBackData } from '#shared/api/endpoints/wallet'
import type { AmountLike } from '#shared/utils/amount'

/**
 * 錢包餘額（member/transfer 系列回傳的四個數字）與稽核流水，鍵值照後端。
 * 所有金額都是後端字串，顯示時再用 decFormat。
 */
export const useWalletStore = defineStore('wallet', () => {
  /** 主錢包餘額 */
  const balance = ref<string | null>(null)
  /** 遊戲錢包餘額（多錢包站才有意義） */
  const gameWalletBalance = ref<string | null>(null)
  /** 禮碼 */
  const rollingChip = ref<string | null>(null)
  /** 目前遊戲商頁的遊戲商餘額（不帶 vendor_id 時固定 0.00） */
  const vendorBalance = ref<string | null>(null)
  /** 稽核流水總額 / 已達成（withdrawal/flow-limit） */
  const flowLimit = ref<string | null>(null)
  const flowReach = ref<string | null>(null)
  /** member 級轉帳鎖的前端 in-flight 旗標（transfer / transfer-all / transfer-back-recent 共用） */
  const transferring = ref(false)
  const updatedAt = ref<number | null>(null)

  function toText(value: AmountLike): string | null {
    return value === null || value === undefined ? null : String(value)
  }

  /**
   * 寫回 member/transfer 系列的回應，欄位對照舊版 store（points／List 模組）：
   *   balance → acctInfo.balance、rolling_chip → giftCode、game_wallet_balance → gameWallet、
   *   vendor_balance → updateStateVendorBalance
   * 舊版不帶 vendor_id 的刷新（actionQuickSingleWalletToCenterWallet）**不寫 vendor_balance**
   * （後端那時固定回 0.00），只有帶 vendor_id 轉入與 transfer-all（singleWalletBalance）才寫，
   * 所以那一種要傳 keepVendorBalance，不然進遊戲商頁看到的遊戲商餘額會被刷成 0。
   */
  function applyTransferResult(data: TransferBackData, opts: { keepVendorBalance?: boolean } = {}) {
    balance.value = toText(data.balance)
    gameWalletBalance.value = toText(data.game_wallet_balance)
    rollingChip.value = toText(data.rolling_chip)
    if (!opts.keepVendorBalance) vendorBalance.value = toText(data.vendor_balance)
    updatedAt.value = Date.now()
  }

  function setVendorBalance(value: AmountLike) {
    vendorBalance.value = toText(value)
  }

  function setFlowLimit(limit: AmountLike, reach: AmountLike) {
    flowLimit.value = toText(limit)
    flowReach.value = toText(reach)
  }

  /**
   * 帶 vendor_id 轉入與「重新整理餘額」開始前清掉禮碼／遊戲錢包的舊值（舊版 singleWalletBalance
   * 送出前 GET_GIFT_CODE／GET_GAME_WALLET 設 null）。主錢包與遊戲商餘額保留：
   * 舊版也沒清，避免畫面在轉帳期間先跳成空白。
   */
  function clearSecondaryBalances() {
    gameWalletBalance.value = null
    rollingChip.value = null
  }

  function reset() {
    balance.value = null
    gameWalletBalance.value = null
    rollingChip.value = null
    vendorBalance.value = null
    flowLimit.value = null
    flowReach.value = null
    transferring.value = false
    updatedAt.value = null
  }

  return {
    balance,
    gameWalletBalance,
    rollingChip,
    vendorBalance,
    flowLimit,
    flowReach,
    transferring,
    updatedAt,
    applyTransferResult,
    setVendorBalance,
    setFlowLimit,
    clearSecondaryBalances,
    reset,
  }
})
