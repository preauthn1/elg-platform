<script setup lang="ts">
/** 輸贏報表。邏輯在 useWinLossReport，畫面在 components/<皮>/Page/RecordWinLoss.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="RecordWinLoss" />
</template>
