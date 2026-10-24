import type { ResponseOf } from '#shared/api/registry'
import type { RangePreset, TransactionLogLike, TransactionTypeFlags, TransactionTypeKey } from '~/utils/records'
import {
  RECORD_FETCH_ALL_PER_PAGE,
  buildTransactionLogsRequest,
  resolveRecordsResult,
  transactionIconKind,
  transactionTypeLabel,
  transactionTypeOptions,
} from '~/utils/records'
import { isOn } from '#shared/utils/site'

export type TransactionLog = ResponseOf<'transaction/logs'>['items'][number]

export interface TransactionLogsQuery {
  type: TransactionTypeKey
  range: Pick<RangePreset, 'start_time' | 'end_time'>
  page?: number
  perPage?: number
}

/**
 * 交易紀錄（transaction/logs）：類型下拉可見性、請求組法、每筆的圖示與名稱。
 */
export function useTransactionRecords() {
  const api = useApi()
  const { site, settings } = useSite()

  const typeFlags = computed<TransactionTypeFlags>(() => ({
    hasAgentLayout: isOn(site.value?.raw.has_agent_layout),
    unlimitVisible: isOn(settings.value?.is_unlimit_visible),
    unlimitedText: settings.value?.text_unlimited ?? '',
  }))
  const typeOptions = computed(() => transactionTypeOptions(typeFlags.value))
  const walletPointText = computed(() => settings.value?.text_wallet_point ?? '')
  const rollingChipText = computed(() => settings.value?.text_rolling_chip ?? '')
  const jackpotTitle = computed(() => {
    const title = site.value?.raw.event_jackpot_title
    return typeof title === 'string' ? title : ''
  })

  const logs = ref<TransactionLog[]>([])

  async function fetchLogs(input: TransactionLogsQuery) {
    const res = await api.post('transaction/logs', buildTransactionLogsRequest({
      type: input.type,
      range: input.range,
      page: input.page ?? 1,
      perPage: input.perPage ?? RECORD_FETCH_ALL_PER_PAGE,
    }))
    if (isApiOk(res)) logs.value = res.data.items
    return resolveRecordsResult(res)
  }

  function iconKindOf(item: TransactionLogLike) {
    return transactionIconKind(item)
  }

  function labelOf(item: TransactionLogLike, t: (key: string) => string) {
    return transactionTypeLabel(item, { t, jackpotTitle: jackpotTitle.value, couponLabelKey: 'wallet.exchange.title.coupon' })
  }

  /** 禮碼列顯示 text_rolling_chip，其餘顯示 text_wallet_point。 */
  function amountLabelOf(item: TransactionLogLike) {
    return item.is_rolling_chip === 1 ? rollingChipText.value : walletPointText.value
  }

  return {
    typeFlags,
    typeOptions,
    walletPointText,
    rollingChipText,
    logs,
    fetchLogs,
    iconKindOf,
    labelOf,
    amountLabelOf,
  }
}
