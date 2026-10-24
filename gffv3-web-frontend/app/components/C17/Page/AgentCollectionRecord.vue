<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/** C17 的畫面，邏輯在 useAgentCollectionRecordPage。 */
const {
  history,
  historyPage,
  load,
} = useAgentCollectionRecordPage()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">
      {{ $t('agent.commission.title.record') }}
    </h1>
    <section class="space-y-3 rounded border border-line bg-surface p-4 text-sm">
      <table class="w-full text-left">
        <thead>
          <tr class="border-b border-line text-muted">
            <th class="py-2">
              {{ $t('common.time.label.date') }}
            </th>
            <th class="py-2 text-right">
              {{ $t('common.amount.label.amount') }}
            </th>
            <th class="py-2">
              {{ $t('common.ui.label.status') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in history"
            :key="row.no"
            class="border-b border-line"
          >
            <td class="py-2">
              {{ row.date }}
            </td>
            <td class="py-2 text-right">
              {{ decFormat(row.amount) }}
            </td>
            <td class="py-2">
              {{ row.status }}
            </td>
          </tr>
          <tr v-if="!history.length">
            <td
              colspan="3"
              class="py-2 text-center text-muted"
            >
              {{ $t('common.ui.empty.noData') }}
            </td>
          </tr>
        </tbody>
      </table>
      <AgentPagination
        :page="historyPage"
        @change="load"
      />
    </section>
    <NuxtLink
      to="/agent"
      class="inline-block text-sm text-accent underline"
    >
      {{ $t('events.invite.action.inviteFriends') }}
    </NuxtLink>
  </div>
</template>
