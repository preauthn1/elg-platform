<script lang="ts" setup>
import type { EventProgressiveJackpotDepositLogRow } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getEventProgressiveJackpotDepositLogApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'EventProgressiveJackpotDepositLogDialog' });

const PER_PAGE = 20;

const visible = ref(false);
const loading = ref(false);
const memberId = ref<number | string>(0);
const level = ref<number | string>(0);
const list = ref<EventProgressiveJackpotDepositLogRow[]>([]);
const page = ref(1);
const total = ref(0);

async function load() {
  loading.value = true;
  try {
    const res = await getEventProgressiveJackpotDepositLogApi({
      level: level.value,
      member_id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
    total.value = res.pagination?.total ?? 0;
  } finally {
    loading.value = false;
  }
}

function open(id: number | string, tierLevel: number | string) {
  memberId.value = id;
  level.value = tierLevel;
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
    :title="tl('event_progressive_jackpot_deposit_detail')"
    width="760"
    align-center
  >
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('ordernum')" prop="order_no" min-width="170" />
      <ElTableColumn :label="tl('time')" prop="order_time" width="170" />
      <ElTableColumn :label="tl('amount')" align="right" min-width="110">
        <template #default="{ row }">
          {{ amountThousands(row.amount, 2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('before_change')" align="right" min-width="120">
        <template #default="{ row }">
          {{ amountThousands(row.before_deposit_point, 2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('after_change')" align="right" min-width="120">
        <template #default="{ row }">
          {{ amountThousands(row.after_deposit_point, 2) }}
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
