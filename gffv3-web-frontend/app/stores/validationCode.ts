import { defineStore } from 'pinia'

/**
 * 驗證碼重送倒數，鍵 = `${type}:${verify_by}:${mobile||email}`，
 * 同一畫面的簡訊與信箱按鈕各自計時；跨頁共用所以放 store。
 */
export const useValidationCodeStore = defineStore('validation-code', () => {
  const timers = ref<Record<string, number>>({})
  let ticker: ReturnType<typeof setInterval> | null = null

  function stopTicking() {
    if (ticker) clearInterval(ticker)
    ticker = null
  }

  function tick() {
    const next: Record<string, number> = {}
    let alive = false
    for (const [key, left] of Object.entries(timers.value)) {
      if (left - 1 > 0) {
        next[key] = left - 1
        alive = true
      }
    }
    timers.value = next
    if (!alive) stopTicking()
  }

  function ensureTicking() {
    if (ticker || !import.meta.client) return
    ticker = setInterval(tick, 1000)
  }

  function start(key: string, seconds: number) {
    timers.value = { ...timers.value, [key]: seconds }
    ensureTicking()
  }

  function remaining(key: string): number {
    return timers.value[key] ?? 0
  }

  function clear(key: string) {
    timers.value = Object.fromEntries(Object.entries(timers.value).filter(([k]) => k !== key))
  }

  /** 驗證通過後把寄到同一目標的倒數全部清掉。 */
  function clearTarget(target: string) {
    if (!target) return
    const next: Record<string, number> = {}
    for (const [key, left] of Object.entries(timers.value)) {
      if (!key.endsWith(`:${target}`)) next[key] = left
    }
    timers.value = next
  }

  return { timers, start, remaining, clear, clearTarget }
})
