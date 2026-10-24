<script lang="ts" setup>
import type { LotteryVariant, LotteryWinningRow } from '#/api';

import { ref } from 'vue';

import { ElButton, ElDialog, ElTable, ElTableColumn } from 'element-plus';

import { getLotteryWinningListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

const props = defineProps<{ variant: LotteryVariant }>();

const visible = ref(false);
const loading = ref(false);
const list = ref<LotteryWinningRow[]>([]);
/** 開窗的期數，匯出稽核紀錄用 */
const currentPeriodId = ref<number | string>('');

/** 實物獎（prize_type 2）顯示原值，否則整數千分位 */
const bonusText = (row: LotteryWinningRow) =>
  Number(row.prize_type) === 2
    ? String(row.point ?? '')
    : amountThousands(row.point, 0);

async function open(periodId: number | string) {
  visible.value = true;
  loading.value = true;
  list.value = [];
  currentPeriodId.value = periodId;
  try {
    list.value =
      (await getLotteryWinningListApi(props.variant, periodId)) ?? [];
  } finally {
    loading.value = false;
  }
}

const exporting = ref(false);
/** 匯出 Excel：欄位/17WinningList（4 欄） */
async function handleExport() {
  exporting.value = true;
  try {
    await exportExcel(
      `${props.variant}_winning_list_${Date.now()}`,
      [
        tl('agent_name'),
        tl('member_name'),
        tl('lottery_ticket_no'),
        tl('bonus'),
      ],
      list.value.map((r) => [
        r.agent_username,
        r.member_username,
        r.ticket_number,
        bonusText(r),
      ]),
      {
        audit: {
          // 期數列表頁自己也有匯出，action 要分得開
          action: 'lottery_winning_list',
          conditions: { period_id: currentPeriodId.value },
        },
        title: tl('winning_list'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('winning_list')" width="800">
    <div class="mb-2 flex justify-end">
      <ElButton
        :disabled="list.length === 0"
        :loading="exporting"
        size="small"
        type="success"
        @click="handleExport"
      >
        {{ tl('excel') }}
      </ElButton>
    </div>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn
        :label="tl('agent_name')"
        prop="agent_username"
        min-width="120"
      >
        <template #default="{ row }">
          <AgentAccount :username="row.agent_username" />
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('member_name')" min-width="120">
        <template #default="{ row }">
          <MemberAccountLink :username="row.member_username" />
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('lottery_ticket_no')"
        prop="ticket_number"
        min-width="140"
      />
      <ElTableColumn :label="tl('bonus')" align="right" min-width="120">
        <template #default="{ row }">{{ bonusText(row) }}</template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
  </ElDialog>
</template>
