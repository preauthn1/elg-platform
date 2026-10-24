<script lang="ts" setup>
import type { WalletLogRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

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

import { getPromotionWalletTypesApi, getReportSr22Api } from '#/api';
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

defineOptions({ name: 'ReportSr22' });

const PER_PAGE = 20;

/** 備註顯示廠商名的類型 */
const SHOW_VENDOR = new Set([1, 2, 27, 28, 29, 32, 33]);

const txTypes = ref<Array<{ type_id: number }>>([]);

const filters = reactive({
  member_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: 0 as number | string,
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<WalletLogRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getReportSr22Api({
        end_time: toApiTime(`${end}:00`),
        member_username: toHalfWidth(filters.member_username),
        page,
        per_page,
        start_time: toApiTime(`${start}:00`),
        type: filters.type,
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

/** 狀態：type 27 用 status_1/2，否則 success/failure */
function statusText(row: WalletLogRow) {
  const ok = Number(row.status) === 1000;
  if (row.type === 27) return ok ? tl('status_1') : tl('status_2');
  return ok ? tl('success') : tl('failure');
}

function memoText(row: WalletLogRow) {
  return SHOW_VENDOR.has(Number(row.type))
    ? (row.vendor_name ?? '')
    : (row.remark ?? '');
}

const exporting = ref(false);
/** 匯出 Excel：9 欄結構同 sr21 匯出。 */
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
      end_time: toApiTime(`${end}:00`),
      member_username: toHalfWidth(filters.member_username),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(`${start}:00`),
      type: filters.type,
    };
    const res = await getReportSr22Api(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr22_${Date.now()}`,
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
          fromApiTime(r.created_at),
          r.agent_username,
          r.member_username,
          fmt(r.before_balance),
          fmt(r.point),
          fmt(r.balance),
          tl(`promotion_wallet_type.${r.type}`),
          statusText(r),
          memoText(r),
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr22'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  getPromotionWalletTypesApi()
    .then((d) => {
      txTypes.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr22')}`">
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
          <ElFormItem :label="tl('source')">
            <ElSelect v-model="filters.type" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="t in txTypes"
                :key="t.type_id"
                :label="tl(`promotion_wallet_type.${t.type_id}`)"
                :value="t.type_id"
              />
            </ElSelect>
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
        <ElTableColumn :label="tl('type')" min-width="130">
          <template #default="{ row }">
            {{ tl(`promotion_wallet_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="100">
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
