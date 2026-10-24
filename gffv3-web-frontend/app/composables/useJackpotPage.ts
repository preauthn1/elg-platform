/** /jackpot 的邏輯，所有皮共用，畫面在 components/<皮>/Page/Jackpot.vue。 */
export function useJackpotPage() {
  const { site, settings } = useSite()
  const jackpot = useJackpot()
  const { show, winList, loading, wonPrize, amount, prizeCountdowns } = jackpot

  /** 標題照舊前台讀站台的 event_jackpot_title；沒有就退到 text_jackpot 用詞，再退到活動本身的標題。 */
  const title = computed(() => {
    const raw = site.value?.raw.event_jackpot_title
    if (typeof raw === 'string' && raw) return raw
    return settings.value?.text_jackpot || show.value?.title || ''
  })

  const showWinners = ref(false)
  const showDescription = ref(false)

  onMounted(() => {
    void jackpot.init()
  })
  onBeforeUnmount(() => jackpot.dispose())

  return {
    jackpot,
    show,
    winList,
    loading,
    wonPrize,
    amount,
    prizeCountdowns,
    title,
    showWinners,
    showDescription,
  }
}
