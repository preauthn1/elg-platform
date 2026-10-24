import { ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'

/**
 * 呼叫其他領域的端點（型別由該領域登記，這裡只做寬鬆型別包裝）。
 * 對方尚未登記時 BFF 回 404 碼，呼叫端一律當「查不到」處理。
 */
type LoosePost = (route: string, body: Record<string, unknown>, opts?: { silent?: boolean }) => Promise<ApiEnvelope<unknown>>

function useLoosePost(): LoosePost {
  return useApi().post as unknown as LoosePost
}

/** 平台活動（event-v2）進行中不可存款：join-detail 有內容就擋。 */
export function useDepositEventGuard() {
  const post = useLoosePost()

  async function hasActiveEvent(): Promise<boolean> {
    try {
      const res = await post('event-v2/join-detail', {}, { silent: true })
      if (!isApiOk(res)) return false
      const detail = res.data
      if (Array.isArray(detail)) return detail.length > 0
      return detail !== null && typeof detail === 'object' && Object.keys(detail).length > 0
    }
    catch {
      return false
    }
  }

  return { hasActiveEvent }
}

/** currency/list 只拿來把 currency_id 換成名稱，拿不到就顯示 #id。 */
export function useDepositCurrencies() {
  const post = useLoosePost()
  const names = ref<Record<number, string>>({})

  async function load() {
    try {
      const res = await post('currency/list', {}, { silent: true })
      if (!isApiOk(res) || !Array.isArray(res.data)) return
      const next: Record<number, string> = {}
      for (const row of res.data as { id?: unknown, name?: unknown }[]) {
        const id = Number(row.id)
        if (Number.isFinite(id) && typeof row.name === 'string') next[id] = row.name
      }
      names.value = next
    }
    catch {
      // 幣別名稱只是顯示輔助，失敗不影響流程
    }
  }

  function nameOf(id: number | null): string {
    if (id === null) return '-'
    return names.value[id] ?? `#${id}`
  }

  return { names, load, nameOf }
}
