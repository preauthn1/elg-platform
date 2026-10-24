import { legacyCardKind } from '~/utils/events-core'

/** /events/description/[id] 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsDescription.vue。 */
export function useEventsDescriptionPage() {
  const { ct } = useContentI18n()

  const route = useRoute()
  const id = computed(() => Number(route.params.id))

  const legacy = useLegacyEvents()
  const { legacyDetail, legacyEvents } = legacy

  const loading = ref(true)

  /** 領取鈕吃的是列表那一筆（詳情沒有 reward_point）；直接開網址進來時才補抓列表。 */
  const row = computed(() => legacyEvents.value.find(item => Number(item.id) === id.value) ?? null)
  const banner = computed(() => row.value?.image_url ?? '')

  onMounted(async () => {
    if (legacyEvents.value.length === 0) void legacy.fetchLegacyEvents()
    await legacy.fetchLegacyDetail(id.value)
    loading.value = false
  })

  /**
   * 彈窗內切內容語言：只重抓詳情。晶片放在 legacyDetail 的 v-if 外面，
   * 因為 fetchLegacyDetail 會先把詳情清成 null，放在裡面會被卸載、把選的語言清掉。
   */
  function reloadDetail() {
    return legacy.fetchLegacyDetail(id.value)
  }

  /** 起訖時間：後端兩組欄位擇一（列表用 start_time、詳情有時只有 event_start_time）。 */
  const period = computed(() => {
    const d = legacyDetail.value
    if (!d) return ''
    const from = (d.start_time || d.event_start_time || '').slice(0, 16)
    const to = (d.end_time || d.event_end_time || '').slice(0, 16)
    return from || to ? `${from} - ${to}` : ''
  })

  const prizes = computed(() => legacyDetail.value?.prize ?? [])

  /**
   * 投注類活動（舊皮 EventDescription.vue，eventType 'bet'）另有兌換期限、領獎說明、
   * 達成條件與兌換紀錄鈕。JackpotCard 也連到這頁，但舊皮 jackpot 走的是另一套說明元件，不套這段。
   */
  const isBetEvent = computed(() => !!legacyDetail.value && legacyCardKind(legacyDetail.value) === 'general')
  const exchangeDate = computed(() => (legacyDetail.value?.prize_expire_date ?? '').slice(0, 16))
  const repeatable = computed(() => Number(legacyDetail.value?.prize_is_repeatedly) === 1)

  return {
    ct,
    legacyDetail,
    loading,
    row,
    banner,
    reloadDetail,
    period,
    prizes,
    isBetEvent,
    exchangeDate,
    repeatable,
  }
}
