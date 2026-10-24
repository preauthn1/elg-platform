import { describe, expect, it, vi } from 'vitest'
import type { ApiEnvelope } from '../../shared/types/api'
import { recordsEndpoints } from '../../shared/api/endpoints/records'
import {
  BET_RECORD_ALL_PERIOD_PER_PAGE,
  buildDayPresets,
  buildTransactionLogsRequest,
  buildTransactionRangePresets,
  fetchBetsForDays,
  findRecordById,
  firstVisibleRecordType,
  formatRecordAmount,
  frontendDate,
  isRecordType,
  msUntilNextFrontendMidnight,
  normalizeTransactionType,
  paginateRecords,
  postUnregisteredRoute,
  recordPageCount,
  recordStatusKind,
  recordStatusLabel,
  recordTabs,
  redEnvelopeTypeOf,
  resolveRecordsResult,
  routeParamString,
  transactionIconKind,
  transactionTypeLabel,
  transactionTypeOptions,
  type TotalValidBetsPost,
} from '../../app/utils/records'

const t = (key: string) => `#${key}`

describe('recordsEndpoints 契約', () => {
  it('每條都是 member、key 與 path 一致、不補 domain', () => {
    for (const [key, def] of Object.entries(recordsEndpoints)) {
      expect(def.path).toBe(key)
      expect(def.auth).toBe('member')
      expect(def.withDomain).toBe(false)
    }
  })

  it('deposit/orders 需要 page/per_page 且最小 1，字串會被收成數字', () => {
    const schema = recordsEndpoints['deposit/orders'].request
    expect(schema.parse({ page: '1', per_page: '999' })).toEqual({ page: 1, per_page: 999 })
    expect(schema.safeParse({ page: 0, per_page: 10 }).success).toBe(false)
    expect(schema.safeParse({ per_page: 10 }).success).toBe(false)
  })

  it('deposit/orders 回應是扁平陣列，status 收成數字', () => {
    const parsed = recordsEndpoints['deposit/orders'].response.parse([
      { order_id: 12, amount: '100.00', status: '3', created_at: '2026-09-10 10:00:00', deposit_url: '', extra_field: 'kept' },
    ])
    expect(parsed[0]?.order_id).toBe(12)
    expect(parsed[0]?.status).toBe(3)
    expect((parsed[0] as Record<string, unknown>).extra_field).toBe('kept')
  })

  it('withdrawal/orders page/per_page 可省略；icon_status 只接受三種值', () => {
    const def = recordsEndpoints['withdrawal/orders']
    expect(def.request.parse({})).toEqual({})
    const ok = def.response.safeParse({
      items: [{ id: 1, created_at: 'x', amount: '10.5', status: 'Pending', icon_status: 'pending', bank_icon: null, bank_name: '', remark: 'AutoWithdrawal' }],
      pagination: { per_page: 15, total: 1, pages: 1, previous: '', current: 1, next: '' },
    })
    expect(ok.success).toBe(true)
    const bad = def.response.safeParse({
      items: [{ id: 1, created_at: 'x', amount: '10.5', status: 'Pending', icon_status: 'unknown' }],
      pagination: {},
    })
    expect(bad.success).toBe(false)
  })

  it('transaction/logs type 只接受 0~8、時間要完整 Y-m-d H:i:s', () => {
    const schema = recordsEndpoints['transaction/logs'].request
    const base = { start_time: '2026-09-08 00:00:00', end_time: '2026-09-10 23:59:59', page: 1, per_page: 999 }
    expect(schema.parse({ ...base, type: '3' })).toMatchObject({ type: 3 })
    expect(schema.parse(base)).not.toHaveProperty('type')
    expect(schema.safeParse({ ...base, type: 9 }).success).toBe(false)
    expect(schema.safeParse({ ...base, start_time: '2026-09-08' }).success).toBe(false)
    expect(schema.safeParse({ end_time: base.end_time, page: 1, per_page: 1 }).success).toBe(false)
  })

  it('transaction/logs 回應 is_rolling_chip 收成 0/1', () => {
    const parsed = recordsEndpoints['transaction/logs'].response.parse({
      items: [{ is_rolling_chip: '1', created_at: 'x', type: 13, point: 12.3456, currency: '', vendor_image: null, vendor_name: null }],
      pagination: { per_page: 999, total: 1, pages: 1, previous: '', current: 1, next: '' },
    })
    expect(parsed.items[0]?.is_rolling_chip).toBe(1)
    expect((parsed.pagination as Record<string, unknown>).pages).toBe(1)
  })

  it('transaction/red-envelope type 只接受 1/2/3', () => {
    const schema = recordsEndpoints['transaction/red-envelope'].request
    expect(schema.parse({ type: 2, page: 1, per_page: 999 })).toEqual({ type: 2, page: 1, per_page: 999 })
    expect(schema.safeParse({ type: 4, page: 1, per_page: 999 }).success).toBe(false)
  })

  it('transaction/customer-service 回應 type 只有 1/2', () => {
    const schema = recordsEndpoints['transaction/customer-service'].response
    const good = schema.safeParse({ items: [{ id: 1, transaction_no: 'T1', point: '5', type: '2', created_at: 'x' }], pagination: {} })
    expect(good.success).toBe(true)
    const bad = schema.safeParse({ items: [{ id: 1, transaction_no: 'T1', point: '5', type: 3, created_at: 'x' }], pagination: {} })
    expect(bad.success).toBe(false)
  })

  it('member/total-valid-bets search_day 要 YYYY-MM-DD', () => {
    const schema = recordsEndpoints['member/total-valid-bets'].request
    expect(schema.safeParse({ search_day: '2026-09-10', page: 1, per_page: 10 }).success).toBe(true)
    expect(schema.safeParse({ search_day: '2026/09/10', page: 1, per_page: 10 }).success).toBe(false)
  })

  it('member/winloses 需要 vendor_id，回應多一個 sum', () => {
    const def = recordsEndpoints['member/winloses']
    expect(def.request.safeParse({ search_day: '2026-09-10', page: 1, per_page: 10 }).success).toBe(false)
    const parsed = def.response.parse({
      items: [{ valid_bet: '10.00', win_lose: '-2.50', record_count: 3, name: 'Game A' }],
      pagination: { per_page: 10, total: 1, pages: 1, previous: '', current: 1, next: '' },
      sum: { sum_win_lose: '-2.50', sum_record_count: 3 },
    })
    expect(parsed.sum.sum_record_count).toBe(3)
    expect(parsed.pagination.total).toBe(1)
  })

  it('bet-record/accumulated-bets vendor_id 不可為負', () => {
    const def = recordsEndpoints['bet-record/accumulated-bets']
    expect(def.request.parse({ vendor_id: '7' })).toEqual({ vendor_id: 7 })
    expect(def.request.safeParse({ vendor_id: -1 }).success).toBe(false)
    expect(def.response.parse({ today_accumulated_bet: '0.00', yesterday_accumulated_bet: 12 })).toEqual({ today_accumulated_bet: '0.00', yesterday_accumulated_bet: 12 })
  })
})

describe('紀錄分頁可見性', () => {
  it('全部開啟時順序固定、withdrawal 永遠在', () => {
    const tabs = recordTabs({ hasDepositRecordPage: true, hasEvent: true, isPgsPlatform: false })
    expect(tabs.map(tab => tab.type)).toEqual([
      'deposit', 'event', 'platform-transfer', 'withdrawal', 'customer-service', 'red-envelope-2', 'red-envelope-1', 'red-envelope-3',
    ])
    expect(tabs[0]?.titleKey).toBe('records.tab.nav.deposit')
  })

  it('依 has_deposit_record_page / has_event / target 開關隱藏', () => {
    const tabs = recordTabs({ hasDepositRecordPage: false, hasEvent: false, isPgsPlatform: true })
    expect(tabs.map(tab => tab.type)).toEqual(['withdrawal', 'customer-service', 'red-envelope-2', 'red-envelope-1', 'red-envelope-3'])
  })

  it('/record 導向第一個可見分頁', () => {
    expect(firstVisibleRecordType({ hasDepositRecordPage: true, hasEvent: true, isPgsPlatform: false })).toBe('deposit')
    expect(firstVisibleRecordType({ hasDepositRecordPage: false, hasEvent: true, isPgsPlatform: false })).toBe('event')
    expect(firstVisibleRecordType({ hasDepositRecordPage: false, hasEvent: false, isPgsPlatform: false })).toBe('platform-transfer')
    expect(firstVisibleRecordType({ hasDepositRecordPage: false, hasEvent: false, isPgsPlatform: true })).toBe('withdrawal')
  })

  it('red-envelope 分頁對應 type 1/2/3', () => {
    expect(redEnvelopeTypeOf('red-envelope-1')).toBe(1)
    expect(redEnvelopeTypeOf('red-envelope-2')).toBe(2)
    expect(redEnvelopeTypeOf('red-envelope-3')).toBe(3)
    expect(redEnvelopeTypeOf('deposit')).toBeNull()
    expect(isRecordType('game')).toBe(false)
    expect(isRecordType('customer-service')).toBe(true)
  })
})

describe('狀態歸類', () => {
  it.each([
    [0, 'pending'],
    [1, 'pending'],
    ['pending', 'pending'],
    [2, 'fail'],
    ['fail', 'fail'],
    [3, 'success'],
    ['success', 'success'],
    [9, null],
    [undefined, null],
  ])('%s → %s', (status, kind) => {
    expect(recordStatusKind(status as number | string | undefined)).toBe(kind)
  })

  it('文字：pending/fail 走語言檔、success 先用中文', () => {
    expect(recordStatusLabel(1, t)).toBe('#records.enum.orderStatus.pending')
    expect(recordStatusLabel('fail', t)).toBe('#common.misc.label.fail')
    expect(recordStatusLabel(3, t)).toBe('成功')
    expect(recordStatusLabel(null, t)).toBe('')
  })
})

describe('前端時間 UTC+8 日期', () => {
  it('跨日以 UTC+8 為準', () => {
    expect(frontendDate(new Date('2026-09-10T15:59:59Z'))).toBe('2026-09-10')
    expect(frontendDate(new Date('2026-09-10T16:00:00Z'))).toBe('2026-09-11')
    expect(frontendDate(new Date('2026-09-10T17:30:00Z'), -1)).toBe('2026-09-10')
    expect(frontendDate(new Date('2026-09-10T17:30:00Z'), -2)).toBe('2026-09-09')
  })

  it('距下一個 UTC+8 午夜的毫秒數', () => {
    expect(msUntilNextFrontendMidnight(new Date('2026-09-10T15:59:59Z'))).toBe(1000)
    expect(msUntilNextFrontendMidnight(new Date('2026-09-10T16:00:00Z'))).toBe(86_400_000)
  })

  it('投注紀錄日期選項：全部期間 + 三個單日（MM-DD）', () => {
    const now = new Date('2026-09-10T02:00:00Z')
    const presets = buildDayPresets(now, { allPeriod: true })
    expect(presets.map(p => p.key)).toEqual(['all', '2026-09-10', '2026-09-09', '2026-09-08'])
    expect(presets[0]).toMatchObject({ labelKey: 'common.filter.option.allPeriod', days: ['2026-09-10', '2026-09-09', '2026-09-08'] })
    expect(presets[1]).toMatchObject({ labelKey: null, label: '09-10', days: ['2026-09-10'] })
    expect(buildDayPresets(now, { allPeriod: false })).toHaveLength(3)
  })

  it('交易紀錄區間：全部期間是前天 00:00:00 ～ 今天 23:59:59', () => {
    const presets = buildTransactionRangePresets(new Date('2026-09-10T02:00:00Z'))
    expect(presets[0]).toEqual({ key: 'all', labelKey: 'common.filter.option.allPeriod', label: '', start_time: '2026-09-08 00:00:00', end_time: '2026-09-10 23:59:59' })
    expect(presets[2]).toEqual({ key: '2026-09-09', labelKey: null, label: '09-09', start_time: '2026-09-09 00:00:00', end_time: '2026-09-09 23:59:59' })
    expect(presets).toHaveLength(4)
  })
})

describe('交易紀錄類型', () => {
  const base = { hasAgentLayout: false, unlimitVisible: false, unlimitedText: 'Unlimited' }

  it('順序 all,1,2,3,4,8,7；type 5 要 has_agent_layout 且 is_unlimit_visible', () => {
    expect(transactionTypeOptions(base).map(o => o.type)).toEqual(['all', '1', '2', '3', '4', '8', '7'])
    expect(transactionTypeOptions({ ...base, hasAgentLayout: true }).map(o => o.type)).not.toContain('5')
    const withUnlimit = transactionTypeOptions({ ...base, hasAgentLayout: true, unlimitVisible: true })
    expect(withUnlimit.map(o => o.type)).toEqual(['all', '1', '2', '3', '4', '5', '8', '7'])
    expect(withUnlimit[5]).toEqual({ type: '5', labelKey: null, label: 'Unlimited' })
    expect(withUnlimit[0]).toEqual({ type: 'all', labelKey: 'records.filter.option.allTypes', label: '' })
  })

  it('路由參數不在可見清單就退回 all', () => {
    const options = transactionTypeOptions(base)
    expect(normalizeTransactionType('3', options)).toBe('3')
    expect(normalizeTransactionType('5', options)).toBe('all')
    expect(normalizeTransactionType(undefined, options)).toBe('all')
    expect(normalizeTransactionType('', options)).toBe('all')
  })

  it('all 不送 type 鍵，其餘送數字', () => {
    const range = { start_time: '2026-09-08 00:00:00', end_time: '2026-09-10 23:59:59' }
    expect(buildTransactionLogsRequest({ type: 'all', range, page: 1, perPage: 999 })).toEqual({
      start_time: range.start_time,
      end_time: range.end_time,
      page: 1,
      per_page: 999,
    })
    expect(buildTransactionLogsRequest({ type: '7', range, page: 2, perPage: 15 })).toMatchObject({ type: 7, page: 2, per_page: 15 })
  })

  it.each([
    [0, 1, 'vendor'],
    [0, 3, 'deposit'],
    [0, 11, 'withdrawal'],
    [0, 8, 'red-envelope'],
    [0, 70, 'transfer-out'],
    [0, 38, 'transfer-in'],
    [0, 33, 'points-adjustment'],
    [0, 17, 'agent'],
    [0, 104, 'event-reward'],
    [0, 999, null],
    [1, 1, 'event-reward'],
    [1, 71, 'event-reward'],
    [1, 4, 'agent'],
    [1, 5, 'points-adjustment'],
    [1, 2, null],
  ])('圖示 chip=%s type=%s → %s', (isChip, type, kind) => {
    expect(transactionIconKind({ is_rolling_chip: isChip, type })).toBe(kind)
  })

  it('類型名稱', () => {
    const ctx = { t, jackpotTitle: 'Mega Jackpot', couponLabelKey: 'wallet.exchange.title.coupon' as const }
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 1, vendor_name: 'PP' }, ctx)).toBe('#records.enum.transactionType.vendorTransferIn PP')
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 2, vendor_name: null }, ctx)).toBe('#records.enum.transactionType.vendorTransferOut')
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 3 }, ctx)).toBe('#deposit.common.title.deposit')
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 103 }, ctx)).toBe('Jackpot')
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 107 }, ctx)).toBe('#wallet.exchange.title.coupon')
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 109 }, ctx)).toBe('#records.enum.transactionType.missionBonus')
    expect(transactionTypeLabel({ is_rolling_chip: 0, type: 999 }, ctx)).toBe('-')
    expect(transactionTypeLabel({ is_rolling_chip: 1, type: 18 }, ctx)).toBe('Mega Jackpot')
    expect(transactionTypeLabel({ is_rolling_chip: 1, type: 23 }, { ...ctx, couponLabelKey: 'promoCode' })).toBe('#promoCode')
    expect(transactionTypeLabel({ is_rolling_chip: 1, type: 15 }, ctx)).toBe('W-Jackpot 6D')
    expect(transactionTypeLabel({ is_rolling_chip: 1, type: 41 }, ctx)).toBe('#events.detail.label.reward')
    expect(transactionTypeLabel({ is_rolling_chip: 1, type: 2 }, ctx)).toBe('-')
  })
})

describe('金額與分頁', () => {
  it('金額無條件捨去兩位並補零', () => {
    expect(formatRecordAmount('1234.567')).toBe('1234.56')
    expect(formatRecordAmount(5)).toBe('5.00')
    expect(formatRecordAmount('0')).toBe('0.00')
    expect(formatRecordAmount('')).toBe('0.00')
    expect(formatRecordAmount(null)).toBe('0.00')
    expect(formatRecordAmount('1,000.5')).toBe('1000.50')
    expect(formatRecordAmount('-2.5')).toBe('-2.50')
    expect(formatRecordAmount('99.999', 0)).toBe('99')
  })

  it('頁數與前端切頁', () => {
    expect(recordPageCount(0, 10)).toBe(1)
    expect(recordPageCount(25, 10)).toBe(3)
    expect(recordPageCount(10, 0)).toBe(1)
    const list = Array.from({ length: 23 }, (_, i) => i + 1)
    expect(paginateRecords(list, 1, 10)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
    expect(paginateRecords(list, 3, 10)).toEqual([21, 22, 23])
    expect(paginateRecords(list, 0, 10)).toEqual(paginateRecords(list, 1, 10))
  })

  it('詳細頁用 id 找紀錄，數字字串都能比', () => {
    const list = [{ order_id: 5, amount: '1' }, { order_id: '7', amount: '2' }]
    expect(findRecordById(list, 'order_id', '5')?.amount).toBe('1')
    expect(findRecordById(list, 'order_id', 7)?.amount).toBe('2')
    expect(findRecordById(list, 'order_id', 9)).toBeUndefined()
    expect(routeParamString(['a', 'b'])).toBe('a')
    expect(routeParamString(undefined)).toBe('')
  })
})

describe('回應碼分流', () => {
  it('200 成功、其他帶回訊息', () => {
    expect(resolveRecordsResult({ code: 200, message: 'ok', data: [1] })).toEqual({ ok: true, data: [1] })
    expect(resolveRecordsResult({ code: 400, message: 'Only records for the past 3 days can be viewed', data: [] }))
      .toEqual({ ok: false, code: 400, message: 'Only records for the past 3 days can be viewed' })
    expect(resolveRecordsResult({ code: 9998, message: 'relogin', data: null })).toMatchObject({ ok: false, code: 9998 })
  })

  it('全部期間逐日呼叫、照順序合併', async () => {
    const calls: unknown[] = []
    const post: TotalValidBetsPost = vi.fn(async (body) => {
      calls.push(body)
      const day = String(body.search_day)
      return {
        code: 200,
        message: '',
        data: {
          items: [{ total_valid_bet: '1', name: `V-${day}`, image: null, summary_time: `${day} 10:00:00` }],
          pagination: { total: 1 },
        },
      }
    })
    const result = await fetchBetsForDays(post, ['2026-09-10', '2026-09-09', '2026-09-08'])
    expect(calls).toEqual([
      { search_day: '2026-09-10', page: 1, per_page: BET_RECORD_ALL_PERIOD_PER_PAGE },
      { search_day: '2026-09-09', page: 1, per_page: BET_RECORD_ALL_PERIOD_PER_PAGE },
      { search_day: '2026-09-08', page: 1, per_page: BET_RECORD_ALL_PERIOD_PER_PAGE },
    ])
    expect(result.ok).toBe(true)
    if (result.ok) expect(result.data.map(item => item.name)).toEqual(['V-2026-09-10', 'V-2026-09-09', 'V-2026-09-08'])
  })

  it('全部期間任一天失敗就停下並回錯誤', async () => {
    const post = vi.fn<TotalValidBetsPost>()
      .mockResolvedValueOnce({ code: 200, message: '', data: { items: [], pagination: {} } })
      .mockResolvedValueOnce({ code: 400, message: 'too old', data: { items: [], pagination: {} } })
    const result = await fetchBetsForDays(post, ['2026-09-10', '2026-09-09', '2026-09-08'])
    expect(result).toEqual({ ok: false, code: 400, message: 'too old' })
    expect(post).toHaveBeenCalledTimes(2)
  })

  it('未登記路由照原路徑與 body 轉發', async () => {
    const post = vi.fn(async (route: string, body: unknown): Promise<ApiEnvelope<unknown>> => ({ code: 200, message: '', data: { route, body } }))
    const res = await postUnregisteredRoute(post as never, 'prize/list', { page: 1, per_page: 999 })
    expect(post).toHaveBeenCalledWith('prize/list', { page: 1, per_page: 999 })
    expect(res.data).toEqual({ route: 'prize/list', body: { page: 1, per_page: 999 } })
  })
})
