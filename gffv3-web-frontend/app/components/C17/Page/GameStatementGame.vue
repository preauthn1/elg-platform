<script setup lang="ts">
const route = useRoute()
const vendorId = computed(() => Number(route.params.vendorId))
const gameId = computed(() => Number(route.params.gameId))
</script>

<template>
  <GamesStatement
    :vendor-id="vendorId"
    :game-id="gameId"
  />
</template>
