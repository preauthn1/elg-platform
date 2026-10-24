<script lang="ts" setup>
import type { Sr08Row } from '#/api';

import { onMounted, reactive } from 'vue';

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

import { getReportSr08Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands, toHalfWidth } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSr08' });

const PER_PAGE = 20;

const filters = reactive({
  // 「今天」由所選時區定義（target/tickets#4095）
  time_range: [
    nowInUserTz().format('YYYY-MM-DD'),
    nowInUserTz().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<Sr08Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSr08Api({
        // 查詢窗以所選時區的日界送出，平移成端點的 GMT+8 牆鐘
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

const fmt = (v?: null | number | string) => amountThousands(v, 2);

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：欄位（9 欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  const params = {
    end_time: toApiTime(`${end} 23:59:59`),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(`${start} 00:00:00`),
    username: toHalfWidth(filters.username),
  };
  await runExport(
    () => getReportSr08Api(params),
    `sr08_${Date.now()}`,
    [
      tl('num'),
      tl('date'),
      tl('send_account'),
      tl('agent_account'),
      tl('accept_account'),
      tl('point'),
      tl('balance'),
      tl('memo'),
      tl('status'),
    ],
    (r) => [
      r.id,
      fromApiTime(r.created_at),
      r.operator,
      r.agent_username,
      r.member_username,
      fmt(r.point),
      fmt(r.balance),
      r.remark,
      Number(r.status) === 1000 ? tl('success_received') : tl('failure'),
    ],
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: tl('sr08'),
    },
  );
}

// 頁面載入即查詢
onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('sr08')}`">
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
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <!-- 端點回 GMT+8 牆鐘，換算成所選時區顯示 -->
        <ElTableColumn
          :formatter="(row: Sr08Row) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn
          :label="tl('send_account')"
          prop="operator"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('accept_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('point')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.point) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('balance')" align="right" width="110">
          <template #default="{ row }">{{ fmt(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="140" />
        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1000 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{
                Number(row.status) === 1000
                  ? tl('success_received')
                  : tl('failure')
              }}
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
