<script lang="ts" setup>
import type { VNode } from 'vue';

import type { GgrSettlementRow } from '#/api';

import { h, reactive, ref } from 'vue';

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
  ElTag,
} from 'element-plus';

import { getGgrSettlementListApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

import SettlementDetailDialog from './components/settlement-detail-dialog.vue';

/**
 * 4125 推廣代理派發紀錄（批次三純查詢版）：結算結果清單。
 * 資料由結算引擎批次寫入，本頁無任何寫入操作；「計算」欄開詳情彈窗
 * 顯示計算明細快照。無審核概念（2026-08-31 定案），只有發放狀態一條線。
 */

defineOptions({ name: 'GgrPayoutRecord' });

const PER_PAGE = 20;

const detailRef = ref<InstanceType<typeof SettlementDetailDialog>>();

const loading = ref(false);
const list = ref<GgrSettlementRow[]>([]);
const page = ref(1);
const total = ref(0);

const filters = reactive({
  agent_username: '',
  carry: '' as number | string,
  date_range: [] as string[],
  payout_status: '' as number | string,
});

function queryParams() {
  const [start, end] = filters.date_range ?? [];
  return {
    ...(filters.agent_username.trim()
      ? { agent_username: filters.agent_username.trim() }
      : {}),
    ...(filters.carry === '' ? {} : { carry: filters.carry }),
    ...(filters.payout_status === ''
      ? {}
      : { payout_status: filters.payout_status }),
    ...(start ? { start_date: start } : {}),
    ...(end ? { end_date: end } : {}),
  };
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getGgrSettlementListApi({
      page: page.value,
      per_page: PER_PAGE,
      ...queryParams(),
    });
    const ok = Number(res.code) === 200;
    list.value = ok ? (res.data?.items ?? []) : [];
    total.value = ok ? (res.data?.pagination?.total ?? 0) : 0;
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function clear() {
  filters.agent_username = '';
  filters.carry = '';
  filters.payout_status = '';
  filters.date_range = [];
  search();
}

function handlePageChange(next: number) {
  page.value = next;
  loadData();
}

function cycleLabel(cycle?: number) {
  if (Number(cycle) === 1) return tl('ggr_cycle_daily');
  if (Number(cycle) === 2) return tl('ggr_cycle_weekly');
  return tl('ggr_cycle_monthly');
}

const fmt = (v?: null | number | string) => amountThousands(v ?? 0, 2);

/** 合計列：GGR金額/最終金額/手續費三個金額欄加總，負值紅字（照 sr12 慣例） */
function summaryMethod(): Array<string | VNode> {
  const sum = (key: 'final_amount' | 'ggr_amount' | 'handling_fee') =>
    list.value.reduce((acc, row) => acc + Number(row[key] ?? 0), 0);
  const cell = (v: number) =>
    h('b', { class: v < 0 ? 'text-red-500' : '' }, fmt(v));
  return [
    tl('total_count'),
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    cell(sum('ggr_amount')) as any,
    cell(sum('final_amount')) as any,
    '',
    '',
    '',
    cell(sum('handling_fee')) as any,
    '',
    '',
    '',
    '',
    '',
  ];
}

const { exporting, runExport } = useExcelExport();

function handleExport() {
  const params = queryParams();
  runExport(
    async () => {
      const res = await getGgrSettlementListApi({
        page: 1,
        per_page: 100_000,
        ...params,
      });
      return { code: res.code, data: res.data?.items ?? [] };
    },
    tl('menu_ggr_payout_record'),
    [
      tl('ggr_payout_status'),
      tl('agent_account'),
      tl('ggr_cycle'),
      tl('ggr_start_date'),
      tl('ggr_period_end'),
      tl('ggr_rate_label'),
      tl('ggr_bonus_rate'),
      tl('ggr_condition_rate'),
      tl('ggr_ggr_amount'),
      tl('ggr_final_amount'),
      tl('ggr_carry_status'),
      tl('ggr_carry_balance'),
      tl('ggr_admin_fee'),
      tl('handling_fee'),
      tl('operator'),
      tl('memo'),
      tl('ggr_paid_at'),
      tl('created_time'),
      tl('updated_at'),
    ],
    (row) => [
      tl(`ggr_payout_status_${row.payout_status ?? 0}`),
      row.agent_username ?? '',
      cycleLabel(row.settlement_cycle),
      row.period_start ?? '',
      row.period_end ?? '',
      row.ggr_rate ?? '',
      row.bonus_rate ?? '',
      row.condition_rate ?? '',
      row.ggr_amount ?? '',
      row.final_amount ?? '',
      Number(row.carry) === 1 ? tl('yes') : tl('no'),
      row.carry_out_amount ?? '',
      row.admin_fee_rate ?? '',
      row.handling_fee ?? '',
      row.admin_username ?? '',
      row.remark ?? '',
      row.paid_at ?? '',
      row.created_at ?? '',
      row.updated_at ?? '',
    ],
    { audit: { conditions: params } },
  );
}

loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_ggr_agents_mode')} > ${tl('menu_ggr_payout_record')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput
              v-model="filters.agent_username"
              clearable
              @keyup.enter="search"
            />
          </ElFormItem>
          <ElFormItem :label="tl('ggr_carry_negative')">
            <ElSelect v-model="filters.carry" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('yes')" :value="1" />
              <ElOption :label="tl('no')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('ggr_payout_status')">
            <ElSelect v-model="filters.payout_status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('ggr_payout_status_0')" :value="0" />
              <ElOption :label="tl('ggr_payout_status_1')" :value="1" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.date_range"
              :fixed-offset="8"
              format="YYYY-MM-DD"
              type="date"
              value-format="YYYY-MM-DD"
            />
            <FixedTimezoneHint />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="clear">{{ tl('clear') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" type="primary" @click="search">
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
          :label="tl('ggr_payout_status')"
          align="center"
          width="100"
        >
          <template #default="{ row }">
            <ElTag :type="Number(row.payout_status) === 1 ? 'success' : 'info'">
              {{ tl(`ggr_payout_status_${row.payout_status ?? 0}`) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          min-width="120"
          prop="agent_username"
          show-overflow-tooltip
        />
        <ElTableColumn :label="tl('ggr_cycle')" align="center" width="80">
          <template #default="{ row }">
            {{ cycleLabel(row.settlement_cycle) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ggr_start_date')"
          align="center"
          prop="period_start"
          width="110"
        />
        <ElTableColumn
          :label="tl('ggr_period_end')"
          align="center"
          prop="period_end"
          width="110"
        />
        <ElTableColumn :label="tl('ggr_calc')" align="center" width="80">
          <template #default="{ row }">
            <ElButton
              circle
              size="small"
              @click="detailRef?.open((row as GgrSettlementRow).id)"
            >
              👁
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ggr_rate_label')"
          align="center"
          prop="ggr_rate"
          width="110"
        />
        <ElTableColumn
          :label="tl('ggr_bonus_rate')"
          align="center"
          prop="bonus_rate"
          width="110"
        />
        <ElTableColumn
          :label="tl('ggr_condition_rate')"
          align="center"
          prop="condition_rate"
          width="110"
        />
        <ElTableColumn :label="tl('ggr_ggr_amount')" align="center" width="120">
          <template #default="{ row }">{{ fmt(row.ggr_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ggr_final_amount')"
          align="center"
          width="120"
        >
          <template #default="{ row }">{{ fmt(row.final_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ggr_carry_status')"
          align="center"
          width="90"
        >
          <template #default="{ row }">
            {{ Number(row.carry) === 1 ? tl('yes') : tl('no') }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ggr_carry_balance')"
          align="center"
          width="120"
        >
          <template #default="{ row }">
            {{ fmt(row.carry_out_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ggr_admin_fee')"
          align="center"
          prop="admin_fee_rate"
          width="100"
        />
        <ElTableColumn :label="tl('handling_fee')" align="center" width="100">
          <template #default="{ row }">{{ fmt(row.handling_fee) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operator')"
          align="center"
          prop="admin_username"
          width="110"
        />
        <ElTableColumn
          :label="tl('memo')"
          align="center"
          min-width="120"
          prop="remark"
          show-overflow-tooltip
        />
        <ElTableColumn
          :label="tl('ggr_paid_at')"
          align="center"
          prop="paid_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('created_time')"
          align="center"
          prop="created_at"
          width="160"
        />
        <ElTableColumn
          :label="tl('updated_at')"
          align="center"
          prop="updated_at"
          width="160"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <SettlementDetailDialog ref="detailRef" />
  </Page>
</template>
