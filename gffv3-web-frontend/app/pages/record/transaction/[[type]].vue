<script setup lang="ts">
/** 交易紀錄。邏輯在 useTransactionRecordList，畫面在 components/<皮>/Page/RecordTransaction.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="RecordTransaction" />
</template>
