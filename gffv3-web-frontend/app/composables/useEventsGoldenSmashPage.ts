import { dailyPrizeState, getPrizeParts, parseIdsQuery } from '~/utils/events-core'

/** 動畫階段：idle 三顆蛋 → smashing 震動碎裂 → opened 破殼蛋＋獎項 */
type Phase = 'idle' | 'smashing' | 'opened'

/** /events/golden-smash 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsGoldenSmash.vue。 */
export function useEventsGoldenSmashPage() {
  const route = useRoute()
  const { t } = useI18n()
  const { settings } = useSite()
  const legacy = useLegacyEvents()
  const { drawPrize, clearDraw } = usePrize()
  const { refreshBalance } = useWallet()
  const v2 = useEventV2()

  /** 三顆蛋在舞台圖上的左緣（設計稿 X = 53.5 / 136 / 219，換算成舞台寬 351 的百分比） */
  const EGG_LEFT = [9.54, 33.05, 56.7]

  const phase = ref<Phase>('idle')
  const picked = ref(-1)
  const busy = ref(false)
  const prize = ref({ value: '', unit: '' })

  /** 預覽模式：不打 API、獎項是樣本、可以一直敲。條件見 isActivityDemoRoute */
  const demo = useActivityDemo()

  const eventId = computed(() => parseIdsQuery(route.query.ids)?.[0] ?? null)
  const row = computed(() => legacy.legacyEvents.value.find(item => Number(item.id) === eventId.value) ?? null)
  const state = computed(() => (row.value ? dailyPrizeState(row.value.show_exchange) : 'locked'))

  /** 已參加活動 V2 時 prize/draw 一定被 1991 擋下，所以開窗就停用（規格 §2.2）。 */
  const blockedByV2 = computed(() => !demo.value && v2.joined.value)
  const canSmash = computed(() => (demo.value || state.value === 'ready') && !blockedByV2.value && !busy.value)

  const prizeNames = computed(() => ({
    walletPoint: settings.value?.text_wallet_point ?? '',
    rollingChip: settings.value?.text_rolling_chip ?? '',
  }))

  const statusText = computed(() => {
    if (demo.value) return t('events.common.label.remainingToday', { value: 1 })
    if (blockedByV2.value) return t('events.common.label.modeOn')
    if (state.value === 'done') return t('events.goldenSmash.msg.doneToday')
    if (state.value === 'locked') return t('events.claim.label.unaccomplished')
    return t('events.common.label.remainingToday', { value: Number(row.value?.remaining_number ?? 0) })
  })

  onMounted(() => {
    if (demo.value) return
    if (legacy.legacyEvents.value.length === 0) void legacy.fetchLegacyEvents()
  })

  /**
   * 預覽模式可以一直敲：點一下開啟態就倒回未敲態。
   * **正式路徑不給這條**——真的敲蛋一天就一次，能重播等於假裝還有次數。
   */
  function replay() {
    if (!demo.value) return
    picked.value = -1
    prize.value = { value: '', unit: '' }
    phase.value = 'idle'
  }

  /**
   * 點下去就發 prize/draw，動畫並行播（規格 §4.1）：
   * 不等動畫播完才發（使用者白等），也不等 API 回來才播（點擊沒有回饋）。失敗時倒回未敲態。
   */
  async function smash(index: number) {
    if (!canSmash.value) return
    busy.value = true
    picked.value = index
    phase.value = 'smashing'

    // 預覽模式只播動畫，獎項是寫死的樣本，走的是正式那條金額格式化
    if (demo.value) {
      await new Promise(resolve => setTimeout(resolve, 600))
      prize.value = getPrizeParts({ type: 0, point: 10, point_type: 1 }, prizeNames.value)
      phase.value = 'opened'
      busy.value = false
      return
    }

    if (eventId.value === null) {
      phase.value = 'idle'
      busy.value = false
      return
    }

    const [res] = await Promise.all([
      drawPrize({ event_id: eventId.value, type: row.value?.type }),
      new Promise(resolve => setTimeout(resolve, 600)),
    ])

    if (!isApiOk(res)) {
      phase.value = 'idle'
      picked.value = -1
      busy.value = false
      return
    }

    const won = Array.isArray(res.data.prize) ? res.data.prize[0] : res.data.prize
    prize.value = won ? getPrizeParts(won, prizeNames.value) : { value: '', unit: '' }
    phase.value = 'opened'
    busy.value = false

    // 這頁自己畫結果，不要再讓活動列表的 EventsDrawResult 跳一次
    clearDraw()
    // 關窗前先更新列表與餘額，回到上一頁時數字才是對的
    await Promise.all([legacy.fetchLegacyEvents(), refreshBalance()])
  }

  return {
    EGG_LEFT,
    phase,
    picked,
    busy,
    prize,
    demo,
    canSmash,
    statusText,
    replay,
    smash,
  }
}
