/**
 * 過渡動畫與載入骨架的共用行為。
 *
 * 時序是這兩件事唯一會出錯的地方（動畫本身是 CSS，看得出來壞了；時序壞掉只會偶爾閃一下），
 * 所以把它抽成純函式放這裡，由 `tests/unit/motion.spec.ts` 鎖住，元件只負責執行。
 */

/**
 * 進場動畫的名稱，對應 `app/assets/css/main.css` 的 `.wf-in-<name>`。
 * 新增一個名字就要同時補 CSS，`tests/unit/motion.spec.ts` 會檢查兩邊有沒有對上。
 *
 * slide-up   由下往上就位（彈窗、由下浮出的面板）
 * slide-down 由上往下就位（下拉、展開）
 * fade       只有淡入，不位移（遮罩、圖片）
 * scale      縮放淡入（獎項數字這類要被看見的東西）
 *
 * **是 CSS animation，不是 Vue 的 `<Transition>`**，做法跟 bc.game 一樣。
 * 原因是 `<Transition appear>` 在這個專案有一半的場合不會播：Nuxt 的頁面包在
 * `<Suspense>` 裡，Vue 的 `needTransition()` 在父層 Suspense 還有 pendingBranch 時
 * 會直接跳過 transition hook，切路由進來的頁面因此完全沒有動畫；SSR 後的 hydration
 * 也一樣會跳過。這在 `/login` 這種「整頁就是一個彈窗」的頁面實測過——加了 appear
 * 也量不到 enter 類別。CSS animation 只認「元素第一次被算樣式」，兩種情況都會播，
 * 而且呼叫端不用多包一層元件。
 *
 * 代價是沒有退場動畫。這是 bc.game 的取捨，也是划算的取捨：退場要讓路給新內容，
 * 拖到看得見反而像卡住。
 */
export const ENTER_ANIMATIONS = ['fade', 'slide-up', 'slide-down', 'scale'] as const
export type EnterAnimation = (typeof ENTER_ANIMATIONS)[number]

/**
 * 輪盤轉一次要多久。CSS 的 `.wf-wheel` 只定義怎麼轉，時間由這裡給——
 * 動畫時間與「轉完才解鎖」的計時器必須是同一個值，分別寫死在 CSS 與 JS 的話，
 * 改了一邊沒改另一邊會變成「還在轉就能再按」或「停了還按不動」。
 */
export const WHEEL_SPIN_MS = 3000

/** 轉幾圈再停到中獎那一格。圈數少了看不出在轉，多了使用者等太久。 */
export const WHEEL_SPIN_TURNS = 5

/**
 * 轉盤要再往前轉幾度，才會把第 `index` 格停到 12 點鐘的指針底下。
 *
 * 扇形是從 `-半格` 起畫的（第一格的中線本來就對著指針），所以倒退 index 格就好，
 * **不可以再減半格**——減了會剛好停在兩格的接縫上，畫面看起來像沒中獎。
 * 這支是純幾何，容易改錯而且改錯了不會報錯，所以由 tests/unit/motion.spec.ts 鎖住。
 *
 * `index` 為負（後端給了盤面上沒有的獎）時只轉整圈，不指定停在哪一格。
 */
export function wheelStopAngle(index: number, count: number): number {
  const turns = 360 * WHEEL_SPIN_TURNS
  if (index < 0 || count <= 0) return turns
  return turns + (360 - (index * 360) / count) % 360
}

export interface SkeletonTiming {
  /** 幾毫秒內載完就完全不顯示骨架——快的請求閃一下比沒有更吵 */
  delay: number
  /** 一旦顯示就至少停這麼久，避免「骨架閃一下馬上被內容換掉」的抖動 */
  minimumDuration: number
}

export const DEFAULT_SKELETON_TIMING: SkeletonTiming = { delay: 120, minimumDuration: 280 }

export interface SkeletonInput {
  loading: boolean
  /** 骨架目前是不是已經畫出來了 */
  visible: boolean
  /** 有沒有一個「等一下要顯示」的計時器還沒到期 */
  pending: boolean
  /** 骨架開始顯示的時間戳；visible 為 false 時不會被用到 */
  shownAt: number
  now: number
  timing: SkeletonTiming
}

export type SkeletonPlan
  = | { action: 'idle' }
    | { action: 'show', after: number }
    | { action: 'hide', after: number }
    /** 還沒到期的顯示要取消掉：請求在 delay 之內就回來了 */
    | { action: 'cancel' }

/**
 * 依目前狀態算出下一步。四條規則：
 *
 * 1. 正在載入而且骨架已經顯示（或已排定要顯示）→ 什麼都不做。
 *    **這條是重點**：`loading` 維持 true 但 watcher 又觸發一次時（父層重設 props、
 *    同一次載入分兩段），不能把計時器重排，否則骨架永遠等不到顯示。
 * 2. 正在載入而且還沒排程 → delay 之後顯示。
 * 3. 載完了但骨架還沒真的出現 → 取消排程，整個過程不閃。
 * 4. 載完了而且骨架已經顯示 → 等滿 minimumDuration 再收，差額為負就立刻收。
 */
export function planSkeleton(input: SkeletonInput): SkeletonPlan {
  const { loading, visible, pending, shownAt, now, timing } = input

  if (loading) {
    if (visible || pending) return { action: 'idle' }
    return { action: 'show', after: Math.max(0, timing.delay) }
  }

  if (pending) return { action: 'cancel' }
  if (!visible) return { action: 'idle' }

  return { action: 'hide', after: Math.max(0, timing.minimumDuration - (now - shownAt)) }
}
