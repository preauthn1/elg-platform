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

import { getEventRewardTypesApi, getReportSr21Api } from '#/api';
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

defineOptions({ name: 'ReportSr21' });

const PER_PAGE = 20;

const rewardTypes = ref<Array<{ event_reward_id: number }>>([]);

const filters = reactive({
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: 0 as number | string,
  username: '',
  username_type: 1,
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<WalletLogRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      const params: Record<string, unknown> = {
        end_time: toApiTime(`${end}:00`),
        page,
        per_page,
        start_time: toApiTime(`${start}:00`),
        type: filters.type,
      };
      if (filters.username) {
        params[
          filters.username_type === 1 ? 'agent_username' : 'member_username'
        ] = toHalfWidth(filters.username);
      }
      return getReportSr21Api(params as any);
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

/** 備註：type 1/2→廠商名；4→reward_1；其餘→remark */
function memoText(row: WalletLogRow) {
  if (row.type === 1 || row.type === 2) return row.vendor_name ?? '';
  if (row.type === 4) return tl('reward_1');
  return row.remark ?? '';
}

const exporting = ref(false);
/** 匯出 Excel：欄位（9 欄；sr22 共用同一匯出） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params: Record<string, unknown> = {
      end_time: toApiTime(`${end}:00`),
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(`${start}:00`),
      type: filters.type,
    };
    if (filters.username) {
      params[
        filters.username_type === 1 ? 'agent_username' : 'member_username'
      ] = toHalfWidth(filters.username);
    }
    const res = await getReportSr21Api(params as any);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr21_${Date.now()}`,
        [
          tl('date'),
          tl('agent_account'),
          tl('member_account'),
          tl('before_rolling_chip'),
          tl('change_rolling_chip'),
          tl('moment_rolling_chip'),
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
          tl(`rolling_chip_type.${r.type}`),
          Number(r.status) === 1000 ? tl('success') : tl('failure'),
          memoText(r),
        ]),
        {
          audit: { conditions: params },
          dateRange: filters.time_range as [string, string],
          title: tl('menu_report_sr21'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  getEventRewardTypesApi()
    .then((d) => {
      rewardTypes.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr21')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.username_type" class="!w-32">
                <ElOption :label="tl('agent_account')" :value="1" />
                <ElOption :label="tl('member_account')" :value="2" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('source')">
            <ElSelect v-model="filters.type" class="w-full" filterable>
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="r in rewardTypes"
                :key="r.event_reward_id"
                :label="tl(`rolling_chip_type.${r.event_reward_id}`)"
                :value="r.event_reward_id"
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
        <ElTableColumn
          :label="tl('before_rolling_chip')"
          align="right"
          width="120"
        >
          <template #default="{ row }">{{ fmt(row.before_balance) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('change_rolling_chip')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            <span :class="{ 'text-red-500': Number(row.point) < 0 }">{{
              fmt(row.point)
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('moment_rolling_chip')"
          align="right"
          width="120"
        >
          <template #default="{ row }">{{ fmt(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">
            {{ tl(`rolling_chip_type.${row.type}`) }}
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
