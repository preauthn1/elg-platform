<script lang="ts" setup>
import type { WalletLogRow } from '#/api';

import { reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

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

import { getReportSr25Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSr25' });

const PAGE_SIZES = [10, 25, 50, 100];

const filters = reactive({
  member_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const loading = ref(false);
const list = ref<WalletLogRow[]>([]);
const pageSize = ref(10);

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 類型文字：type 14 加 user_join_status，其餘加 vendor_name */
function typeText(row: WalletLogRow) {
  const base = tl(`event_wallet_type.${row.type}`);
  if (Number(row.type) === 14) {
    return `${base} (${tl(`user_join_status.${row.user_join_status}`)})`;
  }
  return row.vendor_name ? `${base} (${row.vendor_name})` : base;
}

/** 狀態：1000 成功 / 999 status_2 / 其餘 failure */
function statusText(row: WalletLogRow) {
  const s = Number(row.status);
  if (s === 1000) return tl('success');
  if (s === 999) return tl('status_2');
  return tl('failure');
}

// 前端分頁（API 一次最多回 1000 筆、不回分頁資訊）
const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows: pagedList,
  total,
} = useClientTable(list, {
  perPage: pageSize,
  searchFields: (r) => [
    fromApiTime(r.created_at),
    r.agent_username,
    r.member_username,
    typeText(r),
    r.remark,
    r.operate_agent_username,
  ],
});

function handleSizeChange(size: number) {
  pageSize.value = size;
  page.value = 1;
}

async function loadData() {
  // 會員帳號必填
  if (!filters.member_username) {
    ElMessage.warning(tl('member_account'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  loading.value = true;
  try {
    // sr25 走新 API（code 200），回 data.items（不分頁）
    const res = await getReportSr25Api({
      end_time: toApiTime(end),
      member_username: filters.member_username,
      page: 1,
      per_page: 1000,
      start_time: toApiTime(start),
    });
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
    } else {
      list.value = [];
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

const exporting = ref(false);
/** 匯出 Excel：欄位（8 欄；不含備註/操作代理欄） */
async function handleExport() {
  if (!filters.member_username) {
    ElMessage.warning(tl('member_account'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(end),
      member_username: filters.member_username,
      page: 1,
      per_page: 10_000,
      start_time: toApiTime(start),
    };
    const res = await getReportSr25Api(params);
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr25_${Date.now()}`,
        [
          tl('date'),
          tl('agent_name'),
          tl('member_name'),
          tl('before_point'),
          tl('change_point'),
          tl('moment_point'),
          tl('type'),
          tl('status'),
        ],
        rows.map((r) => [
          fromApiTime(r.created_at),
          r.agent_username,
          r.member_username,
          fmt(r.before_balance),
          fmt(r.point),
          fmt(r.balance),
          typeText(r),
          statusText(r),
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr25'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr25')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('label_start_time')" class="md:col-span-2">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
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
      <div class="mb-3">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedList"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :formatter="(row: WalletLogRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          prop="created_at"
          sortable="custom"
          width="160"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          prop="agent_username"
          min-width="110"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_name')"
          min-width="110"
          sortable="custom"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('before_point')"
          align="right"
          prop="before_balance"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">{{ fmt(row.before_balance) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('change_point')"
          align="right"
          prop="point"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.point) < 0 }">{{
              fmt(row.point)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('moment_point')"
          align="right"
          prop="balance"
          sortable="custom"
          width="120"
        >
          <template #default="{ row }">{{ fmt(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('type')"
          min-width="150"
          prop="type"
          sortable="custom"
        >
          <template #default="{ row }">{{ typeText(row) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('memo')"
          prop="remark"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('operate_agent_username')"
          prop="operate_agent_username"
          min-width="110"
          sortable="custom"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operate_agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          prop="status"
          sortable="custom"
          width="110"
        >
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1000 ? '' : 'font-bold text-red-500'
              "
            >
              {{ statusText(row) }}
            </span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="PAGE_SIZES"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>
  </Page>
</template>
