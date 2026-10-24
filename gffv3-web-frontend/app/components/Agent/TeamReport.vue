<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/** C16 代理中心 Report 分頁：member-team/agent-achievement，進入即載入。 */
const { report, fetchReport } = useMemberTeam()

const rows = computed(() => {
  const r = report.value
  if (!r) return []
  return [
    { key: 'agent.performance.title.currentDirect', value: r.directPlayersAchievement },
    { key: 'agent.performance.title.currentTeam', value: r.teamAchievement },
    { key: 'agent.performance.title.currentTotal', value: r.totalAchievement },
    { key: 'agent.commission.label.lastCommission', value: r.lastpPeriodCommission },
    { key: 'agent.commission.label.totalHistorical', value: r.historicalTotalCommission },
  ]
})

onMounted(async () => {
  await fetchReport()
})
</script>

<template>
  <section class="space-y-3 rounded border border-line bg-surface p-4 text-sm">
    <h3 class="font-semibold">
      {{ $t('agent.common.nav.report') }}
    </h3>
    <dl class="divide-y divide-line">
      <div
        v-for="row in rows"
        :key="row.key"
        class="flex justify-between py-2"
      >
        <dt class="text-muted">
          {{ $t(row.key) }}
        </dt>
        <dd class="font-semibold">
          {{ decFormat(row.value) }}
        </dd>
      </div>
    </dl>
  </section>
</template>
