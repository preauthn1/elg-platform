import type { TransactionTypeKey } from '~/utils/records'
import { buildTransactionRangePresets, normalizeTransactionType, routeParamString } from '~/utils/records'

/**
 * 交易紀錄：類型、日期區間、幣別篩選，過午夜自動更新日期選項。
 * 所有皮共用，畫面在 components/<皮>/Page/RecordTransaction.vue。
 */
export function useTransactionRecordList() {
  const route = useRoute()
  const { t } = useI18n()
  const tx = useTransactionRecords()

  const rangePresets = ref(buildTransactionRangePresets(new Date()))
  const selectedRangeKey = ref(rangePresets.value[0]?.key ?? 'all')
  const selectedType = ref<TransactionTypeKey>(
    normalizeTransactionType(routeParamString(route.params.type), tx.typeOptions.value),
  )
  const selectedCurrency = ref('')

  const selectedRange = computed(() => rangePresets.value.find(preset => preset.key === selectedRangeKey.value) ?? rangePresets.value[0])
  const currencyOptions = computed(() => [...new Set(tx.logs.value.map(item => item.currency ?? ''))].filter(Boolean))
  const filteredLogs = computed(() => tx.logs.value.filter(item => !selectedCurrency.value || (item.currency ?? '') === selectedCurrency.value))

  const loading = ref(false)
  const loadFailed = ref(false)

  async function load() {
    const range = selectedRange.value
    if (!range) return
    loading.value = true
    const result = await tx.fetchLogs({ type: selectedType.value, range })
    loadFailed.value = !result.ok
    loading.value = false
  }

  function labelOf(item: (typeof tx.logs.value)[number]) {
    return tx.labelOf(item, key => t(key))
  }

  useFrontendMidnightRefresh(() => {
    rangePresets.value = buildTransactionRangePresets(new Date())
  })

  onMounted(load)
  watch([selectedType, selectedRangeKey], () => {
    void load()
  })

  return {
    t,
    tx,
    rangePresets,
    selectedRangeKey,
    selectedType,
    selectedCurrency,
    currencyOptions,
    filteredLogs,
    loading,
    loadFailed,
    labelOf,
  }
}
