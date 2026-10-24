<script setup lang="ts">
/** 綁定提款帳號。流程在 useBindBankCard，畫面在 components/<皮>/Page/BindBankCard.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="BindBankCard" />
</template>
