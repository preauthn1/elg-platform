<script setup lang="ts">
/** C17 的畫面，邏輯在 useAgentPromotePage。 */
const {
  directPlayersPage,
  rewardsPage,
  threeAchievementsPage,
  threeRebatesPage,
  fetchDirectPlayers,
  fetchRewards,
  fetchThreeAchievements,
  fetchThreeRebates,
  titleKey,
  tabItems,
  tab,
  lastUpdated,
  table,
  tableMinWidth,
} = useAgentPromotePage()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t(titleKey)"
      fallback="/agent"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <UiPillTabs
        v-model="tab"
        :items="tabItems"
      />

      <div class="overflow-x-auto">
        <UiTable
          :columns="table.columns"
          :rows="table.rows"
          :style="{ minWidth: tableMinWidth }"
        />
      </div>

      <AgentPagination
        v-if="tab === 'players'"
        :page="directPlayersPage"
        @change="fetchDirectPlayers($event, directPlayersPage.perPage)"
      />
      <AgentPagination
        v-else-if="tab === 'rewards' || tab === 'turnover'"
        :page="rewardsPage"
        @change="fetchRewards($event, rewardsPage.perPage)"
      />
      <AgentPagination
        v-else-if="tab === 'rebates'"
        :page="threeRebatesPage"
        @change="fetchThreeRebates($event, threeRebatesPage.perPage)"
      />
      <AgentPagination
        v-else-if="tab === 'achievements'"
        :page="threeAchievementsPage"
        @change="fetchThreeAchievements($event, threeAchievementsPage.perPage)"
      />

      <p
        class="text-center"
        :style="{ fontSize: 'var(--c17-text-note)', color: 'var(--c17-muted)' }"
      >
        *{{ $t('agent.promote.label.lastUpdate') }}: {{ lastUpdated }}
      </p>

      <UiButton
        variant="secondary"
        @click="navigateTo('/agent/withdrawal')"
      >
        {{ $t('agent.center.label.myWithdrawal') }}
      </UiButton>
    </div>
  </div>
</template>
