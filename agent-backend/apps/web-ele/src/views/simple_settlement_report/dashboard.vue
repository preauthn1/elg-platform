<script lang="ts" setup>
import type { SimpleSettlementDashboard } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import { ElButton, ElCard, ElForm, ElFormItem, ElInput } from 'element-plus';

import { getSimpleSettlementDashboardApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { amountThousands, numberFormat } from '#/utils/format';
import { sixTypesQuickRange } from '#/utils/quick-range';

defineOptions({ name: 'SimpleSettlementDashboard' });

const filters = reactive({
  // 混合頁整頁固定 GMT+8，預設區間也釘 +8 日界
  time_range: sixTypesQuickRange('today', 8) as [string, string],
  username: '',
});

const loading = ref(false);
const loaded = ref(false);
const errorMsg = ref('');
const data = ref<null | SimpleSettlementDashboard>(null);

const title = computed(
  () =>
    `${tl('menu_simple_settlement_report')} > ${tl('menu_simple_settlement_report_dashboard')}`,
);

/** 衍生欄位（前端計算） */
const derived = computed(() => {
  const d = data.value;
  if (!d) return null;
  const winLose = Number(d.win_lose) || 0;
  const jackpot = Number(d.jackpot) || 0;
  const bonus = Number(d.bonus) || 0;
  const percentage = Number(d.percentage) || 0;
  const teamCommission = Number(d.team_commission) || 0;
  // 正規化負零（JS -0 會被 toLocaleString 顯示為 -0.00，PHP number_format 則為 0.00）
  const nz = (v: number) => (v === 0 ? 0 : v);
  const grossPnl = (winLose + jackpot) * -1;
  const netPnl = grossPnl - bonus;
  const totalCommission = netPnl * percentage;
  const commission = totalCommission - teamCommission;
  return {
    commission: nz(commission),
    gross_pnl: nz(grossPnl),
    net_pnl: nz(netPnl),
    total_commission: nz(totalCommission),
  };
});

const rows = computed(() => {
  const d = data.value;
  const dv = derived.value;
  if (!d || !dv) return [];
  return [
    {
      key: 'profit_share_percentage',
      tone: 'success',
      value: `${Number(d.percentage) * 100}%`,
    },
    {
      key: 'total_register',
      tone: 'secondary',
      value: numberFormat(d.total_reg, 0),
    },
    {
      key: 'period_register',
      tone: 'secondary',
      value: numberFormat(d.period_reg, 0),
    },
    { key: 'deposit', tone: 'primary', value: amountThousands(d.deposit, 2) },
    {
      key: 'withdrawal',
      tone: 'primary',
      value: amountThousands(d.withdraw, 2),
    },
    { key: 'bet', tone: 'primary', value: amountThousands(d.bet, 2) },
    {
      key: 'gross_pnl',
      tone: 'warning',
      value: amountThousands(dv.gross_pnl, 2),
    },
    { key: 'bonus', tone: 'secondary', value: amountThousands(d.bonus, 2) },
    { key: 'net_pnl', tone: 'warning', value: amountThousands(dv.net_pnl, 2) },
    {
      key: 'total_commission',
      tone: 'info',
      value: amountThousands(dv.total_commission, 2),
    },
    {
      key: 'team_commission',
      tone: 'info',
      value: amountThousands(d.team_commission, 2),
    },
    {
      big: true,
      key: 'commission',
      tone: 'danger',
      value: amountThousands(dv.commission, 2),
    },
  ];
});

const TONE_CLASS: Record<string, string> = {
  danger: 'border-red-400 bg-red-50 dark:bg-red-950/30',
  info: 'border-sky-400 bg-sky-50 dark:bg-sky-950/30',
  primary: 'border-blue-400 bg-blue-50 dark:bg-blue-950/30',
  secondary: 'border-gray-300 bg-gray-50 dark:bg-gray-800/40',
  success: 'border-green-400 bg-green-50 dark:bg-green-950/30',
  warning: 'border-amber-400 bg-amber-50 dark:bg-amber-950/30',
};

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  loading.value = true;
  errorMsg.value = '';
  try {
    const params: Record<string, string> = {
      end_time: `${end}:00`,
      start_time: `${start}:00`,
    };
    if (filters.username) params.username = filters.username;
    const res = await getSimpleSettlementDashboardApi(params as any);
    loaded.value = true;
    if (Number(res.code) === 1000) {
      data.value = res.data ?? null;
    } else {
      data.value = null;
      errorMsg.value = res.message ?? '';
    }
  } catch {
    data.value = null;
    loaded.value = true;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  loadData();
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              :fixed-offset="8"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
            <FixedTimezoneHint />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-loading="loading" class="mx-auto max-w-[540px]" shadow="never">
      <div v-if="errorMsg" class="text-muted-foreground py-4 text-center">
        {{ errorMsg }}
      </div>
      <div v-else-if="data" class="space-y-2">
        <div
          v-for="row in rows"
          :key="row.key"
          class="flex items-center justify-between rounded border-l-4 px-3 py-2"
          :class="TONE_CLASS[row.tone]"
        >
          <span class="font-semibold" :class="row.big ? 'text-lg' : ''">
            {{ tl(row.key) }}
          </span>
          <span :class="row.big ? 'text-lg font-bold' : 'font-medium'">
            {{ row.value }}
          </span>
        </div>
      </div>
    </ElCard>
  </Page>
</template>
