<script lang="ts" setup>
import LotteryMemberSearch from './components/lottery-member-search.vue';

defineOptions({ name: 'ReportSr17MemberSearch' });
</script>

<template>
  <LotteryMemberSearch
    report-key="menu_report_sr17"
    title-key="menu_sr17_member_search"
    variant="sr17"
  />
</template>
