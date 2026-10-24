import type { MemberVendor, WinlosesResponse } from '~/composables/useBetRecords'
import { BET_RECORD_PAGE_SIZE, buildDayPresets } from '~/utils/records'

/**
 * 輸贏報表：遊戲商、日期、頁碼任一變就重抓，過午夜自動更新日期選項。
 * 所有皮共用，畫面在 components/<皮>/Page/RecordWinLoss.vue。
 */
export function useWinLossReport() {
  const bets = useBetRecords()

  const dayPresets = ref(buildDayPresets(new Date(), { allPeriod: false }))
  const selectedDayKey = ref(dayPresets.value[0]?.key ?? '')
  const selectedDay = computed(() => dayPresets.value.find(preset => preset.key === selectedDayKey.value)?.days[0] ?? null)

  const vendors = ref<MemberVendor[]>([])
  const selectedVendorId = ref<number | null>(null)

  const page = ref(1)
  const perPage = BET_RECORD_PAGE_SIZE
  const report = ref<WinlosesResponse | null>(null)
  const loading = ref(false)
  const loadFailed = ref(false)

  const total = computed(() => report.value?.pagination.total ?? 0)

  /** 遊戲商、日期、頁碼任一變就重抓；遊戲商清單載入前不打。 */
  const queryKey = computed(() => `${selectedVendorId.value ?? ''}|${selectedDay.value ?? ''}|${page.value}`)

  async function loadVendors() {
    const result = await bets.fetchMemberVendors()
    if (!result.ok) {
      loadFailed.value = true
      return
    }
    vendors.value = result.data
    selectedVendorId.value = result.data[0]?.vendor_id ?? null
  }

  async function load() {
    const vendorId = selectedVendorId.value
    const day = selectedDay.value
    if (vendorId === null || !day) return
    loading.value = true
    const result = await bets.fetchWinloses({ vendorId, searchDay: day, page: page.value, perPage })
    if (result.ok) report.value = result.data
    loadFailed.value = !result.ok
    loading.value = false
  }

  useFrontendMidnightRefresh(() => {
    dayPresets.value = buildDayPresets(new Date(), { allPeriod: false })
  })

  watch([selectedVendorId, selectedDayKey], () => {
    page.value = 1
  })
  watch(queryKey, () => {
    void load()
  })
  onMounted(loadVendors)

  return {
    dayPresets,
    selectedDayKey,
    vendors,
    selectedVendorId,
    page,
    perPage,
    report,
    loading,
    loadFailed,
    total,
  }
}
