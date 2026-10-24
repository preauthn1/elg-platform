<script setup lang="ts">
/** C17 的畫面，邏輯在 useAgentRulesPage。 */
const {
  params,
  levelColumns,
  levelRows,
  vendorColumns,
  vendorTableRows,
} = useAgentRulesPage()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('agent.rules.title.regulation')"
      fallback="/agent"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <p
        class="font-bold"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ $t('agent.rules.hint.slogan', params) }}
      </p>

      <div class="overflow-x-auto">
        <UiTable
          :columns="levelColumns"
          :rows="levelRows"
          class="min-w-[450px]"
        />
      </div>

      <UiTable
        :columns="vendorColumns"
        :rows="vendorTableRows"
      />

      <UiButton
        variant="secondary"
        @click="navigateTo('/agent')"
      >
        {{ $t('events.invite.action.inviteFriends') }}
      </UiButton>
    </div>
  </div>
</template>
