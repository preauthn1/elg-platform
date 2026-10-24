<script setup lang="ts">
import { formatEventAmount } from '~/utils/jackpot'
import { lotteryPagePath, wjackpotResultGroups, type LotteryVariant } from '~/utils/lottery'

const props = defineProps<{ variant: LotteryVariant, issueId: number }>()

const lottery = useLottery(props.variant)
const { detail, loading, canReceiveAll, config } = lottery

const resultGroups = computed(() => (detail.value && config.resultGroups ? wjackpotResultGroups(detail.value) : []))

onMounted(() => {
  void lottery.fetchDetail(props.issueId)
})
onBeforeUnmount(() => lottery.dispose())
</script>

<template>
  <section class="space-y-4">
    <NuxtLink
      :to="lotteryPagePath(variant, 'issues')"
      class="text-sm text-accent"
    >
      ← {{ $t('events.luckyDraw.label.myRaffleTicket') }}
    </NuxtLink>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <div
      v-if="detail"
      class="space-y-4"
    >
      <h1 class="text-xl font-semibold">
        {{ detail.issue_name }}
      </h1>

      <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt class="text-muted">
          {{ $t('events.detail.label.eventPeriod') }}
        </dt>
        <dd>{{ detail.begin_time }} ~ {{ detail.end_time }}</dd>
      </dl>

      <div class="rounded border border-line bg-surface p-4">
        <h2 class="font-semibold">
          {{ $t('events.luckyDraw.label.winningNumber') }}
        </h2>
        <ul
          v-if="resultGroups.length"
          class="mt-2 space-y-1 font-mono"
        >
          <li
            v-for="group in resultGroups"
            :key="group.key"
          >
            <span class="text-sm text-muted">{{ $t(group.key, { prize: '' }) }}</span>
            <span class="ml-2">{{ group.value }}</span>
          </li>
        </ul>
        <p
          v-else
          class="mt-2 font-mono text-lg tracking-widest"
        >
          {{ detail.result_number }}
        </p>
      </div>

      <div class="rounded border border-line bg-surface p-4">
        <h2 class="font-semibold">
          {{ $t('events.luckyDraw.label.myRaffleTicket') }}
        </h2>
        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt class="text-muted">
            {{ $t('events.luckyDraw.label.ticketCount') }}
          </dt>
          <dd>{{ detail.summary.totalCount_tickets }}</dd>
          <dt class="text-muted">
            {{ $t('events.luckyDraw.label.totalWinning') }}
          </dt>
          <dd>{{ formatEventAmount(detail.summary.totalCount_winnings) }}</dd>
          <dt class="text-muted">
            {{ $t('events.luckyDraw.label.unclaimedPrize') }}
          </dt>
          <dd>{{ formatEventAmount(detail.summary.totalCount_unclaimed) }}</dd>
        </dl>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded bg-accent px-4 py-2 text-inverse disabled:opacity-40"
            :disabled="!canReceiveAll"
            @click="lottery.receiveAll(issueId)"
          >
            {{ $t('events.luckyDraw.action.claimAll') }}
          </button>
          <NuxtLink
            :to="lotteryPagePath(variant, 'tickets', issueId)"
            class="rounded border border-accent px-4 py-2 text-accent"
          >
            {{ $t('events.luckyDraw.nav.lotteryTicket') }}
          </NuxtLink>
        </div>
      </div>
    </div>
    <p
      v-else-if="!loading"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
  </section>
</template>
