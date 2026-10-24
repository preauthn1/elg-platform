<script setup lang="ts">
/** 線上存款結果（QR code）。畫面在 components/<皮>/Page/DepositQrcode.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })

// 沒有結果（例如重新整理）就回存款頁
const { result } = useDepositResult()
onMounted(async () => {
  if (!result.value?.qrPayload) await navigateTo('/topup', { replace: true })
})
</script>

<template>
  <SkinPage name="DepositQrcode" />
</template>
