<script setup lang="ts">
/** 真人遊戲進場前的贏分上限聲明。邏輯在 useLiveStatement，畫面在 components/<皮>/Page/GameLiveStatement.vue。 */
definePageMeta({ requiresAuth: true, fullBleed: true, hideAppHeader: true })

// 沒有選好的遊戲（例如重新整理）就回首頁
const store = useGamesStore()
const game = computed(() => store.selectedGame)

if (!game.value) {
  await navigateTo('/', { replace: true })
}
</script>

<template>
  <SkinPage name="GameLiveStatement" />
</template>
