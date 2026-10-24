<script lang="ts" setup>
import type { UnlimitCancelRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getUnlimitCancelRecordApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'UnlimitCancelRecord' });

const PER_PAGE = 20;

const filters = reactive({
  member_username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<UnlimitCancelRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getUnlimitCancelRecordApi({
        end_time: toApiTime(end ?? ''),
        member_username: filters.member_username,
        page,
        start_time: toApiTime(start ?? ''),
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 2);

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_cancel_bet_record')}`,
);

const exporting = ref(false);
/** 匯出 Excel：欄位（5 欄） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) return;
  exporting.value = true;
  try {
    const params = {
      end_time: toApiTime(end),
      member_username: filters.member_username,
      page: 1,
      per_page: 100_000,
      start_time: toApiTime(start),
    };
    const res = await getUnlimitCancelRecordApi(params);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel({
        audit: { conditions: params },
        columns: [
          { title: tl('date'), width: 30 },
          { title: tl('member_account'), width: 30 },
          { title: tl('bet'), width: 30 },
          { title: tl('type'), width: 30 },
          { title: tl('operator'), width: 30 },
        ],
        dateRange: [start, end],
        rows: rows.map((r) => [
          fromApiTime(r.created_at),
          r.username,
          fmt(r.bet),
          tl(`cancel_record_type.${r.type}`),
          r.operator,
        ]),
        title: tl('menu_cancel_bet_record'),
      }),
    );
  } finally {
    exporting.value = false;
  }
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('member_account')">
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
          :formatter="(row: UnlimitCancelRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bet')" align="right" min-width="110">
          <template #default="{ row }">{{ fmt(row.bet) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" min-width="120">
          <template #default="{ row }">
            {{ tl(`cancel_record_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operator')" prop="operator" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
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
