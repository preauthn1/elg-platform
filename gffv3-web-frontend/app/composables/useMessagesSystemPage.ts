import type { SystemNotifyItem } from '#shared/api/endpoints/messages'
import { readPagination, systemNotifyStatus } from '#shared/utils/messages'

/** /messages/system 的邏輯，所有皮共用，畫面在 components/<皮>/Page/MessagesSystem.vue。 */
export function useMessagesSystemPage() {
  const { loadSystemNotificationsTab, selectSystemNotification } = useMessages()
  const labels = useSystemNotifyLabels()

  const page = ref(1)
  const items = ref<SystemNotifyItem[]>([])
  const pages = ref(1)
  const loading = ref(true)
  const loadFailed = ref(false)

  async function load() {
    loading.value = true
    const res = await loadSystemNotificationsTab(page.value)
    if (isApiOk(res)) {
      items.value = res.data.items
      pages.value = readPagination(res.data.pagination).pages
    }
    loadFailed.value = !isApiOk(res)
    loading.value = false
  }

  function statusClass(item: SystemNotifyItem) {
    switch (systemNotifyStatus(item.status)) {
      case 'pending': return 'text-info'
      case 'fail': return 'text-danger'
      case 'success': return 'text-success'
      default: return ''
    }
  }

  onMounted(load)
  watch(page, load)

  return {
    selectSystemNotification,
    labels,
    page,
    items,
    pages,
    loading,
    loadFailed,
    statusClass,
  }
}
