<script setup lang="ts">
import {
  claimState,
  seasonDate,
  truncateAmount,
  rankingText,
} from '#shared/utils/competitions'

/** C17 的畫面，邏輯在 useCompetitionsSlotPage。 */
const {
  tabs,
  tab,
  podiumLabels,
  competition,
  selectedSeason,
  seasonOptions,
  board,
  myRanking,
  awards,
  loading,
  receiving,
  hasSeason,
  noData,
  onSeasonChange,
} = useCompetitionsSlotPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      老虎機爭霸賽
    </h1>

    <p
      v-if="loading"
      class="text-sm text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>
    <p
      v-else-if="!hasSeason"
      class="text-sm text-muted"
    >
      {{ $t('events.list.empty.noEvents') }}
    </p>

    <template v-if="hasSeason">
      <nav class="flex gap-2 border-b border-line">
        <button
          v-for="item in tabs"
          :key="item"
          type="button"
          class="px-3 py-2 text-sm"
          :class="tab === item ? 'border-b-2 border-accent font-semibold' : 'text-muted'"
          @click="tab = item"
        >
          {{ $t(item) }}
        </button>
      </nav>

      <section
        v-if="tab === 'competitions.ranking.nav.leaderboard'"
        class="space-y-3"
      >
        <label class="block text-sm">
          <span class="font-semibold">{{ $t('competitions.period.label.seasons') }}</span>
          <span class="ml-2 text-xs text-muted">{{ $t('competitions.ranking.hint.updateInterval') }}</span>
          <select
            class="mt-1 block w-full rounded border border-line bg-surface px-2 py-1"
            :value="selectedSeason?.no"
            @change="onSeasonChange"
          >
            <option
              v-for="option in seasonOptions"
              :key="option.no"
              :value="option.no"
            >
              {{ option.label }}
            </option>
          </select>
        </label>

        <p
          v-if="noData"
          class="py-6 text-center text-sm text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
        <template v-else>
          <ol class="grid grid-cols-3 gap-2">
            <li
              v-for="(item, index) in board.podium"
              :key="index"
              class="rounded border border-line bg-surface p-2 text-center text-sm"
            >
              <div class="text-xs text-muted">
                {{ $t(podiumLabels[index] ?? 'competitions.ranking.label.rank') }}
              </div>
              <template v-if="item">
                <div class="font-semibold">
                  {{ item.username }}
                </div>
                <div>{{ truncateAmount(item.bet_amount) }}</div>
              </template>
              <div v-else>
                -
              </div>
            </li>
          </ol>
          <table class="w-full text-center text-sm">
            <thead>
              <tr class="text-muted">
                <th class="py-1">
                  {{ $t('competitions.ranking.title.ranking') }}
                </th>
                <th class="py-1">
                  {{ $t('competitions.ranking.label.accountName') }}
                </th>
                <th class="py-1">
                  {{ $t('common.amount.label.betAmount') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, index) in board.rest"
                :key="`${row.username}-${index}`"
                class="border-t border-line"
              >
                <td class="py-1">
                  {{ row.ranking }}
                </td>
                <td class="py-1">
                  {{ row.username }}
                </td>
                <td class="py-1">
                  {{ truncateAmount(row.bet_amount) }}
                </td>
              </tr>
            </tbody>
          </table>
        </template>
      </section>

      <section
        v-else-if="tab === 'competitions.ranking.nav.myRanking'"
        class="space-y-3"
      >
        <p
          v-if="myRanking.length === 0"
          class="py-6 text-center text-sm text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
        <table
          v-else
          class="w-full text-center text-sm"
        >
          <thead>
            <tr class="text-muted">
              <th class="py-1">
                {{ $t('competitions.period.label.seasons') }}
              </th>
              <th class="py-1">
                {{ $t('competitions.ranking.label.date') }}
              </th>
              <th class="py-1">
                {{ $t('competitions.ranking.label.rank') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in myRanking"
              :key="row.no"
              class="border-t border-line"
            >
              <td class="py-1">
                {{ row.no }}
              </td>
              <td class="py-1">
                <div>{{ seasonDate(row.start_time) }}</div>
                <div>{{ seasonDate(row.end_time) }}</div>
              </td>
              <td class="py-1">
                <div>{{ rankingText(row.ranking) }}</div>
                <button
                  v-if="claimState(row) === 'claimable'"
                  type="button"
                  class="mt-1 rounded bg-accent px-2 py-1 text-xs text-inverse disabled:opacity-50"
                  :disabled="receiving"
                  @click="competition.receive(row)"
                >
                  {{ $t('events.claim.action.claim') }} {{ row.point }}
                </button>
                <span
                  v-else-if="claimState(row) === 'claimed'"
                  class="text-xs text-muted"
                >
                  {{ $t('events.claim.label.claimed') }}
                </span>
                <span
                  v-else-if="claimState(row) === 'expired'"
                  class="text-xs text-muted"
                >
                  {{ $t('events.claim.label.expired') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <section
        v-else
        class="space-y-3"
      >
        <h2 class="font-semibold">
          {{ $t('competitions.rules.nav.detailedRules') }}
        </h2>
        <!-- 規則內容是後台編輯的 HTML -->
        <!-- eslint-disable vue/no-v-html -->
        <div
          v-if="awards?.content"
          class="text-sm"
          v-html="awards.content"
        />
        <!-- eslint-enable vue/no-v-html -->
        <table
          v-if="awards?.prizes.length"
          class="w-full text-center text-sm"
        >
          <thead>
            <tr class="text-muted">
              <th class="py-1">
                {{ $t('vip.common.label.level') }}
              </th>
              <th class="py-1">
                {{ $t('competitions.reward.label.award') }}
              </th>
              <th class="py-1">
                名額
              </th>
              <th class="py-1">
                {{ $t('common.amount.label.amount') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="prize in awards.prizes"
              :key="prize.level"
              class="border-t border-line"
            >
              <td class="py-1">
                {{ prize.level }}
              </td>
              <td class="py-1">
                {{ prize.name }}
              </td>
              <td class="py-1">
                {{ prize.number }}
              </td>
              <td class="py-1">
                {{ truncateAmount(prize.point) }}
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
