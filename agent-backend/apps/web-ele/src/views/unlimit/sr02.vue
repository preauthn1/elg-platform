<script lang="ts" setup>
import type { UnlimitTransactionRow } from '#/api';

import { computed, reactive } from 'vue';

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

import { getUnlimitTransactionListApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'UnlimitSr02' });

const PER_PAGE = 10;

const filters = reactive({
  username: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<UnlimitTransactionRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getUnlimitTransactionListApi({
        end_time: toApiTime(end ?? ''),
        page,
        start_time: toApiTime(start ?? ''),
        username: filters.username,
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

/** 當下點數：額度為負視為無限，否則＝額度−已用額度 */
function momentPoint(row: UnlimitTransactionRow) {
  if (Number(row.to_limit_of_credit) < 0) return tl('unlimited');
  return amountThousands(
    Number(row.to_limit_of_credit ?? 0) - Number(row.to_used_credit ?? 0),
    2,
  );
}

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_unlimit')} > ${tl('menu_unlimit_sr02')}`,
);

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
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
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('detaillist') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" min-width="90" />
        <ElTableColumn
          :formatter="
            (row: UnlimitTransactionRow) => fromApiTime(row.created_at)
          "
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_nickname')"
          prop="nickname"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('change_point')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">{{ fmt(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('moment_point')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ momentPoint(row as UnlimitTransactionRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('order_status')"
          prop="status"
          min-width="110"
        />
        <ElTableColumn :label="tl('memo')" prop="remark" min-width="120" />
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
