<script lang="ts" setup>
import type { WithdrawalListRow } from '#/api';

import { reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getAgentSelfWithdrawalListApi, getCustomizedSettingApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import WithdrawalReviewDialog from '../withdrawal/components/withdrawal-review-dialog.vue';

defineOptions({ name: 'AgentSelfWithdrawalIndex' });

const PER_PAGE = 20;

const STATUS_OPTIONS = [
  { label: 'all', value: '-1' },
  { label: 'order_create', value: '0' },
  { label: 'pending_review', value: '1' },
  { label: 'rejected', value: '2' },
  { label: 'wait_to_pay', value: '3' },
  { label: 'withdrawal_status_4', value: '4' },
  { label: 'withdrawal_status_21', value: '21' },
  { label: 'withdrawal_status_22', value: '22' },
  { label: 'withdrawal_status_23', value: '23' },
  { label: 'withdrawal_status_24', value: '24' },
  { label: 'withdrawal_status_29', value: '29' },
];

const reviewRef = ref<InstanceType<typeof WithdrawalReviewDialog>>();
const route = useRoute();

const filters = reactive({
  // 由通知鈴鐺進來時帶 status（待審核=1），否則預設全部（target/tickets#4193）
  status: (route.query.status as string) ?? ('-1' as number | string),
  time_range: [
    nowInUserTz().subtract(16, 'hour').format('YYYY-MM-DD 00:00'),
    nowInUserTz().add(8, 'hour').format('YYYY-MM-DD 23:59'),
  ] as string[],
  type: '1' as number | string,
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<WithdrawalListRow>(
    ({ page, per_page }) => {
      const [start, end] = filters.time_range ?? [];
      return getAgentSelfWithdrawalListApi({
        ...typeParam(),
        end_time: toApiTime(end ?? ''),
        page,
        per_page,
        start_time: toApiTime(start ?? ''),
        status: filters.status === '-1' ? undefined : filters.status,
      });
    },
    { perPage: PER_PAGE, showError: true },
  );

const enableBsb = ref(false);

function typeParam() {
  const p: Record<string, string> = {};
  if (Number(filters.type) === 1) p.order_no = filters.username;
  else if (Number(filters.type) === 2) p.member_username = filters.username;
  else if (Number(filters.type) === 3) p.account_no = filters.username;
  else if (Number(filters.type) === 4) p.transaction_no = filters.username;
  return p;
}

function handleClear() {
  filters.username = '';
  filters.type = '1';
  filters.status = '-1';
  filters.time_range = [
    nowInUserTz().subtract(16, 'hour').format('YYYY-MM-DD 00:00'),
    nowInUserTz().add(8, 'hour').format('YYYY-MM-DD 23:59'),
  ];
}

/** 狀態配色（target/tickets#4164）：拒絕/退回/代付回應失敗紅、已付款(含代付)綠 */
function statusClass(status?: number | string) {
  const s = Number(status);
  if ([2, 9, 29].includes(s)) return 'text-red-500';
  if ([4, 24].includes(s)) return 'text-green-600';
  return '';
}

getCustomizedSettingApi()
  .then((settings) => {
    const map = new Map(
      (settings ?? []).map((x: any) => [Number(x.setting_key), x.value]),
    );
    enableBsb.value = Number(map.get(4011)) === 1;
  })
  .catch(() => {});
loadData();
</script>

<template>
  <Page
    :title="`${tl('menu_agent_self_deposit_withdrawal')} > ${tl('menu_agent_self_withdrawal_index')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.type" style="width: 130px">
                <ElOption :label="tl('ordernum')" value="1" />
                <ElOption :label="tl('third_order_no')" value="4" />
                <ElOption :label="tl('agent_account')" value="2" />
                <ElOption :label="tl('account_no')" value="3" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="w-full">
              <ElOption
                v-for="o in STATUS_OPTIONS"
                :key="o.value"
                :label="tl(o.label)"
                :value="o.value"
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
        <div class="mb-2 flex items-center justify-end">
          <div class="flex gap-2">
            <ElButton @click="handleClear">{{ tl('clear') }}</ElButton>
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
          :label="tl('created_time')"
          align="center"
          :formatter="(row: WithdrawalListRow) => fromApiTime(row.create_at)"
          width="160"
        />
        <ElTableColumn
          :label="tl('ordernum')"
          align="center"
          prop="order_id"
          min-width="100"
        />
        <ElTableColumn
          :label="tl('third_order_no')"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <div
              v-for="(t, i) in String(row.transaction_no ?? '').split(',')"
              :key="i"
              :class="i % 2 === 0 ? 'font-bold' : 'text-gray-400'"
            >
              {{ t }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('bank')}${tl('type')}`"
          align="center"
          min-width="100"
        >
          <template #default="{ row }">
            {{ tl(`bank_type.${row.bank_type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bank_name')" align="center" min-width="110">
          <template #default="{ row }">
            <b class="text-green-600">{{ row.bank_name }}</b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('bank_code')"
          align="center"
          prop="bank_code"
          min-width="90"
        />
        <ElTableColumn
          :label="`${tl('account_name')}/${tl('account_no')}`"
          align="center"
          min-width="150"
        >
          <template #default="{ row }">
            <b>{{ row.account_name }}</b>
            <div class="text-xs text-gray-400">
              <template v-if="enableBsb">{{ row.bsb_code }}<br /></template>
              {{ row.account_no }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account')"
          align="center"
          min-width="110"
        >
          <template #default="{ row }">
            <b class="text-blue-500">
              <AgentAccount :username="row.username" />
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('amount')" align="right" min-width="100">
          <template #default="{ row }">
            {{ amountThousands(row.amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" align="center" min-width="120">
          <template #default="{ row }">
            <div :title="row.remark" class="truncate" style="max-width: 120px">
              {{ row.remark }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('order_status')" align="center" width="110">
          <template #default="{ row }">
            <span :class="statusClass(row.status)">
              {{ tl(`withdrawal_status_${row.status}`) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="100" fixed="right">
          <template #default="{ row }">
            <!-- 訂單資訊按鈕顏色與審核一致（target/tickets#4164） -->
            <ElButton
              type="primary"
              size="small"
              @click="reviewRef?.open(row.order_id)"
            >
              {{ Number(row.status) === 1 ? tl('review') : tl('order_info') }}
            </ElButton>
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

    <WithdrawalReviewDialog ref="reviewRef" @reviewed="loadData" />
  </Page>
</template>
