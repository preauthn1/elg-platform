import type { LobbyVendor } from '#shared/api/endpoints/games'
import { isVendorPasswordValid, resolveAppInfo } from '#shared/utils/games'

/**
 * APP 類遊戲商場館（/game/app/:vendorId）：開 APP／下載、複製帳號、重設場館密碼。
 * 所有皮共用，畫面在 components/<皮>/Page/GameApp.vue。
 */
export function useVendorApp() {
  const route = useRoute()
  const games = useGames()
  const launcher = useGameLauncher()
  const store = useGamesStore()
  const alert = useAlert()

  const vendorId = computed(() => Number(route.params.vendorId))
  const vendor = ref<LobbyVendor | null>(null)
  const failed = ref(false)
  const loading = ref(true)
  const newPassword = [REDACTED]'')
  const copied = ref(false)

  const platform = computed(() => launcher.platform())
  const appInfo = computed(() => (store.vendorInfo ? resolveAppInfo(store.vendorInfo, platform.value.isIOS) : null))
  const canSubmit = computed(() => isVendorPasswordValid(newPassword.value))

  /** 載入順序：單一錢包轉帳（wallet 領域）→ 場館 → 參與中的活動 → game/url 取 app_infos。 */
  onMounted(async () => {
    loading.value = true
    try {
      launcher.storeGameFromUrl()
      await launcher.syncSingleWallet(vendorId.value)
      const res = await games.fetchVendor(vendorId.value)
      if (!isApiOk(res) || !res.data) {
        failed.value = true
        return
      }
      vendor.value = res.data
      await launcher.fetchVendorEvents(vendorId.value)
      const url = await launcher.fetchGameUrl({ vendorId: vendorId.value })
      if (!isApiOk(url)) failed.value = true
    }
    finally {
      loading.value = false
    }
  })

  function openApp() {
    if (!appInfo.value) return
    launcher.openApp(appInfo.value.openLink, appInfo.value.downloads[0])
  }

  function download(index = 0) {
    const url = appInfo.value?.downloads[index]
    if (url) launcher.openWindow(url)
  }

  async function copyUsername() {
    const username = store.vendorInfo?.username
    if (!username) return
    await navigator.clipboard.writeText(username)
    copied.value = true
  }

  async function submitPassword() {
    if (!canSubmit.value) return
    const res = await launcher.resetVendorPassword(vendorId.value, newPassword.value)
    // 失敗時 useApi 已經彈過錯誤，這裡只補成功的提示
    if (!isApiOk(res)) return
    alert.showResponse(res)
    newPassword.value = ''
  }

  return {
    vendorId,
    vendor,
    failed,
    loading,
    newPassword,
    copied,
    appInfo,
    canSubmit,
    openApp,
    download,
    copyUsername,
    submitPassword,
  }
}
