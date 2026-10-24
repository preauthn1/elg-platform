import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useWalletStore } from '../../app/stores/wallet'
import { useMemberStore } from '../../app/stores/member'
import { useWallet } from '../../app/composables/useWallet'
import { useWalletTransfer } from '../../app/composables/useWalletTransfer'
import { useWalletHistories } from '../../app/composables/useWalletHistories'
import { useCoupon } from '../../app/composables/useCoupon'
import { WALLET_IN_FLIGHT_CODE } from '../../shared/utils/wallet'

// composable / store 依賴 Nuxt 自動匯入（含 vue 的 ref/computed）。vue 不是專案直接相依、vitest 解析不到，
// 這裡用最小 stub：沒有 watch，getter 型 computed 就夠用。
const hoisted = vi.hoisted(() => ({
  REF: Symbol('fake-ref'),
  stores: new Map<string, object>(),
}))

function ref<T>(value: T) {
  return { [hoisted.REF]: true, value }
}
function computed<T>(getter: () => T) {
  return {
    get value() {
      return getter()
    },
  }
}
function isFakeRef(value: unknown): value is { value: unknown } {
  return !!value && typeof value === 'object' && (value as Record<symbol, unknown>)[hoisted.REF] === true
}

/** 模擬 Pinia setup store 的展開：讀寫 store.x 直接對應 ref.value，讓真正的 store 程式碼被測到。 */
function unwrapStore<T extends object>(setup: T): T {
  return new Proxy(setup, {
    get(target, key) {
      const raw = Reflect.get(target, key)
      return isFakeRef(raw) ? raw.value : raw
    },
    set(target, key, value) {
      const raw = Reflect.get(target, key)
      if (isFakeRef(raw)) {
        raw.value = value
        return true
      }
      return Reflect.set(target, key, value)
    },
  })
}

vi.mock('pinia', () => ({
  defineStore: (id: string, setup: () => object) => () => {
    if (!hoisted.stores.has(id)) hoisted.stores.set(id, unwrapStore(setup()))
    return hoisted.stores.get(id)
  },
}))

interface Envelope { code: number, message: string, data: unknown }

const post = vi.fn<(route: string, body: unknown) => Promise<Envelope>>()
const navigateTo = vi.fn()
let flags: Record<string, number> = {}
let settings: Record<string, unknown> = {}
let siteRaw: Record<string, unknown> = {}
let responses: Record<string, Envelope> = {}

const ok = (data: unknown): Envelope => ({ code: 200, message: 'success', data })
const fail = (code: number, message: string): Envelope => ({ code, message, data: [] })
const transferData = { balance: '100.0000', game_wallet_balance: '20.0000', rolling_chip: '3.0000', vendor_balance: '0.00' }

function routesCalled(): string[] {
  return post.mock.calls.map(([route]) => route)
}

beforeEach(() => {
  hoisted.stores.clear()
  post.mockReset()
  navigateTo.mockReset()
  flags = {}
  settings = {}
  siteRaw = {}
  responses = {}
  post.mockImplementation(async route => responses[route] ?? fail(404, `unknown endpoint: ${route}`))

  vi.stubGlobal('ref', ref)
  vi.stubGlobal('computed', computed)
  vi.stubGlobal('useApi', () => ({ post }))
  vi.stubGlobal('isApiOk', (res: Envelope) => res.code === 200)
  vi.stubGlobal('useWalletStore', useWalletStore)
  vi.stubGlobal('useMemberStore', useMemberStore)
  vi.stubGlobal('useWallet', useWallet)
  vi.stubGlobal('navigateTo', navigateTo)
  vi.stubGlobal('useRoute', () => ({ fullPath: '/wallet/coupon' }))
  vi.stubGlobal('useSite', () => ({
    site: computed(() => ({ raw: siteRaw })),
    settings: computed(() => settings),
    flag: (key: string) => flags[key] === 1,
  }))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useWallet：member/transfer 系列', () => {
  it('不帶 vendor_id 刷新餘額，成功後寫入 store、補會員餘額並重抓會員資料', async () => {
    responses['member/transfer'] = ok(transferData)
    const member = useMemberStore()
    member.setProfile({ id: 1, balance: '1.0000', rolling_chip: '0' })

    const res = await useWallet().refreshBalance()

    expect(post).toHaveBeenNthCalledWith(1, 'member/transfer', {})
    expect(routesCalled()).toEqual(['member/transfer', 'member/detail'])
    expect(res.code).toBe(200)
    const wallet = useWalletStore()
    expect(wallet.balance).toBe('100.0000')
    expect(wallet.gameWalletBalance).toBe('20.0000')
    expect(wallet.rollingChip).toBe('3.0000')
    // 舊版不帶 vendor_id 的刷新不寫遊戲商餘額（後端那時固定回 0.00）
    expect(wallet.vendorBalance).toBeNull()
    expect(wallet.updatedAt).not.toBeNull()
    expect(member.profile?.balance).toBe('100.0000')
    expect(member.profile?.rolling_chip).toBe('3.0000')
    expect(wallet.transferring).toBe(false)
  })

  it('帶 vendor_id 轉入遊戲商', async () => {
    responses['member/transfer'] = ok({ ...transferData, vendor_balance: '100.0000' })
    await useWallet().transfer(144)
    expect(post).toHaveBeenNthCalledWith(1, 'member/transfer', { vendor_id: 144 })
    expect(useWalletStore().vendorBalance).toBe('100.0000')
  })

  it('帶 vendor_id 轉入：送出前先清禮碼／遊戲錢包（照舊版 singleWalletBalance），主錢包與遊戲商餘額保留', async () => {
    const wallet = useWalletStore()
    wallet.applyTransferResult({ ...transferData, vendor_balance: '9.0000' })
    let duringRequest: unknown[] = []
    post.mockImplementationOnce(async () => {
      duringRequest = [wallet.balance, wallet.vendorBalance, wallet.rollingChip, wallet.gameWalletBalance]
      return ok({ ...transferData, vendor_balance: '100.0000' })
    })
    await useWallet().transfer(144)
    expect(duringRequest).toEqual(['100.0000', '9.0000', null, null])
    expect(wallet.vendorBalance).toBe('100.0000')
  })

  it('不帶 vendor_id 的刷新保留遊戲商餘額；transfer-all 則照回應寫入', async () => {
    const wallet = useWalletStore()
    wallet.setVendorBalance('55.0000')
    responses['member/transfer'] = ok(transferData)
    await useWallet().refreshBalance()
    expect(wallet.vendorBalance).toBe('55.0000')

    responses['member/transfer-all'] = ok(transferData)
    await useWallet().transferAll()
    expect(wallet.vendorBalance).toBe('0.00')
  })

  it('進行中再觸發直接回 in-flight 碼、不打 API', async () => {
    const wallet = useWalletStore()
    wallet.transferring = true
    const res = await useWallet().transferAll()
    expect(res.code).toBe(WALLET_IN_FLIGHT_CODE)
    expect(post).not.toHaveBeenCalled()
  })

  it('後端拒絕（400 忙碌）只回傳 message，store 不動', async () => {
    responses['member/transfer-all'] = fail(400, 'Wallet is busy')
    const res = await useWallet().transferAll()
    expect(post).toHaveBeenCalledWith('member/transfer-all', {})
    expect(res.code).toBe(400)
    expect(res.message).toBe('Wallet is busy')
    expect(useWalletStore().balance).toBeNull()
    expect(routesCalled()).toEqual(['member/transfer-all'])
  })

  it('transfer-back-recent 也走同一把鎖與更新流程', async () => {
    responses['member/transfer-back-recent'] = ok(transferData)
    await useWallet().transferBackRecent()
    expect(routesCalled()).toEqual(['member/transfer-back-recent', 'member/detail'])
    expect(useWalletStore().balance).toBe('100.0000')
  })

  it('enterVendor：transaction_all 關閉不打 API，開啟才帶 vendor_id 轉入', async () => {
    responses['member/transfer'] = ok(transferData)
    siteRaw = { transaction_all: 0 }
    expect(await useWallet().enterVendor(144)).toBeNull()
    expect(post).not.toHaveBeenCalled()

    useMemberStore().setProfile({ transaction_all: 1 })
    await useWallet().enterVendor(144)
    expect(post).toHaveBeenCalledWith('member/transfer', { vendor_id: 144 })
  })

  it('enterVendor(game)：遊戲頁不看 transaction_all，關閉也帶 vendor_id 轉入', async () => {
    responses['member/transfer'] = ok(transferData)
    siteRaw = { transaction_all: 0 }
    await useWallet().enterVendor(144, 'game')
    expect(post).toHaveBeenCalledWith('member/transfer', { vendor_id: 144 })
  })

  it('getVendorBalance 成功寫入 vendorBalance；失敗不動', async () => {
    responses['member/vendor-balance'] = ok({ vendor_balance: '55.1234' })
    const res = await useWallet().getVendorBalance(7)
    expect(post).toHaveBeenCalledWith('member/vendor-balance', { vendor_id: 7 })
    expect(res.code).toBe(200)
    expect(useWalletStore().vendorBalance).toBe('55.1234')

    responses['member/vendor-balance'] = fail(400, 'not transfer wallet')
    await useWallet().getVendorBalance(8)
    expect(useWalletStore().vendorBalance).toBe('55.1234')
  })

  it('checkTransferStatus / getMemberVendors 的 request 組法', async () => {
    responses['member/check-transfer-status'] = ok({ result: 'SUCCESS' })
    responses['member/vendors'] = ok([{ vendor_id: 1, vendor_name: 'A' }])
    const api = useWallet()
    const status = await api.checkTransferStatus(144, 'T-001')
    expect(post).toHaveBeenCalledWith('member/check-transfer-status', { vendor_id: 144, transaction_no: 'T-001' })
    expect(status.data.result).toBe('SUCCESS')
    const vendors = await api.getMemberVendors()
    expect(post).toHaveBeenCalledWith('member/vendors', {})
    expect(vendors.data).toHaveLength(1)
  })

  it('refreshFlowLimit 把 withdrawal/flow-limit 寫進 store；對方未登記（404）就略過', async () => {
    await useWallet().refreshFlowLimit()
    expect(useWalletStore().flowLimit).toBeNull()

    responses['withdrawal/flow-limit'] = ok({ flow_limit: '50', reach: '20', pass: 0, message: '' })
    await useWallet().refreshFlowLimit()
    const wallet = useWalletStore()
    expect(wallet.flowLimit).toBe('50')
    expect(wallet.flowReach).toBe('20')
  })

  it('store.reset 清空所有欄位', () => {
    const wallet = useWalletStore()
    wallet.applyTransferResult(transferData)
    wallet.setFlowLimit('1', '2')
    wallet.transferring = true
    wallet.reset()
    expect(wallet.balance).toBeNull()
    expect(wallet.flowLimit).toBeNull()
    expect(wallet.transferring).toBe(false)
    expect(wallet.updatedAt).toBeNull()
  })
})

describe('useWalletTransfer：主錢包 ↔ 遊戲錢包', () => {
  it('is_multiple_wallet 未開：不可送出、submit 回 null', async () => {
    const t = useWalletTransfer()
    t.amount.value = '10'
    expect(t.enabled.value).toBe(false)
    expect(t.canSubmit.value).toBe(false)
    expect(await t.submit()).toBeNull()
    expect(post).not.toHaveBeenCalled()
  })

  it('主→遊戲：上限 = 主錢包餘額；成功後依序刷新餘額、會員、稽核流水並清空金額', async () => {
    flags = { is_multiple_wallet: 1 }
    responses['wallet/transfer'] = ok([])
    responses['member/transfer'] = ok({ ...transferData, balance: '50.0000', game_wallet_balance: '70.0000' })
    const wallet = useWalletStore()
    wallet.applyTransferResult(transferData)

    const t = useWalletTransfer()
    expect(t.maxTransferAmount.value).toBe('100.0000')
    t.amount.value = '50'
    expect(t.canSubmit.value).toBe(true)

    const res = await t.submit()
    expect(res?.code).toBe(200)
    expect(post).toHaveBeenNthCalledWith(1, 'wallet/transfer', { type: 1, amount: 50 })
    expect(routesCalled()).toEqual(['wallet/transfer', 'member/transfer', 'member/detail', 'member/detail', 'withdrawal/flow-limit'])
    expect(wallet.balance).toBe('50.0000')
    expect(wallet.gameWalletBalance).toBe('70.0000')
    expect(t.amount.value).toBe('')
  })

  it('遊戲→主：flow_limit_display_visible 開才扣未達流水，並送 type 2', async () => {
    flags = { is_multiple_wallet: 1, flow_limit_display_visible: 1 }
    responses['wallet/transfer'] = fail(400, 'Not up to audit turnover')
    const wallet = useWalletStore()
    wallet.applyTransferResult({ ...transferData, game_wallet_balance: '80.0000' })
    wallet.setFlowLimit('50', '20')

    const t = useWalletTransfer()
    t.toggleDirection()
    expect(t.direction.value).toBe('game_to_main')
    expect(t.remainingFlow.value).toBe('30.0000')
    expect(t.maxTransferAmount.value).toBe('50.0000')

    t.amount.value = '60'
    expect(t.canSubmit.value).toBe(false)
    t.amount.value = '50'
    expect(t.canSubmit.value).toBe(true)

    const res = await t.submit()
    expect(post).toHaveBeenCalledWith('wallet/transfer', { type: 2, amount: 50 })
    expect(res?.code).toBe(400)
    expect(routesCalled()).toEqual(['wallet/transfer'])
    expect(t.amount.value).toBe('50')
  })

  it('遊戲→主：旗標關閉時上限 = 遊戲錢包餘額', () => {
    flags = { is_multiple_wallet: 1 }
    const wallet = useWalletStore()
    wallet.applyTransferResult({ ...transferData, game_wallet_balance: '80.0000' })
    wallet.setFlowLimit('50', '20')
    const t = useWalletTransfer()
    t.toggleDirection()
    expect(t.maxTransferAmount.value).toBe('80.0000')
  })

  it('store 沒有餘額時退回會員資料的 balance', () => {
    flags = { is_multiple_wallet: 1 }
    useMemberStore().setProfile({ balance: '12.5' })
    const t = useWalletTransfer()
    expect(t.mainBalance.value).toBe('12.5')
    expect(t.maxTransferAmount.value).toBe('12.5000')
  })
})

describe('useWalletHistories：錢包紀錄分頁', () => {
  const page = (total: number, count: number) => ok({
    items: Array.from({ length: count }, (_, i) => ({
      id: i + 1,
      created_at: '2026-09-01 10:00:00',
      before_balance: '10',
      point: '-5',
      balance: '5',
      type: 't',
      status: 's',
    })),
    pagination: { per_page: 15, total, pages: Math.ceil(total / 15), previous: '', current: 1, next: '' },
  })

  it('預設 main、第 1 頁、15 筆、最近 7 天；成功後填 items / total / pages', async () => {
    responses['wallet/histories'] = page(31, 15)
    const h = useWalletHistories()
    const req = h.currentRequest()
    expect(req.wallet_type).toBe('main')
    expect(req.page).toBe(1)
    expect(req.per_page).toBe(15)
    expect(req.start_time).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)
    expect(req.end_time).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)

    await h.fetchHistories()
    expect(post).toHaveBeenCalledWith('wallet/histories', req)
    expect(h.items.value).toHaveLength(15)
    expect(h.total.value).toBe(31)
    expect(h.pages.value).toBe(3)
    expect(h.hasPagination.value).toBe(true)
  })

  it('非多錢包站不能切遊戲錢包；多錢包站切換會重抓並回第 1 頁', async () => {
    responses['wallet/histories'] = page(0, 0)
    const h = useWalletHistories()
    expect(await h.setWalletType('game')).toBeNull()
    expect(h.walletType.value).toBe('main')

    flags = { is_multiple_wallet: 1 }
    const h2 = useWalletHistories()
    h2.page.value = 3
    await h2.setWalletType('game')
    expect(h2.walletType.value).toBe('game')
    expect(h2.page.value).toBe(1)
    expect(post).toHaveBeenLastCalledWith('wallet/histories', expect.objectContaining({ wallet_type: 'game', page: 1 }))
  })

  it('翻頁超出範圍不打 API；搜尋清掉區間就不送 start/end', async () => {
    responses['wallet/histories'] = page(31, 15)
    const h = useWalletHistories()
    await h.fetchHistories()
    expect(await h.goToPage(0)).toBeNull()
    expect(await h.goToPage(4)).toBeNull()
    await h.goToPage(2)
    expect(post).toHaveBeenLastCalledWith('wallet/histories', expect.objectContaining({ page: 2 }))

    await h.search(undefined, undefined)
    const last = post.mock.calls.at(-1)?.[1] as Record<string, unknown>
    expect(last.start_time).toBeUndefined()
    expect(last.end_time).toBeUndefined()
    expect(last.page).toBe(1)
  })

  it('失敗時清空（訊息由 useApi 彈窗）', async () => {
    responses['wallet/histories'] = fail(400, 'boom')
    const h = useWalletHistories()
    await h.fetchHistories()
    expect(h.items.value).toEqual([])
    expect(h.total.value).toBe(0)
    expect(h.loading.value).toBe(false)
  })

  it('舊端點 main-logs / game-logs 各一支', async () => {
    responses['wallet/main-logs'] = page(0, 0)
    responses['wallet/game-logs'] = page(0, 0)
    const h = useWalletHistories()
    await h.mainLogs({ page: 1, per_page: 15 })
    await h.gameLogs({})
    expect(post).toHaveBeenCalledWith('wallet/main-logs', { page: 1, per_page: 15 })
    expect(post).toHaveBeenCalledWith('wallet/game-logs', {})
  })
})

describe('useCoupon：兌換優惠碼', () => {
  it('前端驗證擋下：空值 / 非英數，不打 API', async () => {
    const c = useCoupon()
    expect(await c.redeem('')).toBeNull()
    expect(c.promptKey.value).toBe('validation.code.error.redemptionRequired')
    expect(await c.redeem('ab-1')).toBeNull()
    expect(c.promptKey.value).toBe('validation.field.error.alphanumeric')
    expect(post).not.toHaveBeenCalled()
  })

  it('成功：送大寫碼、記結果、刷新餘額與稽核流水；文案單位依 type', async () => {
    flags = { coupon_code_visible: 1 }
    settings = { text_wallet_point: 'Points', text_rolling_chip: 'Chips' }
    responses['coupon/redeem'] = ok({ amount: '12.3456', type: 2, image_url: 'https://img/prize.png' })
    responses['member/transfer'] = ok(transferData)

    const c = useCoupon()
    const res = await c.redeem('abc12')
    expect(post).toHaveBeenNthCalledWith(1, 'coupon/redeem', { code: 'ABC12' })
    expect(res?.code).toBe(200)
    expect(routesCalled()).toEqual(['coupon/redeem', 'member/transfer', 'member/detail', 'withdrawal/flow-limit'])
    expect(c.successText.value).toEqual({ amount: '12.34', unit: 'Chips', imageUrl: 'https://img/prize.png' })
    expect(useWalletStore().balance).toBe('100.0000')

    responses['coupon/redeem'] = ok({ amount: 7, type: 1, image_url: null })
    await c.redeem('POINT1')
    expect(c.successText.value).toEqual({ amount: '7.00', unit: 'Points', imageUrl: null })

    c.clearResult()
    expect(c.successText.value).toBeNull()
  })

  it('324：結果重設；不自己導頁（交給 useApi 全域彈窗關掉後導）', async () => {
    responses['coupon/redeem'] = fail(324, 'Mobile number not recognize')
    const c = useCoupon()
    const res = await c.redeem('ABC')
    expect(res?.code).toBe(324)
    expect(c.result.value).toBe(0)
    expect(c.successText.value).toBeNull()
    expect(navigateTo).not.toHaveBeenCalled()
    expect(routesCalled()).toEqual(['coupon/redeem'])
  })

  it('400：結果重設為 0、不導頁、不刷新（訊息由 useApi 彈窗）', async () => {
    responses['coupon/redeem'] = fail(400, 'Coupon code has been used')
    const c = useCoupon()
    await c.redeem('USED1')
    expect(c.result.value).toBe(0)
    expect(navigateTo).not.toHaveBeenCalled()
    expect(routesCalled()).toEqual(['coupon/redeem'])
  })
})
