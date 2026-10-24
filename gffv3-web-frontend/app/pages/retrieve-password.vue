<script setup lang="ts">
/** 畫面在 components/<皮>/Page/RetrievePassword.vue，邏輯在 useRetrievePasswordPage。 */
const store = useAuthStore()

if (!store.retrieve) {
  await navigateTo('/login', { replace: true })
}
</script>

<template>
  <SkinPage name="RetrievePassword" />
</template>
