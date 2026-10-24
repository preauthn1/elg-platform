import { eventV2CanJoin, resolveJoinRedirect } from '~/utils/events-core'

/** /events/platform-event 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsPlatformEvent.vue。 */
export function useEventsPlatformEventPage() {
  const { ct } = useContentI18n()

  const route = useRoute()
  const v2 = useEventV2()
  const events = useEvents()
  const { detail, participationMessage, hasBonusCodes, joining } = v2

  /** 列表帶過來的是 event_detail_id，沿用 ids 這個 query 名（與 V1 一致）。 */
  const eventDetailId = computed(() => {
    const raw = Array.isArray(route.query.ids) ? route.query.ids[0] : route.query.ids
    const id = Number(raw)
    return Number.isFinite(id) && id > 0 ? id : null
  })

  const loading = ref(true)

  /**
   * Banner：`event-v2/detail` 沒有圖，只有 `event/list` 的項目有，所以從列表對回來。
   * 從促銷頁點進來時 store 已經有列表；直接開網址才補抓一次。
   */
  const banner = computed(() => (
    events.events.value.find(item => Number(item.event_v2_id) === eventDetailId.value)?.image ?? ''
  ))

  onMounted(async () => {
    if (eventDetailId.value === null) {
      loading.value = false
      return
    }
    if (events.events.value.length === 0) void events.listEvents()
    await v2.fetchDetail(eventDetailId.value)
    loading.value = false
  })

  /**
   * 彈窗內切內容語言：只重抓詳情，**不要把 loading 設回 true**——
   * 語言晶片在載入完成的區塊裡，一卸載就會把選的語言清掉。
   */
  async function reloadDetail() {
    if (eventDetailId.value !== null) await v2.fetchDetail(eventDetailId.value)
  }

  const canJoin = computed(() => eventV2CanJoin(detail.value))

  /**
   * 參加狀態與按鈕文字都照舊專案 C13 的 PlatformEvent：
   *   participationStatus = message 為空且 validate → 符合參加資格，否則顯示後端 message
   *   btnText = join_withdraw ? 參加即領獎 : 參加
   * 舊專案外面還包一層 $loadingText：detail 還沒回來或失敗（沒有資料）時顯示「載入中 ...」，不可以預設成符合資格。
   */
  const participationStatus = computed(() => {
    if (!detail.value) return ct('events.common.label.loading')
    return participationMessage.value ?? ct('events.detail.msg.eligible')
  })
  const joinLabel = computed(() => (detail.value?.join_withdraw ? ct('events.detail.hint.joinReward') : ct('events.detail.action.join')))

  async function join() {
    if (eventDetailId.value === null || !canJoin.value) return
    const res = await v2.join(eventDetailId.value)
    if (!res || !isApiOk(res)) return
    const joinedCode = res.data?.bonus_code ?? ''
    if (joinedCode) useAlert().show(`${ct('events.claim.msg.congratulations')} ${joinedCode}`, 'success')
    const target = resolveJoinRedirect(res.data?.redirect_path)
    if (target.kind === 'external') {
      if (import.meta.client) window.open(target.to, '_blank', 'noopener')
      return
    }
    await navigateTo(target.to)
  }

  return {
    ct,
    detail,
    hasBonusCodes,
    joining,
    eventDetailId,
    loading,
    banner,
    reloadDetail,
    canJoin,
    participationStatus,
    joinLabel,
    join,
  }
}
