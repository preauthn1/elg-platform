<script setup lang="ts">
import { lotteryDrawDate, lotteryPagePath, type LotteryVariant } from '~/utils/lottery'

const props = defineProps<{ variant: LotteryVariant }>()

const lottery = useLottery(props.variant)
const { issues, loading } = lottery

onMounted(() => {
  void lottery.fetchIssues()
})
</script>

<template>
  <section class="space-y-3">
    <h1 class="text-xl font-semibold">
      {{ $t('events.luckyDraw.label.myRaffleTicket') }}
    </h1>

    <p
      v-if="loading"
      class="text-muted"
    >
      {{ $t('common.ui.label.loading') }}
    </p>

    <ul
      v-if="issues.length"
      class="divide-y divide-line rounded border border-line bg-surface"
    >
      <li
        v-for="issue in issues"
        :key="issue.issue_id"
      >
        <NuxtLink
          :to="lotteryPagePath(variant, 'detail', issue.issue_id)"
          class="flex items-center justify-between px-4 py-3 hover:bg-page"
        >
          <span>{{ issue.issue_name }}</span>
          <span class="text-sm text-muted">{{ $t('events.luckyDraw.label.drawDate') }} {{ lotteryDrawDate(issue.draw_time) }}</span>
        </NuxtLink>
      </li>
    </ul>
    <p
      v-else-if="!loading"
      class="text-muted"
    >
      {{ $t('common.ui.empty.noData') }}
    </p>
  </section>
</template>
