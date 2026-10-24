<script lang="ts" setup>
import LotteryTicketSearch from './components/lottery-ticket-search.vue';

defineOptions({ name: 'ReportSr17TicketSearch' });
</script>

<template>
  <LotteryTicketSearch
    report-key="menu_report_sr17"
    title-key="menu_sr17_ticket_search"
    variant="sr17"
  />
</template>
