<script setup lang="ts">
/** IP 變更登入驗證開關。邏輯在 useIpChangeVerification，畫面在 components/<皮>/Page/MemberIpChangeVerification.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberIpChangeVerification" />
</template>
