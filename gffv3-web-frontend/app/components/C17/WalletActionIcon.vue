<script setup lang="ts">
/**
 * 錢包列的三顆入口圖示，素材來自設計稿匯出（public/skins/c17/action/）。
 *
 * 素材是**純圖示、裡面不含文字**（設計師重切過，舊版印死了 DEPOSIT／LIVECHAT／SHARE，
 * 14 個語系換不了字）。白底圓角、陰影與 icon 底下那行字都由 C17WalletBar 排，
 * 所以這裡 alt 一律空字串，避免讀屏把同一個標籤唸兩次。
 */
defineProps<{ name: string }>()
</script>

<template>
  <img
    :src="`/skins/c17/action/${name}.webp`"
    alt=""
    class="size-full object-contain"
  >
</template>
