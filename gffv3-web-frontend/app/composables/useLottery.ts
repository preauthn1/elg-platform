import { computed, ref } from 'vue'
import type { ApiEnvelope } from '#shared/types/api'
import type { ResponseOf } from '#shared/api/registry'
import { isJackpotOk } from '~/utils/jackpot'
import {
  LOTTERY_DETAIL_REFRESH_DELAY_MS,
  LOTTERY_PER_PAGE,
  LOTTERY_VARIANTS,
  lotteryCanReceiveAll,
  lotteryReceiveMessage,
  type LotteryVariant,
} from '~/utils/lottery'

export type LotteryIssue = ResponseOf<'event/lucky-draw/issues'>[number]
export type LotteryIssueDetail
  = | ResponseOf<'event/lucky-draw/issues/detail'>
    | ResponseOf<'event/wjackpot/issues/detail'>
    | ResponseOf<'event/wjackpot6d/issues/detail'>
export type LotteryTickets
  = | ResponseOf<'event/lucky-draw/issues/tickets'>
    | ResponseOf<'event/wjackpot/issues/tickets'>
    | ResponseOf<'event/wjackpot6d/issues/tickets'>
export type LotteryTicket = LotteryTickets['items'][number]
export type LotteryPagination = LotteryTickets['pagination']

/**
 * 抽獎券活動（V-Jackpot / W-Jackpot / W-Jackpot 6D 共用）：期數、明細、我的抽獎券、領獎。
 * 變體只決定端點與開獎號碼呈現，業務規則相同：200/1000 都算成功，領獎成功後重抓。
 */
export function useLottery(variant: LotteryVariant) {
  const api = useApi()
  const { t } = useI18n()
  const config = LOTTERY_VARIANTS[variant]

  const issues = ref<LotteryIssue[]>([])
  const detail = ref<LotteryIssueDetail | null>(null)
  const tickets = ref<LotteryTicket[]>([])
  const pagination = ref<LotteryPagination | null>(null)
  const resultNumber = ref('')
  const page = ref(1)
  const perPage = LOTTERY_PER_PAGE
  const loading = ref(false)
  const alert = useAlert()

  const total = computed(() => Number(pagination.value?.total ?? 0))
  const pages = computed(() => Number(pagination.value?.pages ?? 0))
  const canReceiveAll = computed(() => lotteryCanReceiveAll(detail.value?.summary))

  let detailRefreshTimer: ReturnType<typeof setTimeout> | null = null

  async function fetchIssues(): Promise<ApiEnvelope<ResponseOf<typeof config.routes.issues>>> {
    loading.value = true
    try {
      const res = await api.post(config.routes.issues, {})
      if (isJackpotOk(res.code)) {
        issues.value = res.data ?? []
      }
      return res
    }
    finally {
      loading.value = false
    }
  }

  async function fetchDetail(issueId: number): Promise<ApiEnvelope<ResponseOf<typeof config.routes.detail>>> {
    loading.value = true
    try {
      const res = await api.post(config.routes.detail, { issue_id: issueId })
      if (isJackpotOk(res.code)) {
        detail.value = res.data ?? null
      }
      return res
    }
    finally {
      loading.value = false
    }
  }

  async function fetchTickets(issueId: number, pageNo = page.value): Promise<ApiEnvelope<ResponseOf<typeof config.routes.tickets>>> {
    tickets.value = []
    loading.value = true
    try {
      const res = await api.post(config.routes.tickets, { issue_id: issueId, page: pageNo, per_page: perPage })
      if (isJackpotOk(res.code) && res.data) {
        tickets.value = res.data.items
        pagination.value = res.data.pagination
        resultNumber.value = res.data.result_number
        page.value = pageNo
      }
      return res
    }
    finally {
      loading.value = false
    }
  }

  async function receive(issueId: number, ticketId: number): Promise<ApiEnvelope<ResponseOf<typeof config.routes.receive>>> {
    const res = await api.post(config.routes.receive, { ticket_id: ticketId })
    if (isJackpotOk(res.code)) {
      alert.show(lotteryReceiveMessage(t), 'success')
      await fetchTickets(issueId)
    }
    return res
  }

  async function receiveAll(issueId: number): Promise<ApiEnvelope<ResponseOf<typeof config.routes.receiveAll>>> {
    const res = await api.post(config.routes.receiveAll, { issue_id: issueId })
    if (isJackpotOk(res.code)) {
      alert.show(lotteryReceiveMessage(t), 'success')
      scheduleDetailRefresh(issueId)
    }
    return res
  }

  /** 全部領取成功後延遲 1 秒重抓明細。 */
  function scheduleDetailRefresh(issueId: number) {
    if (detailRefreshTimer) clearTimeout(detailRefreshTimer)
    detailRefreshTimer = setTimeout(() => {
      detailRefreshTimer = null
      void fetchDetail(issueId)
    }, LOTTERY_DETAIL_REFRESH_DELAY_MS)
  }

  function dispose() {
    if (detailRefreshTimer) clearTimeout(detailRefreshTimer)
    detailRefreshTimer = null
  }

  return {
    variant,
    config,
    issues,
    detail,
    tickets,
    pagination,
    resultNumber,
    page,
    perPage,
    total,
    pages,
    loading,
    canReceiveAll,
    fetchIssues,
    fetchDetail,
    fetchTickets,
    receive,
    receiveAll,
    dispose,
  }
}
