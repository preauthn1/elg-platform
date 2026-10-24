/** /about 的邏輯，所有皮共用，畫面在 components/<皮>/Page/About.vue。 */
export async function useAboutPage() {
  const { site } = useSite()
  const { menuTitles, load } = useBasicInfo()

  const baseTitles = ['site.support.title.commonProblem', 'site.legal.title.termsConditions', 'site.legal.title.privacyPolicy', 'site.legal.title.cookiesPolicy']
  const titles = computed(() => menuTitles(baseTitles))

  // 列表頁預先取回內容，內容頁沿用 store 快取
  const dataReady = callOnce('basic-info', () => load(), { mode: 'navigation' })

  // 資料在 SSR 就要抓好：上面已經開始抓，這裡才等（await 之後不能再呼叫 composable）
  await dataReady

  return {
    site,
    titles,
  }
}
