<script setup lang="ts">
import { formatEventAmount } from '~/utils/jackpot'

/** C17 的畫面，邏輯在 useJackpotPage。 */
const {
  jackpot,
  show,
  winList,
  loading,
  wonPrize,
  amount,
  prizeCountdowns,
  title,
  showWinners,
  showDescription,
} = useJackpotPage()
</script>

<template>
  <section class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ title }}
    </h1>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <div
      v-if="show"
      class="space-y-4"
    >
      <img
        v-if="show.banner"
        :src="show.banner"
        class="w-full rounded"
        alt=""
      >

      <div class="rounded border border-line bg-surface p-4 text-center">
        <p class="text-sm text-muted">
          {{ show.title }}
        </p>
        <p class="text-3xl font-semibold tabular-nums">
          {{ formatEventAmount(amount) }}
        </p>
        <p class="text-xs text-muted">
          {{ show.start_time }} ~ {{ show.end_time }}
        </p>
      </div>

      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded border border-line bg-surface px-4 py-2"
          @click="showWinners = !showWinners"
        >
          {{ $t('events.jackpot.title.winnerList') }}
        </button>
        <button
          type="button"
          class="rounded border border-line bg-surface px-4 py-2"
          @click="showDescription = !showDescription"
        >
          {{ $t('events.detail.title.description') }}
        </button>
      </div>

      <section
        v-if="showWinners"
        class="rounded border border-line bg-surface p-4"
      >
        <h2 class="font-semibold">
          {{ $t('events.jackpot.title.winnerList') }}
        </h2>
        <ul
          v-if="winList.length"
          class="mt-2 divide-y divide-line text-sm"
        >
          <li
            v-for="(winner, index) in winList"
            :key="index"
            class="flex items-center gap-3 py-2"
          >
            <img
              v-if="winner.image"
              :src="winner.image"
              class="h-8 w-8 rounded-full object-cover"
              alt=""
            >
            <span
              v-else
              class="inline-block h-8 w-8 rounded-full bg-line"
            />
            <span class="flex-1">{{ winner.username }}</span>
            <span class="tabular-nums">{{ formatEventAmount(winner.point) }}</span>
          </li>
        </ul>
        <p
          v-else
          class="mt-2 text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
      </section>

      <section
        v-if="showDescription"
        class="rounded border border-line bg-surface p-4"
      >
        <h2 class="font-semibold">
          {{ $t('events.detail.title.description') }}
        </h2>
        <!-- eslint-disable vue/no-v-html -->
        <div
          class="prose mt-2 max-w-none text-sm"
          v-html="show.content"
        />
        <!-- eslint-enable vue/no-v-html -->
      </section>

      <ul class="grid grid-cols-3 gap-2 sm:grid-cols-5">
        <li
          v-for="prize in show.prize"
          :key="prize.id"
          class="rounded border border-line bg-surface p-3 text-center text-sm"
        >
          <img
            v-if="prize.image"
            :src="prize.image"
            class="mx-auto mb-2 h-16 w-16 object-contain"
            alt=""
          >
          <button
            type="button"
            class="w-full rounded bg-accent px-2 py-1 text-inverse disabled:bg-muted disabled:opacity-60"
            :disabled="Number(prize.status) === 1"
            @click="jackpot.receive(prize)"
          >
            {{ $t(Number(prize.status) === 1 ? 'events.claim.label.claimed' : 'events.claim.action.claim') }}
          </button>
          <p
            v-if="Number(prize.status) === 1"
            class="mt-1 text-xs text-muted"
          >
            {{ $t('common.amount.label.amount') }}: {{ formatEventAmount(prize.point) }}
          </p>
          <p
            v-else-if="prizeCountdowns[prize.id]"
            class="mt-1 text-xs text-muted"
          >
            {{ $t('events.common.label.remainingTime') }}:
            <span>{{ prizeCountdowns[prize.id]?.expired ? $t('events.claim.label.expired') : prizeCountdowns[prize.id]?.text }}</span>
          </p>
        </li>
      </ul>
    </div>
    <p
      v-else-if="!loading"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>

    <div
      v-if="wonPrize"
      class="fixed inset-0 z-50 flex items-center justify-center bg-scrim p-4"
    >
      <div class="w-full max-w-sm rounded bg-surface p-4 text-center">
        <h2 class="font-semibold">
          {{ $t('events.common.label.rewardItem') }}
        </h2>
        <img
          v-if="wonPrize.image"
          :src="wonPrize.image"
          class="mx-auto my-3 h-24 w-24 object-contain"
          alt=""
        >
        <p>{{ $t('common.amount.label.amount') }}: {{ formatEventAmount(wonPrize.point) }}</p>
        <button
          type="button"
          class="mt-4 rounded bg-accent px-4 py-2 text-inverse"
          @click="jackpot.clearWonPrize()"
        >
          {{ $t('common.ui.action.close') }}
        </button>
      </div>
    </div>
  </section>
</template>
