import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { eventsJackpotEndpoints } from '../../shared/api/endpoints/events-jackpot'
import {
  formatEventAmount,
  hasUnclaimedJackpotPrize,
  isJackpotOk,
  jackpotAmountAt,
  jackpotNextResetAt,
  jackpotNotificationImageUrl,
  jackpotPrizeCountdown,
  msUntilNextJackpotReset,
  parseLocalDateTime,
} from '../../app/utils/jackpot'
import {
  LOTTERY_VARIANTS,
  lotteryCanReceiveAll,
  lotteryDrawDate,
  lotteryPagePath,
  lotteryReceiveMessage,
  lotteryRowNumber,
  lotteryShowPagination,
  lotteryTicketClaimable,
  lotteryTicketLabel,
  lotteryTicketShowLevelIcon,
  wjackpotResultGroups,
} from '../../app/utils/lottery'
import {
  growthBuildTiers,
  growthCountdownParts,
  growthCurrentTier,
  growthHasCountdown,
  growthHasTpEvent,
  growthIsRestricted,
  growthMergeDetail,
  growthNewlyOpenedLevels,
  growthNotifiedCookieName,
  growthSecondsUntil,
  growthTierClaimable,
  growthTierStatus,
  type GrowthLevelLike,
} from '../../app/utils/progressive-jackpot'
import { useJackpotStore } from '../../app/stores/jackpot'
import { useJackpot } from '../../app/composables/useJackpot'
import { useJackpotNotifications } from '../../app/composables/useJackpotNotifications'
import { useLottery } from '../../app/composables/useLottery'
import { useProgressiveJackpot } from '../../app/composables/useProgressiveJackpot'

// vue 不是專案的直接相依（只透過 nuxt／pinia 帶進來），vitest 從專案根目錄解析不到。
// 從 pinia 的實際安裝位置借同一份 vue，讓 composable、store 與 pinia 用的是同一個實例。
vi.mock('vue', async () => {
  const { createRequire } = await import('node:module')
  const { realpathSync } = await import('node:fs')
  const { pathToFileURL } = await import('node:url')
  const piniaEntry = realpathSync(createRequire(import.meta.url).resolve('pinia'))
  const vueEntry = createRequire(piniaEntry).resolve('vue')
  const mod = (await import(pathToFileURL(vueEntry).href)) as Record<string, unknown> & { default?: Record<string, unknown> }
  return { ...(mod.default ?? {}), ...mod }
})

// ---------- 假的 Nuxt 自動匯入 ----------

interface Envelope {
  code: number
  message: string
  data: unknown
}

const ok = (data: unknown, code = 200): Envelope => ({ code, message: 'ok', data })
const fail = (code: number, message = `error ${code}`): Envelope => ({ code, message, data: null })

let handlers: Record<string, (body: unknown) => Envelope>
let calls: Array<{ route: string, body: unknown }>
const navigateTo = vi.fn(async () => undefined)
let cookies: Map<string, ReturnType<typeof ref>>

const post = vi.fn(async (route: string, body: unknown): Promise<Envelope> => {
  calls.push({ route, body })
  const handler = handlers[route]
  return handler ? handler(body) : fail(404, `unknown endpoint: ${route}`)
})

/** 全站彈窗：API message 一律走 useAlert，測試只看有沒有彈、彈了什麼 */
const alertShow = vi.fn()

function routesCalled(): string[] {
  return calls.map(c => c.route)
}

beforeEach(() => {
  handlers = {}
  calls = []
  cookies = new Map()
  navigateTo.mockClear()
  post.mockClear()
  alertShow.mockClear()
  setActivePinia(createPinia())
  vi.stubGlobal('useApi', () => ({ post }))
  vi.stubGlobal('useI18n', () => ({ t: (key: string) => key }))
  vi.stubGlobal('useUserSession', () => ({ user: ref({ id: 1, username: 'alice' }), loggedIn: ref(true) }))
  vi.stubGlobal('useCookie', (name: string, opts?: { default?: () => unknown }) => {
    if (!cookies.has(name)) cookies.set(name, ref(opts?.default?.() ?? null))
    return cookies.get(name)
  })
  vi.stubGlobal('navigateTo', navigateTo)
  vi.stubGlobal('useAlert', () => ({ show: alertShow }))
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

// ---------- 樣本資料 ----------

const showSample = {
  start_time: '2026-09-10 15:00:00',
  end_time: '2026-09-11 15:00:00',
  title: 'Jackpot',
  content: '<p>rule</p>',
  banner: 'https://img/banner.png',
  jackpot: '1000.0000',
  prize: [
    { id: 11, event_id: 3, point: '88.0000', image: 'https://img/p.png', status: 0, expired_time: '2026-09-12 10:30:00' },
    { id: 12, event_id: 3, point: '18.0000', image: 'https://img/p.png', status: 1, expired_time: null },
  ],
}

const ticketsSample = {
  result_number: '1234567',
  items: [
    { ticket_id: 1, ticket_number: '1111111', reward_type: 1, reward_level: 2, reward_amount: '888.00', reward_name: '', type: 1, status: 2, statusText: 'Unclaimed' },
    { ticket_id: 2, ticket_number: '2222222', reward_type: null, reward_level: 0, reward_amount: '0.00', reward_name: '', type: 0, status: 3, statusText: 'Not won' },
  ],
  pagination: { per_page: 10, total: 12, pages: 2, previous: '', current: 1, next: 2 },
}

function level(overrides: Partial<GrowthLevelLike> & { level: number }): GrowthLevelLike {
  return {
    status: null,
    reward: '1888.0000',
    point: '0.0000',
    receive: '0.0000',
    remaining: null,
    progress: '0',
    // 後端每一檔都會回，見 docs/api-map/events-jackpot.json 的 progressive-jackpot/list
    last_point: '0.0000',
    remaining_deposit: '3000.0000',
    expire_time: null,
    deposit_point: '3000.0000',
    reward_type: 1,
    reward_percentage: '0.0000',
    max_reward: '0.0000',
    expire_day: 0,
    ...overrides,
  }
}

const growthSample = {
  setting: { status: 1, title: 'Growth', content: '<p>c</p>', image: null },
  summary: {
    level: 0,
    reward: '5888.0000',
    status: 0,
    progress: '30.00',
    can_receive: '1766.4000',
    pending_unlock: '3621.6000',
    unlimited_time: true,
    expire_time: null,
    completed_count: 0,
    total_count: 3,
  },
  levels: [
    level({ level: 0, status: 0, reward: '5888.0000', point: '2000.0000', receive: '233.6000', remaining: '3888.0000', progress: '30.00', deposit_point: '0.0000', reward_type: 2, reward_percentage: '200.0000', max_reward: '5888.0000' }),
    level({ level: 1, status: 1, deposit_point: '3000.0000' }),
    level({ level: 2, status: null, deposit_point: '10000.0000', expire_day: 7 }),
  ],
  bet_settings: [{ game_type_id: 1, rate: '0.3000' }],
}

// ---------- 端點契約 ----------

describe('events-jackpot 端點契約', () => {
  const keys = Object.keys(eventsJackpotEndpoints)

  it('22 條路由全部登記且都是 member', () => {
    expect(keys).toHaveLength(22)
    for (const key of keys) {
      const def = eventsJackpotEndpoints[key as keyof typeof eventsJackpotEndpoints]
      expect(def.path).toBe(key)
      expect(def.auth).toBe('member')
    }
    expect(keys).toContain('event/jackpot/check-notify')
    expect(keys).toContain('event/wjackpot6d/issues/receive-all')
    expect(keys).toContain('event/progressive-jackpot/receive')
  })

  it('request 依 FormRequest：id 必須是 ≥ 1 的整數，字串會被轉成數字', () => {
    const receive = eventsJackpotEndpoints['event/jackpot/receive'].request
    expect(receive.safeParse({ id: 0 }).success).toBe(false)
    expect(receive.safeParse({ id: 1.5 }).success).toBe(false)
    expect(receive.parse({ id: '5' })).toEqual({ id: 5 })

    const tickets = eventsJackpotEndpoints['event/lucky-draw/issues/tickets'].request
    expect(tickets.safeParse({ issue_id: 1, page: 0, per_page: 10 }).success).toBe(false)
    expect(tickets.parse({ issue_id: '7', page: '2', per_page: '10' })).toEqual({ issue_id: 7, page: 2, per_page: 10 })

    expect(eventsJackpotEndpoints['event/progressive-jackpot/receive'].request.parse({ level: '0' })).toEqual({ level: 0 })
  })

  it('show 的 data 可為 null，獎項 status 收成 0/1', () => {
    const schema = eventsJackpotEndpoints['event/jackpot/show'].response
    expect(schema.parse(null)).toBeNull()
    const parsed = schema.parse({ ...showSample, prize: [{ ...showSample.prize[0], status: '1' }] })
    expect(parsed?.prize[0]?.status).toBe(1)
    expect(parsed?.jackpot).toBe('1000.0000')
  })

  it('tickets 回應保留 result_number 與 OnlyPaginateResource 的分頁欄位', () => {
    const parsed = eventsJackpotEndpoints['event/wjackpot6d/issues/tickets'].response.parse(ticketsSample)
    expect(parsed.result_number).toBe('1234567')
    expect(parsed.items).toHaveLength(2)
    expect(parsed.pagination.pages).toBe(2)
    expect(parsed.pagination.previous).toBe('')
    expect(parsed.pagination.next).toBe(2)
  })

  it('W-Jackpot 明細要有五組開獎號碼', () => {
    const schema = eventsJackpotEndpoints['event/wjackpot/issues/detail'].response
    const base = { issue_id: 1, issue_name: '1st', begin_time: 'a', end_time: 'b', draw_time: null, result_number: '----', summary: { totalCount_tickets: 0, totalCount_winnings: '0.00', totalCount_unclaimed: '0.00' } }
    expect(schema.safeParse(base).success).toBe(false)
    expect(schema.safeParse({ ...base, result_number_2: '----', result_number_3: '----', result_number_4: ['----'], result_number_5: ['----'] }).success).toBe(true)
  })

  it('成長彩金 list 回應照後端形狀', () => {
    const parsed = eventsJackpotEndpoints['event/progressive-jackpot/list'].response.parse(growthSample)
    expect(parsed.summary.unlimited_time).toBe(true)
    expect(parsed.summary.level).toBe(0)
    expect(parsed.levels[2]?.status).toBeNull()
    expect(parsed.setting.status).toBe(1)
  })
})

// ---------- 積寶純函式 ----------

describe('jackpot 規則', () => {
  it('200 與 1000 都算成功', () => {
    expect(isJackpotOk(200)).toBe(true)
    expect(isJackpotOk(1000)).toBe(true)
    expect(isJackpotOk(1946)).toBe(false)
  })

  it('金額 = jackpot + 距最近一次 15:00 的秒數', () => {
    expect(jackpotAmountAt('1000.0000', new Date(2026, 8, 10, 15, 0, 10))).toBe('1010')
    // 14:59:59 → 以昨天 15:00 為基準：86399 秒
    expect(jackpotAmountAt(1000, new Date(2026, 8, 10, 14, 59, 59))).toBe(String(1000 + 86399))
  })

  it('下一次 15:00 的排程時間', () => {
    expect(jackpotNextResetAt(new Date(2026, 8, 10, 14, 0, 0))).toEqual(new Date(2026, 8, 10, 15, 0, 0))
    expect(jackpotNextResetAt(new Date(2026, 8, 10, 15, 0, 0))).toEqual(new Date(2026, 8, 11, 15, 0, 0))
    expect(msUntilNextJackpotReset(new Date(2026, 8, 10, 14, 59, 58))).toBe(2000)
  })

  it('獎項倒數 H:mm，小時含天數，負數為過期', () => {
    const now = new Date(2026, 8, 10, 8, 0, 0)
    expect(jackpotPrizeCountdown('2026-09-11 10:05:30', now)).toEqual({ expired: false, text: '26:05' })
    expect(jackpotPrizeCountdown('2026-09-10 07:59:59', now)).toEqual({ expired: true, text: '' })
    expect(jackpotPrizeCountdown(null, now)).toBeNull()
    expect(parseLocalDateTime('bad')).toBeNull()
  })

  it('底部紅點：有 status 0 的獎項', () => {
    expect(hasUnclaimedJackpotPrize(showSample.prize)).toBe(true)
    expect(hasUnclaimedJackpotPrize([{ id: 1, status: 1 }])).toBe(false)
    expect(hasUnclaimedJackpotPrize(null)).toBe(false)
  })

  it('通知圖片依 id 破快取；金額顯示截斷兩位', () => {
    expect(jackpotNotificationImageUrl({ id: 9, image: 'https://img/a.png' })).toBe('https://img/a.png?rid=9')
    expect(formatEventAmount('12.999')).toBe('12.99')
    expect(formatEventAmount(1000)).toBe('1000.00')
    expect(formatEventAmount('1,234.5')).toBe('1234.50')
    expect(formatEventAmount('')).toBe('0.00')
    expect(formatEventAmount(null)).toBe('0.00')
  })
})

// ---------- 抽獎券純函式 ----------

describe('lottery 規則', () => {
  const cash = ticketsSample.items[0]!
  const lost = ticketsSample.items[1]!
  const physical = { ...cash, ticket_id: 3, reward_type: 2, reward_amount: '0.00', reward_name: 'Car', reward_level: 1 }

  it('可領：有獎且 status 2', () => {
    expect(lotteryTicketClaimable(cash)).toBe(true)
    expect(lotteryTicketClaimable(physical)).toBe(true)
    expect(lotteryTicketClaimable(lost)).toBe(false)
    expect(lotteryTicketClaimable({ ...cash, status: 1 })).toBe(false)
    expect(lotteryTicketClaimable({ ...cash, status: 4 })).toBe(false)
  })

  it('按鈕文字與獎級圖示', () => {
    expect(lotteryTicketLabel(cash)).toBe('888.00')
    expect(lotteryTicketLabel(physical)).toBe('Car')
    expect(lotteryTicketLabel(lost)).toBe('Not won')
    expect(lotteryTicketShowLevelIcon(cash)).toBe(true)
    expect(lotteryTicketShowLevelIcon({ ...cash, reward_level: 0 })).toBe(false)
    expect(lotteryTicketShowLevelIcon(lost)).toBe(false)
  })

  it('序號、分頁顯示、全部領取條件、開獎日期', () => {
    expect(lotteryRowNumber(2, 10, 0)).toBe(11)
    expect(lotteryShowPagination(12, 10)).toBe(true)
    expect(lotteryShowPagination(10, 10)).toBe(false)
    expect(lotteryCanReceiveAll({ totalCount_unclaimed: '0.00' })).toBe(false)
    expect(lotteryCanReceiveAll({ totalCount_unclaimed: '18.00' })).toBe(true)
    expect(lotteryCanReceiveAll(null)).toBe(false)
    expect(lotteryDrawDate('2026-09-10 19:00:00')).toBe('2026-09-10')
    expect(lotteryDrawDate(null)).toBe('')
  })

  it('W-Jackpot 五組開獎：第一組用 result_number，陣列以空白串接', () => {
    const groups = wjackpotResultGroups({
      result_number: '1234',
      result_number_2: '5678',
      result_number_3: '----',
      result_number_4: ['0001', '0002'],
      result_number_5: ['----'],
    })
    expect(groups.map(g => g.key)).toEqual(['wjackpot5-1', 'wjackpot5-2', 'wjackpot5-3', 'wjackpot5-4', 'wjackpot5-5'])
    expect(groups.map(g => g.value)).toEqual(['1234', '5678', '----', '0001 0002', '----'])
  })

  it('三變體各自的端點、路徑與佔位字不共用', () => {
    expect(LOTTERY_VARIANTS['lucky-draw'].placeholder).toBe('-------')
    expect(LOTTERY_VARIANTS.wjackpot.placeholder).toBe('----')
    expect(LOTTERY_VARIANTS.wjackpot6d.placeholder).toBe('------')
    expect(LOTTERY_VARIANTS.wjackpot6d.routes.receiveAll).toBe('event/wjackpot6d/issues/receive-all')
    expect(lotteryPagePath('lucky-draw', 'issues')).toBe('/lucky-draw/issues')
    expect(lotteryPagePath('wjackpot', 'detail', 5)).toBe('/wjackpot/detail/5')
    expect(lotteryPagePath('wjackpot6d', 'tickets', 5)).toBe('/wjackpot/6d/tickets/5')
    expect(lotteryReceiveMessage(k => k)).toBe('common.ui.msg.success！events.common.msg.contactCs')
  })
})

// ---------- 成長彩金純函式 ----------

describe('progressive jackpot 規則', () => {
  const tiers = growthBuildTiers(growthSample.levels)

  it('狀態對照：null 未達標、2/5 都是已完成', () => {
    expect(growthTierStatus(null)).toBe('pending')
    expect(growthTierStatus(0)).toBe('ongoing')
    expect(growthTierStatus(1)).toBe('reached')
    expect(growthTierStatus(2)).toBe('done')
    expect(growthTierStatus(3)).toBe('expired')
    expect(growthTierStatus(4)).toBe('locked')
    expect(growthTierStatus(5)).toBe('done')
    expect(growthTierStatus(99)).toBe('pending')
  })

  it('檔位：可領取 = 已解鎖 − 已領取；首充百分比才顯示「首充 N% 最高 X」', () => {
    expect(tiers[0]?.claimable).toBeCloseTo(1766.4)
    expect(tiers[0]?.showRateRule).toBe(true)
    expect(tiers[0]?.percent).toBe(200)
    expect(tiers[1]?.showRateRule).toBe(false)
    expect(tiers[2]?.status).toBe('pending')
    expect(tiers[2]?.limitDays).toBe(7)
  })

  it('當前檔位：summary.level 優先，null 退回第一個未結算檔，再退回最後一檔', () => {
    expect(growthCurrentTier(tiers, 1)?.level).toBe(1)
    expect(growthCurrentTier(tiers, null)?.level).toBe(0)
    const settled = growthBuildTiers([level({ level: 0, status: 2 }), level({ level: 1, status: 4 })])
    expect(growthCurrentTier(settled, null)?.level).toBe(1)
    expect(growthCurrentTier([], null)).toBeNull()
  })

  it('領取條件、資格限制', () => {
    expect(growthTierClaimable(tiers[0]!)).toBe(true)
    expect(growthTierClaimable(tiers[1]!)).toBe(false)
    const done = growthBuildTiers([level({ level: 0, status: 2, point: '100.0000', receive: '100.0000' })])[0]!
    expect(growthTierClaimable(done)).toBe(false)
    const doneWithBalance = growthBuildTiers([level({ level: 0, status: 2, point: '100.0000', receive: '40.0000' })])[0]!
    expect(growthTierClaimable(doneWithBalance)).toBe(true)
    expect(growthIsRestricted(tiers)).toBe(false)
    expect(growthIsRestricted(growthBuildTiers([level({ level: 0, status: 4 })]))).toBe(true)
  })

  it('倒數：本地時間解析、不小於 0；只有總覽不限時且沒有檔位到期才不用每秒重算', () => {
    const now = new Date(2026, 8, 10, 12, 0, 0).getTime()
    expect(growthSecondsUntil('2026-09-11 12:00:05', now)).toBe(86405)
    expect(growthSecondsUntil('2026-09-10 11:00:00', now)).toBe(0)
    expect(growthSecondsUntil(null, now)).toBe(0)
    expect(growthCountdownParts(86405)).toEqual({ days: 1, hours: '00', minutes: '00', seconds: '05' })
    expect(growthCountdownParts(3661)).toEqual({ days: 0, hours: '01', minutes: '01', seconds: '01' })
    expect(growthHasCountdown(false, [])).toBe(true)
    expect(growthHasCountdown(true, tiers)).toBe(false)
    expect(growthHasCountdown(true, growthBuildTiers([level({ level: 0, status: 0, expire_time: '2026-09-17 00:00:00' })]))).toBe(true)
  })

  it('新開啟檔位提示只算進行中且沒提示過的', () => {
    expect(growthNewlyOpenedLevels(tiers, [])).toEqual([0])
    expect(growthNewlyOpenedLevels(tiers, [0])).toEqual([])
    expect(growthNotifiedCookieName('alice')).toBe('wf_growth_notified_alice')
    expect(growthNotifiedCookieName('')).toBe('wf_growth_notified')
  })

  it('receive 回應只合併 summary/levels，空值不動原資料', () => {
    const merged = growthMergeDetail(growthSample, { summary: { ...growthSample.summary, level: 1 }, levels: [] })
    expect(merged?.setting).toEqual(growthSample.setting)
    expect(merged?.summary.level).toBe(1)
    expect(merged?.levels).toEqual([])
    expect(growthMergeDetail(growthSample, null)).toBe(growthSample)
  })

  it('進站守衛：event/list 要有 tp_event 3', () => {
    expect(growthHasTpEvent([{ tp_event: 1 }, { tp_event: '3' }])).toBe(true)
    expect(growthHasTpEvent([{ tp_event: null }])).toBe(false)
    expect(growthHasTpEvent(null)).toBe(false)
  })
})

// ---------- Pinia store ----------

describe('useJackpotStore', () => {
  it('hasUnclaimedPrize 跟著 show 變動', () => {
    const store = useJackpotStore()
    expect(store.hasUnclaimedPrize).toBe(false)
    store.setShow(eventsJackpotEndpoints['event/jackpot/show'].response.parse(showSample))
    expect(store.hasUnclaimedPrize).toBe(true)
    store.reset()
    expect(store.show).toBeNull()
    expect(store.winList).toEqual([])
  })
})

// ---------- composables ----------

describe('useJackpot', () => {
  it('init 同時抓 win-list 與 show；非成功碼不寫入（訊息由 useApi 彈窗）', async () => {
    handlers['event/jackpot/show'] = () => ok(showSample)
    handlers['event/jackpot/win-list'] = () => fail(1934, 'lock mode')
    const jackpot = useJackpot()
    await jackpot.init()
    expect(routesCalled().sort()).toEqual(['event/jackpot/show', 'event/jackpot/win-list'])
    expect(jackpot.show.value?.title).toBe('Jackpot')
    expect(jackpot.winList.value).toEqual([])
    expect(jackpot.hasUnclaimedPrize.value).toBe(true)
    jackpot.dispose()
  })

  it('win-list 抓之前先清空，避免名單殘留', async () => {
    const store = useJackpotStore()
    store.setWinList([{ image: null, username: 'a**c', point: '1.00' }])
    let seenDuringFetch: number | null = null
    handlers['event/jackpot/win-list'] = () => {
      seenDuringFetch = store.winList.length
      return ok([{ image: null, username: 'x**z', point: '5.00' }])
    }
    const jackpot = useJackpot()
    await jackpot.fetchWinList()
    expect(seenDuringFetch).toBe(0)
    expect(jackpot.winList.value).toHaveLength(1)
  })

  it('領取成功保留該獎項供彈窗，失敗不保留；兩種情況都重抓 win-list 與 show', async () => {
    handlers['event/jackpot/show'] = () => ok(showSample)
    handlers['event/jackpot/win-list'] = () => ok([])
    handlers['event/jackpot/receive'] = body => ((body as { id: number }).id === 11 ? ok(null, 1000) : fail(1947, 'already received'))
    const jackpot = useJackpot()
    const prize = { id: 11, event_id: 3, point: '88.0000', image: 'x', status: 0 as const, expired_time: null }

    const success = await jackpot.receive(prize)
    expect(success.code).toBe(1000)
    expect(calls[0]).toEqual({ route: 'event/jackpot/receive', body: { id: 11 } })
    expect(jackpot.wonPrize.value?.id).toBe(11)
    expect(routesCalled().slice(1).sort()).toEqual(['event/jackpot/show', 'event/jackpot/win-list'])

    calls = []
    const failure = await jackpot.receive({ ...prize, id: 12 })
    expect(failure.code).toBe(1947)
    expect(jackpot.wonPrize.value?.id).toBe(11)
    expect(routesCalled().slice(1).sort()).toEqual(['event/jackpot/show', 'event/jackpot/win-list'])
  })

  it('金額每秒 +1，到 15:00 整頁重抓', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 8, 10, 14, 59, 58))
    handlers['event/jackpot/show'] = () => ok(showSample)
    handlers['event/jackpot/win-list'] = () => ok([])
    const jackpot = useJackpot()
    await jackpot.init()
    // 14:59:58 距昨天 15:00 = 86398 秒
    expect(jackpot.amount.value).toBe(String(1000 + 86398))
    await vi.advanceTimersByTimeAsync(1000)
    expect(jackpot.amount.value).toBe(String(1000 + 86399))
    expect(calls).toHaveLength(2)
    await vi.advanceTimersByTimeAsync(1000)
    expect(calls).toHaveLength(4)
    expect(routesCalled().slice(2).sort()).toEqual(['event/jackpot/show', 'event/jackpot/win-list'])
    jackpot.dispose()
  })

  it('獎項倒數只算 status 0 且有 expired_time 的', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 8, 12, 8, 0, 0))
    handlers['event/jackpot/show'] = () => ok(showSample)
    handlers['event/jackpot/win-list'] = () => ok([])
    const jackpot = useJackpot()
    await jackpot.init()
    expect(jackpot.prizeCountdowns.value[11]).toEqual({ expired: false, text: '2:30' })
    expect(jackpot.prizeCountdowns.value[12]).toBeUndefined()
    jackpot.dispose()
  })
})

describe('useJackpotNotifications', () => {
  const list = [
    { id: 1, point: '10.0000', image: 'https://img/1.png' },
    { id: 2, point: '20.0000', image: 'https://img/2.png' },
  ]

  it('排隊一次一個；關閉時 confirm-notify，成功才放下一個', async () => {
    handlers['event/jackpot/check-notify'] = () => ok(list)
    handlers['event/jackpot/confirm-notify'] = () => ok(null)
    const n = useJackpotNotifications()
    await n.load()
    expect(n.visible.value).toBe(true)
    expect(n.current.value?.id).toBe(1)
    expect(n.imageUrl.value).toBe('https://img/1.png?rid=1')
    await n.close()
    expect(calls[1]).toEqual({ route: 'event/jackpot/confirm-notify', body: { id: 1 } })
    expect(n.current.value?.id).toBe(2)
    await n.close()
    expect(n.visible.value).toBe(false)
    expect(n.imageUrl.value).toBe('')
  })

  it('confirm 失敗：彈窗保持關閉、隊列停止', async () => {
    handlers['event/jackpot/check-notify'] = () => ok(list)
    handlers['event/jackpot/confirm-notify'] = () => fail(500)
    const n = useJackpotNotifications()
    await n.load()
    await n.close()
    expect(n.visible.value).toBe(false)
    expect(n.queue.value).toEqual([])
    expect(n.closing.value).toBe(false)
  })

  it('空清單不彈；重複關閉只送一次', async () => {
    handlers['event/jackpot/check-notify'] = () => ok([])
    const n = useJackpotNotifications()
    await n.load()
    expect(n.visible.value).toBe(false)
    expect(await n.close()).toBeNull()
    expect(calls).toHaveLength(1)
  })
})

describe('useLottery', () => {
  it('變體決定端點；tickets 帶 issue_id / page / per_page 10', async () => {
    handlers['event/wjackpot/issues'] = () => ok([{ issue_id: 1, issue_name: '1st', draw_time: '2026-09-10 19:00:00' }])
    handlers['event/wjackpot/issues/tickets'] = () => ok(ticketsSample)
    const lottery = useLottery('wjackpot')
    await lottery.fetchIssues()
    expect(calls[0]).toEqual({ route: 'event/wjackpot/issues', body: {} })
    expect(lottery.issues.value).toHaveLength(1)

    await lottery.fetchTickets(1, 2)
    expect(calls[1]).toEqual({ route: 'event/wjackpot/issues/tickets', body: { issue_id: 1, page: 2, per_page: 10 } })
    expect(lottery.tickets.value).toHaveLength(2)
    expect(lottery.page.value).toBe(2)
    expect(lottery.total.value).toBe(12)
    expect(lottery.pages.value).toBe(2)
    expect(lottery.resultNumber.value).toBe('1234567')
  })

  it('單張領取：成功彈出固定組法的訊息並重抓當頁；失敗不重抓（錯誤彈窗由 useApi 處理）', async () => {
    handlers['event/lucky-draw/issues/tickets'] = () => ok(ticketsSample)
    handlers['event/lucky-draw/issues/receive'] = body => ((body as { ticket_id: number }).ticket_id === 1 ? ok(null) : fail(1943, 'already collected'))
    const lottery = useLottery('lucky-draw')
    await lottery.fetchTickets(7, 1)
    calls = []

    await lottery.receive(7, 1)
    expect(calls[0]).toEqual({ route: 'event/lucky-draw/issues/receive', body: { ticket_id: 1 } })
    expect(calls[1]?.route).toBe('event/lucky-draw/issues/tickets')
    expect(alertShow).toHaveBeenCalledWith('common.ui.msg.success！events.common.msg.contactCs', 'success')

    calls = []
    alertShow.mockClear()
    await lottery.receive(7, 2)
    expect(calls).toHaveLength(1)
    expect(alertShow).not.toHaveBeenCalled()
  })

  it('全部領取：1000 也算成功，1 秒後重抓明細；失敗不重抓', async () => {
    vi.useFakeTimers()
    const detail = { issue_id: 7, issue_name: '7th', begin_time: 'a', end_time: 'b', draw_time: null, result_number: '------', summary: { totalCount_tickets: 3, totalCount_winnings: '18.00', totalCount_unclaimed: '18.00' } }
    handlers['event/wjackpot6d/issues/detail'] = () => ok(detail)
    handlers['event/wjackpot6d/issues/receive-all'] = () => ok(null, 1000)
    const lottery = useLottery('wjackpot6d')
    await lottery.fetchDetail(7)
    expect(lottery.canReceiveAll.value).toBe(true)
    calls = []

    await lottery.receiveAll(7)
    expect(alertShow).toHaveBeenCalledWith(expect.any(String), 'success')
    alertShow.mockClear()
    expect(calls).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(999)
    expect(calls).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(calls[1]).toEqual({ route: 'event/wjackpot6d/issues/detail', body: { issue_id: 7 } })

    calls = []
    handlers['event/wjackpot6d/issues/receive-all'] = () => fail(1991, 'v2 in progress')
    await lottery.receiveAll(7)
    await vi.advanceTimersByTimeAsync(2000)
    expect(calls).toHaveLength(1)
    expect(alertShow).not.toHaveBeenCalled()
    lottery.dispose()
  })
})

describe('useProgressiveJackpot', () => {
  it('進站守衛：event/list 沒有 tp_event 3 導回首頁且不抓列表', async () => {
    handlers['event/list'] = () => ok([{ tp_event: 1 }])
    handlers['event/progressive-jackpot/list'] = () => ok(growthSample)
    const growth = useProgressiveJackpot()
    await growth.init()
    expect(navigateTo).toHaveBeenCalledWith('/', { replace: true })
    expect(routesCalled()).toEqual(['event/list'])
    growth.dispose()
  })

  it('event/list 拿不到（含尚未登記的 404）不阻擋；列表有 tp_event 3 才進', async () => {
    handlers['event/progressive-jackpot/list'] = () => ok(growthSample)
    const blocked = useProgressiveJackpot()
    await blocked.init()
    expect(navigateTo).not.toHaveBeenCalled()
    expect(routesCalled()).toEqual(['event/list', 'event/progressive-jackpot/list'])
    expect(blocked.hasDetail.value).toBe(true)
    blocked.dispose()

    calls = []
    handlers['event/list'] = () => ok([{ tp_event: '3' }])
    const allowed = useProgressiveJackpot()
    await allowed.init()
    expect(navigateTo).not.toHaveBeenCalled()
    expect(routesCalled()).toEqual(['event/list', 'event/progressive-jackpot/list'])
    allowed.dispose()
  })

  it('列表轉成檔位與總覽；失敗時清空不顯示 0 / 0', async () => {
    handlers['event/progressive-jackpot/list'] = () => ok(growthSample)
    const growth = useProgressiveJackpot()
    await growth.fetchList()
    expect(growth.tiers.value).toHaveLength(3)
    expect(growth.currentTier.value?.level).toBe(0)
    expect(growth.currentReward.value).toBe('5888.0000')
    expect(growth.hasCurrentTier.value).toBe(true)
    expect(growth.summaryUnlimited.value).toBe(true)
    expect(growth.ratios.value).toEqual([{ gameTypeId: 1, rate: 0.3 }])

    handlers['event/progressive-jackpot/list'] = () => fail(9999, 'boom')
    await growth.fetchList()
    expect(growth.hasDetail.value).toBe(false)
  })

  it('新開啟檔位只提示一次，並記到依帳號分開的 cookie', async () => {
    handlers['event/progressive-jackpot/list'] = () => ok(growthSample)
    const growth = useProgressiveJackpot()
    await growth.fetchList()
    expect(growth.openedLevels.value).toEqual([0])
    expect(cookies.get('wf_growth_notified_alice')?.value).toEqual([0])
    growth.dismissOpenedNotice()
    await growth.fetchList()
    expect(growth.openedLevels.value).toEqual([])
  })

  it('領取成功只合併 summary/levels 且保留 setting；data 空不清頁', async () => {
    handlers['event/progressive-jackpot/list'] = () => ok(growthSample)
    handlers['event/progressive-jackpot/receive'] = () => ok({
      summary: { ...growthSample.summary, can_receive: '0.0000' },
      levels: [level({ ...growthSample.levels[0]!, receive: '2000.0000' }), growthSample.levels[1]!, growthSample.levels[2]!],
    })
    const growth = useProgressiveJackpot()
    await growth.fetchList()
    calls = []
    const res = await growth.claim(0)
    expect(res?.code).toBe(200)
    expect(calls).toEqual([{ route: 'event/progressive-jackpot/receive', body: { level: 0 } }])
    expect(growth.setting.value?.title).toBe('Growth')
    expect(growth.summary.value?.can_receive).toBe('0.0000')
    expect(growth.tiers.value[0]?.claimable).toBe(0)
    expect(growth.claimingLevel.value).toBeNull()

    handlers['event/progressive-jackpot/receive'] = () => ok(null)
    await growth.fetchList()
    calls = []
    await growth.claim(0)
    expect(growth.hasDetail.value).toBe(true)
    expect(growth.summary.value?.can_receive).toBe('1766.4000')
  })

  it('領取失敗重抓列表（後端可能已鎖定）；不可領的檔位直接忽略', async () => {
    handlers['event/progressive-jackpot/list'] = () => ok(growthSample)
    handlers['event/progressive-jackpot/receive'] = () => fail(1935, 'realname required')
    const growth = useProgressiveJackpot()
    await growth.fetchList()
    calls = []
    await growth.claim(0)
    expect(routesCalled()).toEqual(['event/progressive-jackpot/receive', 'event/progressive-jackpot/list'])

    calls = []
    expect(await growth.claim(1)).toBeNull()
    expect(await growth.claim(99)).toBeNull()
    expect(calls).toHaveLength(0)
  })
})
