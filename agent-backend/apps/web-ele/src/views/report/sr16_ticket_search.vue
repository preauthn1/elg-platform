<script lang="ts" setup>
import LotteryTicketSearch from './components/lottery-ticket-search.vue';

defineOptions({ name: 'ReportSr16TicketSearch' });
</script>

<template>
  <LotteryTicketSearch
    report-key="menu_report_sr16"
    title-key="menu_sr16_ticket_search"
    variant="sr16"
  />
</template>
