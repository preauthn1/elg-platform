<script lang="ts" setup>
import { paySettlementReportApi } from '#/api';
import { tl } from '#/locales';

import PeriodActionCard from './components/period-action-card.vue';

defineOptions({ name: 'SettlementReportPayout' });

function submit(period: number | string) {
  return paySettlementReportApi(period) as Promise<{
    code: number;
    message: string;
  }>;
}
</script>

<template>
  <PeriodActionCard
    :label="tl('date_to_payout')"
    :remark="tl('settlement_payout_remark')"
    :submit="submit"
    :title="`${tl('menu_settlement_report')} > ${tl('menu_settlement_report_payout')}`"
  />
</template>
