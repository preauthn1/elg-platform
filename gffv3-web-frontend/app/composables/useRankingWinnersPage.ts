import { truncateAmount } from '#shared/utils/competitions'
import type { WinnerRankingTab } from '~/composables/useRanking'

/** /ranking/winners 的邏輯，所有皮共用，畫面在 components/<皮>/Page/RankingWinners.vue。 */
export function useRankingWinnersPage() {
  const { t } = useI18n()

  const tabs: { key: WinnerRankingTab, label: string }[] = [
    { key: 'gameWinners', label: 'competitions.ranking.title.todayWinners' },
    { key: 'profits', label: 'competitions.board.title.todayProfitRank' },
  ]
  const podiumLabels = [
    'competitions.ranking.label.rank01',
    'competitions.ranking.label.secondPlace',
    'competitions.ranking.label.rank03',
  ] as const

  const winner = useWinnerRanking()
  const { tab, board, loading } = winner

  const isGameWinners = computed(() => tab.value === 'gameWinners')

  const columns = computed(() => {
    const base = [
      t('competitions.ranking.label.rank'),
      t('competitions.ranking.label.member'),
      t('competitions.board.label.win'),
    ]
    return isGameWinners.value ? [...base, t('games.vendor.label.gameVendor')] : base
  })

  const rows = computed(() => board.value.rest.map((row) => {
    const base = [String(row.rank ?? ''), String(row.username ?? ''), truncateAmount(row.total_win_lose)]
    if (!isGameWinners.value) return base
    return [...base, 'vendor_name' in row ? String(row.vendor_name ?? '') : '']
  }))

  return {
    tabs,
    podiumLabels,
    winner,
    tab,
    board,
    loading,
    columns,
    rows,
  }
}
