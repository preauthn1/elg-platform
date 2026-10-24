<script setup lang="ts">
/** 投注紀錄。邏輯在 useBetRecordList，畫面在 components/<皮>/Page/RecordBet.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="RecordBet" />
</template>
