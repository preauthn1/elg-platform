import { computed, ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import { isJackpotOk, jackpotNotificationImageUrl } from '~/utils/jackpot'

export type JackpotNotification = ResponseOf<'event/jackpot/check-notify'>[number]

/**
 * 積寶中獎彈窗（大廳）：check-notify 取未讀清單排隊、一次一個；
 * 關閉時 confirm-notify，成功才放下一個，失敗就停住整條隊列。
 */
export function useJackpotNotifications() {
  const api = useApi()

  const queue = ref<JackpotNotification[]>([])
  const current = ref<JackpotNotification | null>(null)
  const closing = ref(false)

  const visible = computed(() => current.value !== null)
  const imageUrl = computed(() => (current.value ? jackpotNotificationImageUrl(current.value) : ''))

  function playNext() {
    current.value = queue.value.shift() ?? null
  }

  async function load(): Promise<ApiEnvelope<ResponseOf<'event/jackpot/check-notify'>>> {
    const res = await api.post('event/jackpot/check-notify', {})
    if (isJackpotOk(res.code)) {
      queue.value = [...(res.data ?? [])]
      playNext()
    }
    return res
  }

  async function close(): Promise<ApiEnvelope<ResponseOf<'event/jackpot/confirm-notify'>> | null> {
    if (closing.value || !current.value) return null
    closing.value = true
    const id = current.value.id
    current.value = null
    const res = await api.post('event/jackpot/confirm-notify', { id })
    closing.value = false
    if (!isJackpotOk(res.code)) {
      queue.value = []
      return res
    }
    playNext()
    return res
  }

  return { queue, current, visible, imageUrl, closing, load, close }
}
