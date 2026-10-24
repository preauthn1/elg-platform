<script setup lang="ts">
/** 找回支付密碼。邏輯在 useResetPaymentPassword，畫面在 components/<皮>/Page/MemberResetPaymentPassword.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="MemberResetPaymentPassword" />
</template>
