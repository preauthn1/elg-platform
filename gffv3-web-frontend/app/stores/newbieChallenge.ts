import { defineStore } from 'pinia'
import type { NewbieChallengeDetail, NewbieChallengeProgress } from '#shared/api/endpoints/events-core'

/**
 * 新人專享挑戰：活動介紹與會員進度。提款守衛也會讀 progress，所以放 store。
 */
export const useNewbieChallengeStore = defineStore('newbieChallenge', () => {
  /** newbie-challenge/detail；失敗存 {} */
  const detail = ref<NewbieChallengeDetail>({})
  /** newbie-challenge/progress；null = 未參加（後端 400）或尚未載入 */
  const progress = ref<NewbieChallengeProgress | null>(null)
  const progressLoaded = ref(false)

  function setProgress(next: NewbieChallengeProgress | null) {
    progress.value = next
    progressLoaded.value = true
  }

  function reset() {
    detail.value = {}
    progress.value = null
    progressLoaded.value = false
  }

  return { detail, progress, progressLoaded, setProgress, reset }
})
