import { DEFAULT_SKELETON_TIMING, planSkeleton, type SkeletonTiming } from '~/utils/motion'

/**
 * 「現在該不該畫骨架」。**時序必須放在這裡，不能放在骨架元件裡**：
 * 呼叫端一律寫成 `<UiSkeleton v-if="…">`，元件會在載入結束的當下直接被卸載，
 * 放在元件內的 minimumDuration 計時器根本執行不到——那正是它要避免的抖動。
 *
 *   delay            120ms 內載完就完全不顯示骨架（快的請求閃一下比沒有更吵）
 *   minimumDuration  一旦顯示就至少停 280ms（避免骨架閃一下馬上被內容換掉）
 *
 * 判斷邏輯在 `app/utils/motion.ts` 的 `planSkeleton()`，由單元測試鎖住；
 * 這支只負責把它接上 Vue 的反應式與計時器。
 *
 * ```ts
 * const { data, status } = await useAsyncData(…)
 * const showSkeleton = useSkeleton(() => status.value === 'pending')
 * ```
 */
export function useSkeleton(
  loading: MaybeRefOrGetter<boolean>,
  timing: Partial<SkeletonTiming> = {},
): Readonly<Ref<boolean>> {
  const resolved: SkeletonTiming = { ...DEFAULT_SKELETON_TIMING, ...timing }

  const visible = ref(false)
  let shownAt = 0
  let timer: ReturnType<typeof setTimeout> | undefined
  /** 有一個「等一下要顯示」的計時器還沒到期 */
  let pending = false

  function clear() {
    if (timer !== undefined) clearTimeout(timer)
    timer = undefined
    pending = false
  }

  function apply() {
    const plan = planSkeleton({
      loading: toValue(loading),
      visible: visible.value,
      pending,
      shownAt,
      now: Date.now(),
      timing: resolved,
    })

    if (plan.action === 'idle') return
    if (plan.action === 'cancel') {
      clear()
      return
    }

    clear()
    if (plan.action === 'show') {
      pending = true
      timer = setTimeout(() => {
        timer = undefined
        pending = false
        shownAt = Date.now()
        visible.value = true
      }, plan.after)
      return
    }
    timer = setTimeout(() => {
      timer = undefined
      visible.value = false
    }, plan.after)
  }

  watch(() => toValue(loading), apply, { immediate: true })
  // 在元件外呼叫（測試、store）時沒有生命週期可掛，計時器由呼叫端自己收
  if (getCurrentInstance()) onBeforeUnmount(clear)

  return readonly(visible)
}
