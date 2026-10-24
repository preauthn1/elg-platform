<script lang="ts" setup>
import { rerunSettlementReportApi } from '#/api';
import { tl } from '#/locales';

import PeriodActionCard from './components/period-action-card.vue';

defineOptions({ name: 'SettlementReportRerun' });

function submit(period: number | string) {
  return rerunSettlementReportApi(period) as Promise<{
    code: number;
    message: string;
  }>;
}
</script>

<template>
  <PeriodActionCard
    :label="tl('date_to_recalculate')"
    :remark="tl('settlement_rerun_remark')"
    :submit="submit"
    :title="`${tl('menu_settlement_report')} > ${tl('menu_settlement_report_rerun')}`"
  />
</template>
