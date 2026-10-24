/**
 * 真人遊戲進場前的贏分上限聲明：第一次按是勾選同意，第二次才進遊戲。
 * 所有皮共用，畫面在 components/<皮>/Page/GameLiveStatement.vue。
 */
export function useLiveStatement() {
  const store = useGamesStore()
  const launcher = useGameLauncher()
  const { site } = useSite()

  const game = computed(() => store.selectedGame)

  const agreed = ref(false)
  const notice = ref('')
  const currencyName = computed(() => (game.value ? store.vendorMap[game.value.vendor_id]?.currency_name ?? '' : ''))

  onMounted(() => launcher.storeGameFromUrl())

  async function onClick() {
    if (!agreed.value) {
      agreed.value = true
      return
    }
    if (!game.value) return
    notice.value = ''
    try {
      await launcher.pushToGame(game.value)
    }
    catch {
      notice.value = 'popup'
    }
  }

  return {
    site,
    game,
    agreed,
    notice,
    currencyName,
    onClick,
  }
}
