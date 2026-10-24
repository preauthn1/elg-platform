<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'

/** 轉帳存款單據。畫面在 components/<皮>/Page/TopupReceipt.vue，邏輯在 useDepositReceipt。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })

// 沒有訂單（例如重新整理）就回會員中心
const { bankOrder } = useAutoDeposit()
onMounted(async () => {
  if (!bankOrder.value || !bankOrder.value.order_id) await navigateTo(MEMBER_PATH.home, { replace: true })
})
</script>

<template>
  <SkinPage name="TopupReceipt" />
</template>
