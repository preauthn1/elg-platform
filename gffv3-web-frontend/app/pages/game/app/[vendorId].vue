<script setup lang="ts">
/** APP 類遊戲商場館。邏輯在 useVendorApp，畫面在 components/<皮>/Page/GameApp.vue。 */
// 遊戲商內頁，頂部用有返回鍵的列
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="GameApp" />
</template>
