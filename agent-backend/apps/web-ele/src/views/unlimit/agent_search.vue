<script lang="ts" setup>
import type { UnlimitAgentRow, UnlimitAgentSearchSummary } from '#/api';

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

import { getUnlimitAgentSearchApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'UnlimitAgentSearch' });

const PER_PAGE = 10;

const filters = reactive({
  agent: '',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const summary = ref<UnlimitAgentSearchSummary>({});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<UnlimitAgentRow>(
    ({ page }) => {
      const [start, end] = filters.time_range ?? [];
      return getUnlimitAgentSearchApi({
        agent: filters.agent,
        end_time: toApiTime(end ?? ''),
        page,
        start_time: toApiTime(start ?? ''),
      });
    },
    {
      guard: () => {
        const [start, end] = filters.time_range ?? [];
        return Boolean(start && end);
      },
      onLoaded: (res) => {
        summary.value =
          Number(res.code) === 1000
            ? ((res as { data_summary?: UnlimitAgentSearchSummary })
                .data_summary ?? {})
            : {};
      },
      perPage: PER_PAGE,
    },
  );

const fmt = (v?: number | string) => amountThousands(v, 2);

/**
 * 日總業績 = 日直屬 + 日推廣（比照舊版）。後端 data_summary.performance 是逐列先四捨五入
 * 再加總，會有 0.01 飄移；這裡用整數分加總避免浮點、並四捨五入顯示（amountThousands 是無條件捨去）。
 */
const totalPerformance = computed(() => {
  const d = Math.round(Number(summary.value.performance_direct ?? 0) * 100);
  const t = Math.round(Number(summary.value.performance_team ?? 0) * 100);
  return ((d + t) / 100).toLocaleString('en-US', {
    maximumFractionDigits: 2,
    minimumFractionDigits: 2,
  });
});

const title = computed(
  () =>
    `${tl('menu_reward_mode_management')} > ${tl('menu_unlimit')} > ${tl('menu_unlimit_agent_search')}`,
);

function handleReset() {
  filters.agent = '';
  filters.time_range = [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ];
  search();
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
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent" clearable />
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
            <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </div>
        </div>
      </ElForm>
    </ElCard>

    <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <ElCard shadow="never">
        <div class="text-sm opacity-70">{{ tl('day_total_sales') }}</div>
        <div class="mt-1 text-lg font-semibold">
          {{ totalPerformance }}
        </div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-sm opacity-70">{{ tl('day_promote_sales') }}</div>
        <div class="mt-1 text-lg font-semibold">
          {{ fmt(summary.performance_direct) }}
        </div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-sm opacity-70">{{ tl('day_direct_sales') }}</div>
        <div class="mt-1 text-lg font-semibold">
          {{ fmt(summary.performance_team) }}
        </div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-sm opacity-70">
          {{ tl('reward') }}{{ tl('direct_promote') }}
        </div>
        <div class="mt-1 text-lg font-semibold">{{ fmt(summary.bonus) }}</div>
      </ElCard>
    </div>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('agent_account')"
          prop="username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('day_total_sales')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ fmt(row.performance_total) }}
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
          <template #default="{ row }">{{ fmt(row.bonus_total) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('direct_promote')" min-width="160">
          <template #default="{ row }">
            ({{ fmt(row.bonus_direct) }} + {{ fmt(row.bonus_team) }})
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('createdate')"
          prop="summary_date"
          align="center"
          min-width="150"
        />
        <ElTableColumn :label="tl('memo')" prop="memo" min-width="120" />
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
