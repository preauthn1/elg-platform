<script lang="ts" setup>
import type { PerformanceReportRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getPerformanceReportApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportPerformanceReport' });

const PER_PAGE = 10;

type SortField = 'bet' | 'member_username' | 'win_lose';

const filters = reactive({
  member_username: '',
  time_range: [
    dayjs().subtract(1, 'day').format('YYYY-MM-DD'),
    dayjs().subtract(1, 'day').format('YYYY-MM-DD'),
  ] as string[],
});

const loading = ref(false);
const list = ref<PerformanceReportRow[]>([]);
const total = ref(0);
const page = ref(1);
const totalBet = ref<number | string>(0);
const totalWinLose = ref<number | string>(0);
const sortField = ref<SortField>('bet');
const sortDirection = ref<'asc' | 'desc'>('desc');

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** reportAmountColor：>0 藍、<0 紅、0 無色 */
function amountColor(v?: null | number | string) {
  const n = Number(v);
  if (n > 0) return 'text-blue-500';
  if (n < 0) return 'text-red-500';
  return '';
}

/** plus_sign：>0 顯示 + 號 */
function plusSign(v?: null | number | string) {
  return Number(v) > 0 ? '+' : '';
}

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('date'));
    return;
  }
  loading.value = true;
  try {
    const res = await getPerformanceReportApi({
      end_time: end,
      member_username: toHalfWidth(filters.member_username),
      page: page.value,
      per_page: PER_PAGE,
      sort_direction: sortDirection.value,
      sort_field: sortField.value,
      start_time: start,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data?.items ?? [];
      totalBet.value = res.data?.total_bet ?? 0;
      totalWinLose.value = res.data?.total_win_lose ?? 0;
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      totalBet.value = 0;
      totalWinLose.value = 0;
      ElMessage.warning(res.message);
    }
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
/** 匯出 Excel：欄位（3 欄；僅會員層） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('date'));
    return;
  }
  exporting.value = true;
  try {
    const params = {
      end_time: end,
      member_username: toHalfWidth(filters.member_username),
      page: 1,
      per_page: 100_000,
      sort_direction: sortDirection.value,
      sort_field: sortField.value,
      start_time: start,
    };
    const res = await getPerformanceReportApi(params);
    const rows = Number(res.code) === 1000 ? (res.data?.items ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel({
        audit: { conditions: params },
        columns: [
          { title: tl('member_name'), width: 20 },
          { title: tl('bet_amount'), width: 30 },
          { title: tl('win_lose'), width: 20 },
        ],
        dateRange: [start, end],
        rows: rows.map((r) => [r.member_username, fmt(r.bet), fmt(r.win_lose)]),
        title: tl('menu_performance_report'),
        tzOffset: 8,
      }),
    );
  } finally {
    exporting.value = false;
  }
}

/** 點表頭排序。default-sort 會在掛載時也觸發一次本事件，
 *  與初次載入的排序相同則略過，避免重複請求。 */
function handleSortChange({
  order,
  prop,
}: {
  order: 'ascending' | 'descending' | null;
  prop: null | string;
}) {
  if (!prop) return;
  const field = prop as SortField;
  const direction = order === 'ascending' ? 'asc' : 'desc';
  if (field === sortField.value && direction === sortDirection.value) return;
  sortField.value = field;
  sortDirection.value = direction;
  page.value = 1;
  loadData();
}

handleSearch();
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_shareholder_report')} > ${tl('menu_performance_report')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
            <div class="text-muted-foreground mt-1 text-xs">
              {{ tl('query_period_max_days').replace('{value}', '60') }}
            </div>
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 leading-7">
        <div>{{ tl('total_bet_amount') }}: {{ fmt(totalBet) }}</div>
        <div>
          {{ tl('total_winlose') }}:
          <span :class="amountColor(totalWinLose)">
            {{ plusSign(totalWinLose) }}{{ fmt(totalWinLose) }}
          </span>
        </div>
      </div>

      <ElTable
        v-loading="loading"
        :data="list"
        :default-sort="{ prop: sortField, order: 'descending' }"
        border
        row-key="member_username"
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn type="expand">
          <template #default="{ row }">
            <div class="px-8 py-2">
              <ElTable
                :data="(row as PerformanceReportRow).vendors ?? []"
                border
                size="small"
              >
                <ElTableColumn :label="tl('label_vendor')" prop="name" />
                <ElTableColumn
                  :label="tl('bet_amount')"
                  align="right"
                  width="160"
                >
                  <template #default="scope">{{ fmt(scope.row.bet) }}</template>
                </ElTableColumn>
                <ElTableColumn
                  :label="tl('win_lose')"
                  align="right"
                  width="160"
                >
                  <template #default="scope">
                    <span :class="amountColor(scope.row.win_lose)">
                      {{ plusSign(scope.row.win_lose)
                      }}{{ fmt(scope.row.win_lose) }}
                    </span>
                  </template>
                </ElTableColumn>
                <template #empty>{{ tl('no_record') }}</template>
              </ElTable>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_name')"
          sortable="custom"
          min-width="140"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bet_amount')"
          align="right"
          prop="bet"
          sortable="custom"
          min-width="140"
        >
          <template #default="{ row }">{{ fmt(row.bet) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('win_lose')"
          align="right"
          prop="win_lose"
          sortable="custom"
          min-width="140"
        >
          <template #default="{ row }">
            <span :class="amountColor(row.win_lose)">
              {{ plusSign(row.win_lose) }}{{ fmt(row.win_lose) }}
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
