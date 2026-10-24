<script lang="ts" setup>
import type { ShareholderRankRow } from '#/api';

import { computed, ref, watch } from 'vue';

import {
  ElButton,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getShareholderRankListApi, shareholderRankingCancelApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'ShareholderRankListDialog' });

const props = withDefaults(
  defineProps<{
    id: number | string;
    payoutRecordId: number | string;
    /** 派發狀態，只有 0/3 才能改「禁止計算 / 可計算」 */
    payoutStatus?: number | string;
    summaryDate: string;
  }>(),
  { payoutStatus: 0 },
);
const visible = defineModel<boolean>({ default: false });

const canToggle = computed(() => [0, 3].includes(Number(props.payoutStatus)));

interface DisplayRow extends ShareholderRankRow {
  rank: number | string;
}

const loading = ref(false);
const rows = ref<DisplayRow[]>([]);
const payoutId = ref<number | string>('');

async function load() {
  loading.value = true;
  try {
    const res = await getShareholderRankListApi({
      id: props.id,
      payout_record_id: props.payoutRecordId,
      summary_date: props.summaryDate,
    });
    if (Number(res.code) === 1000) {
      const d = res.data ?? {};
      payoutId.value = d.payout_record?.id ?? props.payoutRecordId;
      const rankRows = (d.rank ?? []).map((r, i) => ({ ...r, rank: i + 1 }));
      const prohibitRows = (d.prohibit ?? []).map((r) => ({ ...r, rank: '-' }));
      rows.value = [...rankRows, ...prohibitRows];
    } else {
      rows.value = [];
      ElMessage.error(res.message);
    }
  } finally {
    loading.value = false;
  }
}

async function updateCancel(row: DisplayRow) {
  const nextStatus = Number(row.status) === 1 ? 0 : 1;
  try {
    const res = await shareholderRankingCancelApi({
      bet: row.bet ?? 0,
      date: row.summary_date ?? '',
      id: row.id,
      payout_record_id: payoutId.value,
      status: nextStatus,
    });
    if (Number(res.code) === 1000) {
      await load();
    } else if (String(res.code) === '2002') {
      ElMessageBox.alert(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message);
    }
  } catch {
    /* network error surfaced by interceptor */
  }
}

watch(visible, (v) => {
  if (v) load();
});
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${summaryDate} ${tl('menu_bet_ranking')}`"
    width="800px"
  >
    <ElTable v-loading="loading" :data="rows" border size="small" stripe>
      <ElTableColumn :label="tl('ranking')" prop="rank" width="80" />
      <ElTableColumn
        :label="tl('performance_date')"
        prop="summary_date"
        min-width="120"
      />
      <ElTableColumn :label="tl('agent_account')" min-width="120">
        <template #default="{ row }">
          <AgentAccount :username="row.username" />
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('bet_amount')" align="right" min-width="120">
        <template #default="{ row }">
          {{ amountThousands(row.bet, 2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('features')" min-width="140">
        <template #default="{ row }">
          <template v-if="canToggle">
            <ElButton
              v-if="Number(row.status) === 1"
              type="danger"
              @click="updateCancel(row as DisplayRow)"
            >
              {{ tl('prohibit_calculate') }}
            </ElButton>
            <ElButton
              v-else
              type="success"
              @click="updateCancel(row as DisplayRow)"
            >
              {{ tl('can_calculate') }}
            </ElButton>
          </template>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
