import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import type { AngpowInfo, EventListItem, LegacyEventRow } from '../../shared/api/endpoints/events-core'
import {
  ACTIVITY_DEMO_PATHS,
  ACTIVITY_POPUPS,
  ANGPOW_PATH,
  angpowRemaining,
  angpowScoreShort,
  angpowTimesExhausted,
  classifyLegacyEvent,
  dailyPrizeState,
  describeRewardAmount,
  eventV2CanJoin,
  findAngpowEventId,
  filterLegacyEvents,
  getPrizeParts,
  getPrizeText,
  hidesExchangeButton,
  inviteButtonState,
  isActivityDemoRoute,
  JACKPOT_ISSUES_PATH,
  jackpotKind,
  LEGACY_EVENT_PRESETS,
  legacyCardKind,
  legacyProgressPercent,
  legacyShowsProgress,
  rechargeButtonState,
  resolveEventListPath,
  sortLegacyEvents,
  wheelFace,
  wheelSlots,
} from '../../app/utils/events-core'

const EVENTS_PAGES = fileURLToPath(new URL('../../app/pages/events', import.meta.url))
const ACTIVITY_ASSETS = fileURLToPath(new URL('../../public/skins/c17/activity', import.meta.url))

function row(partial: Partial<LegacyEventRow> & { id: number }): LegacyEventRow {
  return partial as LegacyEventRow
}

const EVENTS = [
  row({ id: 1, detail: [{ action_id: 51 }] }),
  row({ id: 2, type: 4 }),
  row({ id: 3, type: 7 }),
  row({ id: 4, action_id: 9 }),
  row({ id: 5, category: 'sport' }),
  row({ id: 6, category: 'invite' }),
]

/** 舊皮 C13 的別名路由（router/C13.js:194-246）分別要看到哪一筆。 */
const EXPECTED: Record<(typeof LEGACY_EVENT_PRESETS)[number], number[]> = {
  'v-rebate': [1],
  'w-rebate': [1],
  'v-jackpot': [2],
  'w-jackpot': [2],
  'w-jackpot6d': [3],
  'lose-rebate': [4],
  'wtt-sports': [5],
}

describe('活動別名路由（preset）', () => {
  /**
   * 守門測試：PRESET_MAP 的篩選邏輯早就寫好了，缺的是進得來的路由——
   * /v-rebate 這些舊連結整批 404。別名只剩邏輯沒有頁面會再發生一次，所以鎖起來。
   */
  it('每個 preset 都有進得來的頁面', () => {
    const dynamicPath = join(EVENTS_PAGES, '[preset].vue')
    const dynamic = existsSync(dynamicPath) && readFileSync(dynamicPath, 'utf8').includes('isLegacyEventPreset')
    for (const preset of LEGACY_EVENT_PRESETS) {
      const own = existsSync(join(EVENTS_PAGES, `${preset}.vue`))
      expect(own || dynamic, `preset ${preset} 沒有對應的頁面`).toBe(true)
    }
  })

  it('每個 preset 篩出舊皮同一批活動', () => {
    for (const preset of LEGACY_EVENT_PRESETS) {
      const ids = filterLegacyEvents(EVENTS, { preset }).map(e => Number(e.id))
      expect(ids, preset).toEqual(EXPECTED[preset])
    }
  })

  it('query.ids 優先於 preset', () => {
    const ids = filterLegacyEvents(EVENTS, { ids: [6], preset: 'v-rebate' }).map(e => Number(e.id))
    expect(ids).toEqual([6])
  })
})

describe('活動分群', () => {
  /**
   * 舊皮的 invite／newInvite／inviteRecharge 三個 computed 都帶
   * `|| event.category == 'invite'`，所以 category 是 invite 的活動會被渲染三次。
   * 新的一張卡只歸一類，順序由 classifyLegacyEvent 決定。
   */
  it('category 是 invite 時仍依 action_id 歸單一類', () => {
    expect(classifyLegacyEvent(row({ id: 7, category: 'invite', detail: [{ action_id: 15 }] }))).toBe('newInvite')
    expect(classifyLegacyEvent(row({ id: 8, category: 'invite', detail: [{ action_id: 16 }] }))).toBe('inviteRecharge')
    expect(classifyLegacyEvent(row({ id: 9, category: 'invite' }))).toBe('invite')
  })
})

describe('V1 活動卡的按鈕狀態機', () => {
  /**
   * 守門測試：V1 的按鈕原本只有一種（通用的「立即兌換／進度未達成」），
   * 但舊皮是一種活動一套狀態機——儲值活動在還沒儲值時顯示的是「儲值」並導到儲值頁，
   * 不是「進度未達成」。這幾條對齊 `_source2/.../Event/EventRechargeItem.vue`
   * 與 `EventInviteItem.vue` 的 eventStatus。
   */
  const recharge = (partial: Partial<LegacyEventRow>) => rechargeButtonState(row({ id: 1, ...partial }))

  it('儲值活動：還沒達標顯示「儲值」並導到儲值頁', () => {
    const state = recharge({ show_exchange: 0 })
    expect(state.status).toBe(3)
    expect(state.textKey).toBe('deposit.common.title.deposit')
    expect(state.action).toBe('topup')
    expect(state.disabled).toBe(false)
  })

  it('儲值活動：可兌換、已兌換、已取消、今日已達標各自的狀態', () => {
    expect(recharge({ show_exchange: 1 })).toMatchObject({ status: 1, action: 'claim', disabled: false })
    expect(recharge({ show_exchange: 0, prize_is_repeatedly: 0, count: 1 })).toMatchObject({ status: 2, disabled: true })
    expect(recharge({ show_exchange: 2, show_exchange_text: '請先儲值' })).toMatchObject({ status: 4, textKey: null, text: '請先儲值', action: 'topup' })
    expect(recharge({ show_exchange: 3, show_exchange_text: '活動已取消' })).toMatchObject({ status: 5, textKey: null, disabled: true })
    expect(recharge({ show_exchange: 0, prize_is_repeatedly: 2, reached_today: 1, action_id: 59 })).toMatchObject({ status: 7, disabled: true })
  })

  it('儲值活動：獎項全數發完顯示「獎項已領取完畢」，但空清單不算', () => {
    expect(recharge({ show_exchange: 1, prize_content: [{ number: 5, sent: 5 }] })).toMatchObject({ status: 6, disabled: true })
    expect(recharge({ show_exchange: 1, prize_content: [{ number: -1, sent: 3 }] })).toMatchObject({ status: 1 })
    // 舊皮這裡用 every()，空陣列會回 true，沒有獎項清單的活動一律變成「已領取完畢」
    expect(recharge({ show_exchange: 1, prize_content: [] })).toMatchObject({ status: 1 })
  })

  it('可兌換時 type 2 走輪盤、type 8 走自選獎項', () => {
    expect(recharge({ show_exchange: 1, type: 2 }).action).toBe('wheel')
    expect(recharge({ show_exchange: 1, type: 8 }).action).toBe('choose')
  })

  it('邀請活動：三段狀態，只有可兌換能按，而且沒有輪盤這條路', () => {
    expect(inviteButtonState(row({ id: 1, show_exchange: 1, type: 2 }))).toMatchObject({ status: 1, action: 'claim', disabled: false })
    expect(inviteButtonState(row({ id: 1, show_exchange: 0, prize_is_repeatedly: 0, count: 2 }))).toMatchObject({ status: 2, disabled: true })
    expect(inviteButtonState(row({ id: 1, show_exchange: 0 }))).toMatchObject({ status: 3, textKey: 'events.claim.label.unaccomplished', disabled: true })
  })

  it('action 31 且 auto_payout=1 不顯示兌換鈕', () => {
    expect(hidesExchangeButton(row({ id: 1, action_id: 31, auto_payout: 1 }))).toBe(true)
    expect(hidesExchangeButton(row({ id: 1, action_id: 31, auto_payout: 0 }))).toBe(false)
  })
})

describe('V1 活動卡的卡別', () => {
  /**
   * 守門測試：deposit／daily_deposit／new_member 這三個 category 在舊皮走的是
   * **充值卡**，cj7slotlossrebate 走輸分回扣卡。全部丟給通用卡會讓按鈕邏輯整批走錯。
   */
  it('多個 category 共用同一張卡', () => {
    expect(legacyCardKind(row({ id: 1, category: 'deposit' }))).toBe('recharge')
    expect(legacyCardKind(row({ id: 2, category: 'daily_deposit' }))).toBe('recharge')
    expect(legacyCardKind(row({ id: 3, category: 'new_member' }))).toBe('recharge')
    expect(legacyCardKind(row({ id: 4, category: 'cj7slotlossrebate' }))).toBe('loseRebate')
    expect(legacyCardKind(row({ id: 5, detail: [{ action_id: 51 }] }))).toBe('recharge')
    expect(legacyCardKind(row({ id: 6, type: 7 }))).toBe('jackpot')
    expect(legacyCardKind(row({ id: 7, category: 'sport' }))).toBe('general')
  })

  it('排序照舊皮的分群順序，同一類維持原順序', () => {
    const list = [
      row({ id: 1, category: 'sport' }),
      row({ id: 2, detail: [{ action_id: 51 }] }),
      row({ id: 3, type: 4 }),
      row({ id: 4, detail: [{ action_id: 52 }] }),
    ]
    expect(sortLegacyEvents(list).map(e => Number(e.id))).toEqual([3, 2, 4, 1])
  })
})

describe('V1 活動卡的獎勵文字', () => {
  const names = { walletPoint: '點', rollingChip: '籌碼' }

  it('point_type 決定單位，3 是實體獎勵', () => {
    expect(describeRewardAmount({ type: 0, point: '100.0000', point_type: 1 }, names)).toEqual({ text: '100 點' })
    expect(describeRewardAmount({ type: 0, point: '100.0000', point_type: 2 }, names)).toEqual({ text: '100 籌碼' })
    expect(describeRewardAmount({ type: 0, point: '100.0000', point_type: 3 }, names)).toEqual({ i18n: 'events.claim.label.physicalPrize' })
  })

  it('有 random_point_max 時顯示區間', () => {
    expect(describeRewardAmount({ type: 0, point: '10', random_point_max: '50', point_type: 1 }, names)).toEqual({ text: '10 ~ 50 點' })
  })

  it('type 1 是百分比', () => {
    expect(describeRewardAmount({ type: 1, point_percentage: '5' }, names)).toEqual({ text: '5%' })
  })
})

describe('V1 抽獎類活動', () => {
  it('type 決定期數頁與開獎參考網站', () => {
    expect(jackpotKind(6)).toBe('wjackpot')
    expect(jackpotKind(7)).toBe('wjackpot6d')
    expect(jackpotKind(4)).toBe('luckyDraw')
    expect(JACKPOT_ISSUES_PATH[jackpotKind(7)]).toBe('/wjackpot/6d/issues')
    expect(JACKPOT_ISSUES_PATH[jackpotKind(4)]).toBe('/lucky-draw/issues')
  })
})

describe('每日活動彈窗', () => {
  /**
   * 守門測試：`show_exchange` 的第三態 2（當期根本沒有 prize_members）**是預設態**，
   * 不是邊緣情況——PERIODIC_CLAIM 被列在「需儲值才可達成」清單裡。
   * 用二態判斷（1 可領 / 其餘已領）會讓沒達成的會員看到「今天已拆完」。
   */
  it('show_exchange 三態，缺值時預設是未達成', () => {
    expect(dailyPrizeState(1)).toBe('ready')
    expect(dailyPrizeState(0)).toBe('done')
    expect(dailyPrizeState(2)).toBe('locked')
    expect(dailyPrizeState(null)).toBe('locked')
    expect(dailyPrizeState(undefined)).toBe('locked')
  })

  /** 每張彈窗的晶片列是**另外三張**，四張互跳；有頁面的才可點。 */
  it('四張彈窗互相對照', () => {
    const keys = Object.keys(ACTIVITY_POPUPS)
    expect(keys).toHaveLength(4)
    for (const [key, popup] of Object.entries(ACTIVITY_POPUPS)) {
      expect(popup.key).toBe(key)
    }
    expect(ACTIVITY_POPUPS.dailyCheckin.to).toBe('/events/checkin')
    expect(ACTIVITY_POPUPS.redPacket.to).toBe('/events/red-packet')
    expect(ACTIVITY_POPUPS.luckyWheel.to).toBe('/events/lucky-wheel')
    expect(ACTIVITY_POPUPS.goldenSmash.to).toBe('/events/golden-smash')
  })

  /** 四顆活動圖示與十二顆晶片的圖都要在，缺了就是破圖（設計稿只出了 icon／chip 兩種尺寸）。 */
  it('彈窗用到的圖都在', () => {
    for (const popup of Object.values(ACTIVITY_POPUPS)) {
      for (const name of [popup.icon, popup.chip]) {
        const file = join(ACTIVITY_ASSETS, `${name}.webp`)
        expect(existsSync(file), `缺 ${name}.webp`).toBe(true)
      }
    }
  })

  /** 有頁面的活動，路由檔案要真的存在，不然晶片點了是 404。 */
  it('晶片指到的頁面真的存在', () => {
    for (const popup of Object.values(ACTIVITY_POPUPS)) {
      if (!popup.to) continue
      const file = join(EVENTS_PAGES, `${popup.to.replace('/events/', '')}.vue`)
      expect(existsSync(file), `${popup.to} 沒有對應的頁面`).toBe(true)
    }
  })
})

describe('輪盤盤面', () => {
  const names = { walletPoint: '點', rollingChip: '籌碼' }
  const prizesOf = (n: number) => Array.from({ length: n }, (_, i) => ({ id: i + 1, type: 0, point: String(i + 1) }))

  /**
   * 守門測試：設計稿畫六格，但獎項是後台設的。
   * 格數寫死六格的話，獎項一多就有獎項排不進盤面，一少就出現空格。
   */
  it('格數跟著獎項數走', () => {
    expect(wheelSlots(prizesOf(3), names)).toHaveLength(3)
    expect(wheelSlots(prizesOf(8), names)).toHaveLength(8)
  })

  /**
   * 守門測試：奇數格時第一格與最後一格是相鄰的，兩格都是彩色。
   * 用「彩色／米白」兩色交錯會讓這兩格撞色，接縫看起來像一塊雙倍寬的扇形。
   */
  it('相鄰的格子不同色，奇數格也一樣', () => {
    for (const count of [1, 3, 5, 6, 7, 9, 12]) {
      const colors = wheelSlots(prizesOf(count), names).map(slot => slot.color)
      if (count === 1) continue
      for (let i = 0; i < count; i++) {
        expect(colors[i], `${count} 格的第 ${i} 格`).not.toBe(colors[(i + 1) % count])
      }
    }
  })

  it('米白格配藍字，彩色格配螢光黃字', () => {
    const [first, second] = wheelSlots(prizesOf(2), names)
    expect(first?.color).not.toBe('var(--c17-wheel-cream)')
    expect(first?.ink).toBe('var(--c17-wheel-ink-tint)')
    expect(second?.color).toBe('var(--c17-wheel-cream)')
    expect(second?.ink).toBe('var(--c17-wheel-ink-cream)')
  })

  /**
   * 獎項文字是兩層的（設計稿 `RM 68` + `CASH`）：數值長度由獎項決定、
   * 單位長度由站台設定與語系決定，併成一行的話長單位會把數字一起壓小。
   */
  it('獎項拆成數值與單位兩層，併起來與單行版一致', () => {
    expect(getPrizeParts({ type: 0, point: '68', point_type: 1 }, names)).toEqual({ value: '68', unit: '點' })
    expect(getPrizeParts({ type: 0, point: '68', point_type: 2 }, names)).toEqual({ value: '68', unit: '籌碼' })
    expect(getPrizeParts({ type: 1, point_percentage: '150' }, names)).toEqual({ value: '150%', unit: '' })
    expect(getPrizeText({ type: 0, point: '68', point_type: 2 }, names)).toBe('68籌碼')
  })
})

describe('輪盤盤面的 CSS', () => {
  const names = { walletPoint: '點', rollingChip: '籌碼' }

  /**
   * 守門測試：**沒有獎項時扇形不可以是透明的**。
   * 金框、燈泡、指針是一張去背的圖疊在扇形上面，扇形透明時畫面上只剩一個空心金圈，
   * 看起來像圖破了——實際發生過（沒帶 `?ids=` 進到輪盤頁時）。
   */
  it('沒有獎項時扇形是實心的', () => {
    const face = wheelFace([])
    expect(face).not.toContain('transparent')
    expect(face).toBe('var(--c17-wheel-cream)')
  })

  /** 第一格的中線要正對 12 點鐘的指針，所以整圈往回轉半格；停格角度是照這個算的。 */
  it('第一格的中線對著 12 點鐘', () => {
    const slots = wheelSlots(Array.from({ length: 6 }, (_, i) => ({ id: i + 1, type: 0, point: '1' })), names)
    expect(wheelFace(slots)).toContain('conic-gradient(from -30deg,')
  })

  /** 守門測試：硬色標的接縫沒有反鋸齒，扇形交界會出現鋸齒（target/tickets#4328 回報過）。 */
  it('相鄰扇形之間留過渡，包含 0°／360° 那條接縫', () => {
    const slots = wheelSlots(Array.from({ length: 6 }, (_, i) => ({ id: i + 1, type: 0, point: '1' })), names)
    const face = wheelFace(slots)
    // 去掉 from 角度與頭尾兩個接縫色，剩下每格的 [開始, 結束]
    const angles = [...face.matchAll(/(-?[\d.]+)deg/g)].map(m => Number(m[1])).slice(2, -1)
    expect(angles).toHaveLength(slots.length * 2)
    for (let i = 1; i + 1 < angles.length; i += 2) {
      expect(angles[i + 1]).toBeGreaterThan(angles[i]!)
    }
    expect(angles[0]).toBeGreaterThan(0)
    expect(angles.at(-1)).toBeLessThan(360)
    expect(face.startsWith('conic-gradient(from -30deg, color-mix(')).toBe(true)
    expect(face).toMatch(/, color-mix\(.+\) 360deg\)$/)
  })
})

describe('活動彈窗的預覽模式', () => {
  const on = (path: string, query: Record<string, unknown> = {}) => isActivityDemoRoute(path, query)

  /**
   * 守門測試：**沒帶 `?ids=` 就進到抽獎彈窗時一定要走預覽**。
   * 真實資料要有活動 id，從選單或晶片點進來時沒有 id，不走預覽的話畫面就是
   * 一片灰的「進度未達成」——回報過兩次，都是因為預覽只認 `?demo=1`。
   */
  it('沒有活動 id 的抽獎彈窗自動走預覽', () => {
    expect(on('/events/lucky-wheel')).toBe(true)
    expect(on('/events/golden-smash')).toBe(true)
  })

  /** 紅包已接活動 V2：沒帶 id 時頁面自己從 event/list 找（findAngpowEventId），只有 ?demo=1 才預覽 */
  it('紅包不走自動預覽', () => {
    expect(on('/events/red-packet')).toBe(false)
    expect(on('/events/red-packet', { demo: '1' })).toBe(true)
  })

  it('帶了活動 id 就是真實資料，不走預覽', () => {
    expect(on('/events/lucky-wheel', { ids: '123' })).toBe(false)
    // 明確要求預覽時例外，方便帶 id 的網址也能看動畫
    expect(on('/events/lucky-wheel', { ids: '123', demo: '1' })).toBe(true)
  })

  it('沒有預覽模式的頁面不受影響', () => {
    expect(on('/events/checkin')).toBe(false)
    expect(on('/member')).toBe(false)
    expect(on('/wallet/deposit')).toBe(false)
  })

  it('預覽路徑跟著 ACTIVITY_POPUPS 走，不另外維護一份', () => {
    const expected = Object.values(ACTIVITY_POPUPS).filter(p => p.demo).map(p => p.to)
    expect(ACTIVITY_DEMO_PATHS).toEqual(expected)
    expect(ACTIVITY_DEMO_PATHS).toHaveLength(2)
  })
})

describe('legacyShowsProgress', () => {
  it('action 11／12／17 不畫進度條，其餘都畫（舊皮 EventItem）', () => {
    for (const id of [11, 12, 17]) expect(legacyShowsProgress({ id: 1, action_id: id } as LegacyEventRow)).toBe(false)
    for (const id of [1, 2, 14, null]) expect(legacyShowsProgress({ id: 1, action_id: id } as LegacyEventRow)).toBe(true)
  })
})

describe('legacyProgressPercent', () => {
  it('超過目標停在 100%，目標為 0 是 0%（舊皮 BaseProgress）', () => {
    expect(legacyProgressPercent({ point: '200.0000', now_point: '50.0000' })).toBe(25)
    expect(legacyProgressPercent({ point: 1, now_point: 3 })).toBe(100)
    expect(legacyProgressPercent({ point: 0, now_point: 0 })).toBe(0)
  })

  it('說明頁只排除 11／12，17 仍然畫進度條', () => {
    expect(legacyShowsProgress({ action_id: 17 }, 'description')).toBe(true)
    expect(legacyShowsProgress({ action_id: 11 }, 'description')).toBe(false)
  })
})

describe('紅包（活動 V2 AngpowService）', () => {
  const base: AngpowInfo = {
    is_open: true,
    score_cost: '10.0000',
    score_balance: '25.0000',
    member_times_limit: 3,
    member_times_remaining: 3,
    site_times_limit: null,
    site_times_remaining: null,
    site_amount_limit: null,
    site_amount_remaining: null,
    interval: 10,
    next_available_in: 0,
    time_window: null,
    in_time_window: true,
  }

  it('Remaining Today 取個人、全站、⌊積分÷扣分⌋ 中非 null 的最小值', () => {
    // 工單測試步驟 1：⌊25÷10⌋＝2 小於個人上限 3
    expect(angpowRemaining(base)).toBe(2)
    expect(angpowRemaining({ ...base, member_times_remaining: 1 })).toBe(1)
    expect(angpowRemaining({ ...base, site_times_remaining: 0 })).toBe(0)
    expect(angpowRemaining({ ...base, member_times_remaining: null, score_balance: '39.9999' })).toBe(3)
    expect(angpowRemaining({ ...base, member_times_remaining: null, score_cost: null })).toBeNull()
  })

  /** 守門：後端次數上限檢查在積分之前，兩者同時成立時不可以先跳導流存款彈窗 */
  it('個人或全站次數用完就算用完，與積分無關', () => {
    expect(angpowTimesExhausted({ ...base, member_times_remaining: 0, score_balance: '0' })).toBe(true)
    expect(angpowTimesExhausted({ ...base, site_times_remaining: 0 })).toBe(true)
    expect(angpowTimesExhausted({ ...base, member_times_remaining: null, site_times_remaining: null })).toBe(false)
    expect(angpowTimesExhausted(base)).toBe(false)
  })

  it('積分少於每抽扣分才算不足；扣分沒設交給 join 回錯', () => {
    expect(angpowScoreShort(base)).toBe(false)
    expect(angpowScoreShort({ ...base, score_balance: '10.0000' })).toBe(false)
    expect(angpowScoreShort({ ...base, score_balance: '9.9999' })).toBe(true)
    expect(angpowScoreShort({ ...base, score_cost: null, score_balance: '0' })).toBe(false)
  })

  it('event/list 的 handler_class 是 AngpowService 時導紅包頁，其餘 V2 照舊', () => {
    const item = (handler: string | null) => ({ event_v1_id: null, event_v2_id: 263, tp_event: null, handler_class: handler }) as EventListItem
    expect(resolveEventListPath(item('AngpowService'))).toBe(`${ANGPOW_PATH}?ids=263`)
    expect(resolveEventListPath(item(null))).toBe('/events/platform-event?ids=263')
  })

  /** 守門：驗證通過時後端也回 message「活動條件達成」，曾因多判斷 message 為空而把按鈕鎖死 */
  it('活動 V2 參加鈕只看 validate', () => {
    expect(eventV2CanJoin({ validate: true })).toBe(true)
    expect(eventV2CanJoin({ validate: true, message: '活動條件達成' } as never)).toBe(true)
    expect(eventV2CanJoin({ validate: false })).toBe(false)
    expect(eventV2CanJoin(null)).toBe(false)
  })

  it('沒帶 id 的入口取列表裡第一檔紅包；沒有紅包回 null（晶片變灰）', () => {
    const row = (id: number, handler: string | null) => ({ event_v1_id: null, event_v2_id: id, tp_event: null, handler_class: handler }) as EventListItem
    expect(findAngpowEventId([row(1, 'DepositService'), row(271, 'AngpowService'), row(270, 'AngpowService')])).toBe(271)
    expect(findAngpowEventId([row(1, 'DepositService')])).toBeNull()
    expect(findAngpowEventId([])).toBeNull()
  })
})
