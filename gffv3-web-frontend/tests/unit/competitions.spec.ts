import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { competitionsEndpoints } from '../../shared/api/endpoints/competitions'
import {
  RANKING_POLL_MS,
  REFRESH_RANKING_PATH,
  buildExitUrl,
  canEnterEventGame,
  claimState,
  seasonDate,
  eventGameListPath,
  eventGamePath,
  filterEventGamesByType,
  truncateAmount,
  eventGameTypeIds,
  isVendorPlayable,
  pickEventGameSrc,
  rankingText,
  seasonLabel,
  shouldOpenWindow,
  splitLeaderBoard,
} from '../../shared/utils/competitions'
import { isApiOk } from '../../app/composables/useApi'
import { useSlotCompetition } from '../../app/composables/useSlotCompetition'
import { useRefreshRanking } from '../../app/composables/useRefreshRanking'
import { useRefreshRankingGame, useRefreshRankingGameList } from '../../app/composables/useRefreshRankingGame'
import { useRanking, useWinnerRanking } from '../../app/composables/useRanking'

// composable 依賴 Nuxt 自動匯入（含 vue 的 ref/computed），這裡以最小 stub 取代：
// vue 不是專案直接相依，vitest 解析不到；沒有 watch 所以 getter 型 computed 就夠用
function ref<T>(value: T) {
  return { value }
}
function computed<T>(getter: () => T) {
  return {
    get value() {
      return getter()
    },
  }
}
vi.stubGlobal('ref', ref)
vi.stubGlobal('computed', computed)
vi.stubGlobal('getCurrentInstance', () => null)
vi.stubGlobal('onMounted', () => {})
vi.stubGlobal('onBeforeUnmount', () => {})

const post = vi.fn()
const navigateTo = vi.fn(async () => undefined)
const showResponse = vi.fn()
const site = ref<{ device: string } | null>({ device: 'pc' })
const ORIGIN = 'https://example.invalid'

interface VendorRef { id: number, name: string }
const store = {
  vendor: null as VendorRef | null,
  setVendor(next: VendorRef | null) {
    store.vendor = next
  },
  reset() {
    store.vendor = null
  },
}

vi.stubGlobal('useApi', () => ({ post }))
vi.stubGlobal('isApiOk', isApiOk)
vi.stubGlobal('navigateTo', navigateTo)
vi.stubGlobal('useSite', () => ({ site }))
vi.stubGlobal('useRequestURL', () => new URL(`${ORIGIN}/competitions/refresh-ranking/game/7?name=Fish`))
vi.stubGlobal('useRefreshRankingStore', () => store)
vi.stubGlobal('useAlert', () => ({ showResponse }))

function ok<T>(data: T) {
  return { code: 200, message: 'OK', data }
}

function fail(message: string, code = 400) {
  return { code, message, data: null }
}

function routes(map: Record<string, unknown>) {
  post.mockImplementation(async (route: string) => map[route] ?? ok([]))
}

function calledRoutes() {
  return post.mock.calls.map(call => call[0] as string)
}

const seasons = [
  { event_id: 9, id: 11, no: 3, start_time: '2026-09-01 00:00:00', end_time: '2026-09-07 23:59:59' },
  { event_id: 9, id: 10, no: 2, start_time: '2026-08-25 00:00:00', end_time: '2026-08-31 23:59:59' },
]
const boardRows = [
  { ranking: 1, username: 'a**a', bet_amount: '300.50', last_bet_time: '2026-09-02 10:00:00' },
  { ranking: 2, username: 'b**b', bet_amount: '200.00', last_bet_time: '2026-09-02 11:00:00' },
  { ranking: 3, username: 'c**c', bet_amount: '100.00', last_bet_time: '2026-09-02 12:00:00' },
  { ranking: 4, username: 'd**d', bet_amount: '50.00', last_bet_time: '2026-09-02 13:00:00' },
  { ranking: '--' as const, username: 'me', bet_amount: '1.00', last_bet_time: '2026-09-02 14:00:00' },
]

beforeEach(() => {
  store.reset()
  post.mockReset()
  navigateTo.mockClear()
  showResponse.mockClear()
  site.value = { device: 'pc' }
})

afterEach(() => {
  vi.useRealTimers()
})

describe('competitions endpoints 契約', () => {
  it('16 條端點全數登記、key 等於 path、都要登入', () => {
    const entries = Object.entries(competitionsEndpoints)
    expect(entries).toHaveLength(16)
    for (const [key, def] of entries) {
      expect(def.path).toBe(key)
      expect(def.auth).toBe('member')
    }
    expect(Object.keys(competitionsEndpoints)).toEqual(expect.arrayContaining([
      'event/slot-competition/list',
      'event/slot-competition/leader-board',
      'event/slot-competition/ranking',
      'event/slot-competition/receive',
      'event/slot-competition/awards',
      'event/refresh-ranking/list',
      'event/refresh-ranking/leader-board',
      'event/refresh-ranking/awards',
      'event/refresh-ranking/wallet-balance',
      'event/refresh-ranking/vendor',
      'event/refresh-ranking/game',
      'event/refresh-ranking/game-url',
      'ranking/winning',
      'ranking/platform-betting',
      'ranking/profits',
      'ranking/game-winners',
    ]))
  })

  it('leader-board request：字串轉數字、分頁越界擋下', () => {
    const schema = competitionsEndpoints['event/slot-competition/leader-board'].request
    expect(schema.parse({ event_id: '9', no: '3' })).toEqual({ event_id: 9, no: 3 })
    expect(schema.parse({ event_id: 9, no: 3, page: 2, per_page: 100 })).toEqual({ event_id: 9, no: 3, page: 2, per_page: 100 })
    expect(schema.safeParse({ event_id: 9, no: 3, per_page: 101 }).success).toBe(false)
    expect(schema.safeParse({ event_id: 0, no: 3 }).success).toBe(false)
    expect(schema.safeParse({ event_id: 9 }).success).toBe(false)
  })

  it('ranking type 只接受 1-4', () => {
    const schema = competitionsEndpoints['ranking/winning'].request
    for (const type of [1, 2, 3, 4]) expect(schema.safeParse({ type }).success).toBe(true)
    expect(schema.safeParse({ type: 5 }).success).toBe(false)
    expect(schema.safeParse({}).success).toBe(false)
  })

  it('game-url：exit_url 必須是完整網址，舊版 domain 不再接受', () => {
    const schema = competitionsEndpoints['event/refresh-ranking/game-url'].request
    const parsed = schema.parse({ game_id: '7', exit_url: 'https://example.invalid/competitions/refresh-ranking', domain: 'example.invalid' })
    expect(parsed).toEqual({ game_id: 7, exit_url: 'https://example.invalid/competitions/refresh-ranking' })
    expect(schema.safeParse({ game_id: 7, exit_url: 'example.invalid/path' }).success).toBe(false)
    expect(schema.safeParse({ game_id: 7, domain: 'example.invalid' }).success).toBe(false)
  })

  it('無參數端點接受空物件', () => {
    for (const key of [
      'event/slot-competition/list',
      'event/refresh-ranking/list',
      'event/refresh-ranking/wallet-balance',
      'event/refresh-ranking/vendor',
      'ranking/profits',
      'ranking/game-winners',
    ] as const) {
      expect(competitionsEndpoints[key].request.safeParse({}).success).toBe(true)
    }
  })

  it('response：flag 收成 0/1、名次 -- 保留、金額原樣', () => {
    const vendors = competitionsEndpoints['event/refresh-ranking/vendor'].response.parse([
      { id: '5', name: 'PG', image: null, app_icon: null, only_one_game: '1', need_login: 0, status: '3' },
    ])
    expect(vendors[0]).toMatchObject({ id: 5, only_one_game: 1, need_login: 0, status: 3 })

    const board = competitionsEndpoints['event/slot-competition/leader-board'].response.parse([
      { ranking: '3', username: 'x', bet_amount: '12.30', last_bet_time: null },
      { ranking: '--', username: 'me', bet_amount: 1, last_bet_time: null },
    ])
    expect(board[0]?.ranking).toBe(3)
    expect(board[1]?.ranking).toBe('--')
    expect(board[0]?.bet_amount).toBe('12.30')

    const wallet = competitionsEndpoints['event/refresh-ranking/wallet-balance'].response.parse({ balance: '88.00' })
    expect(wallet.balance).toBe('88.00')

    const url = competitionsEndpoints['event/refresh-ranking/game-url'].response.parse({ pc: 'https://g/pc', mobile: 'https://g/m', open_window: '1' })
    expect(url.open_window).toBe(1)
  })
})

describe('純規則', () => {
  it('期數文字與日期截斷', () => {
    expect(seasonLabel(seasons[0]!)).toBe('3 (2026-09-01 - 2026-09-07)')
    expect(seasonDate('2026-09-01 00:00:00')).toBe('2026-09-01')
    expect(seasonDate(null)).toBe('')
  })

  it('金額無條件捨去 2 位、不四捨五入', () => {
    expect(truncateAmount('300.5')).toBe('300.50')
    expect(truncateAmount('12.999')).toBe('12.99')
    expect(truncateAmount(1234)).toBe('1234.00')
    expect(truncateAmount('1,234.5')).toBe('1234.50')
    expect(truncateAmount(0)).toBe('0.00')
    expect(truncateAmount(null)).toBe('0.00')
    expect(truncateAmount('')).toBe('0.00')
    expect(truncateAmount('-3.456')).toBe('-3.45')
    expect(truncateAmount('12.5', 0)).toBe('12')
    expect(truncateAmount('n/a')).toBe('n/a')
  })

  it('前三名補 null，其餘從第四筆起', () => {
    const { podium, rest } = splitLeaderBoard(boardRows)
    expect(podium.map(row => row?.username)).toEqual(['a**a', 'b**b', 'c**c'])
    expect(rest.map(row => row.ranking)).toEqual([4, '--'])
    expect(splitLeaderBoard(boardRows.slice(0, 1)).podium).toEqual([boardRows[0], null, null])
    expect(splitLeaderBoard([]).rest).toEqual([])
  })

  it('領獎狀態四分支', () => {
    expect(claimState({ type: 1, status: 0 })).toBe('claimable')
    expect(claimState({ type: 1, status: 1 })).toBe('claimed')
    expect(claimState({ type: 1, status: 2 })).toBe('expired')
    expect(claimState({ type: null, status: 2 })).toBe('expired')
    expect(claimState({ type: null, status: null })).toBe('none')
    expect(claimState({ type: 0, status: 0 })).toBe('none')
  })

  it('名次顯示', () => {
    expect(rankingText(null)).toBe('-')
    expect(rankingText(0)).toBe('-')
    expect(rankingText(7)).toBe('7')
    expect(rankingText('--')).toBe('--')
  })

  it('遊戲商／遊戲是否可進', () => {
    expect(isVendorPlayable({ status: 1 })).toBe(true)
    expect(isVendorPlayable({ status: 3 })).toBe(false)
    expect(canEnterEventGame({})).toBe(true)
    expect(canEnterEventGame({ disable: 1 })).toBe(false)
    expect(canEnterEventGame({ is_maintenance: 1 })).toBe(false)
  })

  it('分類頁籤由 type_id 去重、篩選', () => {
    const games = [{ type_id: 2 }, { type_id: 5 }, { type_id: 2 }]
    expect(eventGameTypeIds(games)).toEqual([2, 5])
    expect(filterEventGamesByType(games, 2)).toHaveLength(2)
    expect(filterEventGamesByType(games, null)).toHaveLength(3)
  })

  it('活動遊戲路徑與 exit_url', () => {
    expect(eventGameListPath(5, 'PG Soft')).toBe('/competitions/refresh-ranking/game/list/5?vendorName=PG%20Soft')
    expect(eventGamePath(7, 'Fish & Chips')).toBe('/competitions/refresh-ranking/game/7?name=Fish%20%26%20Chips')
    expect(buildExitUrl('https://example.invalid/', { id: 5, name: 'PG' }))
      .toBe('https://example.invalid/competitions/refresh-ranking/game/list/5?vendorName=PG')
    expect(buildExitUrl('https://example.invalid', null)).toBe(`https://example.invalid${REFRESH_RANKING_PATH}`)
  })

  it('依裝置選遊戲網址、open_window 判斷', () => {
    const data = { pc: 'https://g/pc', mobile: 'https://g/m', open_window: 1 as const }
    expect(pickEventGameSrc(data, false)).toBe('https://g/pc')
    expect(pickEventGameSrc(data, true)).toBe('https://g/m')
    expect(pickEventGameSrc({ pc: null, mobile: undefined }, true)).toBe('')
    expect(shouldOpenWindow(data)).toBe(true)
    expect(shouldOpenWindow({ open_window: 0 })).toBe(false)
  })
})

describe('useSlotCompetition', () => {
  const rankingRows = [
    { event_id: 9, no: 2, draw_time: '2026-09-01 00:00:00', start_time: '2026-08-25 00:00:00', end_time: '2026-08-31 23:59:59', bet_amount: '10.00', ranking: 1, type: 1, status: 0, last_bet_time: null, point: '100' },
  ]

  it('init：list → 預設第一期 → leader-board／ranking／awards', async () => {
    routes({
      'event/slot-competition/list': ok(seasons),
      'event/slot-competition/leader-board': ok(boardRows.slice(0, 2)),
      'event/slot-competition/ranking': ok(rankingRows),
      'event/slot-competition/awards': ok({ content: '<p>rule</p>', prizes: [{ event_id: 9, name: 'Gold', level: 1, number: 1, point: '100' }] }),
    })
    const c = useSlotCompetition()
    await c.init()

    expect(post).toHaveBeenCalledWith('event/slot-competition/list', {})
    expect(post).toHaveBeenCalledWith('event/slot-competition/leader-board', { event_id: 9, no: 3 })
    expect(post).toHaveBeenCalledWith('event/slot-competition/ranking', { event_id: 9 })
    expect(post).toHaveBeenCalledWith('event/slot-competition/awards', { event_id: 9 })
    expect(c.selectedSeason.value?.no).toBe(3)
    expect(c.seasonOptions.value.map(option => option.label)).toEqual(['3 (2026-09-01 - 2026-09-07)', '2 (2026-08-25 - 2026-08-31)'])
    expect(c.board.value.podium[2]).toBeNull()
    expect(c.board.value.rest).toEqual([])
    expect(c.noData.value).toBe(false)
    expect(c.myRanking.value).toHaveLength(1)
    expect(c.awards.value?.prizes[0]?.name).toBe('Gold')
    expect(c.loading.value).toBe(false)
  })

  it('沒有期數：只打 list，其他不打', async () => {
    routes({ 'event/slot-competition/list': ok([]) })
    const c = useSlotCompetition()
    await c.init()
    expect(calledRoutes()).toEqual(['event/slot-competition/list'])
    expect(c.hasSeason.value).toBe(false)
    expect(c.noData.value).toBe(true)
  })

  it('切期數只重抓 leader-board', async () => {
    routes({ 'event/slot-competition/list': ok(seasons), 'event/slot-competition/leader-board': ok(boardRows) })
    const c = useSlotCompetition()
    await c.init()
    post.mockClear()

    await c.selectSeasonByNo(2)
    expect(calledRoutes()).toEqual(['event/slot-competition/leader-board'])
    expect(post).toHaveBeenCalledWith('event/slot-competition/leader-board', { event_id: 9, no: 2 })
    expect(c.selectedSeason.value?.no).toBe(2)

    post.mockClear()
    await c.selectSeasonByNo(99)
    expect(post).not.toHaveBeenCalled()
  })

  it('leader-board 失敗：清空（訊息由 useApi 彈窗）', async () => {
    routes({ 'event/slot-competition/list': ok(seasons), 'event/slot-competition/leader-board': fail('1901 not in hierarchy') })
    const c = useSlotCompetition()
    await c.init()
    expect(c.leaderBoard.value).toEqual([])
    expect('error' in c).toBe(false)
  })

  it('receive 成功：成功彈窗、刷錢包、重抓我的排名', async () => {
    const refreshWallet = vi.fn(async () => undefined)
    routes({
      'event/slot-competition/receive': { code: 200, message: 'received!', data: [] },
      'event/slot-competition/ranking': ok([{ ...rankingRows[0], status: 1 }]),
    })
    const c = useSlotCompetition({ refreshWallet })
    const res = await c.receive({ event_id: 9, no: 2 })

    expect(res.code).toBe(200)
    expect(post).toHaveBeenCalledWith('event/slot-competition/receive', { event_id: 9, no: 2 })
    expect(calledRoutes()).toEqual(['event/slot-competition/receive', 'event/slot-competition/ranking'])
    expect(showResponse).toHaveBeenCalledWith(expect.objectContaining({ code: 200, message: 'received!' }))
    expect(refreshWallet).toHaveBeenCalledTimes(1)
    expect(c.myRanking.value[0]?.status).toBe(1)
    expect(c.receiving.value).toBe(false)
  })

  it('receive 失敗（1946/1947/1948 等以 message 回）：不重複彈窗（useApi 已彈）並重抓，不刷錢包', async () => {
    const refreshWallet = vi.fn()
    routes({
      'event/slot-competition/receive': fail('already received'),
      'event/slot-competition/ranking': ok(rankingRows),
    })
    const c = useSlotCompetition({ refreshWallet })
    const res = await c.receive({ event_id: 9, no: 2 })

    expect(res.code).toBe(400)
    expect(showResponse).not.toHaveBeenCalled()
    expect('message' in c).toBe(false)
    expect(refreshWallet).not.toHaveBeenCalled()
    expect(calledRoutes()).toEqual(['event/slot-competition/receive', 'event/slot-competition/ranking'])
  })
})

describe('useRefreshRanking', () => {
  const refreshSeasons = seasons.map((season, index) => ({ ...season, my_bet_amount: index === 0 ? '55.50' : 0 }))
  const vendors = [
    { id: 5, name: 'PG', image: 'https://img/pg.png', app_icon: '', only_one_game: 0, need_login: 0, status: 1 },
    { id: 6, name: 'JILI', image: 'https://img/jili.png', app_icon: '', only_one_game: 0, need_login: 0, status: 3 },
  ]

  it('init 打齊五支；餘額讀 data.balance；myBetAmount 取選中期數', async () => {
    routes({
      'event/refresh-ranking/list': ok(refreshSeasons),
      'event/refresh-ranking/leader-board': ok(boardRows),
      'event/refresh-ranking/vendor': ok(vendors),
      'event/refresh-ranking/wallet-balance': ok({ balance: '88.00' }),
      'event/refresh-ranking/awards': ok({ content: null, prizes: [] }),
    })
    const r = useRefreshRanking()
    await r.init()

    expect(post).toHaveBeenCalledWith('event/refresh-ranking/list', {})
    expect(post).toHaveBeenCalledWith('event/refresh-ranking/leader-board', { event_id: 9, no: 3 })
    expect(post).toHaveBeenCalledWith('event/refresh-ranking/vendor', {})
    expect(post).toHaveBeenCalledWith('event/refresh-ranking/wallet-balance', {})
    expect(post).toHaveBeenCalledWith('event/refresh-ranking/awards', { event_id: 9 })
    expect(calledRoutes()).toHaveLength(5)
    expect(r.walletBalance.value).toBe('88.00')
    expect(r.myBetAmount.value).toBe('55.50')
    expect(r.vendors.value).toHaveLength(2)
    expect(r.walletFailed.value).toBe(false)
    expect(r.board.value.rest.map(row => row.ranking)).toEqual([4, '--'])

    await r.selectSeasonByNo(2)
    expect(r.myBetAmount.value).toBe(0)
  })

  it('沒有期數：仍抓遊戲商與餘額，不抓排行榜／獎項；wallet 400 只標記失敗', async () => {
    routes({
      'event/refresh-ranking/list': ok([]),
      'event/refresh-ranking/vendor': fail('1426 no vendor config'),
      'event/refresh-ranking/wallet-balance': fail('1901 no active event'),
    })
    const r = useRefreshRanking()
    await r.init()
    expect(calledRoutes().sort()).toEqual(['event/refresh-ranking/list', 'event/refresh-ranking/vendor', 'event/refresh-ranking/wallet-balance'])
    expect(r.myBetAmount.value).toBe(0)
    expect(r.walletFailed.value).toBe(true)
    expect(r.walletBalance.value).toBeNull()
    expect(r.vendors.value).toEqual([])
  })

  it('enterVendor：非 status 1 不動作；status 1 記 store 並導到活動遊戲列表', async () => {
    const r = useRefreshRanking()

    expect(await r.enterVendor(vendors[1]!)).toBe(false)
    expect(navigateTo).not.toHaveBeenCalled()
    expect(store.vendor).toBeNull()

    expect(await r.enterVendor(vendors[0]!)).toBe(true)
    expect(store.vendor).toEqual({ id: 5, name: 'PG' })
    expect(navigateTo).toHaveBeenCalledWith('/competitions/refresh-ranking/game/list/5?vendorName=PG')
  })
})

describe('useRefreshRankingGameList', () => {
  const games = [
    { id: 70, name: 'Fish', image: '', type_id: 2, is_hot: 1, is_new: 0 },
    { id: 71, name: 'Slot A', image: '', type_id: 5, is_hot: 0, is_new: 1 },
    { id: 72, name: 'Slot B', image: '', type_id: 5, is_hot: 0, is_new: 0, disable: 1 },
    { id: 73, name: 'Slot C', image: '', type_id: 5, is_maintenance: 1 },
  ]

  it('fetchGames 帶 vendor_id、分類由 type_id 推出、vendorName 寫回 store', async () => {
    routes({ 'event/refresh-ranking/game': ok(games) })
    const list = useRefreshRankingGameList(5, 'PG')
    expect(store.vendor).toEqual({ id: 5, name: 'PG' })
    expect(list.title.value).toBe('PG')

    await list.fetchGames()
    expect(post).toHaveBeenCalledWith('event/refresh-ranking/game', { vendor_id: 5 })
    expect(list.typeIds.value).toEqual([2, 5])
    expect(list.visibleGames.value).toHaveLength(4)
    list.selectType(5)
    expect(list.visibleGames.value.map(game => game.id)).toEqual([71, 72, 73])
  })

  it('沒帶 vendorName 時不覆寫 store，標題退回 store 的名稱', () => {
    store.setVendor({ id: 5, name: 'Stored' })
    const list = useRefreshRankingGameList(5)
    expect(list.title.value).toBe('Stored')
  })

  it('enterGame：停用／維護擋下，可玩導活動遊戲頁', async () => {
    const list = useRefreshRankingGameList(5, 'PG')
    expect(await list.enterGame(games[2]!)).toBe(false)
    expect(await list.enterGame(games[3]!)).toBe(false)
    expect(navigateTo).not.toHaveBeenCalled()

    expect(await list.enterGame(games[0]!)).toBe(true)
    expect(navigateTo).toHaveBeenCalledWith('/competitions/refresh-ranking/game/70?name=Fish')
  })

  it('fetchGames 失敗列表為空（訊息由 useApi 彈窗）', async () => {
    routes({ 'event/refresh-ranking/game': fail('1426') })
    const list = useRefreshRankingGameList(5, 'PG')
    await list.fetchGames()
    expect(list.games.value).toEqual([])
    expect(list.loading.value).toBe(false)
  })
})

describe('useRefreshRankingGame', () => {
  const urlData = { pc: 'https://g/pc', mobile: 'https://g/m', open_window: 0 }

  it('launch：exit_url 用 store 遊戲商組回遊戲列表；pc 裝置取 pc、iframe 模式', async () => {
    store.setVendor({ id: 5, name: 'PG Soft' })
    routes({ 'event/refresh-ranking/game-url': ok(urlData) })
    const game = useRefreshRankingGame(70)
    await game.launch()

    expect(post).toHaveBeenCalledWith('event/refresh-ranking/game-url', {
      game_id: 70,
      exit_url: `${ORIGIN}/competitions/refresh-ranking/game/list/5?vendorName=PG%20Soft`,
    })
    expect(game.backPath.value).toBe('/competitions/refresh-ranking/game/list/5?vendorName=PG%20Soft')
    expect(game.gameSrc.value).toBe('https://g/pc')
    expect(game.openWindow.value).toBe(false)
    expect(game.failed.value).toBe(false)
  })

  it('mobile 裝置取 mobile；open_window 1 標記開新視窗', async () => {
    site.value = { device: 'mobile' }
    routes({ 'event/refresh-ranking/game-url': ok({ ...urlData, open_window: 1 }) })
    const game = useRefreshRankingGame(70)
    await game.launch()
    expect(game.isMobile.value).toBe(true)
    expect(game.gameSrc.value).toBe('https://g/m')
    expect(game.openWindow.value).toBe(true)
  })

  it('store 沒有遊戲商（直接進遊戲頁）時 exit_url 回活動主頁', async () => {
    routes({ 'event/refresh-ranking/game-url': ok(urlData) })
    const game = useRefreshRankingGame(70)
    await game.launch()
    expect(post).toHaveBeenCalledWith('event/refresh-ranking/game-url', { game_id: 70, exit_url: `${ORIGIN}${REFRESH_RANKING_PATH}` })
    expect(game.backPath.value).toBe(REFRESH_RANKING_PATH)
  })

  it('失敗（1426/1406/9993/9994 以 message 回）：標記失敗、沒有 gameSrc', async () => {
    routes({ 'event/refresh-ranking/game-url': fail('9993 no free account') })
    const game = useRefreshRankingGame(70)
    const res = await game.launch()
    expect(res.code).toBe(400)
    expect(game.failed.value).toBe(true)
    expect(game.gameSrc.value).toBe('')
    expect(game.loading.value).toBe(false)
  })
})

describe('useRanking', () => {
  const winningRow = { username: 'a***a', payout_time: '2026-09-10 10:00:00', win_lose: '600.00', game_name: 'G', vendor_name: 'V' }

  it('預設中獎排行打 type 1；切平台投注打 type 2', async () => {
    routes({ 'ranking/winning': ok([winningRow]), 'ranking/platform-betting': ok([{ sum_bet: '900', username: 'b***b', rank: 1 }]) })
    const r = useRanking()
    await r.fetchCurrent()
    expect(post).toHaveBeenCalledWith('ranking/winning', { type: 1 })
    expect(r.winning.value).toHaveLength(1)

    await r.selectTab('platformBetting')
    expect(post).toHaveBeenCalledWith('ranking/platform-betting', { type: 2 })
    expect(r.platformBetting.value[0]?.rank).toBe(1)
    expect(r.loading.value).toBe(false)
  })

  it('重抓前先清空（舊資料不留）', async () => {
    let resolve: (value: unknown) => void = () => {}
    post.mockImplementation(() => new Promise((res) => {
      resolve = res
    }))
    const r = useRanking()
    r.winning.value = [winningRow]
    const pending = r.fetchWinning()
    expect(r.winning.value).toEqual([])
    resolve(ok([winningRow]))
    await pending
    expect(r.winning.value).toHaveLength(1)
  })

  it('失敗：列表為空（訊息由 useApi 彈窗）', async () => {
    routes({ 'ranking/winning': fail('1213 unknown domain') })
    const r = useRanking()
    await r.fetchCurrent()
    expect(r.winning.value).toEqual([])
    expect(r.loading.value).toBe(false)
  })

  it('每 15 分鐘輪詢目前頁籤，stop 後不再打', async () => {
    vi.useFakeTimers()
    routes({ 'ranking/winning': ok([]) })
    const r = useRanking()
    r.startPolling()
    expect(post).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(RANKING_POLL_MS)
    expect(calledRoutes()).toEqual(['ranking/winning'])
    await vi.advanceTimersByTimeAsync(RANKING_POLL_MS)
    expect(post).toHaveBeenCalledTimes(2)
    r.stopPolling()
    await vi.advanceTimersByTimeAsync(RANKING_POLL_MS * 2)
    expect(post).toHaveBeenCalledTimes(2)
  })
})

describe('useWinnerRanking', () => {
  const winners = Array.from({ length: 5 }, (_, index) => ({
    username: `u${index}`,
    image: null,
    total_win_lose: 1000 - index * 100,
    vendor_name: `V${index}`,
    rank: index + 1,
  }))
  const profits = winners.map(({ vendor_name: _vendorName, ...row }) => row)

  it('預設今日遊戲贏家榜（無參數）；前三名頒獎台、其餘表格', async () => {
    routes({ 'ranking/game-winners': ok(winners) })
    const w = useWinnerRanking()
    await w.fetchCurrent()
    expect(post).toHaveBeenCalledWith('ranking/game-winners', {})
    expect(w.board.value.podium.map(row => row?.username)).toEqual(['u0', 'u1', 'u2'])
    expect(w.board.value.rest.map(row => row.rank)).toEqual([4, 5])
  })

  it('切今日盈利榜；重抓時不先清舊資料', async () => {
    routes({ 'ranking/profits': ok(profits) })
    const w = useWinnerRanking()
    await w.selectTab('profits')
    expect(post).toHaveBeenCalledWith('ranking/profits', {})
    expect(w.current.value).toHaveLength(5)

    let resolve: (value: unknown) => void = () => {}
    post.mockImplementation(() => new Promise((res) => {
      resolve = res
    }))
    const pending = w.fetchCurrent()
    expect(w.profits.value).toHaveLength(5)
    resolve(ok(profits.slice(0, 2)))
    await pending
    expect(w.profits.value).toHaveLength(2)
  })
})
