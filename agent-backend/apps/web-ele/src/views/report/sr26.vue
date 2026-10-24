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

import { getReportSr26Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands, toHalfWidth } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportSr26' });

const PER_PAGE = 20;

const filters = reactive({
  agent_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const sumChangePoint = ref<number | string>(0);

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<WalletLogRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSr26Api({
        agent_username: toHalfWidth(filters.agent_username),
        end_time: toApiTime(`${end}:00`),
        page,
        per_page,
        start_time: toApiTime(`${start}:00`),
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
      onLoaded: (res) => {
        if (Number(res.code) === 1000) {
          sumChangePoint.value =
            (res as { sum_change_point?: number | string }).sum_change_point ??
            0;
        }
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmt = (v?: null | number | string) => amountThousands(v, 2);
/** -1 顯示 ∞ */
function fmtBalance(v?: null | number | string) {
  if (Number(v) === -1) return '∞';
  return fmt(v);
}

/** 表尾合計列 */
function summaryMethod() {
  const sums: string[] = Array.from({ length: 9 }).fill('') as string[];
  sums[0] = tl('total_count');
  sums[4] = fmt(sumChangePoint.value);
  return sums;
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr26')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
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
          :formatter="(row: WalletLogRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn
          :label="`${tl('agent_account')}(from)`"
          prop="from_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.from_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('agent_account')}(to)`"
          prop="to_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.to_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('before_point')" align="right" width="110">
          <template #default="{ row }">
            {{ fmtBalance(row.before_balance) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('change_point')" align="right" width="110">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.point) < 0 }">{{
              fmt(row.point)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="110">
          <template #default="{ row }">{{ fmtBalance(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" prop="type_text" min-width="120" />
        <ElTableColumn :label="tl('status')" width="100">
          <template #default="{ row }">
            <span
              :class="Number(row.status) === 1 ? '' : 'font-bold text-red-500'"
            >
              {{ Number(row.status) === 1 ? tl('success') : tl('failure') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="140" />
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
