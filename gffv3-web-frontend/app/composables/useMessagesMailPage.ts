import type { MemberMailItem } from '#shared/api/endpoints/messages'
import { createLatestGuard, readPagination } from '#shared/utils/messages'

/** /messages/mail 的邏輯，所有皮共用，畫面在 components/<皮>/Page/MessagesMail.vue。 */
export function useMessagesMailPage() {
  const { loadMemberMailsTab, readMemberMail } = useMessages()

  const page = ref(1)
  const items = ref<MemberMailItem[]>([])
  const pages = ref(1)
  const loading = ref(true)
  const loadFailed = ref(false)
  const selected = ref<MemberMailItem | null>(null)
  const guard = createLatestGuard()

  async function load() {
    const requestId = guard.next()
    loading.value = true
    const res = await loadMemberMailsTab(page.value)
    if (!guard.isLatest(requestId)) return
    if (isApiOk(res)) {
      items.value = res.data.items ?? []
      pages.value = readPagination(res.data.pagination).pages
    }
    loadFailed.value = !isApiOk(res)
    loading.value = false
  }

  async function open(item: MemberMailItem) {
    selected.value = item
    if (item.is_read === 0) {
      const res = await readMemberMail(item.id)
      if (isApiOk(res)) item.is_read = 1
    }
  }

  onMounted(load)
  watch(page, load)

  return {
    page,
    items,
    pages,
    loading,
    loadFailed,
    selected,
    open,
  }
}
