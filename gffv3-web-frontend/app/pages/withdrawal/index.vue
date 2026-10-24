<script setup lang="ts">
/** 提款入口。流程在 useWithdrawalEntry，畫面在 components/<皮>/Page/Withdrawal.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="Withdrawal" />
</template>
