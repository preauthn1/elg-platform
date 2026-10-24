import { MEMBER_PATH } from '#shared/utils/member'
import type { DepositRecord, PrizeRecord, WithdrawalRecord } from '~/stores/records'
import { recordStatusLabel, routeParamString } from '~/utils/records'

/**
 * 紀錄明細（/record/:type/:recordId）：存款、提款、活動三種，找不到就回會員中心。
 * 所有皮共用，畫面在 components/<皮>/Page/RecordDetail.vue。
 */
export function useRecordDetail() {
  const route = useRoute()
  const { t } = useI18n()
  const { site } = useSite()
  const { user } = useUserSession()
  const records = useRecords()

  const type = computed(() => routeParamString(route.params.type))
  const recordId = computed(() => routeParamString(route.params.recordId))

  const loading = ref(true)
  const deposit = ref<DepositRecord | null>(null)
  const withdrawal = ref<WithdrawalRecord | null>(null)
  const prize = ref<PrizeRecord | null>(null)

  const titleKey = computed(() => {
    switch (type.value) {
      case 'deposit':
        return 'records.tab.nav.deposit'
      case 'withdrawal':
        return 'records.tab.nav.withdrawal'
      case 'event':
        return 'records.tab.nav.event'
      default:
        return 'records.common.title.recordList'
    }
  })

  function statusText(status: number | string | null | undefined) {
    return recordStatusLabel(status, key => t(key))
  }

  async function load() {
    loading.value = true
    let found = false
    switch (type.value) {
      case 'deposit':
        deposit.value = await records.ensureDepositRecord(recordId.value)
        found = deposit.value !== null
        break
      case 'withdrawal':
        withdrawal.value = await records.ensureWithdrawalRecord(recordId.value)
        found = withdrawal.value !== null
        break
      case 'event':
        prize.value = await records.ensurePrizeRecord(recordId.value)
        found = prize.value !== null
        break
    }
    loading.value = false
    if (!found) await navigateTo(MEMBER_PATH.home, { replace: true })
  }

  onMounted(load)

  return {
    site,
    user,
    records,
    type,
    loading,
    deposit,
    withdrawal,
    prize,
    titleKey,
    statusText,
  }
}
