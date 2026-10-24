<script setup lang="ts">
/** 修改登入密碼。邏輯在 useChangePasswordForm，畫面在 components/<皮>/Page/MemberChangePassword.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberChangePassword" />
</template>
