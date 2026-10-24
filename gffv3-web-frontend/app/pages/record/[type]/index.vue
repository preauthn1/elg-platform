<script setup lang="ts">
/** 紀錄列表。邏輯在 useRecordList，畫面在 components/<皮>/Page/RecordList.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="RecordList" />
</template>
