<script setup lang="ts">
const route = useRoute()
const vendorId = computed(() => Number(route.params.vendorId))
</script>

<template>
  <GamesStatement :vendor-id="vendorId" />
</template>
