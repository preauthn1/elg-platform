<script setup lang="ts">
/** 線上存款結果（圖片）。畫面在 components/<皮>/Page/DepositImage.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })

// 沒有結果（例如重新整理）就回存款頁
const { result } = useDepositResult()
onMounted(async () => {
  if (!result.value?.url) await navigateTo('/topup', { replace: true })
})
</script>

<template>
  <SkinPage name="DepositImage" />
</template>
