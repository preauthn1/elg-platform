import { defineStore } from 'pinia'
import type {
  CheckinRecord,
  DrawnPrize,
  EventInviteRewardCard,
  EventInviteSetting,
  EventListItem,
  EventRemind,
  EventV2JoinDetail,
  LegacyEventDetail,
  LegacyEventRow,
  Mission,
  PrizeDraw,
  PrizeRemind,
} from '#shared/api/endpoints/events-core'
import type { PrizeDrawKind } from '~/utils/events-core'

export type PrizeDrawOutcome
  = | { kind: 'single', drawKind: PrizeDrawKind, data: PrizeDraw }
    | { kind: 'all', prizes: DrawnPrize[] }

/**
 * 活動領域跨頁共用狀態（鍵名照後端）。
 * API 呼叫都在 composables；這裡只放資料與 event/list 的 in-flight dedupe。
 */
export const useEventsStore = defineStore('events', () => {
  /** event/list：null = 尚未載入或請求失敗；[] = 成功但沒活動 */
  const eventList = ref<EventListItem[] | null>(null)
  const eventListLoaded = ref(false)

  // 進站時守衛、Promotions、banner 幾乎同時要列表：進行中的請求共用同一個 promise
  let eventListInflight: Promise<unknown> | null = null
  function dedupeEventList<T>(run: () => Promise<T>): Promise<T> {
    if (eventListInflight) return eventListInflight as Promise<T>
    const request = run().then(
      (result) => {
        eventListInflight = null
        return result
      },
      (error) => {
        eventListInflight = null
        throw error
      },
    )
    eventListInflight = request
    return request
  }

  /** 最近一次 prize/draw(-all) 結果與 action 17 的待領取金額（兌獎結果彈窗用） */
  const lastDraw = ref<PrizeDrawOutcome | null>(null)
  const rewardPoint = ref<string | number | null>(null)

  /** event/legacy-list(-by-domain) */
  /**
   * 活動詳情彈窗裡選的內容語言。null = 照站台語系。
   * 彈窗內的領獎、參加成功後會重抓內容，重抓也要用同一個語言，所以放 store 而不是各頁自己的 ref。
   */
  const contentLocale = ref<string | null>(null)
  const legacyEvents = ref<LegacyEventRow[]>([])
  const legacyTotal = ref(0)
  const legacyIsReddeem = ref<0 | 1>(0)
  /** 取代舊前台 $root.$emit('refreshAllEventStatus')：數字變動即代表要重打 legacy-validate */
  const refreshEventStatusTick = ref(0)

  /** event/legacy-detail：發請求前先重置為 null */
  const legacyDetail = ref<LegacyEventDetail | null>(null)

  /** event-v2/join-detail：null = 未參加 */
  const eventV2JoinDetail = ref<EventV2JoinDetail | null>(null)

  const remind = ref<EventRemind | null>(null)
  const prizeRemind = ref<PrizeRemind>({ vip: false, event: false })

  /** checkin/records；checkinAvailable=false 代表該站沒有簽到活動 */
  const checkinRecords = ref<CheckinRecord[]>([])
  const checkinAvailable = ref(true)

  const missions = ref<Mission[]>([])

  const inviteSetting = ref<EventInviteSetting>({})
  const inviteRewardCards = ref<EventInviteRewardCard[]>([])

  function setEventList(list: EventListItem[] | null) {
    eventList.value = list
    eventListLoaded.value = true
  }

  function setLegacyEvents(items: LegacyEventRow[], total: number, isReddeem: 0 | 1) {
    legacyEvents.value = items
    legacyTotal.value = total
    legacyIsReddeem.value = isReddeem
  }

  function bumpRefreshEventStatus() {
    refreshEventStatusTick.value += 1
  }

  function setInvite(setting: EventInviteSetting | null | undefined, cards: EventInviteRewardCard[] | null | undefined) {
    inviteSetting.value = setting ?? {}
    inviteRewardCards.value = cards ?? []
  }

  function reset() {
    setEventList(null)
    eventListLoaded.value = false
    setLegacyEvents([], 0, 0)
    legacyDetail.value = null
    eventV2JoinDetail.value = null
    remind.value = null
    prizeRemind.value = { vip: false, event: false }
    checkinRecords.value = []
    checkinAvailable.value = true
    missions.value = []
    setInvite(null, null)
    lastDraw.value = null
    rewardPoint.value = null
    contentLocale.value = null
  }

  return {
    contentLocale,
    eventList,
    eventListLoaded,
    dedupeEventList,
    setEventList,
    lastDraw,
    rewardPoint,
    legacyEvents,
    legacyTotal,
    legacyIsReddeem,
    refreshEventStatusTick,
    setLegacyEvents,
    bumpRefreshEventStatus,
    legacyDetail,
    eventV2JoinDetail,
    remind,
    prizeRemind,
    checkinRecords,
    checkinAvailable,
    missions,
    inviteSetting,
    inviteRewardCards,
    setInvite,
    reset,
  }
})
