<script setup lang="ts">
/** 畫面在 components/<皮>/Page/TwoFactorAuthentication.vue，邏輯在 useTwoFactorAuthenticationPage。 */
const store = useAuthStore()

if (!store.pendingLogin || store.pendingLogin.options.length === 0) {
  await navigateTo('/login', { replace: true })
}
</script>

<template>
  <SkinPage name="TwoFactorAuthentication" />
</template>
