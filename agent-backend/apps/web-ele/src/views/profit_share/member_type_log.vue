<script lang="ts" setup>
import type { ProfitShareMemberScheduleRow } from '#/api';

import { onMounted, reactive } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElButtonGroup,
  ElCard,
  ElDatePicker,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getProfitShareMemberScheduleListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { toHalfWidth } from '#/utils/format';
import { SIX_TYPES_KINDS, sixTypesQuickRange } from '#/utils/quick-range';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ProfitShareMemberTypeLog' });

const PER_PAGE = 10;

/** 狀態下拉（-1=全部、0/1/2/9） */
const STATUS_OPTIONS = [-1, 0, 1, 2, 9] as const;

/** schedule_status → 文字色 */
function statusColor(status?: number | string) {
  switch (Number(status)) {
    case 1: {
      return 'text-green-600';
    }
    case 2: {
      return 'text-gray-400';
    }
    case 9: {
      return 'text-red-500';
    }
    default: {
      return '';
    }
  }
}

const filters = reactive({
  end_time: nowInUserTz().format('YYYY-MM-DD 23:59'),
  start_time: nowInUserTz().format('YYYY-MM-DD 00:00'),
  status: -1 as number | string,
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<ProfitShareMemberScheduleRow>(
    ({ page, per_page }) =>
      getProfitShareMemberScheduleListApi({
        end_time: toApiTime(filters.end_time),
        page,
        per_page,
        start_time: toApiTime(filters.start_time),
        status: filters.status,
        username: toHalfWidth(filters.username),
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
    :title="`${tl('menu_cj7_user_management')} > ${tl('menu_profit_share_member_type_log')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-4">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('created_time')">
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
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption
                v-for="s in STATUS_OPTIONS"
                :key="s"
                :label="s === -1 ? tl('all') : tl(`schedule_status.${s}`)"
                :value="s"
              />
            </ElSelect>
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
          :label="tl('content')"
          prop="data_value"
          min-width="160"
        />
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <span
              :class="
                statusColor(
                  (row as ProfitShareMemberScheduleRow).schedule_status,
                )
              "
            >
              {{ (row as ProfitShareMemberScheduleRow).schedule_status_txt }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('memo')"
          prop="schedule_remark"
          min-width="140"
        />
        <ElTableColumn :label="tl('operator')" prop="operator" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_time')"
          :formatter="
            (row: ProfitShareMemberScheduleRow) => fromApiTime(row.created_at)
          "
          min-width="160"
        />
        <ElTableColumn :label="tl('updated_at')" min-width="160">
          <template #default="{ row }">
            {{
              Number((row as ProfitShareMemberScheduleRow).schedule_status) ===
              0
                ? ''
                : (row as ProfitShareMemberScheduleRow).updated_at
            }}
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
