import type { PrizeContent } from '#shared/api/endpoints/events-core'
import {
  dailyPrizeState,
  parseIdsQuery,
  PRIZE_TYPE,
  wheelFace,
  wheelSlots,
} from '~/utils/events-core'
import { WHEEL_SPIN_MS, wheelStopAngle } from '~/utils/motion'

/** /events/lucky-wheel 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsLuckyWheel.vue。 */
export function useEventsLuckyWheelPage() {
  const route = useRoute()
  const { t } = useI18n()
  const { settings } = useSite()
  const legacy = useLegacyEvents()
  const { legacyDetail } = legacy
  const { store: prizeStore, drawPrize, clearDraw } = usePrize()
  const { refreshBalance } = useWallet()
  const v2 = useEventV2()

  /** 預覽模式：不打 API、獎項是樣本、可以一直轉。條件見 isActivityDemoRoute */
  const demo = useActivityDemo()

  /** 預覽用的樣本獎項，照設計稿盤面的六格：四格點數、兩格百分比。 */
  const DEMO_PRIZES: PrizeContent[] = [
    { id: 1, type: 0, point: '68' },
    { id: 2, type: 0, point: '5' },
    { id: 3, type: 1, point_percentage: '150' },
    { id: 4, type: 0, point: '8' },
    { id: 5, type: 0, point: '20' },
    { id: 6, type: 1, point_percentage: '100' },
  ]

  const eventId = computed(() => parseIdsQuery(route.query.ids)?.[0] ?? null)

  const loading = ref(false)
  const spinning = ref(false)
  const angle = ref(0)
  const wonText = ref('')

  const names = computed(() => ({
    walletPoint: settings.value?.text_wallet_point || t('common.amount.label.platformPoint'),
    rollingChip: settings.value?.text_rolling_chip || t('common.amount.label.rollingChip'),
  }))

  const prizes = computed<PrizeContent[]>(() => (demo.value ? DEMO_PRIZES : legacyDetail.value?.prize ?? []))
  const slots = computed(() => wheelSlots(prizes.value, names.value))
  const slice = computed(() => (slots.value.length ? 360 / slots.value.length : 0))

  const face = computed(() => wheelFace(slots.value))

  /**
   * 今天還能不能轉。**只能看 event/legacy-list 的 show_exchange**——
   * legacy-detail 的 remaining_number 是歷史累計未領筆數，判斷不出「今天」
   * （理由見 docs/daily-red-packet-spec.md §2.5）。
   */
  const row = computed(() => legacy.legacyEvents.value.find(item => Number(item.id) === eventId.value) ?? null)
  const state = computed(() => (row.value ? dailyPrizeState(row.value.show_exchange) : 'locked'))

  /** 已參加活動 V2 時 prize/draw 一定被 1991 擋下，所以開窗就停用，不要讓使用者轉完才吃錯誤。 */
  const blockedByV2 = computed(() => !demo.value && v2.joined.value)
  const canSpin = computed(() => (demo.value || state.value === 'ready')
    && !blockedByV2.value && !spinning.value && slots.value.length > 0)

  const statusText = computed(() => {
    if (demo.value) return t('events.common.label.remainingToday', { value: 1 })
    if (blockedByV2.value) return t('events.common.label.modeOn')
    if (state.value === 'done') return t('events.luckyDraw.msg.doneToday')
    if (state.value === 'locked') return t('events.claim.label.unaccomplished')
    return t('events.common.label.remainingToday', { value: Number(row.value?.remaining_number ?? 0) })
  })

  onMounted(async () => {
    if (demo.value || eventId.value === null) return
    loading.value = true
    try {
      await Promise.all([
        legacy.fetchLegacyDetail(eventId.value),
        legacy.legacyEvents.value.length === 0 ? legacy.fetchLegacyEvents() : Promise.resolve(null),
      ])
    }
    finally {
      loading.value = false
    }
  })

  /** 中獎獎項在盤面上的位置；找不到（後端給了清單外的獎）就不停在特定格。 */
  function wonIndex(): number {
    const draw = prizeStore.lastDraw
    if (!draw || draw.kind !== 'single') return -1
    return slots.value.findIndex(slot => slot.id === Number(draw.data.prize.id))
  }

  /** 多轉幾圈再停到該格的正中央；角度怎麼算見 wheelStopAngle。 */
  function stopAt(index: number) {
    angle.value += wheelStopAngle(index, slots.value.length)
  }

  async function spin() {
    if (!canSpin.value) return
    spinning.value = true
    wonText.value = ''

    if (demo.value) {
      const index = Math.floor(Math.random() * slots.value.length)
      const slot = slots.value[index]
      stopAt(index)
      setTimeout(() => {
        wonText.value = t('events.luckyDraw.msg.result06', { value: `${slot?.value ?? ''}${slot?.unit ?? ''}` })
        spinning.value = false
      }, WHEEL_SPIN_MS)
      return
    }

    const res = await drawPrize({
      event_id: eventId.value!,
      type: PRIZE_TYPE.WHEEL,
      action_id: legacyDetail.value?.action_id,
    })
    if (!isApiOk(res)) {
      spinning.value = false
      return
    }

    const index = wonIndex()
    stopAt(index)
    const slot = index >= 0 ? slots.value[index] : null
    // 這頁自己畫結果，不要再讓活動列表的 EventsDrawResult 跳一次
    clearDraw()

    // 轉完才公布獎項，並把列表與餘額更新掉（關窗回上一頁時數字才是對的）
    setTimeout(() => {
      wonText.value = t('events.luckyDraw.msg.result06', { value: `${slot?.value ?? ''}${slot?.unit ?? ''}` })
      spinning.value = false
      void Promise.all([legacy.fetchLegacyEvents(), refreshBalance()])
    }, WHEEL_SPIN_MS)
  }

  return {
    loading,
    spinning,
    angle,
    wonText,
    slots,
    slice,
    face,
    canSpin,
    statusText,
    spin,
  }
}
