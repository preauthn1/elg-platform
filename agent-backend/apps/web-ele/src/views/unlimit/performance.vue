<script lang="ts" setup>
import type { UnlimitPerformanceRow } from '#/api';

import { computed, reactive } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getUnlimitPerformanceApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'UnlimitPerformance' });

const PER_PAGE = 20;

const filters = reactive({
  agent_username: '',
  username: '',
  time_range: [
    dayjs().format('YYYY-MM-DD'),
    dayjs().format('YYYY-MM-DD'),
  ] as string[],
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<UnlimitPerformanceRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getUnlimitPerformanceApi({
        agent_username: filters.agent_username,
        end_time: end ?? '',
        page,
        start_time: start ?? '',
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

function kindText(row: UnlimitPerformanceRow) {
  return Number(row.type) === 1 ? tl('agent') : tl('member');
}

/** 獎勵欄＝直推＋團隊獎金原始加總 */
function rewardSum(row: UnlimitPerformanceRow) {
  return Number(row.bonus_direct ?? 0) + Number(row.bonus_team ?? 0);
}

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_unlimit')} > ${tl('menu_unlimit_performance')}`,
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
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" :fixed-offset="8" />
            <FixedTimezoneHint />
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
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('time')"
          prop="summary_date"
          min-width="120"
        />
        <ElTableColumn :label="tl('kind')" min-width="90">
          <template #default="{ row }">
            {{ kindText(row as UnlimitPerformanceRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('day_promote_sales')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ fmt(row.performance_direct) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('day_direct_sales')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ fmt(row.performance_team) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('reward')" align="right" min-width="110">
          <template #default="{ row }">
            {{ rewardSum(row as UnlimitPerformanceRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('direct_promote')" min-width="160">
          <template #default="{ row }">
            ({{ fmt(row.bonus_direct) }} + {{ fmt(row.bonus_team) }})
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
