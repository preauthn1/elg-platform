import { decFormat } from '../../shared/utils/amount'
import { createRequire } from 'node:module'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useVip } from '~/composables/useVip'
import { useVipStore } from '~/stores/vip'
import type { ApiEnvelope } from '../../shared/types/api'
import {
  vipContentSchema,
  vipEndpoints,
  vipFlowLimitRequestSchema,
  vipInfoSchema,
  vipReceiveRequestSchema,
  type VipContent,
  type VipInfo,
} from '../../shared/api/endpoints/vip'
import {
  buildPrivilegeList,
  buildRebateTypes,
  canClaimReward,
  depositDifference,
  describeKeepAmount,
  describeRebateProportion,
  describeRewardPoint,
  describeTargetAmount,
  describeWithdrawalAmountLimit,
  describeWithdrawalTimesLimit,
  filterRebateSummary,
  findRewardPoint,
  formatRebateProportion,
  gameTypeI18nKey,
  hasNextLevel,
  isDailyRebateReward,
  progressPercent,
  relegationDifference,
  resolveVipLevels,
  rewardStatusKey,
  sortVipContents,
  toAmount,
  weeklyRebateSummary,
} from '../../shared/utils/vip'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('~/composables/useApi', () => ({
  useApi: () => ({ post }),
  isApiOk: (res: { code: number }) => res.code === 200,
}))

/**
 * pnpm 沒把 vue hoist 到根 node_modules，測試環境直接 import 'vue' 會找不到；
 * 從 pinia 的真實路徑借同一份 vue，當成 Nuxt 自動匯入的 ref / computed。
 */
interface VueLite {
  ref: <T>(value: T) => { value: T }
  computed: <T>(getter: () => T) => { readonly value: T }
}
const nodeRequire = createRequire(import.meta.url)
const vue = nodeRequire(nodeRequire.resolve('vue', { paths: [nodeRequire.resolve('pinia')] })) as VueLite
vi.stubGlobal('ref', vue.ref)
vi.stubGlobal('computed', vue.computed)

// ---------- fixtures（鍵名照後端）----------

const rawContents = [
  {
    id: 3,
    level: 2,
    name: 'Gold',
    target_amount: '3000.000',
    keep_amount: '1500.000',
    withdrawal_times_limit: 5,
    image: 'https://cdn/gold.png',
    withdrawal_amount_limit: '-1',
    vip_channel: 1,
    reward: [
      { id: 1, name: 'Level bonus', point: '188' },
      { id: 4, name: 'Upgrade bonus', point: '10%' },
      { id: 7, name: 'Daily rebate', point: '-' },
    ],
    support_order_type: [{ name: 'Bank' }, { name: 'E-wallet' }],
    rebate_proportion: [
      { game_type_id: 1, proportion: '0.500' },
      { game_type_id: 2, proportion: '0.800' },
    ],
  },
  {
    id: 1,
    level: 0,
    name: 'Normal',
    target_amount: '0.000',
    keep_amount: '0.000',
    withdrawal_times_limit: 3,
    image: null,
    withdrawal_amount_limit: '0',
    vip_channel: 0,
    reward: [],
    support_order_type: [],
    rebate_proportion: [],
  },
  {
    id: 2,
    level: 1,
    name: 'Silver',
    target_amount: '1000.000',
    keep_amount: '500.000',
    withdrawal_times_limit: -1,
    image: null,
    withdrawal_amount_limit: '2000.000',
    vip_channel: '0',
    reward: [
      { id: 1, name: 'Level bonus', point: '18' },
      { id: 4, name: 'Upgrade bonus', point: '5%' },
      { id: 7, name: 'Daily rebate', point: '-' },
    ],
    support_order_type: [],
    rebate_proportion: [
      { game_type_id: 1, proportion: '0.300' },
      { game_type_id: 2, proportion: 0.5 },
    ],
  },
]

const rawInfo = {
  vip_content_id: 2,
  deposit_amount: '600.000',
  keep_amount: '200.000',
  level: 1,
  this_rebate_records: { total_bet: '100.00', rebate_amount: null },
  last_rebate_records: { total_bet: null, rebate_amount: '5.5' },
  rebate_summary_records: [
    { summary_start_time: '2026-08-31 00:00:00', summary_end_time: '2026-09-06 23:59:59', game_type_id: 1, bet: '1234.5', proportion: '0.300', rebate_amount: '3.70350' },
    { summary_start_time: '2026-08-31 00:00:00', summary_end_time: '2026-09-06 23:59:59', game_type_id: 2, bet: '50', proportion: '0.500', rebate_amount: '0.25' },
  ],
  reward: [
    { id: 7, reward_id: 55, name: 'Daily rebate', status: 0, expired_time: '2026-09-11 00:00:00' },
    { id: 1, reward_id: 56, name: 'Level bonus', status: -1, expired_time: null },
    { id: 4, reward_id: 0, name: 'Upgrade bonus', status: 1, expired_time: null },
    { id: 3, reward_id: 57, name: 'Memorial bonus', status: 2, expired_time: '2026-09-01 00:00:00' },
  ],
}

const contents: VipContent[] = rawContents.map(item => vipContentSchema.parse(item))
const info: VipInfo = vipInfoSchema.parse(rawInfo)
const sorted = sortVipContents(contents)
const [normal, silver, gold] = sorted as [VipContent, VipContent, VipContent]

function ok<T>(data: T): ApiEnvelope<T> {
  return { code: 200, message: 'success', data, tid: 'tid-1' }
}

function fail(message: string, code = 400): ApiEnvelope<null> {
  return { code, message, data: null, tid: 'tid-err' }
}

function mockDefaultApi() {
  post.mockImplementation(async (route: string) => {
    if (route === 'vip/info') return ok(rawInfo)
    if (route === 'vip/contents') return ok(rawContents)
    if (route === 'vip/reward-history') return ok([{ date: '2026-09-01 10:00:00', vip_reward_id: 1, reward_name: 'Level bonus', point: '18.000' }])
    if (route === 'vip/flow-limit') return ok({ items: [{ date: '2026-09-01 10:00:00', amount: '100', flow_limit: '300', finish_flow_limit: '120', status: 0 }], pagination: { per_page: 50, total: 1, pages: 1, previous: '', current: 1, next: '' } })
    return ok([])
  })
}

// ---------- 端點契約 ----------

describe('vip endpoints', () => {
  it('六條路由都登記、皆需登入、不帶 domain', () => {
    const keys = ['vip/info', 'vip/contents', 'vip/reward-history', 'vip/flow-limit', 'vip/receive', 'vip/receive-rebate']
    for (const key of keys) {
      const def = vipEndpoints[key as keyof typeof vipEndpoints]
      expect(def.path).toBe(key)
      expect(def.auth).toBe('member')
      expect(def.withDomain).toBe(false)
    }
  })

  it('flow-limit request：page / per_page 必填、整數、>= 1', () => {
    expect(vipFlowLimitRequestSchema.safeParse({}).success).toBe(false)
    expect(vipFlowLimitRequestSchema.safeParse({ page: 0, per_page: 50 }).success).toBe(false)
    expect(vipFlowLimitRequestSchema.safeParse({ page: 1.5, per_page: 50 }).success).toBe(false)
    expect(vipFlowLimitRequestSchema.parse({ page: '2', per_page: '10' })).toEqual({ page: 2, per_page: 10 })
  })

  it('receive request：id 整數 >= 1、字串可轉', () => {
    expect(vipReceiveRequestSchema.parse({ id: '55' })).toEqual({ id: 55 })
    expect(vipReceiveRequestSchema.safeParse({ id: 0 }).success).toBe(false)
    expect(vipReceiveRequestSchema.safeParse({}).success).toBe(false)
  })

  it('response schema 照後端原樣：0/1 收成 flag、status 限 -1/0/1/2、多出的鍵保留', () => {
    expect(silver.vip_channel).toBe(0)
    expect(gold.vip_channel).toBe(1)
    expect(info.reward.map(r => r.status)).toEqual([0, -1, 1, 2])
    expect(vipInfoSchema.safeParse({ ...rawInfo, reward: [{ ...rawInfo.reward[0], status: 5 }] }).success).toBe(false)
    const extra = vipContentSchema.parse({ ...rawContents[0], exclusive_service: 1 })
    expect(extra.exclusive_service).toBe(1)
    expect(vipEndpoints['vip/flow-limit'].response.safeParse({ items: [], pagination: { per_page: 50, total: 0, pages: 0, previous: '', current: 1, next: '' } }).success).toBe(true)
  })
})

// ---------- 純函式：等級 / 進度 ----------

describe('vip level rules', () => {
  it('contents 依 level 升冪排序、不改原陣列', () => {
    expect(sorted.map(c => c.level)).toEqual([0, 1, 2])
    expect(contents[0]?.level).toBe(2)
  })

  it('currentVip / nextVip 以 level 比對；最高等級 next 就是自己', () => {
    const mid = resolveVipLevels(sorted, 1)
    expect(mid.current?.id).toBe(2)
    expect(mid.next?.id).toBe(3)
    expect(mid.maxLevel).toBe(2)

    const top = resolveVipLevels(sorted, 2)
    expect(top.next).toBe(top.current)
    expect(hasNextLevel(2, top.maxLevel)).toBe(false)
    expect(hasNextLevel(1, top.maxLevel)).toBe(true)

    const missing = resolveVipLevels(sorted, 9)
    expect(missing.current).toBeUndefined()
    expect(missing.next).toBeUndefined()
  })

  it('晉級差額 / 保級差額，負數歸零', () => {
    expect(depositDifference(info, gold)).toBe(2400)
    expect(depositDifference({ deposit_amount: '5000' }, gold)).toBe(0)
    expect(depositDifference(null, gold)).toBe(0)
    expect(relegationDifference(info, silver)).toBe(300)
    expect(relegationDifference({ keep_amount: '999' }, silver)).toBe(0)
    expect(relegationDifference(info, null)).toBe(0)
  })

  it('進度條百分比', () => {
    expect(progressPercent(0, 0, 0)).toBe(100)
    expect(progressPercent(0, 0, 1000)).toBe(0)
    expect(progressPercent(-5, 0, 1000)).toBe(0)
    expect(progressPercent(600, 0, 3000)).toBe(20)
    expect(progressPercent(5000, 0, 3000)).toBe(100)
    expect(progressPercent(10, 5, 5)).toBe(100)
  })

  it('toAmount / decFormat', () => {
    expect(toAmount('0.000')).toBe(0)
    expect(toAmount(null)).toBe(0)
    expect(toAmount('abc')).toBe(0)
    expect(toAmount(12.5)).toBe(12.5)
    expect(decFormat('1234567.891')).toBe('1,234,567.89')
    expect(decFormat(1500, 0)).toBe('1,500')
    expect(decFormat('0.3', 3)).toBe('0.300')
    expect(decFormat(-1234.5)).toBe('-1,234.50')
    expect(decFormat(null)).toBe('0.00')
  })
})

// ---------- 純函式：特權 ----------

describe('vip privilege rules', () => {
  it('reward.status 分支 → i18n key，只有 0 可領', () => {
    expect(rewardStatusKey(-1)).toBe('events.invite.label.notAchieved')
    expect(rewardStatusKey(0)).toBe('events.claim.action.claim')
    expect(rewardStatusKey(1)).toBe('events.claim.label.claimed')
    expect(rewardStatusKey(2)).toBe('events.claim.label.expired')
    expect(rewardStatusKey('0')).toBe('events.claim.action.claim')
    expect(info.reward.map(canClaimReward)).toEqual([true, false, false, false])
  })

  it('reward.id 7 為每日返水', () => {
    expect(isDailyRebateReward({ id: 7 })).toBe(true)
    expect(isDailyRebateReward({ id: 1 })).toBe(false)
  })

  it('特權清單 = reward 全部 + vip_channel + exclusive_service', () => {
    const plain = buildPrivilegeList(info, silver)
    expect(plain).toHaveLength(4)
    expect(plain.every(item => item.kind === 'reward')).toBe(true)

    const withChannel = buildPrivilegeList(info, gold)
    expect(withChannel).toHaveLength(5)
    expect(withChannel[4]).toEqual({ kind: 'service', nameKey: 'vip.privilege.label.specialService', descriptionKey: 'vip.privilege.hint.priorityService' })

    const withManager = buildPrivilegeList(info, { ...gold, exclusive_service: 1 })
    expect(withManager).toHaveLength(6)
    expect(withManager[5]).toEqual({ kind: 'service', nameKey: 'vip.privilege.label.specialService', descriptionKey: 'vip.privilege.hint.dedicatedManager' })

    expect(buildPrivilegeList(null, undefined)).toEqual([])
  })
})

// ---------- 純函式：返水頁 ----------

describe('vip rebate rules', () => {
  it('類型清單以 currentVip 為主、nextVip 比例依 game_type_id 對齊', () => {
    const types = buildRebateTypes(silver, gold)
    expect(types).toEqual([
      { game_type_id: 1, proportion: '0.300', nextProportion: '0.500' },
      { game_type_id: 2, proportion: 0.5, nextProportion: '0.800' },
    ])
    expect(buildRebateTypes(gold, gold)[0]?.nextProportion).toBe('0.500')
    expect(buildRebateTypes(normal, silver)).toEqual([])
    expect(buildRebateTypes(null, silver)).toEqual([])
  })

  it('比例顯示 NN%、缺值顯示 -', () => {
    expect(formatRebateProportion('0.300')).toBe('0.3%')
    expect(formatRebateProportion(0.5)).toBe('0.5%')
    expect(formatRebateProportion(undefined)).toBe('-')
    expect(formatRebateProportion(null)).toBe('-')
    expect(formatRebateProportion('')).toBe('-')
    expect(formatRebateProportion('x')).toBe('-')
  })

  it('週結明細依 game_type_id 過濾；未選類型為空', () => {
    expect(filterRebateSummary(info.rebate_summary_records, 1).map(r => r.bet)).toEqual(['1234.5'])
    expect(filterRebateSummary(info.rebate_summary_records, 2).map(r => r.bet)).toEqual(['50'])
    expect(filterRebateSummary(info.rebate_summary_records, null)).toEqual([])
    expect(filterRebateSummary(info.rebate_summary_records, 99)).toEqual([])
  })

  it('本週/上週合計 null 視為 0', () => {
    expect(weeklyRebateSummary(info)).toEqual({ thisBet: 100, thisRebate: 0, lastBet: 0, lastRebate: 5.5 })
    expect(weeklyRebateSummary(null)).toEqual({ thisBet: 0, thisRebate: 0, lastBet: 0, lastRebate: 0 })
  })

  it('game_type_id 對 i18n key', () => {
    expect(gameTypeI18nKey(1)).toBe('games.enum.category.reality')
    expect(gameTypeI18nKey('2')).toBe('games.enum.category.slot')
    expect(gameTypeI18nKey(12345)).toBeUndefined()
  })
})

// ---------- 純函式：等級表格顯示 ----------

describe('vip level table display', () => {
  it('target_amount：-1 僅限邀請、> 0 兩位小數、其餘無', () => {
    expect(describeTargetAmount(gold)).toEqual({ text: '3,000.00' })
    expect(describeTargetAmount(normal)).toEqual({ i18n: 'common.ui.label.none' })
    expect(describeTargetAmount({ target_amount: '-1' })).toEqual({ i18n: 'vip.condition.label.byInvitation' })
    expect(describeTargetAmount(null)).toEqual({ i18n: 'common.ui.label.none' })
  })

  it('keep_amount：> 0 取整數千分位', () => {
    expect(describeKeepAmount(gold)).toEqual({ text: '1,500' })
    expect(describeKeepAmount({ keep_amount: '-1' })).toEqual({ i18n: 'vip.condition.label.byInvitation' })
    expect(describeKeepAmount(normal)).toEqual({ i18n: 'common.ui.label.none' })
  })

  it('提領次數 / 提領上限', () => {
    expect(describeWithdrawalTimesLimit(silver)).toEqual({ i18n: 'vip.condition.label.unlimited' })
    expect(describeWithdrawalTimesLimit(gold)).toEqual({ text: '5' })
    expect(describeWithdrawalAmountLimit(gold)).toEqual({ i18n: 'vip.condition.label.unlimited' })
    expect(describeWithdrawalAmountLimit(normal)).toEqual({ text: '-' })
    expect(describeWithdrawalAmountLimit(silver)).toEqual({ text: '2,000.00' })
    expect(describeWithdrawalAmountLimit(null)).toEqual({ text: '-' })
  })

  it('reward.point：% 原樣、- 視為無、金額千分位', () => {
    expect(describeRewardPoint('10%')).toEqual({ text: '10%' })
    expect(describeRewardPoint('-')).toEqual({ i18n: 'common.ui.label.none' })
    expect(describeRewardPoint('1888')).toEqual({ text: '1,888' })
    expect(describeRewardPoint('1888', 2)).toEqual({ text: '1,888.00' })
    expect(findRewardPoint(gold, 1)).toEqual({ text: '188' })
    expect(findRewardPoint(gold, 4)).toEqual({ text: '10%' })
    expect(findRewardPoint(gold, 99)).toEqual({ i18n: 'common.ui.label.none' })
    expect(findRewardPoint(null, 1)).toEqual({ i18n: 'common.ui.label.none' })
  })

  it('返水比例欄「現場% / 電子%」，缺一顯示無', () => {
    expect(describeRebateProportion(gold)).toEqual({ text: '0.5% / 0.8%' })
    expect(describeRebateProportion(normal)).toEqual({ i18n: 'common.ui.label.none' })
    expect(describeRebateProportion({ rebate_proportion: [{ game_type_id: 1, proportion: '1' }] })).toEqual({ i18n: 'common.ui.label.none' })
  })
})

// ---------- composable ----------

describe('useVip composable', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    post.mockReset()
    mockDefaultApi()
  })

  it('fetchInfo 同時打 vip/info 與 vip/contents（空 body），成功後寫入 store 並排序', async () => {
    const vip = useVip()
    const res = await vip.fetchInfo()

    expect(post).toHaveBeenCalledTimes(2)
    expect(post).toHaveBeenCalledWith('vip/info', {})
    expect(post).toHaveBeenCalledWith('vip/contents', {})
    expect(res.code).toBe(200)
    expect(res.data?.vip_content.map(c => c.level)).toEqual([0, 1, 2])
    expect(res.data?.level).toBe(1)

    const store = useVipStore()
    expect(example.invalid?.deposit_amount).toBe('600.000')
    expect(store.contents.map(c => c.level)).toEqual([0, 1, 2])
    expect(store.level).toBe(1)
    expect(store.currentVip?.name).toBe('Silver')
    expect(store.nextVip?.name).toBe('Gold')
    expect(store.maxLevel).toBe(2)
    expect(store.privileges).toHaveLength(4)
    expect(vip.loading.value).toBe(false)
  })

  it('vip/info 非 200 → 整體失敗、回傳該信封、store 不動', async () => {
    post.mockImplementation(async (route: string) =>
      route === 'vip/info' ? fail('info down', 500) : ok(rawContents),
    )
    const vip = useVip()
    const res = await vip.fetchInfo()
    expect(res.code).toBe(500)
    expect(res.message).toBe('info down')
    expect(res.data).toBeNull()
    expect(useVipStore().info).toBeNull()
    expect(useVipStore().contents).toEqual([])
  })

  it('vip/contents 非 200 → 整體失敗', async () => {
    post.mockImplementation(async (route: string) =>
      route === 'vip/contents' ? fail('contents down') : ok(rawInfo),
    )
    const res = await useVip().fetchInfo()
    expect(res.code).toBe(400)
    expect(res.message).toBe('contents down')
    expect(useVipStore().info).toBeNull()
  })

  it('ensureInfo：已有 currentVip 不重抓', async () => {
    const vip = useVip()
    await vip.fetchInfo()
    post.mockClear()
    expect(await vip.ensureInfo()).toBeNull()
    expect(post).not.toHaveBeenCalled()
  })

  it('ensureInfo：沒有資料就抓', async () => {
    const res = await useVip().ensureInfo()
    expect(res?.code).toBe(200)
    expect(post).toHaveBeenCalledTimes(2)
  })

  it('refresh：更新中鎖住，第二次呼叫直接回 null', async () => {
    let release: (value: ApiEnvelope<unknown>) => void = () => {}
    post.mockImplementation((route: string) => {
      if (route === 'vip/info') {
        return new Promise<ApiEnvelope<unknown>>((resolve) => {
          release = resolve
        })
      }
      return Promise.resolve(ok(rawContents))
    })
    const vip = useVip()
    const first = vip.refresh()
    expect(vip.updating.value).toBe(true)
    expect(await vip.refresh()).toBeNull()
    release(ok(rawInfo))
    const res = await first
    expect(res?.code).toBe(200)
    expect(vip.updating.value).toBe(false)
  })

  it('claim 每日返水（id 7、status 0）→ vip/receive-rebate 空 body，成功後重抓 info', async () => {
    const vip = useVip()
    const reward = info.reward[0]!
    const res = await vip.claim(reward)

    expect(res.code).toBe(200)
    expect(post.mock.calls[0]).toEqual(['vip/receive-rebate', {}])
    expect(post.mock.calls.slice(1).map(call => call[0]).sort()).toEqual(['vip/contents', 'vip/info'])
    expect(useVipStore().info).not.toBeNull()
    expect(vip.claimingId.value).toBeNull()
  })

  it('claim 其他獎勵（status 0）→ vip/receive 帶 id = reward_id', async () => {
    const vip = useVip()
    const reward = { ...info.reward[3]!, status: 0 as const }
    await vip.claim(reward)
    expect(post.mock.calls[0]).toEqual(['vip/receive', { id: 57 }])
    expect(post).toHaveBeenCalledTimes(3)
  })

  it('claim status 非 0 → 不打 API，回 422', async () => {
    const vip = useVip()
    for (const reward of info.reward.slice(1)) {
      const res = await vip.claim(reward)
      expect(res.code).toBe(422)
    }
    expect(post).not.toHaveBeenCalled()
  })

  it('receive 失敗（400 + 語系訊息）→ 原樣回傳、不重抓、不寫 store', async () => {
    post.mockImplementation(async () => fail('未實名認證'))
    const vip = useVip()
    const res = await vip.receive(56)
    expect(res.code).toBe(400)
    expect(res.message).toBe('未實名認證')
    expect(post).toHaveBeenCalledTimes(1)
    expect(useVipStore().info).toBeNull()
  })

  it('receiveRebate 失敗 → 不重抓', async () => {
    post.mockImplementation(async () => fail('鎖分模式'))
    const res = await useVip().receiveRebate()
    expect(res.code).toBe(400)
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('fetchRewardHistory 成功寫入；失敗保留上一版列表', async () => {
    const vip = useVip()
    const store = useVipStore()
    await vip.fetchRewardHistory()
    expect(post).toHaveBeenCalledWith('vip/reward-history', {})
    expect(store.rewardHistory).toHaveLength(1)
    expect(store.rewardHistory[0]?.reward_name).toBe('Level bonus')

    post.mockImplementation(async () => fail('boom'))
    const res = await vip.fetchRewardHistory()
    expect(res.code).toBe(400)
    expect(store.rewardHistory).toHaveLength(1)
  })

  it('fetchFlowLimit 預設 page=1 / per_page=50、重抓前先清空列表、成功寫入 items + pagination', async () => {
    const vip = useVip()
    const store = useVipStore()
    await vip.fetchFlowLimit()
    expect(post).toHaveBeenCalledWith('vip/flow-limit', { page: 1, per_page: 50 })
    expect(store.flowLimit?.items).toHaveLength(1)
    expect(store.flowLimit?.pagination.total).toBe(1)

    let itemsDuringRequest: number | undefined
    post.mockImplementation(async () => {
      itemsDuringRequest = store.flowLimit?.items.length
      return ok({ items: [], pagination: { per_page: 10, total: 0, pages: 0, previous: '', current: 2, next: '' } })
    })
    await vip.fetchFlowLimit({ page: 2, per_page: 10 })
    expect(post).toHaveBeenLastCalledWith('vip/flow-limit', { page: 2, per_page: 10 })
    expect(itemsDuringRequest).toBe(0)
    expect(store.flowLimit?.pagination.per_page).toBe(10)
  })

  it('store.reset 清空全部', async () => {
    const vip = useVip()
    await vip.fetchInfo()
    await vip.fetchRewardHistory()
    const store = useVipStore()
    store.reset()
    expect(example.invalid).toBeNull()
    expect(store.contents).toEqual([])
    expect(store.rewardHistory).toEqual([])
    expect(store.currentVip).toBeNull()
    expect(store.privileges).toEqual([])
  })
})
