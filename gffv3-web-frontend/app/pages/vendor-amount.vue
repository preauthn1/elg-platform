<script setup lang="ts">
/** 場館前頁（站台開 has_game_provider_front_page 才有）。邏輯在 useVendorAmount，畫面在 components/<皮>/Page/VendorAmount.vue。 */
definePageMeta({
  requiresAuth: true,
  fullBleed: true,
  hideAppHeader: true,
  /**
   * 旗標關著的站台不該有這一頁。舊專案只在入口（VendorListGrid.openVendor）判旗標，
   * 路由本身沒擋，所以旗標中途被關掉時，用上一頁回到這裡還是進得來
   * （store 裡的場館還在）。這裡補上守衛，讓頁面本身也吃 has_game_provider_front_page。
   */
  middleware: () => {
    if (!useSite().flag('has_game_provider_front_page')) return navigateTo('/', { replace: true })
  },
})
</script>

<template>
  <SkinPage name="VendorAmount" />
</template>
