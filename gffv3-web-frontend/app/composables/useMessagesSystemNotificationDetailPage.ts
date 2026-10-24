import type { SystemNotifyDetail } from '#shared/utils/messages'
import {
  buildSystemNotifyHeader,
  commissionSummaryRows,
  hasLoseColumn,
  systemNotifyStatus,
  unwrapSystemNotifyDetail,
} from '#shared/utils/messages'
import { useMessagesStore } from '~/stores/messages'

/** /messages/system-notification-detail 的邏輯，所有皮共用，畫面在 components/<皮>/Page/MessagesSystemNotificationDetail.vue。 */
export function useMessagesSystemNotificationDetailPage() {
  const store = useMessagesStore()
  const { site } = useSite()
  const { user } = useUserSession()
  const { fetchSystemNotifyDetail } = useMessages()
  const labels = useSystemNotifyLabels()

  const item = computed(() => store.selectedSystemNotification)
  const page = ref(1)
  const detail = ref<SystemNotifyDetail | null>(null)
  const loading = ref(false)

  const logo = computed(() => site.value?.raw.logo_mobile || site.value?.raw.logo_pc || '')
  const header = computed(() => item.value
    ? buildSystemNotifyHeader(item.value, {
        username: user.value?.username ?? '',
        currencyName: labels.currencyName(item.value),
      })
    : null)
  const commission = computed(() => (detail.value?.kind === 'commission' ? detail.value : null))
  const transfer = computed(() => (detail.value?.kind === 'transfer' ? detail.value : null))
  const summaryRows = computed(() => (commission.value && item.value
    ? commissionSummaryRows(commission.value.sum_data, labels.currencyName(item.value))
    : []))
  const showLose = computed(() => (commission.value ? hasLoseColumn(commission.value.items, commission.value.sum_data) : false))
  const payer = computed(() => (transfer.value ? { kind: 'text' as const, text: transfer.value.sender } : header.value?.payer ?? null))
  const payee = computed(() => (transfer.value ? { kind: 'text' as const, text: transfer.value.receiver } : header.value?.payee ?? null))
  const statusClass = computed(() => {
    switch (systemNotifyStatus(item.value?.status)) {
      case 'pending': return 'text-info'
      case 'fail': return 'text-danger'
      case 'success': return 'text-success'
      default: return ''
    }
  })

  async function load() {
    if (!item.value) return
    loading.value = true
    const res = await fetchSystemNotifyDetail(item.value, page.value)
    if (res && isApiOk(res)) detail.value = unwrapSystemNotifyDetail(res.data)
    loading.value = false
  }

  onMounted(async () => {
    if (!item.value) {
      await navigateTo('/messages/system', { replace: true })
      return
    }
    await load()
  })
  watch(page, load)

  return {
    site,
    labels,
    item,
    page,
    loading,
    logo,
    header,
    commission,
    summaryRows,
    showLose,
    payer,
    payee,
    statusClass,
  }
}
