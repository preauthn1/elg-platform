import type { ResponseOf } from '#shared/api/registry'
import type { RecordType, RecordTabFlags } from '~/utils/records'
import {
  RECORD_FETCH_ALL_PER_PAGE,
  findRecordById,
  postUnregisteredRoute,
  recordTabs,
  redEnvelopeTypeOf,
  resolveRecordsResult,
} from '~/utils/records'
import { useRecordsStore, type DepositRecord, type PrizeRecord, type WithdrawalRecord } from '~/stores/records'

export type GameTransferLog = ResponseOf<'transaction/game-transfer-logs'>['items'][number]
export type CustomerServiceLog = ResponseOf<'transaction/customer-service'>['items'][number]
export type RedEnvelopeLog = ResponseOf<'transaction/red-envelope'>['items'][number]

interface PrizeListResponse {
  items?: PrizeRecord[]
}

/**
 * /record/:type 各分頁與詳細頁：抓列表、分頁可見性、詳細頁用 id 查快取。
 */
export function useRecords() {
  const api = useApi()
  const store = useRecordsStore()
  const { flag, settings } = useSite()

  const tabFlags = computed<RecordTabFlags>(() => ({
    hasDepositRecordPage: flag('has_deposit_record_page'),
    hasEvent: flag('has_event'),
    isPgsPlatform: flag('has_pgs_lobby_layout'),
  }))
  const tabs = computed(() => recordTabs(tabFlags.value))
  const walletPointText = computed(() => settings.value?.text_wallet_point ?? '')

  const gameTransferLogs = ref<GameTransferLog[]>([])
  const customerServiceLogs = ref<CustomerServiceLog[]>([])
  const redEnvelopeLogs = ref<RedEnvelopeLog[]>([])

  async function fetchDepositOrders() {
    const res = await api.post('deposit/orders', { page: 1, per_page: RECORD_FETCH_ALL_PER_PAGE })
    if (isApiOk(res)) store.setDepositRecords(res.data)
    return res
  }

  async function fetchWithdrawalOrders(page = 1, perPage = RECORD_FETCH_ALL_PER_PAGE) {
    const res = await api.post('withdrawal/orders', { page, per_page: perPage })
    if (isApiOk(res)) store.setWithdrawalRecords(res.data.items)
    return res
  }

  async function fetchPrizeRecords() {
    const res = await postUnregisteredRoute<PrizeListResponse>(api.post, 'prize/list', {
      page: 1,
      per_page: RECORD_FETCH_ALL_PER_PAGE,
    })
    if (isApiOk(res)) store.setPrizeRecords(res.data?.items ?? [])
    return res
  }

  async function fetchGameTransferLogs(page = 1, perPage = RECORD_FETCH_ALL_PER_PAGE) {
    const res = await api.post('transaction/game-transfer-logs', { page, per_page: perPage })
    if (isApiOk(res)) gameTransferLogs.value = res.data.items
    return res
  }

  async function fetchCustomerServiceLogs(page = 1, perPage = RECORD_FETCH_ALL_PER_PAGE) {
    const res = await api.post('transaction/customer-service', { page, per_page: perPage })
    if (isApiOk(res)) customerServiceLogs.value = res.data.items
    return res
  }

  async function fetchRedEnvelopeLogs(type: 1 | 2 | 3, page = 1, perPage = RECORD_FETCH_ALL_PER_PAGE) {
    const res = await api.post('transaction/red-envelope', { type, page, per_page: perPage })
    if (isApiOk(res)) redEnvelopeLogs.value = res.data.items
    return res
  }

  /** 列表頁依分頁類型抓對應端點；回傳統一的成功／錯誤結果給畫面顯示訊息。 */
  async function fetchRecordList(type: RecordType) {
    switch (type) {
      case 'deposit':
        return resolveRecordsResult(await fetchDepositOrders())
      case 'event':
        return resolveRecordsResult(await fetchPrizeRecords())
      case 'platform-transfer':
        return resolveRecordsResult(await fetchGameTransferLogs())
      case 'withdrawal':
        return resolveRecordsResult(await fetchWithdrawalOrders())
      case 'customer-service':
        return resolveRecordsResult(await fetchCustomerServiceLogs())
      default: {
        const envelopeType = redEnvelopeTypeOf(type)
        if (envelopeType === null) return resolveRecordsResult(await fetchWithdrawalOrders())
        return resolveRecordsResult(await fetchRedEnvelopeLogs(envelopeType))
      }
    }
  }

  async function ensureDepositRecord(id: string | number): Promise<DepositRecord | null> {
    if (store.depositRecords.length === 0) await fetchDepositOrders()
    return findRecordById(store.depositRecords, 'order_id', id) ?? null
  }

  async function ensureWithdrawalRecord(id: string | number): Promise<WithdrawalRecord | null> {
    if (store.withdrawalRecords.length === 0) await fetchWithdrawalOrders()
    return findRecordById(store.withdrawalRecords, 'id', id) ?? null
  }

  async function ensurePrizeRecord(id: string | number): Promise<PrizeRecord | null> {
    if (store.prizeRecords.length === 0) await fetchPrizeRecords()
    return findRecordById(store.prizeRecords, 'id', id) ?? null
  }

  return {
    store,
    tabs,
    tabFlags,
    walletPointText,
    gameTransferLogs,
    customerServiceLogs,
    redEnvelopeLogs,
    fetchDepositOrders,
    fetchWithdrawalOrders,
    fetchPrizeRecords,
    fetchGameTransferLogs,
    fetchCustomerServiceLogs,
    fetchRedEnvelopeLogs,
    fetchRecordList,
    ensureDepositRecord,
    ensureWithdrawalRecord,
    ensurePrizeRecord,
  }
}
