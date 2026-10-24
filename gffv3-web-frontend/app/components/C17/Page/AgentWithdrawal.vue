<script setup lang="ts">
/** 我的提領：總覽／紀錄／申請都在 AgentWithdrawalPanel，這裡只放標題與導覽。 */
const { isThree } = useAgentMode()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('agent.withdrawal.title.apply')"
      fallback="/agent"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <AgentWithdrawalPanel />
      <UiButton
        variant="secondary"
        @click="navigateTo('/agent/promote')"
      >
        {{ $t(isThree ? 'agent.center.label.myRebate' : 'agent.center.label.myPromote') }}
      </UiButton>
    </div>
  </div>
</template>
