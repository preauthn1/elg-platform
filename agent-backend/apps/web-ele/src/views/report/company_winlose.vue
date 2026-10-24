<script lang="ts" setup>
import type { CompanyWinLoseData } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getCompanyWinLoseApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { amountThousands, sumAmounts } from '#/utils/format';

defineOptions({ name: 'ReportCompanyWinLose' });

const filters = reactive({
  time_range: [
    dayjs().format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
});

const loading = ref(false);
const raw = ref<CompanyWinLoseData>({});

const fmt = (v?: null | number | string) => amountThousands(v, 2);
const signClass = (v?: number | string) =>
  Number(v) < 0 ? 'text-red-500' : '';

function i18nRole(r?: number | string) {
  const n = Number(r);
  if (n === 0) return tl('member');
  if (n === 1) return tl('agent');
  return `Unknow(${r})`;
}
function i18nPayType(t?: number | string) {
  const n = Number(t);
  if (n === 0) return tl('paytype_deposit');
  if (n === 1) return tl('paytype_withdrawal');
  return `Unknow(${t})`;
}
function evtName(role?: number | string, type?: number | string) {
  const r = Number(role);
  if (r === 0) {
    if (type === 'use_rolling_chip') return tl('use_rolling_chip');
    return tl(`transaction_type.${type}`);
  }
  if (r === 1 && Number(type) === 3) return tl('transaction_type.17');
  return `Unknow(${role}_${type})`;
}

// ── 衍生資料（對齊舊版 _ajax 計算） ──
const vendors = computed(() =>
  (raw.value.vendors ?? []).map((v) => {
    const ggr = -Number(v.total_win_lose ?? 0);
    const cost = Number(v.total_cost ?? 0);
    return { ...v, total_ggr: ggr, total_profit: ggr - cost };
  }),
);
const useRollingChip = computed(() =>
  Number(
    sumAmounts((raw.value.vendors ?? []).map((v) => v.total_rolling_chip)),
  ),
);
const sumVendors = computed(() => ({
  bet: Number(sumAmounts(vendors.value.map((v) => v.total_bet))),
  cost: Number(sumAmounts(vendors.value.map((v) => v.total_cost))),
  ggr: Number(sumAmounts(vendors.value.map((v) => v.total_ggr))),
  payout: Number(sumAmounts(vendors.value.map((v) => v.total_payout))),
  profit: Number(sumAmounts(vendors.value.map((v) => v.total_profit))),
}));
const events = computed(() => [
  ...(raw.value.events ?? []),
  { point: useRollingChip.value, role: 0, type: 'use_rolling_chip' as const },
]);
const sumEvents = computed(() =>
  Number(sumAmounts(events.value.map((e) => e.point))),
);
const pays = computed(() => raw.value.pays ?? []);
const sumPays = computed(() =>
  Number(sumAmounts(pays.value.map((p) => p.total_cost))),
);
const sms = computed(() => raw.value.sms ?? []);
const sumSms = computed(() =>
  Number(sumAmounts(sms.value.map((x) => x.total_cost))),
);
const netPnl = computed(
  () =>
    sumVendors.value.profit - sumEvents.value - sumPays.value - sumSms.value,
);

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  loading.value = true;
  try {
    const res = await getCompanyWinLoseApi({
      end_date: end,
      start_date: start,
    });
    raw.value = Number(res.code) === 1000 ? (res.data ?? {}) : {};
    if (Number(res.code) !== 1000) ElMessage.warning(res.message);
  } finally {
    loading.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('shareholder_management')} > ${tl('menu_shareholder_company_winlose')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="loadData">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" type="primary" @click="loadData">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 摘要卡 -->
    <div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-5">
      <ElCard shadow="never">
        <div class="text-muted-foreground text-sm">{{ tl('game_profit') }}</div>
        <div class="mt-1 text-xl font-bold">{{ fmt(sumVendors.profit) }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-sm">{{ tl('event_cost') }}</div>
        <div class="mt-1 text-xl font-bold">{{ fmt(sumEvents) }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-sm">{{ tl('pay_fees') }}</div>
        <div class="mt-1 text-xl font-bold">{{ fmt(sumPays) }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-sm">{{ tl('sms_fee') }}</div>
        <div class="mt-1 text-xl font-bold">{{ fmt(sumSms) }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-sm">{{ tl('net_pnl') }}</div>
        <div class="mt-1 text-xl font-bold" :class="signClass(netPnl)">
          {{ fmt(netPnl) }}
        </div>
      </ElCard>
    </div>

    <!-- 遊戲損益 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('game_profit') }}</span>
      </template>
      <ElTable
        v-loading="loading"
        :data="vendors"
        border
        size="small"
        stripe
        show-summary
        :summary-method="
          () => [
            'Total',
            fmt(sumVendors.bet),
            fmt(sumVendors.payout),
            fmt(sumVendors.ggr),
            fmt(sumVendors.cost),
            fmt(sumVendors.profit),
          ]
        "
      >
        <ElTableColumn
          :label="tl('game_names')"
          align="center"
          min-width="120"
          prop="vendor_name"
        />
        <ElTableColumn :label="tl('bet_amount')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.total_bet) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('payout_amount')"
          align="right"
          min-width="110"
        >
          <template #default="{ row }">{{ fmt(row.total_payout) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ggr')" align="right" min-width="110">
          <template #default="{ row }">
            <span :class="signClass(row.total_ggr)">{{
              fmt(row.total_ggr)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cost')" align="right" min-width="100">
          <template #default="{ row }">{{ fmt(row.total_cost) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('profit')" align="right" min-width="110">
          <template #default="{ row }">
            <span :class="signClass(row.total_profit)">
              {{ fmt(row.total_profit) }}
            </span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <!-- 活動成本 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('event_cost') }}</span>
      </template>
      <ElTable
        v-loading="loading"
        :data="events"
        border
        size="small"
        stripe
        show-summary
        :summary-method="() => ['Total', '', fmt(sumEvents)]"
      >
        <ElTableColumn :label="tl('role')" align="center" min-width="100">
          <template #default="{ row }">{{ i18nRole(row.role) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('event_type')" align="center" min-width="160">
          <template #default="{ row }">
            {{ evtName(row.role, row.type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cost')" align="right" min-width="120">
          <template #default="{ row }">{{ fmt(row.point) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <!-- 金流成本 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('pay_fees') }}</span>
      </template>
      <ElTable
        v-loading="loading"
        :data="pays"
        border
        size="small"
        stripe
        show-summary
        :summary-method="() => ['Total', '', '', fmt(sumPays)]"
      >
        <ElTableColumn :label="tl('pay_method')" align="center" min-width="110">
          <template #default="{ row }">{{ i18nPayType(row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('pay_vendor_name')"
          align="center"
          min-width="140"
          prop="name"
        />
        <ElTableColumn :label="tl('point')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.total_point) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cost')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.total_cost) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <!-- 簡訊成本 -->
    <ElCard shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('sms_fee') }}</span>
      </template>
      <ElTable
        v-loading="loading"
        :data="sms"
        border
        size="small"
        stripe
        show-summary
        :summary-method="() => ['Total', '', '', fmt(sumSms)]"
      >
        <ElTableColumn :label="tl('role')" align="center" min-width="100">
          <template #default="{ row }">{{ i18nRole(row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sms_gateway_name')"
          align="center"
          min-width="140"
          prop="name"
        />
        <ElTableColumn
          :label="tl('send_times')"
          align="right"
          min-width="100"
          prop="total_send"
        />
        <ElTableColumn :label="tl('cost')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.total_cost) }}</template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>
  </Page>
</template>
