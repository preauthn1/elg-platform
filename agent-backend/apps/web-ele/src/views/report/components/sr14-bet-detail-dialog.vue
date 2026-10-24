<script lang="ts" setup>
import type { BetDetailRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getBetDetailApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'Sr14BetDetailDialog' });

const PER_PAGE = 10;

const visible = ref(false);
const loading = ref(false);
const txId = ref<number | string>('');
const startTime = ref('');
const endTime = ref('');
const page = ref(1);
const total = ref(0);
const rows = ref<Array<BetDetailRow & { __label?: string }>>([]);

const fmt = (v?: number | string) =>
  Number.isFinite(Number(v)) ? amountThousands(v, 2) : (v ?? '-');

async function load() {
  loading.value = true;
  try {
    const res: any = await getBetDetailApi({
      end_time: toApiTime(endTime.value),
      page: page.value,
      per_page: PER_PAGE,
      start_time: toApiTime(startTime.value),
      transaction_id: txId.value,
      // SR14 的 transaction_id 屬主錢包 transaction_logs，缺這參數後端會
      // 誤查 wallet_transaction_logs 而 500（對齊舊版 sr14_bet_detail.php，target/tickets#4160）
      wallet_type: 1,
    });
    if (Number(res?.code) === 1000) {
      const data: BetDetailRow[] = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
      const sub = res.subtotal ?? {};
      const stat = res.statistics ?? {};
      const summaryRow = (label: string, t: any) => ({
        __label: label,
        bonus: t.total_bonus,
        disburse_award: t.total_disburse_award,
        point: t.total_point,
        point_win_lose: t.total_point_win_lose,
        rolling_chip: t.total_rolling_chip,
        rolling_chip_win_lose: t.total_rolling_chip_win_lose,
        valid_bet: t.total_valid_bet,
        win_lose: t.total_win_lose,
      });
      rows.value =
        data.length > 0
          ? [
              ...data,
              summaryRow(tl('local_page_subtotal'), sub),
              summaryRow(tl('full_page_total'), stat),
            ]
          : [];
    } else {
      rows.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function open(transactionId: number | string, start: string, end: string) {
  txId.value = transactionId;
  startTime.value = start;
  endTime.value = end;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('transaction_type.27')"
    width="1100"
    align-center
  >
    <ElTable v-loading="loading" :data="rows" border size="small" stripe>
      <ElTableColumn :label="tl('bet_time')" min-width="150">
        <template #default="{ row }">
          {{ row.__label ? '' : fromApiTime(row.bet_time) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('ordernumber')" min-width="140">
        <template #default="{ row }">
          <b v-if="row.__label">{{ row.__label }}：</b>
          <span v-else>{{ row.bet_record_id }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('point_bet')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.point) }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('rolling_chip_bet')"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.rolling_chip) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('valid_bet')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.valid_bet) }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('disburse_award')"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.disburse_award) }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('point_win_lose')"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.point_win_lose) }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('rolling_chip_win_lose')"
        align="right"
        min-width="120"
      >
        <template #default="{ row }">
          {{ fmt(row.rolling_chip_win_lose) }}
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('total_win_lose')"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.win_lose) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('event_bonus')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.bonus) }}</template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
