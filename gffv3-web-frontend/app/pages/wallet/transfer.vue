<script setup lang="ts">
/** 主錢包／遊戲錢包互轉（邏輯在 useWalletTransfer）。畫面在 components/<皮>/Page/WalletTransfer.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="WalletTransfer" />
</template>
