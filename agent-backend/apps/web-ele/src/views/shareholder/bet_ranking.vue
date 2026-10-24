<script lang="ts" setup>
import type { ShareholderBetRankingRow } from '#/api';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getShareholderBetRankingApi,
  shareholderRankingCancelApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'ShareholderBetRanking' });

interface RankRow extends ShareholderBetRankingRow {
  rankLabel: string;
}

const loading = ref(false);
const rankData = ref<ShareholderBetRankingRow[]>([]);
const prohibitData = ref<ShareholderBetRankingRow[]>([]);
const payoutRecordId = ref<number | string>('');

/** rank 依序編號、prohibit 顯示 - */
const tableRows = computed<RankRow[]>(() => {
  const out: RankRow[] = [];
  let n = 1;
  for (const r of rankData.value) out.push({ ...r, rankLabel: String(n++) });
  for (const r of prohibitData.value) out.push({ ...r, rankLabel: '-' });
  return out;
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getShareholderBetRankingApi();
    if (Number(res.code) === 1000 && res.data) {
      rankData.value = res.data.rank ?? [];
      prohibitData.value = res.data.prohibit ?? [];
      payoutRecordId.value = res.data.payout_record?.id ?? '';
    } else {
      rankData.value = [];
      prohibitData.value = [];
      if (res.message) ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

/** status 1=已派發→可改禁止計算(0)，其餘→可計算(1) */
async function toggleCancel(row: ShareholderBetRankingRow) {
  const next = Number(row.status) === 1 ? 0 : 1;
  const res = await shareholderRankingCancelApi({
    bet: row.bet ?? 0,
    date: row.summary_date ?? '',
    id: row.id,
    payout_record_id: payoutRecordId.value,
    status: next,
  });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
  } else if (Number(res.code) === 2002) {
    ElMessage.error(String(res.data));
  } else {
    ElMessage.error(res.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('menu_shareholder')} > ${tl('menu_bet_ranking')}`">
    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="tableRows" border size="small" stripe>
        <ElTableColumn :label="tl('ranking')" align="center" width="80">
          <template #default="{ row }">{{ row.rankLabel }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('performance_date')"
          min-width="120"
          prop="summary_date"
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
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="140"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              :type="Number(row.status) === 1 ? 'danger' : 'success'"
              size="small"
              @click="toggleCancel(row as ShareholderBetRankingRow)"
            >
              {{
                Number(row.status) === 1
                  ? tl('prohibit_calculate')
                  : tl('can_calculate')
              }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>
  </Page>
</template>
