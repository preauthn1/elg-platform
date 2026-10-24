<script lang="ts" setup>
import type { Sr24Row } from '#/api';

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

import { getReportSr24Api } from '#/api';
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

import Sr24BetDetailDialog from './components/sr24-bet-detail-dialog.vue';

defineOptions({ name: 'ReportSr24' });

const PER_PAGE = 20;

const filters = reactive({
  member_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const betDetailRef = ref<InstanceType<typeof Sr24BetDetailDialog>>();

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 會員帳號必填 + 起迄必填 */
function guard() {
  if (!toHalfWidth(filters.member_username)) {
    ElMessage.warning(tl('member_account'));
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
  useListQuery<Sr24Row>(
    ({ page, per_page }) =>
      getReportSr24Api({
        end_time: toApiTime(`${filters.time_range[1]}:00`),
        member_username: toHalfWidth(filters.member_username),
        page,
        per_page,
        start_time: toApiTime(`${filters.time_range[0]}:00`),
      }),
    { guard, perPage: PER_PAGE, showError: true },
  );

/** 狀態：1000 成功 / 999 遊戲中 / 1111 已退款 / 1112 已回收 / 其餘 failure */
function statusText(row: Sr24Row) {
  const s = Number(row.status);
  if (s === 1000) return tl('success');
  if (s === 999) return tl('status_2');
  if (s === 1111) return tl('status_1111');
  if (s === 1112) return tl('status_1112');
  return tl('failure');
}

/** 備註：type 3/4/27/28/29 顯示遊戲商；type 9 加上操作人 */
function memoLines(row: Sr24Row): string[] {
  if ([3, 4, 27, 28, 29].includes(Number(row.type))) {
    return [row.vendor_name ?? ''];
  }
  if (Number(row.type) === 9) {
    return [row.remark ?? '', row.operator ?? ''];
  }
  return [row.remark ?? ''];
}

const exporting = ref(false);
/** 匯出 Excel（欄位對齊舊版 ajax action=sr24） */
async function handleExport() {
  if (!guard()) return;
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(`${filters.time_range[1]}:00`),
      member_username: toHalfWidth(filters.member_username),
      page: 1,
      per_page: 10_000,
      start_time: toApiTime(`${filters.time_range[0]}:00`),
    };
    const res = await getReportSr24Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr24_${Date.now()}`,
        [
          tl('date'),
          tl('agent_account'),
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
          r.agent_username ?? '',
          r.member_username ?? '',
          fmt(r.before_balance),
          fmt(r.point),
          fmt(r.balance),
          tl(`member_wallet_type.${r.type}`),
          statusText(r),
          memoLines(r).filter(Boolean).join(' '),
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr24'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr24')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('member_account')" required>
            <ElInput
              v-model="filters.member_username"
              :placeholder="tl('required_column')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="md:col-span-2">
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
          :formatter="(row: Sr24Row) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          min-width="110"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('before_point')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.before_balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('change_point')" align="right" width="120">
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.point) < 0 }">
              {{ fmt(row.point) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" min-width="150">
          <template #default="{ row }">
            {{ tl(`member_wallet_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="110">
          <template #default="{ row }">
            <span
              :class="row.is_success_status ? '' : 'font-bold text-red-500'"
            >
              {{ statusText(row as Sr24Row) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="130">
          <template #default="{ row }">
            <div v-for="(line, i) in memoLines(row as Sr24Row)" :key="i">
              {{ line }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="Number(row.type) === 27"
              size="small"
              type="primary"
              @click="betDetailRef?.open(row.transaction_id)"
            >
              {{ tl('detail') }}
            </ElButton>
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

    <Sr24BetDetailDialog ref="betDetailRef" />
  </Page>
</template>
