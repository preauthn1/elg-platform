<script lang="ts" setup>
import LotteryPeriod from './components/lottery-period.vue';

defineOptions({ name: 'ReportSr16Period' });
</script>

<template>
  <LotteryPeriod
    report-key="menu_report_sr16"
    title-key="menu_sr16_period"
    variant="sr16"
  />
</template>
