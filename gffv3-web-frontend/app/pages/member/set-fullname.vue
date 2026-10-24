<script setup lang="ts">
/** 設定真實姓名。邏輯在 useSetFullname，畫面在 components/<皮>/Page/MemberSetFullname.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberSetFullname" />
</template>
