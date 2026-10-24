import { pickGameUrl } from '#shared/utils/games'

/**
 * 進到單一遊戲（/game/:id）：取名稱 → 轉入遊戲商 → 取遊戲網址，iframe 內嵌或開新視窗。
 * 所有皮共用，畫面在 components/<皮>/Page/GamePlaying.vue。
 * 畫面要等領獎提醒放行（EventsPrizeReminder 的 ready）才呼叫 init，跟舊版一樣。
 */
export function useGamePlaying() {
  const route = useRoute()
  const router = useRouter()
  const launcher = useGameLauncher()

  const gameId = computed(() => Number(route.params.id))
  const title = ref('')
  const src = ref<string | null>(null)
  const openedInWindow = ref(false)
  const popupBlocked = ref(false)
  const failed = ref(false)
  const loading = ref(true)

  useHead({ title: () => title.value || undefined })

  /** 返回：有上一頁就退回，沒有（直接開網址）就回首頁。 */
  function back() {
    if (import.meta.client && window.history.length > 1) return router.back()
    return navigateTo('/')
  }

  /**
   * 進入順序：game/show 取名稱 → member/transfer 帶 vendor_id 轉入 → game/url。
   *
   * 轉入用 'game'：遊戲頁不看 transaction_all（規則集中在 shouldTransferOnVendorEntry）。
   * 進場前也**不要**另外打一次不帶參數的 refreshBalance：轉帳在前端有防重送
   * （in-flight 直接回 WALLET_IN_FLIGHT_CODE），後端也是會員級別鎖、並發立即失敗，
   * 兩支一起打會讓帶 vendor_id 的那支被擋掉、錢沒轉進遊戲商。轉入的回應本身就會更新餘額。
   */
  async function init() {
    loading.value = true
    failed.value = false
    try {
      const show = await launcher.fetchGame(gameId.value)
      if (!isApiOk(show)) {
        failed.value = true
        return
      }
      title.value = show.data.name ?? ''
      await launcher.syncSingleWallet(show.data.vendor_id, 'game')

      const res = await launcher.fetchGameUrl({ vendorId: show.data.vendor_id, gameId: gameId.value })
      if (!isApiOk(res)) {
        failed.value = true
        return
      }
      const url = pickGameUrl(res.data, launcher.isMobile.value)
      if (!url) {
        failed.value = true
        return
      }
      if (res.data.open_window === 1) {
        openedInWindow.value = true
        src.value = url
        try {
          launcher.openWindow(url)
        }
        catch {
          popupBlocked.value = true
        }
      }
      else {
        src.value = url
      }
    }
    finally {
      loading.value = false
    }
  }

  function reopen() {
    if (!src.value) return
    try {
      launcher.openWindow(src.value)
      popupBlocked.value = false
    }
    catch {
      popupBlocked.value = true
    }
  }

  return { title, src, openedInWindow, popupBlocked, failed, loading, back, init, reopen }
}
