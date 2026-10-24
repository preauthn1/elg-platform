<script setup lang="ts">
/** 錢包紀錄（邏輯在 useWalletHistories）。畫面在 components/<皮>/Page/WalletRecords.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="WalletRecords" />
</template>
