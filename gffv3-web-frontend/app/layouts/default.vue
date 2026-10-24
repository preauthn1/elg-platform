<script setup lang="ts">
/**
 * 全站唯一的版面，外殼依站台的皮選（components/<皮>/Shell.vue，見 app/utils/skin.ts）。
 *
 * 不要再開第二個版面：Nuxt 在兩個版面之間切頁會把外殼整個卸載重建，
 * 頂部列、下載列、底部分頁的狀態全部重來，餘額也會重打一次。
 * 同一個站台的皮在切頁時不會變，所以在這裡依皮選外殼不會造成重掛。
 * 需要滿版的頁面用 definePageMeta({ fullBleed: true })，
 * 完全不要外殼的頁面（維護頁、遊戲進行中）用 layout: false。
 *
 * 舊的開發用寬版面保留在 layouts/plain.vue，除錯時可以臨時指定。
 */
</script>

<template>
  <SkinShell>
    <slot />
  </SkinShell>
</template>
