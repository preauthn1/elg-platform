<script lang="ts" setup>
import type {
  CustomerReportHeader,
  CustomerReportSubRow,
  SettlementPeriod,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

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

import {
  getCustomerReportApi,
  getParamListApi,
  getSettlementPeriodApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, sumAmounts } from '#/utils/format';

defineOptions({ name: 'SettlementCustomerReport' });

const PER_PAGE = 20;

const userStore = useUserStore();

const periods = ref<SettlementPeriod[]>([]);
const filters = reactive({
  username: '',
  start_period: '' as number | string,
  end_period: '' as number | string,
});

const loading = ref(false);
const sub = ref<CustomerReportSubRow[]>([]);
const header = ref<CustomerReportHeader | null>(null);
const total = ref(0);
const page = ref(1);
const hasHeaderData = ref(false);

const fmt = (v?: null | number | string) => amountThousands(v, 2);

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

function rowProfit(row: CustomerReportSubRow) {
  return (
    Number(row.sales) +
    Number(row.commission) +
    Number(row.vendor_fee) +
    Number(row.rebate) +
    Number(row.bonus)
  );
}

function rowProportions(row: CustomerReportSubRow) {
  return rowProfit(row) * Number(row.proportions) * -1;
}

function rowNetProfit(row: CustomerReportSubRow) {
  return rowProfit(row) + rowProportions(row);
}

/** 表頭摘要卡（依當頁明細加總）；後端在有帶 username 篩選時 header 會是 [] */
const headerCard = computed(() => {
  const h = header.value;
  if (!h) return null;
  const hasData = !Array.isArray(h);
  let totalSales = 0;
  let totalCommission = 0;
  let totalRebate = 0;
  let totalCustomerProportions = 0;
  if (hasData) {
    const rows = sub.value;
    totalSales = Number(sumAmounts(rows.map((r) => r.sales)));
    totalCommission = Number(sumAmounts(rows.map((r) => r.commission)));
    totalRebate = Number(sumAmounts(rows.map((r) => r.rebate)));
    totalCustomerProportions = -Number(
      sumAmounts(rows.map((r) => rowNetProfit(r))),
    );
  }
  const headerProfit = hasData
    ? totalSales +
      totalCommission +
      Number(h.vendor_fee) +
      totalRebate +
      Number(h.bonus)
    : 0;
  const headerNetProfit = headerProfit + totalCustomerProportions;
  return {
    customer_name: hasData ? h.customer_name : '',
    total_sales: totalSales,
    total_commission: totalCommission,
    vendor_fee: hasData ? Number(h.vendor_fee) : 0,
    total_rebate: totalRebate,
    bonus: hasData ? Number(h.bonus) : 0,
    header_profit: headerProfit,
    total_customer_proportions: totalCustomerProportions,
    header_net_profit: headerNetProfit,
  };
});

async function resolveHasHeaderData() {
  const info = userStore.userInfo as any;
  if (Number(info?.host_id) === 1) {
    hasHeaderData.value = true;
    return;
  }
  const list = await getParamListApi('has_settlements_report').catch(() => []);
  hasHeaderData.value = (list ?? []).some(
    (p) => String(p.value) === String(info?.id),
  );
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getCustomerReportApi({
      end_period: filters.end_period,
      page: page.value,
      per_page: PER_PAGE,
      start_period: filters.start_period,
      username: filters.username,
    });
    if (Number(res.code) === 1000) {
      sub.value = res.data?.sub ?? [];
      header.value = res.data?.header ?? null;
      total.value = res.pagination?.total ?? 0;
    } else {
      sub.value = [];
      header.value = null;
      total.value = 0;
    }
  } catch {
    sub.value = [];
    header.value = null;
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：欄位（9 欄；表頭摘要列 + 空列 + 子表標題列 + 明細列） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = {
      end_period: filters.end_period,
      page: 1,
      per_page: 100_000,
      start_period: filters.start_period,
      username: filters.username,
    };
    const res = await getCustomerReportApi(params);
    const ok = Number(res.code) === 1000;
    const subRows = ok ? (res.data?.sub ?? []) : [];
    const rawHeader = ok ? (res.data?.header ?? null) : null;
    const h = rawHeader && !Array.isArray(rawHeader) ? rawHeader : null;
    let totalSales = 0;
    let totalCommission = 0;
    let totalRebate = 0;
    let totalCustomerProportions = 0;
    if (h) {
      totalSales = Number(sumAmounts(subRows.map((r) => r.sales)));
      totalCommission = Number(sumAmounts(subRows.map((r) => r.commission)));
      totalRebate = Number(sumAmounts(subRows.map((r) => r.rebate)));
      totalCustomerProportions = -Number(
        sumAmounts(subRows.map((r) => rowNetProfit(r))),
      );
    }
    const headerProfit = h
      ? totalSales +
        totalCommission +
        Number(h.vendor_fee) +
        totalRebate +
        Number(h.bonus)
      : 0;
    const headerNetProfit = headerProfit + totalCustomerProportions;
    const rows: Array<null | number | string | undefined>[] = [];
    if (h) {
      rows.push(
        [
          h.customer_name,
          fmt(totalSales),
          fmt(totalCommission),
          fmt(h.vendor_fee),
          fmt(totalRebate),
          fmt(h.bonus),
          fmt(headerProfit),
          fmt(totalCustomerProportions),
          fmt(headerNetProfit),
        ],
        ['', '', '', '', '', '', '', '', ''],
        [
          tl('customer_name'),
          tl('total_winlose'),
          tl('commission'),
          `${tl('customer')}${tl('vendor_fee')}`,
          tl('rebate'),
          tl('bonus'),
          tl('profit'),
          `${tl('customer')}${tl('proportion')}`,
          tl('net_profit'),
        ],
      );
    }
    for (const r of subRows) {
      rows.push([
        r.customer_name,
        fmt(r.sales),
        fmt(r.commission),
        fmt(r.vendor_fee),
        fmt(r.rebate),
        fmt(r.bonus),
        fmt(rowProfit(r)),
        fmt(rowProportions(r)),
        fmt(rowNetProfit(r)),
      ]);
    }
    await exportExcel(
      `settlement_customer_report_${Date.now()}`,
      [
        tl('header_customer'),
        tl('total_winlose'),
        tl('commission'),
        `${tl('customer')}${tl('vendor_fee')}`,
        tl('rebate'),
        'Bonus',
        tl('profit'),
        `${tl('sub_customer')}${tl('net_profit')}`,
        tl('net_profit'),
      ],
      rows,
      {
        audit: {
          conditions: params,
          // rows 前三列是總覽列、空白間隔列、第二段表頭列，不是資料列
          recordCount: subRows.length,
        },
        dateRange: [String(filters.start_period), String(filters.end_period)],
        title: tl('menu_settlement_customer_report'),
        tzOffset: 8,
      },
    );
  } finally {
    exporting.value = false;
  }
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

onMounted(async () => {
  await resolveHasHeaderData();
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
    :title="`${tl('settlement_report')} > ${tl('menu_settlement_customer_report')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="`${tl('agent')}${tl('username')}`">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <div></div>
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

    <ElCard v-if="hasHeaderData && headerCard" class="mb-3" shadow="never">
      <ElTable :data="[headerCard]" border size="small">
        <ElTableColumn
          :label="tl('header_customer')"
          align="right"
          min-width="140"
          prop="customer_name"
        />
        <ElTableColumn
          :label="tl('total_winlose')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.total_sales)">
              {{ fmt(row.total_sales) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('commission')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.total_commission)">
              {{ fmt(row.total_commission) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('customer')}${tl('vendor_fee')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.vendor_fee)">
              {{ fmt(row.vendor_fee) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('rebate')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.total_rebate)">
              {{ fmt(row.total_rebate) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="Bonus" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.bonus)">{{ fmt(row.bonus) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('profit')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.header_profit)">
              {{ fmt(row.header_profit) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('sub_customer')}${tl('net_profit')}`"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.total_customer_proportions)">
              {{ fmt(row.total_customer_proportions) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('net_profit')" align="right" min-width="130">
          <template #default="{ row }">
            <span :class="colorClass(row.header_net_profit)">
              {{ fmt(row.header_net_profit) }}
            </span>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="sub" border size="small" stripe>
        <ElTableColumn
          :label="tl('customer_name')"
          align="right"
          min-width="140"
          prop="customer_name"
        />
        <ElTableColumn
          :label="tl('total_winlose')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.sales)">{{ fmt(row.sales) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('commission')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.commission)">
              {{ fmt(row.commission) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('customer')}${tl('vendor_fee')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="colorClass(row.vendor_fee)">
              {{ fmt(row.vendor_fee) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('rebate')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.rebate)">{{ fmt(row.rebate) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bonus')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(row.bonus)">{{ fmt(row.bonus) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('profit')" align="right" min-width="120">
          <template #default="{ row }">
            <span :class="colorClass(rowProfit(row as CustomerReportSubRow))">
              {{ fmt(rowProfit(row as CustomerReportSubRow)) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('customer')}${tl('proportion')}`"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span
              :class="colorClass(rowProportions(row as CustomerReportSubRow))"
            >
              {{ fmt(rowProportions(row as CustomerReportSubRow)) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('net_profit')" align="right" min-width="130">
          <template #default="{ row }">
            <span
              :class="colorClass(rowNetProfit(row as CustomerReportSubRow))"
            >
              {{ fmt(rowNetProfit(row as CustomerReportSubRow)) }}
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
