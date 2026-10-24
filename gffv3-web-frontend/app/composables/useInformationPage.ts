/** /information 的邏輯，所有皮共用，畫面在 components/<皮>/Page/Information.vue。 */
export async function useInformationPage() {
  const route = useRoute()
  const { t } = useI18n()
  const { site } = useSite()
  const { loadInformation } = useInformation()
  const { openBanner, isClickable } = useBannerNavigation()

  const tabs = computed(() => [t('site.support.nav.tutorial'), t('site.support.title.commonProblem'), t('site.brand.nav.whyChooseUs')])
  const initialIndex = Number(route.query.index)
  const index = ref(Number.isInteger(initialIndex) && initialIndex >= 0 && initialIndex < 3 ? initialIndex : 0)

  const dataReady = useAsyncData('information-page', () => loadInformation(), {
    default: () => [],
  })
  const { data: information } = dataReady

  /** 文案裡的 {name} 帶入 template 回的站台名稱；沒有才退回語言檔的通用名稱。 */
  const platformName = computed(() => site.value?.raw.title || t('site.legal.unit.platformName'))

  const commonProblems = computed(() => [
    { title: t('site.support.nav.technicalSupport'), content: t('site.support.text.commonProblem', { name: platformName.value }) },
  ])
  const openProblem = ref<number | null>(0)
  // {name} 要當 vue-i18n 的參數傳，t() 回傳前就會把它插值掉，事後字串取代來不及
  const whyChooseUs = computed(() => t('site.brand.text.whyChooseUs', { name: platformName.value }))

  // 資料在 SSR 就要抓好：上面已經開始抓，這裡才等（await 之後不能再呼叫 composable）
  await dataReady

  return {
    openBanner,
    isClickable,
    tabs,
    index,
    information,
    commonProblems,
    openProblem,
    whyChooseUs,
  }
}
