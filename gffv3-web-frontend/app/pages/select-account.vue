<script setup lang="ts">
/** 畫面在 components/<皮>/Page/SelectAccount.vue，邏輯在 useSelectAccountPage。 */
const store = useAuthStore()

if (store.accountList.length === 0 || !store.resetContext) {
  await navigateTo('/forget-password', { replace: true })
}
</script>

<template>
  <SkinPage name="SelectAccount" />
</template>
