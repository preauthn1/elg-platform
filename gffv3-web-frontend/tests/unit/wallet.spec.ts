import { describe, expect, it } from 'vitest'
import {
  buildMemberTransferRequest,
  buildWalletHistoriesRequest,
  buildWalletTransferRequest,
  canSubmitWalletTransfer,
  couponUnitKey,
  datetimeLocalToApi,
  defaultWalletHistoryRange,
  formatWalletDateTime,
  normalizeCouponCode,
  oppositeDirection,
  shouldTransferOnVendorEntry,
  remainingFlowLimit,
  shouldAutoTransferIntoVendor,
  totalPages,
  validateCouponCode,
  walletMaxTransferAmount,
} from '../../shared/utils/wallet'
import { transferBackSchema, walletEndpoints } from '../../shared/api/endpoints/wallet'

describe('遊戲錢包互轉規則', () => {
  const base = { mainBalance: '100.5', gameBalance: '80', flowLimit: '50', reach: '20' }

  it('主→遊戲上限 = 主錢包餘額', () => {
    expect(walletMaxTransferAmount({ ...base, direction: 'main_to_game', flowLimitDisplayVisible: true })).toBe('100.5000')
    expect(walletMaxTransferAmount({ ...base, direction: 'main_to_game', flowLimitDisplayVisible: false })).toBe('100.5000')
  })

  it('遊戲→主：flow_limit_display_visible 開才扣未達流水', () => {
    expect(walletMaxTransferAmount({ ...base, direction: 'game_to_main', flowLimitDisplayVisible: true })).toBe('50.0000')
    expect(walletMaxTransferAmount({ ...base, direction: 'game_to_main', flowLimitDisplayVisible: false })).toBe('80.0000')
  })

  it('遊戲→主：負數歸零', () => {
    expect(walletMaxTransferAmount({
      direction: 'game_to_main',
      mainBalance: '0',
      gameBalance: '10',
      flowLimit: '100',
      reach: '0',
      flowLimitDisplayVisible: true,
    })).toBe('0.0000')
  })

  it('剩餘流水 = flow_limit − reach，可為負', () => {
    expect(remainingFlowLimit('50', '20')).toBe('30.0000')
    expect(remainingFlowLimit('10', '25')).toBe('-15.0000')
    expect(remainingFlowLimit(null, null)).toBe('0.0000')
  })

  it('可送出：有值、>= 1、<= 上限', () => {
    expect(canSubmitWalletTransfer('', '100')).toBe(false)
    expect(canSubmitWalletTransfer('abc', '100')).toBe(false)
    expect(canSubmitWalletTransfer('0.5', '100')).toBe(false)
    expect(canSubmitWalletTransfer('1', '100')).toBe(true)
    expect(canSubmitWalletTransfer('100', '100')).toBe(true)
    expect(canSubmitWalletTransfer('100.0001', '100')).toBe(false)
    expect(canSubmitWalletTransfer('5', '0.0000')).toBe(false)
  })

  it('request：type 送數字、amount 截到 4 位', () => {
    expect(buildWalletTransferRequest('main_to_game', '50')).toEqual({ type: 1, amount: 50 })
    expect(buildWalletTransferRequest('game_to_main', '12.345678')).toEqual({ type: 2, amount: 12.3456 })
    expect(oppositeDirection('main_to_game')).toBe('game_to_main')
    expect(oppositeDirection('game_to_main')).toBe('main_to_game')
  })
})

describe('member/transfer request 組法', () => {
  it('沒有目標遊戲商就不送 vendor_id', () => {
    expect(buildMemberTransferRequest()).toEqual({})
    expect(buildMemberTransferRequest(null)).toEqual({})
    expect(buildMemberTransferRequest(0)).toEqual({})
    expect(buildMemberTransferRequest(-3)).toEqual({})
    expect(buildMemberTransferRequest(Number.NaN)).toEqual({})
  })

  it('有目標遊戲商送整數 vendor_id', () => {
    expect(buildMemberTransferRequest(144)).toEqual({ vendor_id: 144 })
    expect(buildMemberTransferRequest(12.9)).toEqual({ vendor_id: 12 })
  })

  it('transaction_all 只有 1 / "1" / true 才自動轉入', () => {
    expect(shouldAutoTransferIntoVendor(1)).toBe(true)
    expect(shouldAutoTransferIntoVendor('1')).toBe(true)
    expect(shouldAutoTransferIntoVendor(0)).toBe(false)
    expect(shouldAutoTransferIntoVendor(undefined)).toBe(false)
  })

  it('進場轉入（照舊版 C13／C16）：遊戲頁一律轉，列表／APP／聲明頁看 transaction_all', () => {
    expect(shouldTransferOnVendorEntry('game', 0)).toBe(true)
    expect(shouldTransferOnVendorEntry('game', undefined)).toBe(true)
    expect(shouldTransferOnVendorEntry('vendor', 1)).toBe(true)
    expect(shouldTransferOnVendorEntry('vendor', 0)).toBe(false)
    expect(shouldTransferOnVendorEntry('vendor', undefined)).toBe(false)
  })
})

describe('優惠碼', () => {
  it('前端驗證訊息 key', () => {
    expect(validateCouponCode('')).toBe('validation.code.error.redemptionRequired')
    expect(validateCouponCode('   ')).toBe('validation.code.error.redemptionRequired')
    expect(validateCouponCode('ab-12')).toBe('validation.field.error.alphanumeric')
    expect(validateCouponCode('中文')).toBe('validation.field.error.alphanumeric')
    expect(validateCouponCode('ABCDEFGHIJK')).toBe('validation.field.error.alphanumeric')
    expect(validateCouponCode('abc123')).toBeNull()
  })

  it('送出前轉大寫', () => {
    expect(normalizeCouponCode(' abc12 ')).toBe('ABC12')
  })

  it('單位：type 2 禮碼、其餘平台點數', () => {
    expect(couponUnitKey(2)).toBe('text_rolling_chip')
    expect(couponUnitKey('2')).toBe('text_rolling_chip')
    expect(couponUnitKey(1)).toBe('text_wallet_point')
    expect(couponUnitKey(0)).toBe('text_wallet_point')
  })
})

describe('錢包紀錄', () => {
  it('日期格式與預設最近 7 天', () => {
    const now = new Date(2026, 8, 10, 15, 4, 5)
    expect(formatWalletDateTime(now)).toBe('2026-09-10 15:04:05')
    expect(defaultWalletHistoryRange(now)).toEqual({
      start_time: '2026-09-03 15:04:05',
      end_time: '2026-09-10 15:04:05',
    })
  })

  it('datetime-local 轉 Y-m-d H:i:s', () => {
    expect(datetimeLocalToApi('2026-09-10T15:04')).toBe('2026-09-10 15:04:00')
    expect(datetimeLocalToApi('2026-09-10T15:04:05')).toBe('2026-09-10 15:04:05')
    expect(datetimeLocalToApi('')).toBeUndefined()
    expect(datetimeLocalToApi('nope')).toBeUndefined()
  })

  it('request 組法：沒帶區間就不送、頁碼保底 1', () => {
    expect(buildWalletHistoriesRequest({ walletType: 'main', page: 0, perPage: 0 })).toEqual({
      wallet_type: 'main',
      page: 1,
      per_page: 15,
    })
    expect(buildWalletHistoriesRequest({
      walletType: 'game',
      startTime: '2026-09-01 00:00:00',
      endTime: '2026-09-10 23:59:59',
      page: 3,
      perPage: 20,
    })).toEqual({
      wallet_type: 'game',
      start_time: '2026-09-01 00:00:00',
      end_time: '2026-09-10 23:59:59',
      page: 3,
      per_page: 20,
    })
  })

  it('總頁數', () => {
    expect(totalPages(0, 15)).toBe(0)
    expect(totalPages(15, 15)).toBe(1)
    expect(totalPages(16, 15)).toBe(2)
    expect(totalPages('31', '15')).toBe(3)
  })
})

describe('walletEndpoints 契約', () => {
  const keys = Object.keys(walletEndpoints).sort()

  it('登記 11 條且 key = path、全部 auth member', () => {
    expect(keys).toEqual([
      'coupon/redeem',
      'member/check-transfer-status',
      'member/transfer',
      'member/transfer-all',
      'member/transfer-back-recent',
      'member/vendor-balance',
      'member/vendors',
      'wallet/game-logs',
      'wallet/histories',
      'wallet/main-logs',
      'wallet/transfer',
    ].sort())
    for (const [key, def] of Object.entries(walletEndpoints)) {
      expect(def.path).toBe(key)
      expect(def.auth).toBe('member')
    }
  })

  it('member/transfer：vendor_id 可省略、可字串', () => {
    const req = walletEndpoints['member/transfer'].request
    expect(req.parse({})).toEqual({})
    expect(req.parse({ vendor_id: '144' })).toEqual({ vendor_id: 144 })
    expect(req.safeParse({ vendor_id: 'x' }).success).toBe(false)
  })

  it('TransferBackResource 形狀', () => {
    const data = { balance: '100.0000', game_wallet_balance: '0.0000', rolling_chip: '5.5000', vendor_balance: '0.00' }
    expect(transferBackSchema.parse(data)).toEqual(data)
    expect(transferBackSchema.safeParse({ balance: '1' }).success).toBe(false)
  })

  it('wallet/transfer：type 只收 1/2、amount 必須 > 0', () => {
    const req = walletEndpoints['wallet/transfer'].request
    expect(req.parse({ type: '2', amount: '12.5' })).toEqual({ type: 2, amount: 12.5 })
    expect(req.parse({ amount: 1 })).toEqual({ amount: 1 })
    expect(req.safeParse({ type: 3, amount: 1 }).success).toBe(false)
    expect(req.safeParse({ type: 1, amount: 0 }).success).toBe(false)
  })

  it('coupon/redeem：1～10 位英數', () => {
    const req = walletEndpoints['coupon/redeem'].request
    expect(req.parse({ code: 'ABC123' })).toEqual({ code: 'ABC123' })
    expect(req.safeParse({ code: '' }).success).toBe(false)
    expect(req.safeParse({ code: 'AB-1' }).success).toBe(false)
    expect(req.safeParse({ code: 'ABCDEFGHIJK' }).success).toBe(false)
    expect(walletEndpoints['coupon/redeem'].response.parse({ amount: 10, type: 2, image_url: null })).toEqual({ amount: 10, type: 2, image_url: null })
  })

  it('wallet/histories：wallet_type 列舉、時間格式、分頁欄位', () => {
    const req = walletEndpoints['wallet/histories'].request
    expect(req.parse({ wallet_type: 'game', start_time: '2026-09-01 00:00:00', page: '2', per_page: '15' })).toEqual({
      wallet_type: 'game',
      start_time: '2026-09-01 00:00:00',
      page: 2,
      per_page: 15,
    })
    expect(req.safeParse({ wallet_type: 'promotion' }).success).toBe(false)
    expect(req.safeParse({ start_time: '2026-09-01' }).success).toBe(false)

    const res = walletEndpoints['wallet/histories'].response.parse({
      items: [{ id: 1, created_at: '2026-09-01 10:00:00', before_balance: '10', point: -5, balance: '5', type: '轉出', status: '成功' }],
      pagination: { per_page: 15, total: 1, pages: 1, previous: '', current: 1, next: '' },
    })
    expect(res.items).toHaveLength(1)
    expect(res.pagination.total).toBe(1)
  })

  it('member/check-transfer-status 與 member/vendor-balance 必填欄位', () => {
    expect(walletEndpoints['member/check-transfer-status'].request.safeParse({ vendor_id: 0, transaction_no: 'T1' }).success).toBe(false)
    expect(walletEndpoints['member/check-transfer-status'].request.safeParse({ vendor_id: 144 }).success).toBe(false)
    expect(walletEndpoints['member/check-transfer-status'].request.parse({ vendor_id: '144', transaction_no: 'T1' })).toEqual({ vendor_id: 144, transaction_no: 'T1' })
    expect(walletEndpoints['member/vendor-balance'].request.safeParse({}).success).toBe(false)
    expect(walletEndpoints['member/vendors'].response.parse([{ vendor_id: 1, vendor_name: 'A' }])).toEqual([{ vendor_id: 1, vendor_name: 'A' }])
  })
})
