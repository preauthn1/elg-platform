/**
 * 主選單抽屜的開關。
 *
 * 選單**不是一個路由**。設計稿 Menu 是蓋在當前頁面上的右側抽屜（左邊還看得到底下的首頁），
 * 做成 `/menu` 頁面的話，點底部分頁列的 Menu 會整頁切走、底下變成一片灰底。
 * 抽屜掛在 C17Shell，底部分頁列的 Menu 只是切這個開關。
 *
 * 兩個 state：
 *   open     現在開不開（UiDrawer 據此滑入／滑出）
 *   mounted  有沒有開過。第一次開才把 MenuDrawer 掛進 DOM，之後**一直留著**——
 *            用 v-if 跟著 open 切會每開一次重建一次，裡面的資料請求全部重跑、清單閃一下。
 *            量到的差別：用 v-if 每次展開重打 4 支 API，清單從 6 列跳成 10 列。
 *
 * 用 useState 而不是元件內的 ref：BottomNav（開）與 MenuDrawer（關）是兩個不同的元件，
 * 而且要在 SSR 與水合之間保持一致（預設關、預設沒掛）。
 */
export function useMenuDrawer() {
  const open = useState<boolean>('c17-menu-open', () => false)
  const mounted = useState<boolean>('c17-menu-mounted', () => false)

  function show() {
    mounted.value = true
    open.value = true
  }

  function hide() {
    open.value = false
  }

  function toggle() {
    if (open.value) hide()
    else show()
  }

  return { open, mounted, show, hide, toggle }
}
