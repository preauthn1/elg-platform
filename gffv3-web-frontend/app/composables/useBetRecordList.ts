import type { TotalValidBetItem } from '~/utils/records'
import { BET_RECORD_PAGE_SIZE, buildDayPresets, paginateRecords } from '~/utils/records'

/**
 * 投注紀錄：日期區間（全部期間前端分頁、單日後端分頁），過午夜自動更新日期選項。
 * 所有皮共用，畫面在 components/<皮>/Page/RecordBet.vue。
 */
export function useBetRecordList() {
  const bets = useBetRecords()

  const dayPresets = ref(buildDayPresets(new Date(), { allPeriod: true }))
  const selectedDayKey = ref(dayPresets.value[0]?.key ?? 'all')
  const selectedPreset = computed(() => dayPresets.value.find(preset => preset.key === selectedDayKey.value) ?? dayPresets.value[0])
  const isAllPeriod = computed(() => (selectedPreset.value?.days.length ?? 0) > 1)

  const { t } = useI18n()

  const page = ref(1)
  const perPage = BET_RECORD_PAGE_SIZE
  const mergedList = ref<TotalValidBetItem[]>([])
  const serverList = ref<TotalValidBetItem[]>([])
  const serverTotal = ref(0)
  const loading = ref(false)
  const loadFailed = ref(false)

  const list = computed(() => (isAllPeriod.value ? paginateRecords(mergedList.value, page.value, perPage) : serverList.value))
  const totalCount = computed(() => (isAllPeriod.value ? mergedList.value.length : serverTotal.value))

  /** 全部期間是前端分頁，翻頁不重抓；單日翻頁才打後端。 */
  const queryKey = computed(() => `${selectedDayKey.value}|${isAllPeriod.value ? 1 : page.value}`)

  async function load() {
    const preset = selectedPreset.value
    if (!preset) return
    loading.value = true
    loadFailed.value = false
    if (isAllPeriod.value) {
      const result = await bets.fetchAllPeriodBets(preset.days)
      if (result.ok) {
        mergedList.value = result.data
        page.value = 1
      }
      else {
        loadFailed.value = true
      }
    }
    else {
      const day = preset.days[0]
      if (day) {
        const result = await bets.fetchTotalValidBets({ searchDay: day, page: page.value, perPage })
        if (result.ok) {
          serverList.value = result.data.items
          serverTotal.value = result.data.pagination.total ?? 0
        }
        else {
          loadFailed.value = true
        }
      }
    }
    loading.value = false
  }

  useFrontendMidnightRefresh(() => {
    dayPresets.value = buildDayPresets(new Date(), { allPeriod: true })
  })

  watch(selectedDayKey, () => {
    page.value = 1
    mergedList.value = []
    serverList.value = []
    serverTotal.value = 0
  })
  watch(queryKey, () => {
    void load()
  })
  onMounted(load)

  return {
    dayPresets,
    selectedDayKey,
    t,
    page,
    perPage,
    loading,
    loadFailed,
    list,
    totalCount,
  }
}
