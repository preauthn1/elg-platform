<script setup lang="ts">
const route = useRoute()
const issueId = computed(() => Number(route.params.id))
</script>

<template>
  <LotteryIssueDetail
    variant="wjackpot"
    :issue-id="issueId"
  />
</template>
