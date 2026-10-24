import type { ApiEnvelope } from '#shared/types/api'
import type { AngpowInfo, EventV2Join } from '#shared/api/endpoints/events-core'
import { isApiOk, useApi } from './useApi'
import { angpowRemaining, angpowScoreShort, angpowTimesExhausted } from '~/utils/events-core'

/**
 * 紅包（活動 V2 的 AngpowService）：畫面數字走 event-v2/angpow/info，抽走通用的 event-v2/join。
 *
 * 能不能抽由 join 失敗的 message 決定（後端 HTTP 400 只回 message、不回驗證碼），
 * 只有「積分不足」「間隔未到」要在點下當下換成專屬呈現，所以這兩項先用 info 的數字判斷，不打 join。
 */
export function useAngpow() {
  const api = useApi()

  const info = ref<AngpowInfo | null>(null)
  const loading = ref(false)
  const joining = ref(false)
  /**
   * join 被後端擋過（鎖分模式、不在目標名單⋯），info 的數字看不出來，
   * 這頁之後一律當剩 0 次：再點只打 join 讓後端回原因、不播開啟動畫。重新進頁才重算。
   */
  const blocked = ref(false)

  /** 間隔倒數：以拿到 info 的時間點推算截止時刻，每秒更新剩餘秒數 */
  const waitUntil = ref(0)
  const now = ref(Date.now())
  let timer: ReturnType<typeof setInterval> | null = null

  function startTicking() {
    if (timer || !import.meta.client) return
    timer = setInterval(() => {
      now.value = Date.now()
      if (now.value >= waitUntil.value && timer) {
        clearInterval(timer)
        timer = null
      }
    }, 1000)
  }

  onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
    timer = null
  })

  async function fetchInfo(eventDetailId: number): Promise<ApiEnvelope<AngpowInfo>> {
    loading.value = true
    try {
      const res = await api.post('event-v2/angpow/info', { event_detail_id: eventDetailId })
      if (isApiOk(res)) {
        info.value = res.data
        now.value = Date.now()
        waitUntil.value = now.value + res.data.next_available_in * 1000
        if (res.data.next_available_in > 0) startTicking()
      }
      return res
    }
    finally {
      loading.value = false
    }
  }

  /** 抽一次；不論成功與否都重抓 info（文件規定數字以後端為準，不在前端自己減） */
  async function join(eventDetailId: number): Promise<ApiEnvelope<EventV2Join> | null> {
    if (joining.value) return null
    joining.value = true
    try {
      const res = await api.post('event-v2/join', { event_detail_id: eventDetailId })
      blocked.value = !isApiOk(res)
      await fetchInfo(eventDetailId)
      return res
    }
    finally {
      joining.value = false
    }
  }

  const waitSeconds = computed(() => Math.max(0, Math.ceil((waitUntil.value - now.value) / 1000)))
  const remaining = computed(() => {
    if (!info.value) return null
    return blocked.value ? 0 : angpowRemaining(info.value)
  })
  /** 次數已用完或這頁 join 被擋過：點了只打 join 拿後端原因，優先於積分不足 */
  const exhausted = computed(() => !!info.value && (blocked.value || angpowTimesExhausted(info.value)))
  const scoreShort = computed(() => (info.value ? angpowScoreShort(info.value) : false))

  return {
    info,
    loading,
    joining,
    fetchInfo,
    join,
    waitSeconds,
    remaining,
    exhausted,
    scoreShort,
  }
}
