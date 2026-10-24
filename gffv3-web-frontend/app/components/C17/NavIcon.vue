<script setup lang="ts">
/** 底部分頁圖示，素材來自設計稿匯出（public/skins/c17/nav/）。 */
defineProps<{
  name: string
  /** 中間錢包那顆是彩色圓鈕，素材本身已含 WALLET 字樣 */
  fab?: boolean
}>()
</script>

<template>
  <img
    :src="`/skins/c17/nav/${name}.webp`"
    alt=""
    class="size-full object-contain"
    :class="fab ? 'drop-shadow' : ''"
  >
</template>
