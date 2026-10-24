<script lang="ts" setup>
import type { VNode } from 'vue';

import type {
  AgentReportRow,
  AgentReportSummary,
  SettlementPeriod,
} from '#/api';

import { h, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentReportApi, getSettlementPeriodApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';

defineOptions({ name: 'SettlementAgentReport' });

const PER_PAGE = 20;

const periods = ref<SettlementPeriod[]>([]);
const filters = reactive({
  username: '',
  type: 1 as number,
  start_period: '' as number | string,
  end_period: '' as number | string,
});

const loading = ref(false);
const list = ref<AgentReportRow[]>([]);
const summary = ref<AgentReportSummary | null>(null);
const total = ref(0);
const page = ref(1);

const fmt = (v?: null | number | string, digits = 2) =>
  amountThousands(v, digits);

/** reportAmountColor：>0 藍、<0 紅、0 無色 */
function colorClass(v?: null | number | string) {
  const n = Number(v);
  if (n > 0) return 'text-blue-500';
  if (n < 0) return 'text-red-500';
  return '';
}

function periodLabel(row: SettlementPeriod) {
  return `${tl('periods').replace('{N}', String(row.period))} - ${row.start_date} ~ ${row.end_date}`;
}

function rowSales(row: AgentReportRow) {
  return (
    Number(row.slot) +
    Number(row.score) +
    Number(row.live) +
    Number(row.sport) +
    Number(row.other)
  );
}

function rowProfit(row: AgentReportRow) {
  return Number(row.profit_1) + Number(row.down_profit) - Number(row.bonus);
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getAgentReportApi({
      end_period: filters.end_period,
      page: page.value,
      per_page: PER_PAGE,
      start_period: filters.start_period,
      type: filters.type,
      username: filters.username,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      summary.value = res.summary_data ?? null;
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      summary.value = null;
      total.value = 0;
    }
  } catch {
    list.value = [];
    summary.value = null;
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：欄位（13 欄；sales/net_profit 為計算欄） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      end_period: filters.end_period,
      page: 1,
      per_page: 100_000,
      start_period: filters.start_period,
      type: filters.type,
      username: filters.username,
    };
    const res = await getAgentReportApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `settlement_agent_report_${Date.now()}`,
        [
          tl('unlimit_parent_member'),
          tl('agent'),
          `${tl('direct')}${tl('member')}`,
          tl('sales'),
          `${tl('slot')}${tl('sales')}`,
          `${tl('score')}${tl('sales')}`,
          `${tl('live')}${tl('sales')}`,
          `${tl('sport')}${tl('sales')}`,
          `${tl('others')}${tl('sales')}`,
          `${tl('direct')}${tl('profit')}`,
          `${tl('bonus')}${tl('vendor_fee')}`,
          `${tl('all_lower')}${tl('agent')}${tl('profit')}`,
          tl('net_profit'),
        ],
        rows.map((r) => [
          r.parent_username,
          r.agent_name,
          r.count,
          fmt(rowSales(r)),
          fmt(r.slot),
          fmt(r.score),
          fmt(r.live),
          fmt(r.sport),
          fmt(r.other),
          fmt(r.profit_1),
          fmt(r.bonus),
          fmt(r.down_profit),
          fmt(rowProfit(r), 4),
        ]),
        {
          audit: { conditions: params },
          dateRange: [String(filters.start_period), String(filters.end_period)],
          title: tl('menu_settlement_agent_report'),
          tzOffset: 8,
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

/** 表尾合計列 */
function summaryMethod(): Array<string | VNode> {
  const s = summary.value;
  const cell = (v?: null | number | string, digits = 2) =>
    h('b', { class: colorClass(v) }, fmt(v, digits));
  return [
    '',
    h('b', { class: 'text-blue-500' }, tl('total_count')),
    h('b', {}, String(s?.count ?? '')),
    cell(s?.total_sales) as any,
    cell(s?.slot) as any,
    cell(s?.score) as any,
    cell(s?.live) as any,
    cell(s?.sport) as any,
    cell(s?.other) as any,
    cell(s?.profit_1) as any,
    cell(s?.bonus) as any,
    cell(s?.down_profit) as any,
    cell(s?.total_profit, 4) as any,
  ];
}

onMounted(async () => {
  const data = await getSettlementPeriodApi().catch(() => []);
  periods.value = data ?? [];
  if (periods.value.length > 0) {
    filters.start_period = periods.value[0]?.period ?? filters.start_period;
    filters.end_period = periods.value[0]?.period ?? filters.end_period;
  }
  loadData();
});
</script>

<template>
  <Page
    :title="`${tl('menu_settlement_report')} > ${tl('menu_settlement_agent_report')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="`${tl('agent')}${tl('username')}`">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="!w-full">
              <ElOption :label="tl('fuzzy_query')" :value="1" />
              <ElOption :label="tl('agent_line')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <ElSelect v-model="filters.start_period" class="!w-full" filterable>
              <ElOption
                v-for="p in periods"
                :key="p.period"
                :label="periodLabel(p)"
                :value="p.period"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('to')">
            <ElSelect v-model="filters.end_period" class="!w-full" filterable>
              <ElOption
                v-for="p in periods"
                :key="p.period"
                :label="periodLabel(p)"
                :value="p.period"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :show-summary="list.length > 0"
        :summary-method="summaryMethod"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('unlimit_parent_member')"
          min-width="140"
          prop="parent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent')" min-width="140" prop="agent_name">
          <template #default="{ row }">
            <AgentAccount :username="row.agent_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('direct')}${tl('member')}`"
          align="right"
          min-width="100"
          prop="count"
        />
        <ElTableColumn :label="tl('sales')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(rowSales(row as AgentReportRow))">
              {{ fmt(rowSales(row as AgentReportRow)) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('slot')}${tl('sales')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.slot)">{{ fmt(row.slot) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('score')}${tl('sales')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.score)">{{ fmt(row.score) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('live')}${tl('sales')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.live)">{{ fmt(row.live) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('sport')}${tl('sales')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.sport)">{{ fmt(row.sport) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('others')}${tl('sales')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.other)">{{ fmt(row.other) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('direct')}${tl('profit')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.profit_1)">{{
              fmt(row.profit_1)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('bonus')}${tl('vendor_fee')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.bonus)">{{ fmt(row.bonus) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('all_lower')}${tl('agent')}${tl('profit')}`"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.down_profit)">
              {{ fmt(row.down_profit) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('net_profit')" align="right" min-width="130">
          <template #default="{ row }">
            <span :class="colorClass(rowProfit(row as AgentReportRow))">
              {{ fmt(rowProfit(row as AgentReportRow), 4) }}
            </span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>
  </Page>
</template>
