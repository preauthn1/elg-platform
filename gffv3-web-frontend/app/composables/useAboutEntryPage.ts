import { replacePolicyPlaceholders, type PolicyEntry } from '#shared/utils/siteContent'

/** /about/[entry] 的邏輯，所有皮共用，畫面在 components/<皮>/Page/AboutEntry.vue。 */
export async function useAboutEntryPage() {
  const route = useRoute()
  const { t } = useI18n()
  const { site } = useSite()
  const { mergedPolicies, load, isReady } = useBasicInfo()

  /** 文案裡的站名，取 template 回的 title；沒有才退回語言檔的通用名稱。 */
  const platformName = computed(() => site.value?.raw.title || t('site.legal.unit.platformName'))

  /**
   * 版型寫死的政策清單；後台有供稿的項目由 mergedPolicies 換內容或補在最後。
   *
   * 這幾份文案裡有 {name}，**必須當成 vue-i18n 的參數傳進去**：
   * t() 在回傳前就會把 {name} 插值掉（沒給參數就變空字串），
   * 之後再用 replacePolicyPlaceholders 取代已經來不及——那支是給後台供稿的內容用的。
   */
  const basePolicies = computed<PolicyEntry[]>(() => {
    const name = platformName.value
    return [
      {
        titleKey: 'site.support.title.commonProblem',
        content: `${t('site.support.nav.technicalSupport')}\n${t('site.support.text.commonProblem', { name })}`,
      },
      {
        titleKey: 'site.legal.title.termsConditions',
        content: t('site.legal.text.termsConditions01', { name })
          + t('site.legal.text.termsConditions02', { name })
          + t('site.legal.text.termsConditions03', { name }),
      },
      { titleKey: 'site.legal.title.privacyPolicy', content: t('site.legal.text.privacyPolicy', { name }) },
      { titleKey: 'site.legal.title.cookiesPolicy', content: t('site.legal.text.cookiesPolicy', { name }) },
    ]
  })

  const dataReady = callOnce('basic-info', () => load(), { mode: 'navigation' })

  const policies = computed(() => mergedPolicies(basePolicies.value))
  // 參數名不能叫 index，會與 about/index.vue 產生同名路由
  const index = computed(() => Number(route.params.entry))
  const current = computed(() => policies.value[index.value] ?? null)
  const ready = computed(() => (current.value ? isReady(current.value.titleKey) : false))
  // 後台供稿的內容沒經過 t()，{name} 還在，這裡才需要自己取代
  const html = computed(() => (current.value ? replacePolicyPlaceholders(current.value.content, platformName.value) : ''))

  // 資料在 SSR 就要抓好：上面已經開始抓，這裡才等（await 之後不能再呼叫 composable）
  await dataReady

  return {
    site,
    current,
    ready,
    html,
  }
}
