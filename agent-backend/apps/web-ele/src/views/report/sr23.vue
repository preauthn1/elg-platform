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

import { getReportSr23Api } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ReportSr23' });

const PER_PAGE = 20;

const filters = reactive({
  agent_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

/** 代理帳號必填 + 起迄必填 */
function guard() {
  if (!toHalfWidth(filters.agent_username)) {
    ElMessage.warning(tl('agent_account'));
    return false;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return false;
  }
  return true;
}

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<WalletLogRow>(
    ({ page, per_page }) =>
      getReportSr23Api({
        agent_username: toHalfWidth(filters.agent_username),
        end_time: toApiTime(`${filters.time_range[1]}:00`),
        page,
        per_page,
        start_time: toApiTime(`${filters.time_range[0]}:00`),
      }),
    { guard, perPage: PER_PAGE, showError: true },
  );

const fmt = (v?: null | number | string) => amountThousands(v, 2);
/** -1 顯示 ∞ */
function fmtBalance(v?: null | number | string) {
  if (Number(v) === -1) return '∞';
  return fmt(v);
}

const exporting = ref(false);
/** 匯出 Excel（欄位對齊舊版 ajax action=sr23） */
async function handleExport() {
  if (!guard()) return;
  exporting.value = true;
  try {
    const params = {
      agent_username: toHalfWidth(filters.agent_username),
      end_time: toApiTime(`${filters.time_range[1]}:00`),
      page: 1,
      per_page: 10_000,
      start_time: toApiTime(`${filters.time_range[0]}:00`),
    };
    const res = await getReportSr23Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr23_${Date.now()}`,
        [
          tl('date'),
          `${tl('agent_account')}(from)`,
          `${tl('agent_account')}(to)`,
          tl('member_account'),
          tl('before_point'),
          tl('change_point'),
          tl('moment_point'),
          tl('type'),
          tl('status'),
          tl('memo'),
        ],
        rows.map((r) => [
          r.created_at ?? '',
          r.from_username ?? '',
          r.to_username ?? '',
          r.member_username ?? '',
          fmtBalance(r.before_balance),
          fmt(r.point),
          fmtBalance(r.balance),
          tl(`agent_wallet_type.${r.type}`),
          Number(r.status) === 1 ? tl('success') : tl('failure'),
          r.remark ?? '',
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr23'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr23')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')" required>
            <ElInput
              v-model="filters.agent_username"
              :placeholder="tl('required_column')"
              clearable
            />
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
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
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
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
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">
            {{ tl(`agent_wallet_type.${row.type}`) }}
          </template>
        </ElTableColumn>
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
