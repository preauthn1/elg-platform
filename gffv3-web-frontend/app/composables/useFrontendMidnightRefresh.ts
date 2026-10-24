import { msUntilNextFrontendMidnight } from '~/utils/records'

/**
 * 日期預設清單（今天／昨天／前天）在前端時間 UTC+8 跨日時重算。
 * 只在瀏覽器掛載後排程，setup 階段不碰計時器。
 */
export function useFrontendMidnightRefresh(refresh: () => void) {
  let timer: ReturnType<typeof setTimeout> | null = null

  function schedule() {
    timer = setTimeout(() => {
      refresh()
      schedule()
    }, msUntilNextFrontendMidnight(new Date()))
  }

  onMounted(schedule)
  onUnmounted(() => {
    if (timer) clearTimeout(timer)
  })
}
