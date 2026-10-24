<script setup lang="ts">
/** 畫面在 components/<皮>/Page/SetPassword.vue，邏輯在 useSetPasswordPage。 */
definePageMeta({ fullBleed: true })

const store = useAuthStore()

if (!store.resetContext) {
  await navigateTo('/forget-password', { replace: true })
}
else if (!store.resetContext.username) {
  await navigateTo('/select-account', { replace: true })
}
</script>

<template>
  <SkinPage name="SetPassword" />
</template>
