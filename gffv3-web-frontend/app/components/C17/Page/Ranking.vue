<script setup lang="ts">
import { truncateAmount } from '#shared/utils/competitions'
import type { RankingTab } from '~/composables/useRanking'

const tabs: { key: RankingTab, label: string }[] = [
  { key: 'winning', label: 'competitions.board.nav.realTimeWinningRank' },
  { key: 'platformBetting', label: 'competitions.board.option.yesterdayBet' },
]

const ranking = useRanking()
const { tab, winning, platformBetting, loading } = ranking
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('competitions.ranking.nav.leaderboard') }}
    </h1>

    <nav class="flex gap-2 border-b border-line">
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        class="px-3 py-2 text-sm"
        :class="tab === item.key ? 'border-b-2 border-accent font-semibold' : 'text-muted'"
        @click="ranking.selectTab(item.key)"
      >
        {{ $t(item.label) }}
      </button>
    </nav>

    <p
      v-if="loading"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <table
      v-if="tab === 'winning'"
      class="w-full text-center text-sm"
    >
      <thead>
        <tr class="text-muted">
          <th class="py-1">
            {{ $t('competitions.ranking.label.member') }}
          </th>
          <th class="py-1">
            {{ $t('common.time.label.time') }}
          </th>
          <th class="py-1">
            {{ $t('competitions.board.label.win') }}
          </th>
          <th class="py-1">
            {{ $t('games.vendor.label.gameVendor') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, index) in winning"
          :key="`${row.username}-${row.payout_time}-${index}`"
          class="border-t border-line"
        >
          <td class="py-1">
            {{ row.username }}
          </td>
          <td class="py-1">
            {{ row.payout_time }}
          </td>
          <td class="py-1">
            {{ truncateAmount(row.win_lose) }}
          </td>
          <td class="py-1">
            {{ row.vendor_name }}
          </td>
        </tr>
        <tr v-if="!loading && winning.length === 0">
          <td
            colspan="4"
            class="py-6 text-muted"
          >
            {{ $t('common.ui.empty.noData') }}
          </td>
        </tr>
      </tbody>
    </table>

    <table
      v-else
      class="w-full text-center text-sm"
    >
      <thead>
        <tr class="text-muted">
          <th class="py-1">
            {{ $t('competitions.ranking.label.rank') }}
          </th>
          <th class="py-1">
            {{ $t('competitions.ranking.label.member') }}
          </th>
          <th class="py-1">
            {{ $t('common.amount.label.betAmount') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in platformBetting"
          :key="`${row.rank}-${row.username}`"
          class="border-t border-line"
        >
          <td class="py-1">
            {{ row.rank }}
          </td>
          <td class="py-1">
            {{ row.username }}
          </td>
          <td class="py-1">
            {{ truncateAmount(row.sum_bet) }}
          </td>
        </tr>
        <tr v-if="!loading && platformBetting.length === 0">
          <td
            colspan="3"
            class="py-6 text-muted"
          >
            {{ $t('common.ui.empty.noData') }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
