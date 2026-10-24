<script lang="ts" setup>
import type { Ssr07Row } from '#/api';

import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

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

import { getReportSsr07Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { looseThousands, toHalfWidth } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSsr07' });

const PER_PAGE = 20;

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD'),
    nowInUserTz().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<Ssr07Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSsr07Api({
        end_time: toApiTime(`${end} 23:59:59`),
        page,
        per_page,
        start_time: toApiTime(`${start} 00:00:00`),
        username: toHalfWidth(filters.username),
      });
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

/** 金額顯示原始小數（最多 2 位、千分位） */
const fmt = (v?: null | number | string) => looseThousands(v, 2, '0');

const exporting = ref(false);
/** 匯出 Excel：欄位（7 欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(`${end} 23:59:59`),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(`${start} 00:00:00`),
      username: toHalfWidth(filters.username),
    };
    const res = await getReportSsr07Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await exportExcel(
      `ssr07_${Date.now()}`,
      [
        tl('num'),
        tl('date'),
        tl('send_account'),
        tl('accept_account'),
        tl('point'),
        tl('handling_fee'),
        tl('status'),
      ],
      rows.map((r) => [
        r.id,
        fromApiTime(r.created_at),
        r.operator_username,
        r.receiver_username,
        fmt(r.amount),
        fmt(r.tax),
        Number(r.status) === 1000 ? tl('success') : tl('failure'),
      ]),
      {
        audit: { conditions: params },
        dateRange: filters.time_range as [string, string],
        title: tl('menu_report_ssr07'),
      },
    );
  } finally {
    exporting.value = false;
  }
}

// 頁面載入即查詢；支援帶 query 進入
const route = useRoute();
onMounted(() => {
  if (route.query.member_username) {
    filters.username = String(route.query.member_username);
  }
  loadData();
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_ssr07')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" />
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
        <ElTableColumn :label="tl('num')" prop="id" width="100" />
        <ElTableColumn
          :formatter="(row: Ssr07Row) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="170"
        />
        <ElTableColumn
          :label="`${tl('transferout')}${tl('username')}`"
          prop="operator_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('transferin')}${tl('username')}`"
          min-width="120"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.receiver_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('handling_fee')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.tax) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="90">
          <template #default="{ row }">
            <!-- 成功用一般文字色，僅失敗標紅（target/tickets#4166） -->
            <span :class="Number(row.status) === 1000 ? '' : 'text-red-500'">
              {{ Number(row.status) === 1000 ? tl('success') : tl('failure') }}
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
