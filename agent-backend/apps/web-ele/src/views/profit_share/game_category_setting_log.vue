<script lang="ts" setup>
import type { ProfitShareCategoryLogRow } from '#/api';

import { computed, onMounted, reactive } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElButtonGroup,
  ElCard,
  ElDatePicker,
  ElForm,
  ElFormItem,
  ElInput,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getProfitShareCategoryLogApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { toHalfWidth } from '#/utils/format';
import { SIX_TYPES_KINDS, sixTypesQuickRange } from '#/utils/quick-range';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ProfitShareGameCategorySettingLog' });

const PER_PAGE = 10;

const userStore = useUserStore();
/** role_id 1/11/12 才有代理帳號搜尋 */
const isHighRole = computed(() =>
  [1, 11, 12].includes(Number((userStore.userInfo as any)?.role_id)),
);

const filters = reactive({
  end_time: nowInUserTz().format('YYYY-MM-DD 23:59'),
  start_time: nowInUserTz().format('YYYY-MM-DD 00:00'),
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<ProfitShareCategoryLogRow>(
    ({ page, per_page }) =>
      getProfitShareCategoryLogApi({
        end_time: toApiTime(filters.end_time),
        page,
        per_page,
        start_time: toApiTime(filters.start_time),
        username: isHighRole.value ? toHalfWidth(filters.username) : '',
      }),
    { perPage: PER_PAGE, showError: true },
  );

function applyRangeKind(kind: string) {
  const [s, e] = sixTypesQuickRange(kind);
  filters.start_time = s;
  filters.end_time = e;
}

onMounted(search);
</script>

<template>
  <Page
    :title="`${tl('menu_agent_profit_sharing_management')} > ${tl('menu_profit_share_game_category_setting_log')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-3">
          <ElFormItem v-if="isHighRole" :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <ElDatePicker
              v-model="filters.start_time"
              class="!w-full"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('to')">
            <ElDatePicker
              v-model="filters.end_time"
              class="!w-full"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-between">
          <ElButtonGroup>
            <ElButton
              v-for="k in SIX_TYPES_KINDS"
              :key="k"
              size="small"
              @click="applyRangeKind(k)"
            >
              {{ tl(k) }}
            </ElButton>
          </ElButtonGroup>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small">
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
          :label="tl('type')"
          prop="shareholder_category_name"
          min-width="120"
        />
        <ElTableColumn :label="tl('direct_share')" min-width="140">
          <template #default="{ row }">
            {{ (row as ProfitShareCategoryLogRow).pre_direct_value }} &gt;
            {{ (row as ProfitShareCategoryLogRow).direct_value }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('sub_share')" min-width="140">
          <template #default="{ row }">
            {{ (row as ProfitShareCategoryLogRow).pre_team_value }} &gt;
            {{ (row as ProfitShareCategoryLogRow).team_value }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <span
              :class="
                Number((row as ProfitShareCategoryLogRow).schedule_status) === 1
                  ? 'text-green-600'
                  : 'text-red-500'
              "
            >
              {{ (row as ProfitShareCategoryLogRow).schedule_status_txt }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('memo')"
          prop="schedule_remark"
          min-width="140"
        />
        <ElTableColumn
          :label="tl('updated_at')"
          :formatter="
            (row: ProfitShareCategoryLogRow) => fromApiTime(row.updated_at)
          "
          min-width="160"
        />
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
