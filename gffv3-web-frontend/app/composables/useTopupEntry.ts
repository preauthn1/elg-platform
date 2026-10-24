import { buildTopupTabs, type TopupTabKey } from '#shared/utils/deposit'

/**
 * 存款入口（/topup）：先擋進行中的活動，沒擋才抓存款方式並選第一個分頁。
 * 所有皮共用，畫面在 components/<皮>/Page/Topup.vue。
 */
export function useTopupEntry() {
  const { t } = useI18n()
  const { paymentTypes, fetchTopUpTypes } = useDeposit()
  const { hasActiveEvent } = useDepositEventGuard()

  const loading = ref(true)
  const blocked = ref(false)
  const loadFailed = ref(false)
  const active = ref<TopupTabKey | null>(null)
  const tabs = computed(() => buildTopupTabs(paymentTypes.value, t('deposit.entry.nav.quickDeposit')))

  onMounted(async () => {
    if (await hasActiveEvent()) {
      blocked.value = true
      loading.value = false
      return
    }
    const res = await fetchTopUpTypes()
    loadFailed.value = !isApiOk(res)
    active.value = tabs.value[0]?.key ?? null
    loading.value = false
  })

  return { loading, blocked, loadFailed, active, tabs }
}
