<script lang="ts" setup>
import type { WinLoseDetailRow, WinLoseRankingPath } from '#/api';

import { ref } from 'vue';

import { ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getWinLoseDetailApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'WinLoseDetailDialog' });

const visible = ref(false);
const loading = ref(false);
const rows = ref<WinLoseDetailRow[]>([]);
const accountLabel = ref('');
const username = ref('');
const rangeText = ref('');

const fmt = (v?: number | string) => amountThousands(v, 2);

async function open(
  path: WinLoseRankingPath,
  row: { id: number | string; username: string },
  params: {
    end_time: string;
    start_time: string;
    type: number | string;
    vendor_id: number | string;
  },
) {
  accountLabel.value =
    path === 'member' ? tl('member_account') : tl('agent_account');
  username.value = row.username;
  rangeText.value = `${params.start_time} ~ ${params.end_time}`;
  rows.value = [];
  visible.value = true;
  loading.value = true;
  try {
    const res = await getWinLoseDetailApi(path, {
      ...params,
      ...(path === 'member' ? { member_id: row.id } : { agent_id: row.id }),
    });
    rows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('detailinfo')"
    width="700"
    align-center
  >
    <div class="mb-3 space-y-1 text-sm font-medium">
      <div>{{ accountLabel }}: {{ username }}</div>
      <div>{{ tl('date_range') }}: {{ rangeText }}</div>
    </div>
    <ElTable v-loading="loading" :data="rows" border size="small" stripe>
      <ElTableColumn
        :label="tl('vendor_name')"
        min-width="140"
        prop="vendor_name"
      />
      <ElTableColumn :label="tl('total_bet')" align="right" min-width="120">
        <template #default="{ row }">
          <span
            :class="
              Number(row.bet_amount) < 0 ? 'text-red-500' : 'text-blue-500'
            "
          >
            {{ fmt(row.bet_amount) }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('total_win_lose')"
        align="right"
        min-width="120"
      >
        <template #default="{ row }">
          <span
            :class="
              Number(row.win_lose_amount) < 0 ? 'text-red-500' : 'text-blue-500'
            "
          >
            {{ fmt(row.win_lose_amount) }}
          </span>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
