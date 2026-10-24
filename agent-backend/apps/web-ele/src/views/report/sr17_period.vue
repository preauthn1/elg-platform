<script lang="ts" setup>
import LotteryPeriod from './components/lottery-period.vue';

defineOptions({ name: 'ReportSr17Period' });
</script>

<template>
  <LotteryPeriod
    report-key="menu_report_sr17"
    title-key="menu_sr17_period"
    variant="sr17"
  />
</template>
