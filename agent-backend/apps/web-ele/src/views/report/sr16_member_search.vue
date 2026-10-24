<script lang="ts" setup>
import LotteryMemberSearch from './components/lottery-member-search.vue';

defineOptions({ name: 'ReportSr16MemberSearch' });
</script>

<template>
  <LotteryMemberSearch
    report-key="menu_report_sr16"
    title-key="menu_sr16_member_search"
    variant="sr16"
  />
</template>
