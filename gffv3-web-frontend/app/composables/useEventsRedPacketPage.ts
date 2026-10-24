import { decFloorFixed, decFormat } from '#shared/utils/amount'
import { findAngpowEventId, parseIdsQuery } from '~/utils/events-core'

/** 動畫階段：closed 關閉態 → opening 搖晃淡出 → opened 開啟態＋金額 */
type Phase = 'closed' | 'opening' | 'opened'

/** /events/red-packet 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsRedPacket.vue。 */
export function useEventsRedPacketPage() {
  const route = useRoute()
  const { t } = useI18n()
  const { currencyLabel: currency } = useSite()
  const { refreshBalance } = useWallet()
  const events = useEvents()
  const v2 = useEventV2()
  const angpow = useAngpow()

  const phase = ref<Phase>('closed')
  const busy = ref(false)
  const prizeText = ref('')

  /** 預覽模式：不打 API、獎項是樣本、可以一直拆。條件見 isActivityDemoRoute */
  const demo = useActivityDemo()

  const eventId = computed(() => parseIdsQuery(route.query.ids)?.[0] ?? findAngpowEventId(events.events.value))
  /** event/list 已經拿到過：沒帶 id 又找不到紅包活動時，才能確定是「目前未開放」而不是還在載入 */
  const listLoaded = ref(false)
  const eventName = computed(() =>
    events.events.value.find(item => Number(item.event_v2_id) === eventId.value)?.name ?? '',
  )
  const info = example.invalid

  /** is_open 為 false（不在活動期間或不在可抽時段）就不展示紅包 */
  const showPacket = computed(() => demo.value || info.value?.is_open === true)
  const canOpen = computed(() => showPacket.value && !busy.value)

  function formatReward(reward: string | number | null | undefined): string {
    return decFormat(decFloorFixed(reward ?? 0), 'trim')
  }

  const statusText = computed(() => {
    if (demo.value) return t('events.common.label.remainingToday', { value: 1 })
    if (!info.value) return listLoaded.value && eventId.value === null ? t('events.redPacket.msg.notOpen') : ''
    if (!info.value.is_open) {
      const window = info.value.time_window
      if (!info.value.in_time_window && window) {
        return t('events.redPacket.msg.outOfHours', { start: window[0], end: window[1] })
      }
      return t('events.redPacket.msg.notOpen')
    }
    const remaining = angpow.remaining.value
    return remaining === null ? '' : t('events.common.label.remainingToday', { value: remaining })
  })

  /** 間隔未到的頂部提示：秒數跟著倒數，顯示 3 秒或倒數歸零就收掉 */
  const toastVisible = ref(false)
  let toastTimer: ReturnType<typeof setTimeout> | null = null
  function showWaitToast() {
    toastVisible.value = true
    if (toastTimer) clearTimeout(toastTimer)
    toastTimer = setTimeout(() => {
      toastVisible.value = false
    }, 3000)
  }
  watch(angpow.waitSeconds, (value) => {
    if (value === 0) toastVisible.value = false
  })
  onBeforeUnmount(() => {
    if (toastTimer) clearTimeout(toastTimer)
  })

  /** 積分不足的導流存款彈窗 */
  const depositOpen = ref(false)
  function goDeposit() {
    depositOpen.value = false
    void navigateTo('/topup')
  }

  onMounted(async () => {
    if (demo.value) return
    // 有 id 就先打 info；沒帶 id 的等 event/list 回來找到紅包活動再打
    watch(eventId, (id) => {
      if (id !== null) void angpow.fetchInfo(id)
    }, { immediate: true })
    if (events.events.value.length === 0) await events.listEvents()
    listLoaded.value = true
  })

  /**
   * 預覽模式可以一直拆：點一下開啟態就倒回關閉態。
   * **正式路徑不給這條**——抽完要關掉重新進頁，設計稿沒有再抽鈕。
   */
  function replay() {
    if (!demo.value) return
    prizeText.value = ''
    phase.value = 'closed'
  }

  /**
   * 「間隔未到」「積分不足」先用 info 的數字擋下、換成專屬呈現，紅包不打開；
   * 其餘原因（次數用完、紅包發完、資格不符⋯）由 join 失敗的 message 走全站提示彈窗。
   * info 已顯示剩 0 次時只打 join 不播動畫；其餘點擊瞬間就發 join，動畫並行播，失敗倒回關閉態。
   */
  async function open() {
    if (!canOpen.value) return

    // 預覽模式只播動畫，金額是寫死的樣本，走的是正式那條金額格式化
    if (demo.value) {
      busy.value = true
      phase.value = 'opening'
      await new Promise(resolve => setTimeout(resolve, 600))
      prizeText.value = formatReward(10)
      phase.value = 'opened'
      busy.value = false
      return
    }

    if (eventId.value === null) return
    if (angpow.waitSeconds.value > 0) {
      showWaitToast()
      return
    }
    // 次數用完優先於積分不足（後端檢查順序），兩者同時成立時不跳導流存款彈窗
    const exhausted = angpow.exhausted.value
    if (!exhausted && angpow.scoreShort.value) {
      depositOpen.value = true
      return
    }

    // 已知剩 0 次（含這頁 join 被擋過）：照樣打 join 讓後端回原因，但不播開啟動畫，紅包原地不動
    // info 若已過時、join 其實成功，照樣直接開
    busy.value = true
    const knownEmpty = exhausted || angpow.remaining.value === 0
    if (!knownEmpty) phase.value = 'opening'
    const [res] = await Promise.all([
      angpow.join(eventId.value),
      knownEmpty ? Promise.resolve() : new Promise(resolve => setTimeout(resolve, 600)),
    ])

    if (!res || !isApiOk(res)) {
      phase.value = 'closed'
      busy.value = false
      return
    }

    prizeText.value = formatReward(res.data.reward)
    phase.value = 'opened'
    busy.value = false

    // 入主錢包的更新餘額；進活動錢包的（join_withdraw false）要讓活動帳戶列出現
    await Promise.all([
      refreshBalance(),
      res.data.join_withdraw === false ? v2.fetchJoinDetail() : Promise.resolve(),
    ])
  }

  return {
    currency,
    angpow,
    phase,
    prizeText,
    demo,
    eventName,
    showPacket,
    canOpen,
    statusText,
    toastVisible,
    depositOpen,
    goDeposit,
    replay,
    open,
  }
}
