<script lang="ts" setup>
import type { ReportSr06Row } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getReportSr06Api, getVendorListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSr06' });

const PER_PAGE = 50;

/** 帳號搜尋類型 */
const TYPE_PARAM: Record<number, string> = {
  1: 'agent_username',
  2: 'member_username',
  3: 'member_nickname',
  4: 'transaction_no',
};

const vendors = ref<Array<{ id: number; name: string }>>([]);

function selectAllVendors() {
  filters.vendor_id = vendors.value.map((v) => v.id);
}
function clearVendors() {
  filters.vendor_id = [];
}

const filters = reactive({
  searchType: 1,
  status: 0,
  time_range: [
    dayjs().format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
  vendor_id: [] as number[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<ReportSr06Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const params: Record<string, unknown> = {
        end_date: end,
        page,
        per_page,
        start_date: start,
        status: filters.status,
        vendor_id: filters.vendor_id.length > 0 ? filters.vendor_id : 0,
      };
      if (filters.username) {
        const key = TYPE_PARAM[filters.searchType] ?? '';
        params[key] =
          filters.searchType === 3
            ? filters.username
            : toHalfWidth(filters.username);
      }
      return getReportSr06Api(params as any);
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        if (!start || !end) {
          ElMessage.warning(tl('between'));
          return false;
        }
        return true;
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 變更點數：轉出(type 1)為負數紅字 */
function changePoint(row: ReportSr06Row) {
  const p = Number(row.point ?? 0);
  return Number(row.type) === 1 ? -p : p;
}

/** 狀態文字 */
function statusText(row: ReportSr06Row) {
  const s = Number(row.status);
  if (Number(row.type) === 1 && Number(row.vendor_id) === 29 && s !== 1000) {
    if (s === 8888) return tl('dg_checking');
    if (s === 324) return tl('dg_checking_refund');
    return tl('dg_failed');
  }
  let text = s === 1000 ? tl('success') : tl('failure');
  if (s === 9000 || s === 9990) text += `(${tl('returned')})`;
  return text;
}

const exporting = ref(false);
/** 匯出 Excel：欄位（11 欄；值沿用畫面已驗格式） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params: Record<string, unknown> = {
      end_date: end,
      page: 1,
      per_page: 100_000,
      start_date: start,
      status: filters.status,
      vendor_id: filters.vendor_id.length > 0 ? filters.vendor_id : 0,
    };
    if (filters.username) {
      const key = TYPE_PARAM[filters.searchType] ?? '';
      params[key] =
        filters.searchType === 3
          ? filters.username
          : toHalfWidth(filters.username);
    }
    const res = await getReportSr06Api(params as any);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr06_${Date.now()}`,
        [
          tl('num'),
          tl('ordernum'),
          tl('date'),
          tl('agent_account'),
          tl('platform'),
          tl('type'),
          tl('member_account'),
          tl('member_nickname'),
          tl('change_point'),
          tl('moment_point'),
          tl('status'),
        ],
        rows.map((r) => [
          r.id,
          r.transaction_no,
          r.created_at,
          r.agent_username,
          r.vendor,
          Number(r.type) === 1 ? tl('transferout') : tl('transferin'),
          r.member_username,
          r.member_nickname,
          fmt(changePoint(r)),
          fmt(r.balance),
          statusText(r),
        ]),
        {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('sr06'),
          tzOffset: 8,
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

// 頁面載入即查詢（預設平台全選）
onMounted(async () => {
  try {
    const vendorList = await getVendorListApi();
    vendors.value = vendorList ?? [];
    filters.vendor_id = vendors.value.map((v) => v.id);
  } catch {
    // 下拉載入失敗不阻擋頁面
  }
  loadData();
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('sr06')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.searchType" class="!w-36">
                <ElOption :label="tl('agent_account')" :value="1" />
                <ElOption :label="tl('member_account')" :value="2" />
                <ElOption :label="tl('member_nickname')" :value="3" />
                <ElOption :label="tl('num')" :value="4" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('platform')">
            <div class="w-full">
              <ElSelect
                v-model="filters.vendor_id"
                class="w-full"
                collapse-tags
                collapse-tags-tooltip
                filterable
                multiple
              >
                <ElOption
                  v-for="v in vendors"
                  :key="v.id"
                  :label="v.name"
                  :value="v.id"
                />
              </ElSelect>
              <div class="mt-1 flex gap-3">
                <ElButton
                  link
                  size="small"
                  type="primary"
                  @click="selectAllVendors"
                >
                  {{ tl('select_all') }}
                </ElButton>
                <ElButton link size="small" @click="clearVendors">
                  {{ tl('clear') }}
                </ElButton>
              </div>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('success')" :value="1" />
              <ElOption :label="tl('failure')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
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
      <template #header>
        <span class="font-medium">{{ tl('detaillist') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn
          :label="tl('ordernum')"
          prop="transaction_no"
          min-width="170"
        />
        <ElTableColumn :label="tl('date')" prop="created_at" width="160" />
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('platform')" min-width="100">
          <template #default="{ row }">
            <span class="text-info font-bold">{{ row.vendor }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" width="90">
          <template #default="{ row }">
            {{ Number(row.type) === 1 ? tl('transferout') : tl('transferin') }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_nickname')"
          prop="member_nickname"
          min-width="100"
        />
        <ElTableColumn :label="tl('change_point')" align="right" width="110">
          <template #default="{ row }">
            <span
              :class="{ 'text-red-500': changePoint(row as ReportSr06Row) < 0 }"
            >
              {{ fmt(changePoint(row as ReportSr06Row)) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="120">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1000 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ statusText(row as ReportSr06Row) }}
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
