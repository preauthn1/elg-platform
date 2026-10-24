<script lang="ts" setup>
import type { BetDetailRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getBetDetailApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'Sr24BetDetailDialog' });

const PER_PAGE = 10;

const visible = ref(false);
const loading = ref(false);
const txId = ref<number | string>('');
const page = ref(1);
const total = ref(0);
const rows = ref<BetDetailRow[]>([]);

const fmt = (v?: number | string) =>
  Number.isFinite(Number(v)) ? amountThousands(v, 2) : (v ?? '-');

async function load() {
  loading.value = true;
  try {
    const res: any = await getBetDetailApi({
      page: page.value,
      per_page: PER_PAGE,
      transaction_id: txId.value,
      wallet_type: 2,
    });
    if (Number(res?.code) === 1000) {
      rows.value = res.data ?? [];
      total.value = res.pagination?.total ?? 0;
    } else {
      rows.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function open(transactionId: number | string) {
  txId.value = transactionId;
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
      <ElTableColumn
        :formatter="(row: any) => fromApiTime(row.bet_time)"
        :label="tl('bet_time')"
        min-width="150"
      />
      <ElTableColumn
        :label="tl('ordernumber')"
        min-width="140"
        prop="bet_record_id"
      />
      <ElTableColumn :label="tl('bet')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.bet) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('valid_bet')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.valid_bet) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('winlose')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.win_lose) }}</template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('platform_point')"
        align="right"
        min-width="110"
      >
        <template #default="{ row }">{{ fmt(row.point) }}</template>
      </ElTableColumn>
      <ElTableColumn :label="tl('rolling_chip')" align="right" min-width="110">
        <template #default="{ row }">{{ fmt(row.rolling_chip) }}</template>
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
