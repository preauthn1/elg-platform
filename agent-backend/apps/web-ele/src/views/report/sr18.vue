<script lang="ts" setup>
import type { Sr18Row } from '#/api';

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

import { getReportSr18Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { toHalfWidth } from '#/utils/format';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportSr18' });

const PER_PAGE = 20;

const filters = reactive({
  agent_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<Sr18Row>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSr18Api({
        agent_username: toHalfWidth(filters.agent_username),
        end_time: toApiTime(end ?? ''),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
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

/** 秒數轉 hh:mm:ss */
function secToHms(cnt?: number, sec?: number) {
  const c = Number(cnt ?? 0);
  if (!c) return '00:00:00';
  const seconds = Number(sec ?? 0) / c;
  if (!Number.isFinite(seconds)) return '00:00:00';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds - 3600 * h) / 60);
  const s = Math.floor((seconds - 3600 * h - 60 * m) % 60);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${pad(h)}:${pad(m)}:${pad(s)}`;
}

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：欄位（6 欄；第5/6欄用 success 數值） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  const params = {
    agent_username: toHalfWidth(filters.agent_username),
    end_time: toApiTime(end),
    page: 1,
    per_page: 100_000,
    start_time: toApiTime(start),
  };
  await runExport(
    () => getReportSr18Api(params),
    `sr18_${Date.now()}`,
    [
      tl('cs_account'),
      `${tl('deposit')}/${tl('withdrawal')}`,
      tl('review_order_count'),
      tl('average_review_order_time'),
      tl('pass_order_count'),
      tl('average_pass_order_time'),
    ],
    (r) => [
      r.username,
      Number(r.cash_flow_id) === 5 ? tl('deposit') : tl('withdrawal'),
      r.audit_cnt,
      secToHms(r.audit_cnt, r.audit_second),
      r.success_cnt,
      secToHms(r.success_cnt, r.success_second),
    ],
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: tl('menu_report_sr18'),
    },
  );
}

// 頁面載入即查詢
onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr18')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('cs_account')">
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('cs_account')"
          prop="username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('deposit')}/${tl('withdrawal')}`"
          width="100"
        >
          <template #default="{ row }">
            {{
              Number(row.cash_flow_id) === 5 ? tl('deposit') : tl('withdrawal')
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('review_order_count')"
          align="center"
          width="110"
        >
          <template #default="{ row }">{{ row.audit_cnt }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('average_review_order_time')"
          align="center"
          width="140"
        >
          <template #default="{ row }">
            {{ secToHms(row.audit_cnt, row.audit_second) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('approved_order_count')"
          align="center"
          width="110"
        >
          <template #default="{ row }">{{ row.success_cnt }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('average_approved_order_time')"
          align="center"
          width="140"
        >
          <template #default="{ row }">
            {{ secToHms(row.success_cnt, row.success_second) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('pass_order_count')"
          align="center"
          width="110"
        >
          <template #default="{ row }">
            {{ Number(row.cash_flow_id) === 6 ? row.pay_cnt : '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('average_pass_order_time')"
          align="center"
          width="140"
        >
          <template #default="{ row }">
            {{
              Number(row.cash_flow_id) === 6
                ? secToHms(row.pay_cnt, row.pay_second)
                : '-'
            }}
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
