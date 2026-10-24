import type { RecordType } from '~/utils/records'
import { isRecordType, recordStatusLabel, routeParamString } from '~/utils/records'

/**
 * 紀錄列表（/record/:type）：依網址的種類抓對應的紀錄，切換種類時重抓。
 * 所有皮共用，畫面在 components/<皮>/Page/RecordList.vue。
 */
export function useRecordList() {
  const route = useRoute()
  const { t } = useI18n()
  const records = useRecords()
  const { store, tabs, walletPointText, gameTransferLogs, customerServiceLogs, redEnvelopeLogs } = records

  const type = computed<RecordType | null>(() => {
    const param = routeParamString(route.params.type)
    return isRecordType(param) ? param : null
  })
  const currentTab = computed(() => tabs.value.find(tab => tab.type === type.value) ?? null)
  const isRedEnvelope = computed(() => type.value?.startsWith('red-envelope') ?? false)

  const loading = ref(false)
  const loadFailed = ref(false)

  const listLength = computed(() => {
    switch (type.value) {
      case 'deposit':
        return store.depositRecords.length
      case 'event':
        return store.prizeRecords.length
      case 'platform-transfer':
        return gameTransferLogs.value.length
      case 'withdrawal':
        return store.withdrawalRecords.length
      case 'customer-service':
        return customerServiceLogs.value.length
      case null:
        return 0
      default:
        return redEnvelopeLogs.value.length
    }
  })

  function statusText(status: number | string | null | undefined) {
    return recordStatusLabel(status, key => t(key))
  }

  async function load() {
    const current = type.value
    if (!current) return
    loading.value = true
    const result = await records.fetchRecordList(current)
    loadFailed.value = !result.ok
    loading.value = false
  }

  onMounted(() => {
    void load()
  })
  watch(type, load)

  return {
    store,
    tabs,
    walletPointText,
    gameTransferLogs,
    customerServiceLogs,
    redEnvelopeLogs,
    type,
    currentTab,
    isRedEnvelope,
    loading,
    loadFailed,
    listLength,
    statusText,
  }
}
