<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/** C17 的畫面，邏輯在 useAgentAllianceShareholdersPage。 */
const {
  profit,
  applyButtonLabelKey,
  canApply,
} = useAgentAllianceShareholdersPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('agent.shareholder.title.alliance') }}
    </h1>

    <section
      v-if="profit"
      class="space-y-2 rounded border border-line bg-surface p-4 text-center text-sm"
    >
      <div class="text-muted">
        {{ profit.profit_time }} {{ $t('agent.shareholder.label.totalDividends') }}
      </div>
      <div class="text-2xl font-semibold">
        {{ decFormat(profit.total_profit) }}
      </div>
    </section>

    <div class="flex flex-wrap gap-2 text-sm">
      <NuxtLink
        v-if="canApply"
        to="/agent/apply-shareholder"
        class="rounded bg-accent px-4 py-2 text-inverse"
      >
        {{ $t(applyButtonLabelKey) }}
      </NuxtLink>
      <button
        v-else
        type="button"
        class="rounded border border-line px-4 py-2 opacity-60"
        disabled
      >
        {{ $t(applyButtonLabelKey) }}
      </button>
    </div>
  </div>
</template>
