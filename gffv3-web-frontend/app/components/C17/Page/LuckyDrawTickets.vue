<script setup lang="ts">
const route = useRoute()
const issueId = computed(() => Number(route.params.id))
</script>

<template>
  <LotteryTicketList
    variant="lucky-draw"
    :issue-id="issueId"
  />
</template>
