<script lang="ts" setup>
import type { ProfitShareScheduleRow, ProfitShareTransactionRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

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

import {
  getProfitShareTransactionLogApi,
  getProfitShareUnexecutedScheduleApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands, toHalfWidth } from '#/utils/format';
import { SIX_TYPES_KINDS, sixTypesQuickRange } from '#/utils/quick-range';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'ProfitShareTransactionLog' });

const PER_PAGE = 10;

const userStore = useUserStore();
/** 角色 1/11/12（公司股東管理者）才有股東帳號搜尋與未處理排程表 */
const isHighRole = computed(() =>
  [1, 11, 12].includes(Number((userStore.userInfo as any)?.role_id)),
);

const filters = reactive({
  end_time: nowInUserTz().format('YYYY-MM-DD 23:59'),
  start_time: nowInUserTz().format('YYYY-MM-DD 00:00'),
  username: '',
});

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<ProfitShareTransactionRow>(
    ({ page, per_page }) =>
      getProfitShareTransactionLogApi({
        end_time: toApiTime(filters.end_time),
        page,
        per_page,
        start_time: toApiTime(filters.start_time),
        username: isHighRole.value ? toHalfWidth(filters.username) : '',
      }),
    { perPage: PER_PAGE },
  );
const pending = ref<ProfitShareScheduleRow[]>([]);

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

function negColor(v?: null | number | string) {
  return Number(v) < 0 ? 'red' : '';
}

async function loadPending() {
  if (!isHighRole.value) return;
  const res = await getProfitShareUnexecutedScheduleApi({
    username: toHalfWidth(filters.username),
  });
  pending.value = Number(res.code) === 1000 ? (res.data?.data ?? []) : [];
}

function handleSearch() {
  loadPending();
  search();
}

function applyRangeKind(kind: string) {
  const [s, e] = sixTypesQuickRange(kind);
  filters.start_time = s;
  filters.end_time = e;
}

onMounted(handleSearch);
</script>

<template>
  <Page
    :title="`${tl('menu_agent_profit_sharing_management')} > ${tl('menu_profit_share_transaction_record')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-3">
          <ElFormItem v-if="isHighRole" :label="tl('shareholder_account')">
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

    <ElCard v-if="isHighRole" class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium text-red-500">{{
          tl('pending_records')
        }}</span>
      </template>
      <ElTable :data="pending" border size="small">
        <ElTableColumn
          :label="tl('id')"
          prop="schedule_log_id"
          min-width="100"
        />
        <ElTableColumn :label="tl('type')" min-width="140">
          <template #default="{ row }">
            {{
              tl(
                Number((row as ProfitShareScheduleRow).schedule_type_id) === 1
                  ? 'transfer_in_profit_share'
                  : 'transfer_out_profit_share',
              )
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" min-width="130">
          <template #default="{ row }">
            {{ fmt4((row as ProfitShareScheduleRow).data_value) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_at')"
          :formatter="
            (row: ProfitShareTransactionRow) => fromApiTime(row.created_at)
          "
          min-width="160"
        />
        <ElTableColumn :label="tl('estimated_effective_time')" min-width="160">
          <template #default="{ row }">
            <span class="text-blue-500">
              {{ (row as ProfitShareScheduleRow).estimated_effective_time }}
            </span>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <ElCard shadow="never">
      <template v-if="isHighRole" #header>
        <span class="font-medium text-green-600">
          {{ tl('processed_records') }}
        </span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small">
        <ElTableColumn
          :label="tl('superior_shareholder_account')"
          prop="parent_username"
          min-width="130"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('shareholder_account')" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" prop="type_name" min-width="120" />
        <ElTableColumn :label="tl('amount')" align="right" min-width="130">
          <template #default="{ row }">
            <span
              :style="{
                color: negColor((row as ProfitShareTransactionRow).amount),
              }"
            >
              {{ fmt4((row as ProfitShareTransactionRow).amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('before_change')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            <span
              :style="{
                color: negColor(
                  (row as ProfitShareTransactionRow).before_balance,
                ),
              }"
            >
              {{ fmt4((row as ProfitShareTransactionRow).before_balance) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('after_change')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            <span
              :style="{
                color: negColor((row as ProfitShareTransactionRow).balance),
              }"
            >
              {{ fmt4((row as ProfitShareTransactionRow).balance) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <span
              :class="
                Number((row as ProfitShareTransactionRow).status) === 1
                  ? 'text-green-600'
                  : 'text-red-500'
              "
            >
              {{ (row as ProfitShareTransactionRow).status_name }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_at')"
          :formatter="
            (row: ProfitShareTransactionRow) => fromApiTime(row.created_at)
          "
          min-width="160"
        />
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
