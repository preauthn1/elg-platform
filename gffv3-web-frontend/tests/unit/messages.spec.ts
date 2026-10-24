import { beforeEach, describe, expect, it, vi } from 'vitest'
import { messagesEndpoints, systemNotifyItemSchema } from '../../shared/api/endpoints/messages'
import type { SystemNotifyItem } from '../../shared/api/endpoints/messages'
import {
  buildSystemNotifyHeader,
  commissionCell,
  commissionSummaryRows,
  createLatestGuard,
  filterPlatformAnnouncements,
  formatFixed2,
  hasLoseColumn,
  isMessagesRoute,
  platformAnnouncementAction,
  platformAnnouncementFilteredTotal,
  platformAnnouncementListContent,
  platformAnnouncementTagKey,
  readPagination,
  resolveAnnouncementMessage,
  sumUnread,
  systemNotifyContent,
  systemNotifyCurrencySetting,
  systemNotifyDetailKind,
  systemNotifyStatus,
  systemNotifyStatusKey,
  systemNotifyTagKey,
  systemNotifyTypeLabel,
  unreadBadgeText,
  unwrapSystemNotifyDetail,
} from '../../shared/utils/messages'

const { fakeStore } = vi.hoisted(() => ({
  fakeStore: {
    platformAnnouncementUnread: 0,
    systemNotificationUnread: 0,
    memberMailUnread: 0,
    announcement: null as unknown,
    announcementLoaded: false,
    selectedSystemNotification: null as unknown,
  },
}))

vi.mock('~/stores/messages', () => ({ useMessagesStore: () => fakeStore }))

const post = vi.fn()
const navigateTo = vi.fn()
const session = { loggedIn: { value: true } }
vi.stubGlobal('useApi', () => ({ post }))
vi.stubGlobal('isApiOk', (res: { code: number }) => res.code === 200)
vi.stubGlobal('navigateTo', navigateTo)
vi.stubGlobal('useUserSession', () => session)

const { useMessages, MESSAGES_PER_PAGE, SYSTEM_NOTIFICATION_DETAIL_PATH } = await import('../../app/composables/useMessages')

const ok = <T>(data: T) => ({ code: 200, message: 'success', data })
const fail = (code = 500) => ({ code, message: 'boom', data: null })

function notifyItem(overrides: Partial<SystemNotifyItem> = {}): SystemNotifyItem {
  return systemNotifyItemSchema.parse({
    data_name: 'transaction_logs',
    data_id: 1,
    tag: 'transaction',
    type: 17,
    content: '',
    currency_name: 'MYR',
    amount: 0,
    point: 12.345,
    status: 'success',
    data_type: 0,
    time: '2026-09-10 12:00:00',
    ...overrides,
  })
}

describe('messages endpoints 契約', () => {
  it('13 條路由都登記、auth 依 routes.json middleware', () => {
    const keys = Object.keys(messagesEndpoints).sort()
    expect(keys).toEqual([
      'announcement-message/latest',
      'announcement-message/read',
      'member-mail/list',
      'member-mail/read',
      'member-mail/read-all',
      'member-mail/unread',
      'platform-announcement/list',
      'platform-announcement/read-all',
      'platform-announcement/unread',
      'system-notify/detail',
      'system-notify/list',
      'system-notify/read-all',
      'system-notify/unread',
    ])
    expect(messagesEndpoints['announcement-message/latest'].auth).toBe('optional')
    expect(messagesEndpoints['announcement-message/read'].auth).toBe('optional')
    for (const key of keys.filter(k => !k.startsWith('announcement-message/'))) {
      expect(messagesEndpoints[key as keyof typeof messagesEndpoints].auth).toBe('member')
    }
    for (const [key, def] of Object.entries(messagesEndpoints)) expect(def.path).toBe(key)
  })

  it('request：數字字串會 coerce、page 最小 1、detail 的 data_name 只收兩種', () => {
    const list = messagesEndpoints['platform-announcement/list'].request
    expect(list.parse({ page: '2', per_page: '10' })).toEqual({ page: 2, per_page: 10 })
    expect(list.safeParse({ page: 0 }).success).toBe(false)
    expect(list.parse({})).toEqual({})

    const detail = messagesEndpoints['system-notify/detail'].request
    expect(detail.parse({ data_name: 'rolling_chip_logs', data_id: '9', type: '4', page: 1, per_page: 10 }))
      .toEqual({ data_name: 'rolling_chip_logs', data_id: 9, type: 4, page: 1, per_page: 10 })
    expect(detail.safeParse({ data_name: 'orders', data_id: 1, type: 2, page: 1, per_page: 10 }).success).toBe(false)
    expect(detail.safeParse({ data_name: 'transaction_logs', data_id: 1, type: 17 }).success).toBe(false)

    expect(messagesEndpoints['member-mail/list'].request.safeParse({ per_page: 51 }).success).toBe(false)
    expect(messagesEndpoints['member-mail/read'].request.safeParse({ id: 0 }).success).toBe(false)
    expect(messagesEndpoints['announcement-message/read'].request.parse({ announcement_message_id: '7' }))
      .toEqual({ announcement_message_id: 7 })
  })

  it('response：latest 與 detail 的 data 可以是 []', () => {
    const latest = messagesEndpoints['announcement-message/latest'].response
    expect(latest.safeParse([]).success).toBe(true)
    expect(latest.parse({ announcement_message_id: '3', title: 't', message: 'm', created_at: '2026-01-01 00:00:00' }))
      .toMatchObject({ announcement_message_id: 3, title: 't' })

    const detail = messagesEndpoints['system-notify/detail'].response
    expect(detail.safeParse([]).success).toBe(true)
    expect(detail.safeParse({ sender: 'a', receiver: 'b', amount: '1.5', tax: 0, time: 'x' }).success).toBe(true)
    expect(detail.safeParse({ data: { items: [], pagination: { total: 0 }, sum_data: { bet: 0, rebate: 0 }, time: 'x' } }).success).toBe(true)
  })

  it('response：分頁列表照 successWithPagination 形狀，0/1 收成 flag', () => {
    const list = messagesEndpoints['member-mail/list'].response.parse({
      items: [{ id: '1', title: 't', content: 'c', publish_time: '2026-01-01 00:00:00', is_read: '1' }],
      pagination: { per_page: 10, total: 1, pages: 1, previous: '', current: 1, next: '' },
    })
    expect(list.items[0]?.is_read).toBe(1)
    expect(list.pagination.total).toBe(1)

    const notify = messagesEndpoints['system-notify/list'].response.parse({
      items: [{ data_name: 'realname_validation_requests', data_id: 5, tag: '', type: '', content: 'ok', data_type: 2, time: 'x' }],
      pagination: { per_page: 10, total: 1, pages: 1, previous: '', current: 1, next: '' },
    })
    expect(notify.items[0]?.type).toBe('')
    expect(messagesEndpoints['platform-announcement/unread'].response.parse({ unread: '3' }).unread).toBe(3)
  })
})

describe('formatFixed2（截斷補零）', () => {
  it.each([
    [null, '0.00'],
    ['', '0.00'],
    [0, '0.00'],
    ['0', '0.00'],
    [3, '3.00'],
    [12.5, '12.50'],
    [1.239, '1.23'],
    ['1,234.567', '1234.56'],
    [-2.5, '-2.50'],
    ['abc', '0.00'],
  ])('%s → %s', (input, expected) => {
    expect(formatFixed2(input)).toBe(expected)
  })
})

describe('群發公告', () => {
  it('[] 收成 null、物件原樣、陣列取第一筆', () => {
    expect(resolveAnnouncementMessage([])).toBeNull()
    expect(resolveAnnouncementMessage(null)).toBeNull()
    expect(resolveAnnouncementMessage({ a: 1 })).toEqual({ a: 1 })
    expect(resolveAnnouncementMessage([{ a: 1 }, { a: 2 }])).toEqual({ a: 1 })
  })
})

describe('readPagination', () => {
  it('吃 OnlyPaginateResource 鍵，缺頁數就用 total/per_page 算', () => {
    expect(readPagination({ per_page: 10, total: 25, pages: 3, previous: '', current: 1, next: 2 }))
      .toEqual({ total: 25, perPage: 10, pages: 3, current: 1 })
    expect(readPagination({ per_page: 10, total: 25 })).toEqual({ total: 25, perPage: 10, pages: 3, current: 1 })
    expect(readPagination({ current_page: 2, last_page: 4, total: 40, per_page: 10 }))
      .toEqual({ total: 40, perPage: 10, pages: 4, current: 2 })
    expect(readPagination(undefined)).toEqual({ total: 0, perPage: 10, pages: 1, current: 1 })
  })
})

describe('平台公告', () => {
  const items = [
    { id: 1, tag: 1, type: 1, content: 'popup body', open_window: 0 as const },
    { id: 2, tag: 3, type: 2, content: 'https://x.test', open_window: 1 as const },
    { id: 3, tag: 3, type: 3, content: '/promotions', open_window: 0 as const },
    { id: 4, tag: 5, type: 0, content: null, open_window: 0 as const },
  ]

  it('tag 對照沿用舊前台', () => {
    expect([1, 2, 3, 4, 5, 9].map(platformAnnouncementTagKey)).toEqual(['messages.enum.notifyType.latest', 'games.lobby.nav.popular', 'site.nav.nav.events', 'games.common.label.game', 'common.ui.label.maintenance', ''])
  })

  it('非 P2 不過濾；P2 最新消息藏 tag 3；活動分頁只留 tag 3', () => {
    expect(filterPlatformAnnouncements(items, { isP2: false }).map(i => i.id)).toEqual([1, 2, 3, 4])
    expect(filterPlatformAnnouncements(items, { isP2: true }).map(i => i.id)).toEqual([1, 4])
    expect(filterPlatformAnnouncements(items, { isP2: true, tagFilter: 3 }).map(i => i.id)).toEqual([2, 3])
  })

  it('過濾後總數：活動 = 本頁長度；P2 最新 = total − 本頁 tag 3；其餘 total', () => {
    expect(platformAnnouncementFilteredTotal(items, 40, { isP2: false })).toBe(40)
    expect(platformAnnouncementFilteredTotal(items, 40, { isP2: true })).toBe(38)
    expect(platformAnnouncementFilteredTotal(items, 40, { isP2: true, tagFilter: 3 })).toBe(2)
  })

  it('列表內文只有彈窗型顯示 content', () => {
    expect(platformAnnouncementListContent(items[0]!)).toBe('popup body')
    expect(platformAnnouncementListContent(items[1]!)).toBe('')
  })

  it('點擊行為依 type 與 open_window', () => {
    expect(platformAnnouncementAction(items[0]!)).toEqual({ kind: 'popup' })
    expect(platformAnnouncementAction(items[1]!)).toEqual({ kind: 'external', url: 'https://x.test', newWindow: true })
    expect(platformAnnouncementAction({ type: 2, content: 'https://y.test', open_window: 0 }))
      .toEqual({ kind: 'external', url: 'https://y.test', newWindow: false })
    expect(platformAnnouncementAction(items[2]!)).toEqual({ kind: 'route', path: '/promotions' })
    expect(platformAnnouncementAction(items[3]!)).toEqual({ kind: 'none' })
  })
})

describe('系統通知：狀態／標籤／內文', () => {
  it('狀態接受字串與舊數字碼', () => {
    expect(systemNotifyStatus('pending')).toBe('pending')
    expect(systemNotifyStatus(0)).toBe('pending')
    expect(systemNotifyStatus('fail')).toBe('fail')
    expect(systemNotifyStatus('2')).toBe('fail')
    expect(systemNotifyStatus('success')).toBe('success')
    expect(systemNotifyStatus(3)).toBe('success')
    expect(systemNotifyStatus('')).toBeNull()
    expect(systemNotifyStatusKey('success')).toBe('common.ui.msg.success')
    expect(systemNotifyStatusKey(undefined)).toBe('')
  })

  it('幣別設定：禮碼 text_rolling_chip，其餘 text_wallet_point', () => {
    expect(systemNotifyCurrencySetting({ data_type: 1 })).toBe('text_rolling_chip')
    expect(systemNotifyCurrencySetting({ data_type: 0 })).toBe('text_wallet_point')
    expect(systemNotifyCurrencySetting({ data_type: 2 })).toBe('text_wallet_point')
  })

  it('tag → key，空 tag 回 null 讓畫面改顯示幣別', () => {
    expect(systemNotifyTagKey({ tag: 'orders' })).toBe('messages.enum.notifyType.orders')
    expect(systemNotifyTagKey({ tag: 'reward' })).toBe('messages.enum.notifyType.reward')
    expect(systemNotifyTagKey({ tag: 'system' })).toBe('messages.enum.notifyType.system')
    expect(systemNotifyTagKey({ tag: 'transaction' })).toBe('messages.enum.notifyType.transaction')
    expect(systemNotifyTagKey({ tag: '' })).toBeNull()
  })

  it('內文：綁定類原樣、其餘「幣別 金額」', () => {
    expect(systemNotifyContent({ data_type: 2, content: 'Changed phone', point: 5 }, 'MYR')).toBe('Changed phone')
    expect(systemNotifyContent({ data_type: 0, content: 'x', point: 12.345 }, 'MYR')).toBe('MYR 12.34')
    expect(systemNotifyContent({ data_type: 1, point: null }, 'Chips')).toBe('Chips 0.00')
  })

  it('動作名稱查表：訂單／禮碼／綁定／現金各自一張表', () => {
    expect(systemNotifyTypeLabel({ tag: 'orders', type: 1, data_type: 0 })).toEqual({ kind: 'i18n', key: 'withdrawal.entry.title.withdrawal' })
    expect(systemNotifyTypeLabel({ tag: 'orders', type: 2, data_type: 0 })).toEqual({ kind: 'i18n', key: 'deposit.common.title.deposit' })
    expect(systemNotifyTypeLabel({ tag: 'orders', type: 9, data_type: 0 })).toBeNull()

    expect(systemNotifyTypeLabel({ tag: 'reward', type: 4, data_type: 1 })).toEqual({ kind: 'i18n', key: 'messages.enum.notifyType.commission' })
    expect(systemNotifyTypeLabel({ tag: 'reward', type: 14, data_type: 1 })).toEqual({ kind: 'text', text: 'W-Jackpot' })
    expect(systemNotifyTypeLabel({ tag: 'reward', type: 18, data_type: 1 })).toEqual({ kind: 'setting', key: 'event_jackpot_title' })
    expect(systemNotifyTypeLabel({ tag: 'reward', type: '23', data_type: 1 })).toEqual({ kind: 'i18n', key: 'wallet.exchange.title.coupon' })

    expect(systemNotifyTypeLabel({ tag: 'system', type: 5, data_type: 2 })).toEqual({ kind: 'i18n', key: 'member.password.title.changePassword' })
    expect(systemNotifyTypeLabel({ tag: 'system', type: 8, data_type: 2 })).toBeNull()

    expect(systemNotifyTypeLabel({ tag: 'transaction', type: 17, data_type: 0 })).toEqual({ kind: 'i18n', key: 'messages.enum.notifyType.commission' })
    expect(systemNotifyTypeLabel({ tag: 'transaction', type: 37, data_type: 0 })).toEqual({ kind: 'i18n', key: 'records.enum.transactionType.memberTransferOut' })
    expect(systemNotifyTypeLabel({ tag: 'transaction', type: 38, data_type: 0 })).toEqual({ kind: 'i18n', key: 'records.enum.transactionType.memberTransferIn' })
    expect(systemNotifyTypeLabel({ tag: 'reward', type: 103, data_type: 0 })).toEqual({ kind: 'text', text: 'Jackpot' })
    expect(systemNotifyTypeLabel({ tag: 'reward', type: 109, data_type: 0 })).toEqual({ kind: 'i18n', key: 'records.enum.transactionType.missionBonus' })
    expect(systemNotifyTypeLabel({ tag: 'system', type: 999, data_type: 0 })).toBeNull()
  })
})

describe('系統通知詳情', () => {
  it('只有傭金與轉帳要打 detail', () => {
    expect(systemNotifyDetailKind({ data_name: 'transaction_logs', type: 17 })).toBe('commission')
    expect(systemNotifyDetailKind({ data_name: 'transaction_logs', type: 37 })).toBe('transfer')
    expect(systemNotifyDetailKind({ data_name: 'transaction_logs', type: '38' })).toBe('transfer')
    expect(systemNotifyDetailKind({ data_name: 'rolling_chip_logs', type: 4 })).toBe('commission')
    expect(systemNotifyDetailKind({ data_name: 'rolling_chip_logs', type: 1 })).toBeNull()
    expect(systemNotifyDetailKind({ data_name: 'transaction_logs', type: 34 })).toBeNull()
    expect(systemNotifyDetailKind({ data_name: 'orders', type: 2 })).toBeNull()
    expect(systemNotifyDetailKind({ data_name: 'realname_validation_requests', type: '' })).toBeNull()
  })

  it('unwrap：找不到、平鋪傭金、rolling_chip 多包一層 data、轉帳', () => {
    expect(unwrapSystemNotifyDetail([])).toEqual({ kind: 'notFound' })
    expect(unwrapSystemNotifyDetail(null)).toEqual({ kind: 'notFound' })
    expect(unwrapSystemNotifyDetail({})).toEqual({ kind: 'notFound' })

    const flat = unwrapSystemNotifyDetail({
      items: [{ from_member_name: 'a**c', vendor_name: 'V', bet: 10, lose: 0, rebate: 1, rebate_type: 1 }],
      pagination: { per_page: 10, total: 1, pages: 1, current: 1 },
      sum_data: { bet: '10', lose: 0, rebate: '1.5' },
      time: '2026-09-10 00:00:00',
    })
    expect(flat).toMatchObject({ kind: 'commission', sum_data: { bet: 10, lose: 0, rebate: 1.5 }, time: '2026-09-10 00:00:00' })
    expect(flat.kind === 'commission' && flat.pagination.pages).toBe(1)

    const nested = unwrapSystemNotifyDetail({
      data: {
        items: [],
        pagination: { per_page: 10, total: 0, pages: 1, current: 1 },
        sum_data: { bet: 3, rebate: 0.5 },
        time: 't',
      },
    })
    expect(nested).toMatchObject({ kind: 'commission', items: [], sum_data: { bet: 3, lose: 0, rebate: 0.5 } })

    expect(unwrapSystemNotifyDetail({ sender: 'alice', receiver: 'bob', amount: '100', tax: '1', time: 't' }))
      .toEqual({ kind: 'transfer', sender: 'alice', receiver: 'bob', amount: 100, tax: 1, time: 't' })
  })

  it('傭金摘要列：流水／輸分 > 0 才有，獎勵一律有', () => {
    expect(commissionSummaryRows({ bet: 100, lose: 0, rebate: 2 }, 'MYR')).toEqual([
      { labelKey: 'records.bet.label.turnover', value: 'MYR 100.00' },
      { labelKey: 'common.amount.label.bonus', value: 'MYR 2.00' },
    ])
    expect(commissionSummaryRows({ bet: 0, lose: 50, rebate: 1 }, 'MYR')).toEqual([
      { labelKey: 'messages.detail.label.lose', value: 'MYR 50.00' },
      { labelKey: 'common.amount.label.bonus', value: 'MYR 1.00' },
    ])
    expect(commissionSummaryRows({ bet: 0, lose: 0, rebate: 0 }, 'MYR')).toEqual([
      { labelKey: 'common.amount.label.bonus', value: 'MYR 0.00' },
    ])
  })

  it('表格欄：流水只在 rebate_type 1、輸分只在 rebate_type 2，否則 -', () => {
    const betRow = { bet: 10, lose: 0, rebate: 1, rebate_type: 1 }
    const loseRow = { bet: 0, lose: 20, rebate: 1, rebate_type: 2 }
    expect(commissionCell(betRow, 'bet')).toBe('10.00')
    expect(commissionCell(betRow, 'lose')).toBe('-')
    expect(commissionCell(loseRow, 'bet')).toBe('-')
    expect(commissionCell(loseRow, 'lose')).toBe('20.00')
    expect(hasLoseColumn([betRow], { bet: 10, lose: 0, rebate: 1 })).toBe(false)
    expect(hasLoseColumn([loseRow], { bet: 0, lose: 0, rebate: 1 })).toBe(true)
    expect(hasLoseColumn([], { bet: 0, lose: 5, rebate: 1 })).toBe(true)
  })

  it('表頭：提款單 = 站台付款、會員收款、帳號／戶名列；存款單相反；其餘用類型名稱', () => {
    const withdrawal = buildSystemNotifyHeader(notifyItem({
      data_name: 'withdrawal_orders',
      tag: 'orders',
      type: 1,
      content: 'Maybank',
      content_1: '1234567890',
      content_2: 'Alice',
      amount: 250.5,
      status: 'pending',
    }), { username: 'alice01', currencyName: 'MYR' })
    expect(withdrawal.statusKey).toBe('records.enum.orderStatus.pending')
    expect(withdrawal.actionName).toEqual({ kind: 'text', text: 'Maybank' })
    expect(withdrawal.rows).toEqual([
      { labelKey: 'common.time.label.date', value: '2026-09-10 12:00:00' },
      { labelKey: 'common.amount.label.amount', value: 'MYR 250.50' },
      { labelKey: 'common.bank.label.accountNo', value: '1234567890' },
      { labelKey: 'common.bank.label.accountName', value: 'Alice' },
    ])
    expect(withdrawal.payer).toEqual({ kind: 'logo' })
    expect(withdrawal.payee).toEqual({ kind: 'text', text: 'alice01' })

    const deposit = buildSystemNotifyHeader(notifyItem({ data_name: 'orders', tag: 'orders', type: 2, content: 'FPX', amount: 100 }), { username: 'alice01', currencyName: 'MYR' })
    expect(deposit.payer).toEqual({ kind: 'text', text: 'alice01' })
    expect(deposit.payee).toEqual({ kind: 'logo' })
    expect(deposit.rows.map(r => r.labelKey)).toEqual(['common.time.label.date', 'common.amount.label.amount'])

    const commission = buildSystemNotifyHeader(notifyItem(), { username: 'alice01', currencyName: 'MYR' })
    expect(commission.actionName).toEqual({ kind: 'i18n', key: 'messages.enum.notifyType.commission' })
    expect(commission.content).toBe('MYR 12.34')
    expect(commission.payer).toBeNull()
    expect(commission.rows).toEqual([{ labelKey: 'common.time.label.date', value: '2026-09-10 12:00:00' }])
  })
})

describe('鈴鐺與工具', () => {
  it('未讀加總與 99+ 上限', () => {
    expect(sumUnread(1, 2, 3)).toBe(6)
    expect(sumUnread(null, undefined, -5, 2)).toBe(2)
    expect(unreadBadgeText(0)).toBe('')
    expect(unreadBadgeText(7)).toBe('7')
    expect(unreadBadgeText(99)).toBe('99')
    expect(unreadBadgeText(100)).toBe('99+')
  })

  it('通知中心路由判斷', () => {
    expect(isMessagesRoute('/messages')).toBe(true)
    expect(isMessagesRoute('/messages/system')).toBe(true)
    expect(isMessagesRoute('/messages-x')).toBe(false)
    expect(isMessagesRoute('/')).toBe(false)
  })

  it('最後一次請求守衛', () => {
    const guard = createLatestGuard()
    const first = guard.next()
    const second = guard.next()
    expect(guard.isLatest(first)).toBe(false)
    expect(guard.isLatest(second)).toBe(true)
  })
})

describe('useMessages composable', () => {
  beforeEach(() => {
    post.mockReset()
    navigateTo.mockReset()
    session.loggedIn.value = true
    fakeStore.platformAnnouncementUnread = 5
    fakeStore.systemNotificationUnread = 3
    fakeStore.memberMailUnread = 2
    fakeStore.announcement = null
    fakeStore.announcementLoaded = false
    fakeStore.selectedSystemNotification = null
  })

  it('fetchLatestAnnouncement：[] → null、物件 → 存進 store、失敗不動', async () => {
    const { fetchLatestAnnouncement } = useMessages()
    post.mockResolvedValueOnce(ok([]))
    await fetchLatestAnnouncement()
    expect(post).toHaveBeenCalledWith('announcement-message/latest', {})
    expect(fakeStore.announcement).toBeNull()
    expect(fakeStore.announcementLoaded).toBe(true)

    const item = { announcement_message_id: 9, title: 't', message: 'm' }
    post.mockResolvedValueOnce(ok(item))
    await fetchLatestAnnouncement()
    expect(fakeStore.announcement).toEqual(item)

    post.mockResolvedValueOnce(fail())
    const res = await fetchLatestAnnouncement()
    expect(res.code).toBe(500)
    expect(fakeStore.announcement).toEqual(item)
  })

  it('readAnnouncement：先清狀態再送、silent、回應不看', async () => {
    const { readAnnouncement } = useMessages()
    fakeStore.announcement = { announcement_message_id: 9 }
    post.mockImplementationOnce(async () => {
      expect(fakeStore.announcement).toBeNull()
      return fail()
    })
    await readAnnouncement(9)
    expect(post).toHaveBeenCalledWith('announcement-message/read', { announcement_message_id: 9 }, { silent: true })
  })

  it('loadPlatformAnnouncementsTab：第一頁成功才 read-all 並歸零；第二頁與失敗都不會', async () => {
    const { loadPlatformAnnouncementsTab } = useMessages()
    const page = ok({ items: [], pagination: { total: 0 } })
    post.mockResolvedValueOnce(page).mockResolvedValueOnce(ok({ lastReadTimeUtc: 'x' }))
    await loadPlatformAnnouncementsTab(1)
    expect(post).toHaveBeenNthCalledWith(1, 'platform-announcement/list', { page: 1, per_page: MESSAGES_PER_PAGE })
    expect(post).toHaveBeenNthCalledWith(2, 'platform-announcement/read-all', {}, { silent: true })
    expect(fakeStore.platformAnnouncementUnread).toBe(0)

    post.mockReset()
    fakeStore.platformAnnouncementUnread = 5
    post.mockResolvedValueOnce(page)
    await loadPlatformAnnouncementsTab(2)
    expect(post).toHaveBeenCalledTimes(1)
    expect(fakeStore.platformAnnouncementUnread).toBe(5)

    post.mockReset()
    post.mockResolvedValueOnce(fail())
    await loadPlatformAnnouncementsTab(1)
    expect(post).toHaveBeenCalledTimes(1)
    expect(fakeStore.platformAnnouncementUnread).toBe(5)
  })

  it('unread 三支：200 才寫 store', async () => {
    const m = useMessages()
    post.mockResolvedValueOnce(ok({ unread: 7 }))
    await m.fetchPlatformAnnouncementUnread()
    expect(post).toHaveBeenCalledWith('platform-announcement/unread', {}, { silent: true })
    expect(fakeStore.platformAnnouncementUnread).toBe(7)

    post.mockResolvedValueOnce(fail(9998))
    await m.fetchSystemNotifyUnread()
    expect(fakeStore.systemNotificationUnread).toBe(3)

    post.mockResolvedValueOnce(ok({ unread: 1 }))
    await m.fetchMemberMailUnread()
    expect(post).toHaveBeenLastCalledWith('member-mail/unread', {}, { silent: true })
    expect(fakeStore.memberMailUnread).toBe(1)
  })

  it('refreshUnread：未登入不打；登入打三支', async () => {
    const { refreshUnread } = useMessages()
    session.loggedIn.value = false
    await refreshUnread()
    expect(post).not.toHaveBeenCalled()

    session.loggedIn.value = true
    post.mockResolvedValue(ok({ unread: 0 }))
    await refreshUnread()
    expect(post.mock.calls.map(c => c[0]).sort()).toEqual([
      'member-mail/unread',
      'platform-announcement/unread',
      'system-notify/unread',
    ])
  })

  it('loadSystemNotificationsTab：第一頁成功後 read-all（silent）並歸零', async () => {
    const { loadSystemNotificationsTab } = useMessages()
    post.mockResolvedValueOnce(ok({ items: [], pagination: {} })).mockResolvedValueOnce(ok([]))
    await loadSystemNotificationsTab()
    expect(post).toHaveBeenNthCalledWith(1, 'system-notify/list', { page: 1, per_page: 10 })
    expect(post).toHaveBeenNthCalledWith(2, 'system-notify/read-all', {}, { silent: true })
    expect(fakeStore.systemNotificationUnread).toBe(0)
  })

  it('fetchSystemNotifyDetail：非傭金／轉帳回 null 不打；否則帶列表項目的 data_name/data_id/type', async () => {
    const { fetchSystemNotifyDetail } = useMessages()
    expect(await fetchSystemNotifyDetail(notifyItem({ data_name: 'orders', tag: 'orders', type: 2 }))).toBeNull()
    expect(await fetchSystemNotifyDetail(notifyItem({ data_name: 'transaction_logs', type: 34 }))).toBeNull()
    expect(post).not.toHaveBeenCalled()

    post.mockResolvedValueOnce(ok({ items: [] }))
    await fetchSystemNotifyDetail(notifyItem({ data_id: 77, type: 17 }), 2)
    expect(post).toHaveBeenCalledWith('system-notify/detail', { data_name: 'transaction_logs', data_id: 77, type: 17, page: 2, per_page: 10 })

    post.mockResolvedValueOnce(ok({ data: { items: [] } }))
    await fetchSystemNotifyDetail(notifyItem({ data_name: 'rolling_chip_logs', data_id: 5, type: 4, data_type: 1 }))
    expect(post).toHaveBeenLastCalledWith('system-notify/detail', { data_name: 'rolling_chip_logs', data_id: 5, type: 4, page: 1, per_page: 10 })

    post.mockResolvedValueOnce(ok({ sender: 'a', receiver: 'b' }))
    await fetchSystemNotifyDetail(notifyItem({ data_id: 6, type: 38 }))
    expect(post).toHaveBeenLastCalledWith('system-notify/detail', { data_name: 'transaction_logs', data_id: 6, type: 38, page: 1, per_page: 10 })
  })

  it('selectSystemNotification：暫存到 store 並導詳情頁', async () => {
    const { selectSystemNotification } = useMessages()
    const item = notifyItem()
    await selectSystemNotification(item)
    expect(fakeStore.selectedSystemNotification).toBe(item)
    expect(navigateTo).toHaveBeenCalledWith(SYSTEM_NOTIFICATION_DETAIL_PATH)
  })

  it('站內信：第一頁成功後 read-all 並歸零；單封已讀帶 id', async () => {
    const { loadMemberMailsTab, readMemberMail } = useMessages()
    post.mockResolvedValueOnce(ok({ items: [], pagination: {} })).mockResolvedValueOnce(ok([]))
    await loadMemberMailsTab(1)
    expect(post).toHaveBeenNthCalledWith(1, 'member-mail/list', { page: 1, per_page: 10 })
    expect(post).toHaveBeenNthCalledWith(2, 'member-mail/read-all', {})
    expect(fakeStore.memberMailUnread).toBe(0)

    post.mockResolvedValueOnce(ok([]))
    await readMemberMail(42)
    expect(post).toHaveBeenLastCalledWith('member-mail/read', { id: 42 })
  })
})
