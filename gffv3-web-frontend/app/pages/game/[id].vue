<script setup lang="ts">
/** 遊戲進行中。進場順序在 useGamePlaying，畫面在 components/<皮>/Page/GamePlaying.vue。 */
// 遊戲頁自己畫頂部那條列、沒有底部分頁，所以不套外殼
definePageMeta({ requiresAuth: true, layout: false })
</script>

<template>
  <SkinPage name="GamePlaying" />
</template>
