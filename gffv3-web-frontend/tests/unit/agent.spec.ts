import { decFormat } from '../../shared/utils/amount'
import { computed, reactive, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { agentHierarchyEndpoints } from '../../shared/api/endpoints/agent-hierarchy'
import { isOn } from '../../shared/utils/site'
import {
  applyButtonKey,
  awardTypeKey,
  buildShareLinks,
  buildTgQrLink,
  buildTgShareUrl,
  buildTgStartappLink,
  calcWithdrawableLimit,
  filterItselfRewardRows,
  formatProportion,
  hasLoseRebate,
  memberTypeKind,
  normalizeHierarchySummary,
  normalizePagination,
  normalizeWithdrawalHistory,
  normalizeWithdrawalSummary,
  resolveAgentMode,
  resolveApplyStatus,
  ruleItemKey,
  ruleItemsForType,
  ruleLevelMaxText,
  teamManagementResult,
  toNumber,
  uniqueGameTypes,
  validateShareholderForm,
  validateWithdrawAmount,
  vendorRateNote,
  type RuleItem,
} from '../../shared/utils/agent'

// ---- 純函式 ------------------------------------------------------------------------------------

describe('resolveAgentMode：agent_mode_setting 分流', () => {
  it.each([
    ['1', 'unlimit'],
    [1, 'unlimit'],
    ['2', 'three'],
    ['3', 'three'],
    [4, 'three'],
    [null, 'unlimit'],
    [undefined, 'unlimit'],
    ['abc', 'unlimit'],
  ])('%s → %s', (value, mode) => {
    expect(resolveAgentMode(value)).toBe(mode)
  })

  it('只有 3/4 顯示輸分返利欄', () => {
    expect(hasLoseRebate('2')).toBe(false)
    expect(hasLoseRebate('3')).toBe(true)
    expect(hasLoseRebate(4)).toBe(true)
  })
})

describe('toNumber / decFormat：後端字串金額', () => {
  it('字串、數字、空值都收成有限數字', () => {
    expect(toNumber('12.50')).toBe(12.5)
    expect(toNumber(3)).toBe(3)
    expect(toNumber('')).toBe(0)
    expect(toNumber(null)).toBe(0)
    expect(toNumber('abc')).toBe(0)
    expect(toNumber(Number.NaN)).toBe(0)
  })

  it('decFormat 預設 2 位、可指定 0 位，且無條件捨去', () => {
    expect(decFormat('1234.5')).toBe('1,234.50')
    // 餘額四捨五入會顯示出會員沒有的錢，所以一律捨去（對齊舊版 ToMoneyDisplayFormat）
    expect(decFormat(1234.567, 0)).toBe('1,234')
    expect(decFormat('1234.999')).toBe('1,234.99')
    expect(decFormat(undefined)).toBe('0.00')
  })
})

describe('代理總覽正規化與分享連結', () => {
  const raw = {
    topUsername: 'boss',
    username: 'me',
    teamCount: 10,
    directPlayerCount: '3',
    doubleDirectPlayerCount: 7,
    addedToday: 1,
    activeToday: 2,
    code: 12345,
    link: 'https://example.invalid/Signup/12345',
  }

  it('無限級保留 teamCount、三級保留 doubleDirectPlayerCount', () => {
    const unlimit = normalizeHierarchySummary(raw, 'unlimit')
    expect(unlimit.teamCount).toBe(10)
    expect(unlimit.doubleDirectPlayerCount).toBeNull()
    expect(unlimit.directPlayerCount).toBe(3)
    expect(unlimit.code).toBe('12345')

    const three = normalizeHierarchySummary(raw, 'three')
    expect(three.teamCount).toBeNull()
    expect(three.doubleDirectPlayerCount).toBe(7)
  })

  it('空回應不炸', () => {
    expect(normalizeHierarchySummary(null, 'unlimit').link).toBe('')
  })

  it('一般使用者直接用後端 link', () => {
    expect(buildShareLinks({ link: raw.link, code: '12345' })).toEqual({ copyLink: raw.link, qrLink: raw.link })
  })

  it('preferOrigin 改用 origin/Signup/code', () => {
    expect(buildShareLinks({ link: raw.link, code: '12345', origin: 'http://localhost:3000/', preferOrigin: true }))
      .toEqual({ copyLink: 'http://localhost:3000/Signup/12345', qrLink: 'http://localhost:3000/Signup/12345' })
  })

  it('TG 使用者：分享連結是 startapp，QR 連結是 origin/?tg_redirect=', () => {
    const tg = buildTgStartappLink('mybot', '12345')
    expect(tg).toBe('https://example.invalid/mybot/startapp?startapp=12345')
    expect(buildShareLinks({ link: raw.link, code: '12345', origin: 'https://example.invalid', tgBotUsername: 'mybot' }))
      .toEqual({ copyLink: tg, qrLink: buildTgQrLink('https://example.invalid', tg) })
    expect(buildTgQrLink('https://example.invalid', tg)).toBe(`https://example.invalid/?tg_redirect=${tg}`)
  })

  it('TG 使用者沒有 origin 時 QR 連結退回 startapp 連結', () => {
    const links = buildShareLinks({ link: raw.link, code: 'c', tgBotUsername: 'bot' })
    expect(links.qrLink).toBe(links.copyLink)
  })

  it('example.invalid share url 會 encode 連結與文字', () => {
    expect(buildTgShareUrl('https://a.b/Signup/1?x=1', 'hi there'))
      .toBe('https://example.invalid/share/url?url=https%3A%2F%2Fa.b%2FSignup%2F1%3Fx%3D1&text=hi%20there')
  })
})

describe('提領總覽與上限', () => {
  const unlimitRaw = {
    username: 'me',
    totalRewardPoints: '100.00',
    withdrawnPoints: '40.00',
    withdrawablePoints: '60.00',
    flowAmount: '999.00',
    itselfWithdrawnPoints: '5.00',
    itselfWithdrawablePoints: '15.00',
  }
  const threeRaw = { username: 'me', totalRewardPoints: '10.00', withdrawnPoints: '0.00', withdrawablePoints: '10.00' }

  it('三級端點缺的欄位補 0', () => {
    const s = normalizeWithdrawalSummary(threeRaw)
    expect(s.flowAmount).toBe(0)
    expect(s.itselfWithdrawablePoints).toBe(0)
    expect(s.withdrawablePoints).toBe(10)
  })

  it('無限級開自身回水 = 可提 + 自身可提；關閉只算可提；三級只算可提', () => {
    const s = normalizeWithdrawalSummary(unlimitRaw)
    expect(calcWithdrawableLimit(s, { mode: 'unlimit', itselfRebate: true })).toBe(75)
    expect(calcWithdrawableLimit(s, { mode: 'unlimit', itselfRebate: false })).toBe(60)
    expect(calcWithdrawableLimit(s, { mode: 'three', itselfRebate: true })).toBe(60)
  })

  it.each([
    ['', 'required'],
    ['   ', 'required'],
    ['0', 'min'],
    ['0.001', 'min'],
    ['1.23456', 'format'],
    ['abc', 'format'],
    ['1234567890123', 'format'],
    ['75.01', 'exceed'],
    ['75', null],
    ['0.01', null],
  ])('validateWithdrawAmount(%s, 75) → %s', (input, error) => {
    expect(validateWithdrawAmount(input, 75)).toBe(error)
  })

  it('紀錄欄位改名：type → status、withdrawType 原樣', () => {
    const rows = normalizeWithdrawalHistory([
      { date: '2026-09-01 10:00:00', no: 7, amount: '12.00', type: 'Issued', withdrawType: 'Points', withdrawAccount: 0 },
    ])
    expect(rows).toEqual([{ date: '2026-09-01 10:00:00', no: 7, amount: 12, status: 'Issued', withdrawType: 'Points' }])
  })
})

describe('無限級規則頁', () => {
  it('max 為 0/空顯示無上限文字，否則格式化', () => {
    expect(ruleLevelMaxText(0, 'None')).toBe('None')
    expect(ruleLevelMaxText('', 'None')).toBe('None')
    expect(ruleLevelMaxText(null, 'None')).toBe('None')
    expect(ruleLevelMaxText('50000', 'None')).toBe('50,000')
  })

  it('倍率 > 0 給 10000 × 倍率 的範例，否則 none', () => {
    expect(vendorRateNote('0.5')).toEqual({ kind: 'sales', rate: 0.5, sales: 5000 })
    expect(vendorRateNote(0)).toEqual({ kind: 'none' })
    expect(vendorRateNote('')).toEqual({ kind: 'none' })
  })
})

describe('我的推廣', () => {
  it('memberType 2 = 代理，其他 = 會員', () => {
    expect(memberTypeKind(2)).toBe('agent')
    expect(memberTypeKind('2')).toBe('agent')
    expect(memberTypeKind(1)).toBe('member')
    expect(memberTypeKind(undefined)).toBe('member')
  })

  it('我的流水只留 flow 或 itself_reward > 0 的列', () => {
    const rows = [
      { date: 'a', flow: '0.00', itself_reward: '0.00' },
      { date: 'b', flow: '10.00', itself_reward: '0.00' },
      { date: 'c', flow: '0.00', itself_reward: '1.00' },
    ]
    expect(filterItselfRewardRows(rows).map(r => r.date)).toEqual(['b', 'c'])
  })

  it('rebate_type 1/2 對應投注／輸分返利', () => {
    expect(awardTypeKey(1)).toBe('agent.rebate.label.bettingRebate')
    expect(awardTypeKey('2')).toBe('agent.rebate.label.lostRebate')
    expect(awardTypeKey(9)).toBe('')
  })
})

describe('三級分銷返利規則', () => {
  const items: RuleItem[] = [
    { vendor_id: 1, vendor_name: 'A', vendor_image: '', game_type_name: 'Slot', game_type_id: 1, type: 1, proportion_1: '0.5', proportion_2: '0.2', proportion_3: '0.1' },
    { vendor_id: 2, vendor_name: 'B', vendor_image: '', game_type_name: 'Slot', game_type_id: 1, type: 1, proportion_1: 'x', proportion_2: '0', proportion_3: '0' },
    { vendor_id: 3, vendor_name: 'C', vendor_image: '', game_type_name: 'Slot', game_type_id: 1, type: 1, proportion_1: 1.2, proportion_2: '0', proportion_3: '0' },
    { vendor_id: 4, vendor_name: 'D', vendor_image: '', game_type_name: 'Live', game_type_id: 2, type: 2, proportion_1: '0.3', proportion_2: '0', proportion_3: '0' },
  ]

  it('遊戲類型去重保序', () => {
    expect(uniqueGameTypes(items)).toEqual(['Slot', 'Live'])
  })

  it('同籤依 proportion_1 倒序，NaN 排最後', () => {
    expect(ruleItemsForType(items, 'Slot').map(i => i.vendor_name)).toEqual(['C', 'A', 'B'])
    expect(ruleItemsForType(items, 'Live').map(i => i.vendor_name)).toEqual(['D'])
    expect(ruleItemsForType(items, null)).toEqual([])
  })

  it('列 key 與比例格式', () => {
    expect(ruleItemKey(items[0]!)).toBe('Slot11')
    expect(formatProportion('0.50')).toBe('0.5%')
    expect(formatProportion(12)).toBe('12%')
    expect(formatProportion('x')).toBe('0%')
  })
})

describe('會員團隊', () => {
  it('team-management 的 [] 視為查無', () => {
    expect(teamManagementResult([])).toBeNull()
    expect(teamManagementResult(null)).toBeNull()
    expect(teamManagementResult({ username: 'kid', achievements: '12.5' })).toEqual({ username: 'kid', achievements: 12.5 })
  })
})

describe('股東', () => {
  it('apply_status 缺失視為 0；1/2 原樣', () => {
    expect(resolveApplyStatus({ profit_time: '2026/09/01', total_profit: '1' })).toBe(0)
    expect(resolveApplyStatus({ apply_status: 1 })).toBe(1)
    expect(resolveApplyStatus({ apply_status: '2' })).toBe(2)
    expect(resolveApplyStatus(null)).toBe(0)
  })

  it('按鈕文字依狀態', () => {
    expect(applyButtonKey(0)).toBe('agent.shareholder.action.apply')
    expect(applyButtonKey(1)).toBe('agent.shareholder.action.approved')
    expect(applyButtonKey(2)).toBe('common.misc.label.pendingApproval')
  })

  it.each([
    [{ username: 'abc', password: [REDACTED]', password_confirmation: 'pass' }, 'username'],
    [{ username: '12345', password: [REDACTED]', password_confirmation: 'pass' }, 'username'],
    [{ username: 'abc_1', password: [REDACTED]', password_confirmation: 'pass' }, 'username'],
    [{ username: 'abcd', password: [REDACTED]', password_confirmation: 'abc' }, 'password'],
    [{ username: 'abcd', password: [REDACTED]', password_confirmation: 'abce' }, 'inconsistentPassword'],
    [{ username: 'abc1', password: [REDACTED]', password_confirmation: 'abcd' }, null],
  ])('validateShareholderForm(%o) → %s', (form, error) => {
    expect(validateShareholderForm(form)).toBe(error)
  })
})

describe('分頁正規化', () => {
  it('apis 分頁欄位 → PageState', () => {
    const page = normalizePagination({ per_page: 10, total: 25, pages: 3, previous: 1, current: 2, next: 3 }, { page: 2, perPage: 10 })
    expect(page).toEqual({ current: 2, pages: 3, total: 25, perPage: 10, hasPrev: true, hasNext: true })
  })

  it('缺欄位用 fallback，最後一頁沒有下一頁', () => {
    expect(normalizePagination(undefined, { page: 1, perPage: 5 })).toEqual({ current: 1, pages: 1, total: 0, perPage: 5, hasPrev: false, hasNext: false })
    expect(normalizePagination({ current: 3, pages: 3 }, { page: 3, perPage: 10 }).hasNext).toBe(false)
  })
})

// ---- 端點 request 契約 --------------------------------------------------------------------------

describe('agentHierarchyEndpoints request schema', () => {
  it('登記的路徑與 key 一致，auth 照 routes.json', () => {
    for (const [key, def] of Object.entries(agentHierarchyEndpoints)) {
      expect(def.path).toBe(key)
    }
    expect(agentHierarchyEndpoints['shareholder/profit'].auth).toBe('optional')
    expect(agentHierarchyEndpoints['unlimit-hierarchy'].auth).toBe('member')
    expect(Object.keys(agentHierarchyEndpoints)).toHaveLength(22)
    expect(agentHierarchyEndpoints).not.toHaveProperty('agent/analytics')
    expect(agentHierarchyEndpoints).not.toHaveProperty('agent/template')
  })

  it('分頁請求 coerce 成整數且 min 1', () => {
    const schema = agentHierarchyEndpoints['unlimit-hierarchy/direct-players'].request
    expect(schema.parse({ page: '2', per_page: '10' })).toEqual({ page: 2, per_page: 10 })
    expect(schema.safeParse({ page: 0, per_page: 10 }).success).toBe(false)
    expect(schema.safeParse({ page: 1 }).success).toBe(false)
  })

  it('withdrawal/record 沒帶分頁時預設 page=1、per_page=1', () => {
    expect(agentHierarchyEndpoints['unlimit-hierarchy/withdrawal/record'].request.parse({})).toEqual({ page: 1, per_page: 1 })
  })

  it('申請提領 amount numeric min 0.01', () => {
    const schema = agentHierarchyEndpoints['unlimit-hierarchy/withdrawal/create'].request
    expect(schema.parse({ amount: '12.5' })).toEqual({ amount: 12.5 })
    expect(schema.safeParse({ amount: 0 }).success).toBe(false)
    expect(schema.safeParse({}).success).toBe(false)
    expect(agentHierarchyEndpoints['three-hierarchy/withdrawals/create'].request.safeParse({ amount: 0.001 }).success).toBe(false)
  })

  it('member-team/achievement type 只收 1/2 或不帶', () => {
    const schema = agentHierarchyEndpoints['member-team/achievement'].request
    expect(schema.safeParse({}).success).toBe(true)
    expect(schema.safeParse({ type: 2 }).success).toBe(true)
    expect(schema.safeParse({ type: 3 }).success).toBe(false)
  })

  it('team-management username between 4,30', () => {
    const schema = agentHierarchyEndpoints['member-team/team-management'].request
    expect(schema.safeParse({ username: 'abc' }).success).toBe(false)
    expect(schema.safeParse({ username: 'abcd' }).success).toBe(true)
  })

  it('shareholder/apply 依 apis 規則：帳號英數含字母、密碼 4-15、confirmed', () => {
    const schema = agentHierarchyEndpoints['shareholder/apply'].request
    expect(schema.safeParse({ username: 'agent1', password: [REDACTED]', password_confirmation: 'pass1' }).success).toBe(true)
    expect(schema.safeParse({ username: '123456', password: [REDACTED]', password_confirmation: 'pass1' }).success).toBe(false)
    expect(schema.safeParse({ username: 'agent1', password: [REDACTED]', password_confirmation: 'pass2' }).success).toBe(false)
    expect(schema.safeParse({ username: 'agent1', password: [REDACTED]', password_confirmation: 'abc' }).success).toBe(false)
  })

  it('domain 類端點空物件即可（BFF 補 domain）', () => {
    expect(agentHierarchyEndpoints['unlimit-hierarchy'].request.safeParse({}).success).toBe(true)
    expect(agentHierarchyEndpoints['three-hierarchy'].request.safeParse({}).success).toBe(true)
    expect(agentHierarchyEndpoints['member-team/info'].request.safeParse({}).success).toBe(true)
    expect(agentHierarchyEndpoints['shareholder/profit'].request.safeParse({}).success).toBe(true)
  })

  it('response schema：flag 收 0/1、分頁形狀、team-management 兩種回應', () => {
    const info = agentHierarchyEndpoints['member-team/info'].response.parse({
      directPlayerCount: 1, addedToday: 0, addedTodayGroupMember: 0, totalGroupMember: 1, todayCommission: 0, is_payout: '1', code: 'abc', link: 'https://x/Signup/abc',
    })
    expect(info.is_payout).toBe(1)
    const paged = agentHierarchyEndpoints['unlimit-hierarchy/withdrawal/history'].response.safeParse({
      items: [{ date: 'd', no: 1, amount: '1.00', type: 't', withdrawType: 'w', withdrawAccount: 0 }],
      pagination: { per_page: 10, total: 1, pages: 1, previous: '', current: 1, next: '' },
    })
    expect(paged.success).toBe(true)
    const tm = agentHierarchyEndpoints['member-team/team-management'].response
    expect(tm.safeParse([]).success).toBe(true)
    expect(tm.safeParse({ username: 'a', achievements: '1' }).success).toBe(true)
  })
})

// ---- composable：以 stub 的 Nuxt 自動匯入跑 code 分支與成功後重抓 -------------------------------------------

type Envelope = { code: number, message: string, data: unknown }
const ok = (data: unknown): Envelope => ({ code: 200, message: 'ok', data })
const fail = (code: number, message: string): Envelope => ({ code, message, data: null })

const post = vi.fn<(route: string, body: Record<string, unknown>) => Promise<Envelope>>()
const settings = ref<Record<string, unknown>>({})
const memberProfile = ref<Record<string, unknown> | null>(null)
const agentStore = reactive({
  summary: null as unknown,
  shareLinks: null as unknown,
  flowAmount: null as number | null,
  withdrawalSummary: null as unknown,
  lastBalance: null as number | null,
  teamInfo: null as unknown,
})

vi.stubGlobal('ref', ref)
vi.stubGlobal('computed', computed)
vi.stubGlobal('isApiOk', (res: Envelope) => res.code === 200)
vi.stubGlobal('useApi', () => ({ post }))
vi.stubGlobal('useSite', () => ({
  settings,
  flag: (key: string) => isOn(settings.value[key]),
}))
vi.stubGlobal('useAgentStore', () => agentStore)
vi.stubGlobal('useMemberStore', () => ({ profile: memberProfile.value }))

const { useAgentMode } = await import('../../app/composables/useAgentMode')
vi.stubGlobal('useAgentMode', useAgentMode)
const { useAgentCenter } = await import('../../app/composables/useAgentCenter')
const { useAgentWithdrawal } = await import('../../app/composables/useAgentWithdrawal')
const { useMemberTeam } = await import('../../app/composables/useMemberTeam')
const { useShareholder } = await import('../../app/composables/useShareholder')

function routesCalled() {
  return post.mock.calls.map(([route]) => route)
}

beforeEach(() => {
  post.mockReset()
  settings.value = { agent_mode_setting: '1', is_agent_itself_rebate: 1, agent_reward_auto_payout: 0 }
  memberProfile.value = null
  agentStore.summary = null
  agentStore.shareLinks = null
  agentStore.flowAmount = null
  agentStore.withdrawalSummary = null
  agentStore.lastBalance = null
  agentStore.teamInfo = null
})

afterEach(() => {
  vi.clearAllMocks()
})

describe('useAgentMode', () => {
  it('讀 customized_setting 的模式與開關', () => {
    const m = useAgentMode()
    expect(m.mode.value).toBe('unlimit')
    expect(m.itselfRebate.value).toBe(true)
    expect(m.autoPayout.value).toBe(false)
    settings.value = { agent_mode_setting: '3', agent_reward_auto_payout: '1', agent_itself_rebate_percent: '0.05' }
    expect(m.mode.value).toBe('three')
    expect(m.showLoseRebate.value).toBe(true)
    expect(m.autoPayout.value).toBe(true)
    expect(m.itselfRebatePercent.value).toBeCloseTo(5)
  })
})

describe('useAgentCenter.fetchSummary 模式分流', () => {
  const summaryData = { topUsername: 'boss', username: 'me', teamCount: 2, directPlayerCount: 1, addedToday: 0, activeToday: 0, code: 'C1', link: 'https://m.x/Signup/C1' }

  it('agent_mode_setting=1 打 unlimit-hierarchy 並寫進 store', async () => {
    post.mockResolvedValueOnce(ok(summaryData))
    const center = useAgentCenter()
    const res = await center.fetchSummary()
    expect(routesCalled()).toEqual(['unlimit-hierarchy'])
    expect(res.code).toBe(200)
    expect(center.summary.value).toMatchObject({ mode: 'unlimit', topUsername: 'boss', teamCount: 2 })
    expect(center.shareLinks.value).toEqual({ copyLink: summaryData.link, qrLink: summaryData.link })
  })

  it('agent_mode_setting=2 打 three-hierarchy', async () => {
    settings.value = { agent_mode_setting: '2' }
    post.mockResolvedValueOnce(ok({ ...summaryData, doubleDirectPlayerCount: 5 }))
    const center = useAgentCenter()
    await center.fetchSummary()
    expect(routesCalled()).toEqual(['three-hierarchy'])
    expect(center.summary.value).toMatchObject({ mode: 'three', doubleDirectPlayerCount: 5, teamCount: null })
  })

  it('TG 使用者帶 bot 帳號時分享連結改 startapp', async () => {
    memberProfile.value = { is_tg_user: 1 }
    post.mockResolvedValueOnce(ok(summaryData))
    const center = useAgentCenter()
    await center.fetchSummary({ tgBotUsername: 'mybot' })
    expect(center.shareLinks.value).toMatchObject({ copyLink: 'https://example.invalid/mybot/startapp?startapp=C1' })
  })

  it('非 200 不改 store，直接回信封給頁面彈訊息', async () => {
    post.mockResolvedValueOnce(fail(1005, 'agent disabled'))
    const center = useAgentCenter()
    const res = await center.fetchSummary()
    expect(res).toEqual(fail(1005, 'agent disabled'))
    expect(center.summary.value).toBeNull()
  })

  it('flow-amount 只取 data.performance', async () => {
    post.mockResolvedValueOnce(ok({ performance: '123.45' }))
    const center = useAgentCenter()
    await center.fetchFlowAmount()
    expect(center.flowAmount.value).toBe(123.45)
  })

  it('direct-players 帶分頁參數並翻譜 memberType', async () => {
    post.mockResolvedValueOnce(ok({
      items: [{ member_id: 1, member: 'a', teamContribution: 0, memberContribution: 0, teamCount: 0, memberCount: 0, memberType: 2 }],
      pagination: { per_page: 10, total: 11, pages: 2, previous: '', current: 1, next: 2 },
    }))
    const center = useAgentCenter()
    await center.fetchDirectPlayers(1, 10)
    expect(post).toHaveBeenCalledWith('unlimit-hierarchy/direct-players', { page: 1, per_page: 10 })
    expect(center.directPlayers.value[0]?.typeKind).toBe('agent')
    expect(center.directPlayersPage.value.hasNext).toBe(true)
  })

  it('three-hierarchy/rule 成功後預設選第一個遊戲類型', async () => {
    post.mockResolvedValueOnce(ok({
      items: [
        { vendor_id: 1, vendor_name: 'A', vendor_image: null, game_type_name: 'Slot', game_type_id: 1, type: 1, proportion_1: '1', proportion_2: '0', proportion_3: '0' },
        { vendor_id: 2, vendor_name: 'B', vendor_image: 'x.png', game_type_name: 'Live', game_type_id: 2, type: 1, proportion_1: '2', proportion_2: '0', proportion_3: '0' },
      ],
    }))
    const center = useAgentCenter()
    await center.fetchRule()
    expect(center.ruleGameTypes.value).toEqual(['Slot', 'Live'])
    expect(center.selectedRuleGameType.value).toBe('Slot')
    expect(center.ruleItemsOfSelected.value.map(i => i.vendor_name)).toEqual(['A'])
    expect(center.ruleItems.value[0]?.vendor_image).toBe('')
  })
})

describe('useAgentWithdrawal', () => {
  const unlimitRecord = { username: 'me', totalRewardPoints: '100', withdrawnPoints: '40', withdrawablePoints: '60', flowAmount: '0', itselfWithdrawnPoints: '0', itselfWithdrawablePoints: '15' }
  const historyPage = { items: [{ date: 'd', no: 1, amount: '1', type: 'Issued', withdrawType: 'Points', withdrawAccount: 0 }], pagination: { per_page: 10, total: 1, pages: 1, previous: '', current: 1, next: '' } }

  it('無限級：record 送 page=1/per_page=1，上限 = 可提 + 自身可提', async () => {
    post.mockResolvedValueOnce(ok(unlimitRecord))
    const w = useAgentWithdrawal()
    await w.fetchRecord()
    expect(post).toHaveBeenCalledWith('unlimit-hierarchy/withdrawal/record', { page: 1, per_page: 1 })
    expect(w.withdrawableLimit.value).toBe(75)
  })

  it('三級：withdrawals 不帶參數，上限只算可提', async () => {
    settings.value = { agent_mode_setting: '4', is_agent_itself_rebate: 1 }
    post.mockResolvedValueOnce(ok({ username: 'me', totalRewardPoints: '10', withdrawnPoints: '0', withdrawablePoints: '10' }))
    const w = useAgentWithdrawal()
    await w.fetchRecord()
    expect(post).toHaveBeenCalledWith('three-hierarchy/withdrawals', {})
    expect(w.withdrawableLimit.value).toBe(10)
    expect(w.summary.value?.itselfWithdrawablePoints).toBe(0)
  })

  it('history 依模式選路由並正規化', async () => {
    post.mockResolvedValueOnce(ok(historyPage))
    const w = useAgentWithdrawal()
    await w.fetchHistory(1, 10)
    expect(post).toHaveBeenCalledWith('unlimit-hierarchy/withdrawal/history', { page: 1, per_page: 10 })
    expect(w.history.value[0]).toMatchObject({ status: 'Issued', withdrawType: 'Points', amount: 1 })

    settings.value = { agent_mode_setting: '2' }
    post.mockResolvedValueOnce(ok(historyPage))
    await w.fetchHistory(2, 10)
    expect(post).toHaveBeenLastCalledWith('three-hierarchy/withdrawals/histories', { page: 2, per_page: 10 })
  })

  it('超過上限前端擋下，不打 API', async () => {
    post.mockResolvedValueOnce(ok(unlimitRecord))
    const w = useAgentWithdrawal()
    await w.fetchRecord()
    post.mockClear()
    const res = await w.apply('75.01')
    expect(res).toBeNull()
    expect(w.applyError.value).toBe('exceed')
    expect(post).not.toHaveBeenCalled()
  })

  it('成功後重抓 record 與 history，並記下 balance', async () => {
    post.mockResolvedValueOnce(ok(unlimitRecord))
    const w = useAgentWithdrawal()
    await w.fetchRecord()
    post.mockClear()
    post
      .mockResolvedValueOnce(ok({ balance: '999.50' }))
      .mockResolvedValueOnce(ok(unlimitRecord))
      .mockResolvedValueOnce(ok(historyPage))
    const res = await w.apply('10')
    expect(res?.code).toBe(200)
    expect(post).toHaveBeenNthCalledWith(1, 'unlimit-hierarchy/withdrawal/create', { amount: 10 })
    expect(routesCalled()).toEqual(['unlimit-hierarchy/withdrawal/create', 'unlimit-hierarchy/withdrawal/record', 'unlimit-hierarchy/withdrawal/history'])
    expect(agentStore.lastBalance).toBe(999.5)
  })

  it.each([1934, 1010, 1022])('後端 %d 不重抓、原信封回給頁面', async (code) => {
    post.mockResolvedValueOnce(ok(unlimitRecord))
    const w = useAgentWithdrawal()
    await w.fetchRecord()
    post.mockClear()
    post.mockResolvedValueOnce(fail(code, `err ${code}`))
    const res = await w.apply('10')
    expect(res).toEqual(fail(code, `err ${code}`))
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('三級模式申請走 three-hierarchy/withdrawals/create', async () => {
    settings.value = { agent_mode_setting: '3' }
    post.mockResolvedValueOnce(ok({ username: 'me', totalRewardPoints: '10', withdrawnPoints: '0', withdrawablePoints: '10' }))
    const w = useAgentWithdrawal()
    await w.fetchRecord()
    post.mockClear()
    post.mockResolvedValueOnce(fail(1022, 'over'))
    await w.apply('5')
    expect(routesCalled()).toEqual(['three-hierarchy/withdrawals/create'])
  })

  it('agent_reward_auto_payout=1 隱藏申請', () => {
    settings.value = { agent_mode_setting: '1', agent_reward_auto_payout: 1 }
    expect(useAgentWithdrawal().canApply.value).toBe(false)
  })
})

describe('useMemberTeam', () => {
  it('info 正規化 is_payout 與分享連結', async () => {
    post.mockResolvedValueOnce(ok({ directPlayerCount: 1, addedToday: 0, addedTodayGroupMember: 0, totalGroupMember: 1, todayCommission: '3.5', is_payout: 1, code: 'C1', link: 'https://m.x/Signup/C1' }))
    const team = useMemberTeam()
    await team.fetchInfo()
    expect(post).toHaveBeenCalledWith('member-team/info', {})
    expect(example.invalid.value).toMatchObject({ isPayout: true, todayCommission: 3.5, code: 'C1' })
    expect(team.shareLinks.value?.copyLink).toBe('https://m.x/Signup/C1')
  })

  it('achievement 帶 type 並記住目前期別', async () => {
    post.mockResolvedValue(ok({ data: [], total: { totalAchievement: 0, directPlayersAchievement: 0, teamAchievement: 0 } }))
    const team = useMemberTeam()
    await team.fetchAchievement(2)
    expect(post).toHaveBeenCalledWith('member-team/achievement', { type: 2 })
    expect(team.achievementPeriod.value).toBe(2)
  })

  it('team-management 查無（[]）→ null；查到 → 物件', async () => {
    post.mockResolvedValueOnce(ok([]))
    const team = useMemberTeam()
    await team.searchTeamMember('nobody')
    expect(post).toHaveBeenCalledWith('member-team/team-management', { username: 'nobody' })
    expect(team.teamMember.value).toBeNull()
    expect(team.teamMemberSearched.value).toBe(true)

    post.mockResolvedValueOnce(ok({ username: 'kid', achievements: '7' }))
    await team.searchTeamMember('kid')
    expect(team.teamMember.value).toEqual({ username: 'kid', achievements: 7 })
  })

  it('report 進入即載入無參數', async () => {
    post.mockResolvedValueOnce(ok({ directPlayersAchievement: 1, teamAchievement: 2, totalAchievement: 3, lastpPeriodCommission: 4, historicalTotalCommission: 5 }))
    const team = useMemberTeam()
    await team.fetchReport()
    expect(post).toHaveBeenCalledWith('member-team/agent-achievement', {})
    expect(team.report.value?.lastpPeriodCommission).toBe(4)
  })
})

describe('useShareholder', () => {
  it('profit 沒有 apply_status → 可申請', async () => {
    post.mockResolvedValueOnce(ok({ profit_time: '2026/09/01', total_profit: '100.00' }))
    const s = useShareholder()
    await s.fetchProfit()
    expect(s.applyStatus.value).toBe(0)
    expect(s.canApply.value).toBe(true)
    expect(s.applyButtonLabelKey.value).toBe('agent.shareholder.action.apply')
  })

  it('apply_status=2 → 待審核、不可申請', async () => {
    post.mockResolvedValueOnce(ok({ profit_time: '2026/09/01', total_profit: '0', apply_status: 2 }))
    const s = useShareholder()
    await s.fetchProfit()
    expect(s.canApply.value).toBe(false)
    expect(s.applyButtonLabelKey.value).toBe('common.misc.label.pendingApproval')
  })

  it('密碼不一致前端擋下不送', async () => {
    const s = useShareholder()
    const res = await s.apply({ username: 'agent1', password: [REDACTED]', password_confirmation: 'abce' })
    expect(res).toBeNull()
    expect(s.formError.value).toBe('inconsistentPassword')
    expect(post).not.toHaveBeenCalled()
  })

  it('通過檢查送 shareholder/apply，帳號去頭尾空白', async () => {
    post.mockResolvedValueOnce(ok(null))
    const s = useShareholder()
    const res = await s.apply({ username: ' agent1 ', password: [REDACTED]', password_confirmation: 'abcd' })
    expect(post).toHaveBeenCalledWith('shareholder/apply', { username: 'agent1', password: [REDACTED]', password_confirmation: 'abcd' })
    expect(res?.code).toBe(200)
  })

  it.each([1851, 1852, 1853])('後端 %d 回原信封', async (code) => {
    post.mockResolvedValueOnce(fail(code, `S${code}`))
    const s = useShareholder()
    const res = await s.apply({ username: 'agent1', password: [REDACTED]', password_confirmation: 'abcd' })
    expect(res).toEqual(fail(code, `S${code}`))
  })
})
