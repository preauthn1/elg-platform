<script setup lang="ts">
/** 個人資料頁。邏輯在 useMemberCenter，畫面在 components/<皮>/Page/Member.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="Member" />
</template>
