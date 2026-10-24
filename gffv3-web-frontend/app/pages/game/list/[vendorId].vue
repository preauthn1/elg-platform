<script setup lang="ts">
/** 遊戲商的遊戲列表。邏輯在 useVendorGameList，畫面在 components/<皮>/Page/GameList.vue。 */
// 從大廳點進來的內頁，頂部用有返回鍵的列，不用首頁那條 logo 列
definePageMeta({ fullBleed: true, hideAppHeader: true })
</script>

<template>
  <SkinPage name="GameList" />
</template>
