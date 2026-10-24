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

import { getReportSr05Api } from '#/api';
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

defineOptions({ name: 'ReportSr05' });

const PER_PAGE = 20;

const filters = reactive({
  member_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<WalletLogRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSr05Api({
        end_time: toApiTime(end ?? ''),
        member_username: toHalfWidth(filters.member_username),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
      });
    },
    {
      guard: () => {
        // 會員帳號必填
        if (!filters.member_username) {
          ElMessage.warning(tl('required_column'));
          return false;
        }
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
      showError: true,
    },
  );

const fmt = (v?: null | number | string) => amountThousands(v, 2);

/** 備註：type 1/2→廠商名；其餘→remark */
function memoText(row: WalletLogRow) {
  return row.type === 1 || row.type === 2
    ? (row.vendor_name ?? '')
    : (row.remark ?? '');
}

const exporting = ref(false);
/** 匯出 Excel：欄位（12 欄；值沿用畫面已驗格式） */
async function handleExport() {
  if (!filters.member_username) {
    ElMessage.warning(tl('required_column'));
    return;
  }
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(end),
      member_username: toHalfWidth(filters.member_username),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(start),
    };
    const res = await getReportSr05Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr05_${Date.now()}`,
        [
          tl('num'),
          tl('ordernum'),
          tl('date'),
          tl('agent_account'),
          tl('status'),
          tl('member_account'),
          tl('member_nickname'),
          tl('change_point'),
          tl('moment_point'),
          tl('execution_account'),
          tl('order_status'),
          tl('memo'),
        ],
        rows.map((r) => {
          const rr = r as Record<string, unknown> & WalletLogRow;
          return [
            rr.id as number | string,
            rr.transaction_no as string,
            fromApiTime(r.created_at),
            r.agent_username,
            Number(r.type) === 1 ? tl('transferin') : tl('transferout'),
            r.member_username,
            rr.member_nickname as string,
            fmt(r.point),
            fmt(r.balance),
            rr.operator_username as string,
            Number(r.status) === 1000 ? tl('success') : tl('failure'),
            r.remark,
          ];
        }),
        {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('menu_report_sr05'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr05')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')" required>
            <ElInput
              v-model="filters.member_username"
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
          <div class="flex gap-2">
            <ElButton :loading="exporting" type="success" @click="handleExport">
              {{ tl('excel') }}
            </ElButton>
            <ElButton :loading="loading" type="primary" @click="search">
              {{ tl('search') }}
            </ElButton>
          </div>
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
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
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
            <span :class="{ 'text-red-500': Number(row.point) < 0 }">{{
              fmt(row.point)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="120">
          <template #default="{ row }">{{ fmt(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">
            {{ tl(`transaction_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="100">
          <template #default="{ row }">
            <span
              :class="
                Number(row.status) === 1000 ? '' : 'font-bold text-red-500'
              "
            >
              {{ Number(row.status) === 1000 ? tl('success') : tl('failure') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="140">
          <template #default="{ row }">{{ memoText(row) }}</template>
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
