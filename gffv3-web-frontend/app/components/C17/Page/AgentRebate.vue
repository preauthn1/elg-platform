<script setup lang="ts">
/** 三級分銷返利頁：提領總覽＋申請（three-hierarchy/withdrawals*）與返利規則（three-hierarchy/rule）。 */
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('agent.rules.title.regulation')"
      fallback="/agent"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-4) px-(--c17-pad-x) py-(--c17-gap-4)">
      <AgentWithdrawalPanel />
      <AgentRebateRules />
      <UiButton
        variant="secondary"
        @click="navigateTo('/agent')"
      >
        {{ $t('events.invite.action.inviteFriends') }}
      </UiButton>
    </div>
  </div>
</template>
