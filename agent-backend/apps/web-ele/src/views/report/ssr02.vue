<script lang="ts" setup>
import type { SummaryToneCard } from './components/summary-tone-cards.vue';

import type {
  CashFlowRow,
  TransactionNewFilterParams,
  TransactionRow,
} from '#/api';

import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
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

import {
  getAgentOrderTypesApi,
  getCashFlowsApi,
  getEventRewardsApi,
  getTransactionListNewApi,
  getTransactionTotalNewApi,
  getWithdrawalSubListApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  diffAmounts,
  sumAmounts,
  toHalfWidth,
} from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import Sr02DetailDialog from './components/sr02-detail-dialog.vue';
import SummaryToneCards from './components/summary-tone-cards.vue';

defineOptions({ name: 'ReportSsr02' });

const PER_PAGE = 10;
const MAX_DAYS = 60;

const detailRef = ref<InstanceType<typeof Sr02DetailDialog>>();

/** 帳號搜尋類型 */
const TYPE_PARAM: Record<number, keyof TransactionNewFilterParams> = {
  1: 'agent_username',
  2: 'member_username',
  3: 'member_nickname',
  4: 'transaction_no',
  5: 'execution_username',
};

const filters = reactive({
  deposit_to: 0,
  filter: 1,
  is_created_period: 0,
  order_type_id: 0 as number | string,
  searchType: 1,
  source_temp: '0' as string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
});

const cashFlows = ref<CashFlowRow[]>([]);
const eventRewards = ref<Array<{ event_reward_id: number | string }>>([]);
const orderTypes = ref<Array<{ name: string; order_type_id: number | string }>>(
  [],
);

/** 來源下拉：0=全部、1=客服系統、3.X 存款、4.X 提款、5.X 活動獎勵 */
const sourceOptions = computed(() => {
  const opts: Array<{ label: string; value: string }> = [
    { label: tl('all'), value: '0' },
    { label: tl('cs_system'), value: '1' },
  ];
  for (const cf of cashFlows.value) {
    const sourceType = cf.type === 1 || cf.type === 2 ? 3 : 4;
    opts.push({
      label: `${sourceType === 3 ? tl('deposit') : tl('withdrawal')} - ${cf.name}`,
      value: `${sourceType}.${cf.cash_flow_id}`,
    });
  }
  for (const ev of eventRewards.value) {
    opts.push({
      label: `${tl('reward')} - ${tl(`transaction_type.${ev.event_reward_id}`)}`,
      value: `5.${ev.event_reward_id}`,
    });
  }
  return opts;
});

/** 金流/活動來源時 filter 鎖定為「所有會員」 */
const isCashFlowSource = computed(() => filters.source_temp.includes('.'));
const isDeposit = computed(() => filters.source_temp.startsWith('3.'));
const isWithdrawal = computed(() => filters.source_temp.startsWith('4.'));

const filterOptions = computed(() =>
  isCashFlowSource.value
    ? [{ label: tl('all_members'), value: 2 }]
    : [
        { label: tl('agent_cs_deposit_withdrawal'), value: 1 },
        { label: tl('all_members'), value: 2 },
        { label: tl('cs_to_agent_transfer'), value: 3 },
      ],
);

/** 來源變更：鎖 filter、載入對應訂單類型 */
watch(
  () => filters.source_temp,
  async (v) => {
    if (isCashFlowSource.value) filters.filter = 2;
    filters.order_type_id = 0;
    orderTypes.value = [];
    const cfId = v.split('.')[1] ?? '';
    try {
      if (isDeposit.value) {
        const r = await getAgentOrderTypesApi(cfId);
        if (Number(r.code) === 1000) orderTypes.value = r.data ?? [];
      } else if (isWithdrawal.value) {
        const r = await getWithdrawalSubListApi(cfId);
        if (Number(r.code) === 1000) orderTypes.value = r.data ?? [];
      }
    } catch {
      orderTypes.value = [];
    }
  },
);

/** 快速日期 */
const loading = ref(false);
const list = ref<TransactionRow[]>([]);
const total = ref(0);
const page = ref(1);
const totals = reactive({
  payout: '0',
  reward: '0',
  totalIn: '0',
  totalOut: '0',
});

const fmt2 = (v?: null | number | string) => amountThousands(v, 2);
const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

/** 合計卡片（5 張；只有差額依正負上色，target/tickets#4319） */
const summaryItems = computed<SummaryToneCard[]>(() => {
  const diff = diffAmounts(totals.totalIn, totals.totalOut);
  return [
    {
      key: 'in',
      label: tl('total_deposit'),
      tone: 'blue',
      value: fmt4(totals.totalIn),
    },
    {
      key: 'out',
      label: tl('total_withdrawl'),
      tone: 'pink',
      value: fmt4(totals.totalOut),
    },
    {
      key: 'diff',
      label: `${tl('total_deposit')}-${tl('total_withdrawl')}`,
      signed: diff,
      tone: 'gray',
      value: fmt4(diff),
    },
    {
      key: 'reward',
      label: tl('total_reward'),
      tone: 'green',
      value: fmt4(totals.reward),
    },
    {
      key: 'payout',
      label: tl('total_payout'),
      tone: 'yellow',
      value: fmt4(totals.payout),
    },
  ];
});

function buildParams(): null | TransactionNewFilterParams {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return null;
  }
  if (dayjs(end).diff(dayjs(start), 'day') > MAX_DAYS) {
    ElMessage.warning(
      tl('query_period_max_days').replace('{value}', String(MAX_DAYS)),
    );
    return null;
  }
  const p: TransactionNewFilterParams = {
    deposit_to: filters.deposit_to,
    end_date: toApiTime(`${end}:59`),
    filter: filters.filter,
    is_created_period: filters.is_created_period,
    order_type_id: filters.order_type_id,
    source: 0,
    start_date: toApiTime(`${start}:00`),
  };
  if (filters.source_temp.includes('.')) {
    const [src, cfId] = filters.source_temp.split('.');
    p.source = Number(src);
    p.cash_flow_id = Number(cfId);
  } else {
    p.source = Number(filters.source_temp);
    p.cash_flow_id = '';
  }
  if (filters.username) {
    const key = TYPE_PARAM[filters.searchType] ?? '';
    (p as any)[key] =
      filters.searchType === 3
        ? filters.username
        : toHalfWidth(filters.username);
  }
  return p;
}

async function loadData() {
  const params = buildParams();
  if (!params) return;
  loading.value = true;
  try {
    const [totalRows, listRes] = await Promise.all([
      getTransactionTotalNewApi(params).catch(() => []),
      getTransactionListNewApi({
        ...params,
        page: page.value,
        per_page: PER_PAGE,
      }),
    ]);
    const rows = totalRows ?? [];
    totals.totalIn = sumAmounts(rows.map((r) => r.total_in));
    totals.totalOut = sumAmounts(rows.map((r) => r.total_out));
    totals.reward = sumAmounts(rows.map((r) => r.total_reward));
    totals.payout = sumAmounts(rows.map((r) => r.total_payout));
    if (Number(listRes.code) === 1000) {
      list.value = listRes.data ?? [];
      total.value = listRes.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.warning(listRes.message);
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：欄位（14 欄；無錢包別後綴、status 為 API 文字） */
async function handleExport() {
  const params = buildParams();
  if (!params) return;
  exporting.value = true;
  try {
    const res = await getTransactionListNewApi({
      ...params,
      page: 1,
      per_page: 100_000,
    });
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `ssr02_${Date.now()}`,
        [
          tl('num'),
          tl('ordernum'),
          tl('date'),
          tl('agent_account'),
          `${tl('deposit')}/${tl('withdrawal')}`,
          tl('agent_account_to'),
          tl('member_account'),
          tl('real_name'),
          tl('change_point'),
          tl('moment_point'),
          tl('source'),
          tl('execution_account'),
          tl('order_status'),
          tl('memo'),
        ],
        rows.map((r) => {
          const rr = r as Record<string, unknown> & TransactionRow;
          return [
            r.id,
            r.third_party_order_no
              ? `${r.transaction_no}\n${r.third_party_order_no}`
              : r.transaction_no,
            fromApiTime(r.created_at),
            r.agent_username,
            tl(`transaction_type.${r.type}`),
            r.to_agent_username,
            r.member_username,
            r.member_first_name,
            fmt4(r.point),
            fmt2(r.balance),
            r.source,
            r.operator_username,
            r.status,
            rr.remark as string,
          ];
        }),
        {
          audit: { conditions: { ...params } },
          dateRange: filters.time_range as [string, string],
          title: tl('ssr02'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

// 支援帶 query 進入（會員詳情抽屜的報表捷徑，target/tickets#4438）：帶入會員帳號、範圍改所有會員後查詢
const route = useRoute();
onMounted(() => {
  if (route.query.member_username) {
    filters.searchType = 2;
    filters.username = String(route.query.member_username);
    filters.filter = 2;
  }
  loadData();
  getCashFlowsApi()
    .then((d) => {
      cashFlows.value = d ?? [];
    })
    .catch(() => {});
  getEventRewardsApi()
    .then((d) => {
      eventRewards.value = d ?? [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('ssr02')}`">
    <!-- 查詢 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem>
            <template #label>
              <ElSelect v-model="filters.searchType" class="!w-36">
                <ElOption :label="tl('agent_account')" :value="1" />
                <ElOption :label="tl('member_account')" :value="2" />
                <ElOption :label="tl('member_nickname')" :value="3" />
                <ElOption :label="tl('ordernum')" :value="4" />
                <ElOption :label="tl('execution_account')" :value="5" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <div class="w-full">
              <DateRangeFields
                v-model="filters.time_range"
                format="YYYY-MM-DD HH:mm"
                type="datetime"
                value-format="YYYY-MM-DD HH:mm"
              />
              <div class="text-muted-foreground mt-1 text-xs">
                {{ tl('query_period_max_days').replace('{value}', '60') }}
              </div>
            </div>
          </ElFormItem>

          <ElFormItem :label="tl('source')">
            <ElSelect v-model="filters.source_temp" class="w-full" filterable>
              <ElOption
                v-for="o in sourceOptions"
                :key="o.value"
                :label="o.label"
                :value="o.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem v-if="orderTypes.length > 0" :label="tl('type')">
            <ElSelect v-model="filters.order_type_id" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="o in orderTypes"
                :key="o.order_type_id"
                :label="o.name"
                :value="o.order_type_id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('filter')">
            <ElSelect v-model="filters.filter" class="w-full">
              <ElOption
                v-for="o in filterOptions"
                :key="o.value"
                :label="o.label"
                :value="o.value"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem :label="tl('deposit_to')">
            <ElSelect v-model="filters.deposit_to" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption :label="tl('only_agent')" :value="1" />
              <ElOption :label="tl('only_member')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('is_created_period')">
            <ElSelect v-model="filters.is_created_period" class="w-full">
              <ElOption :label="tl('option_no')" :value="0" />
              <ElOption :label="tl('option_yes')" :value="1" />
            </ElSelect>
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

    <!-- 合計（5 卡，4 位小數；差額正綠負紅） -->
    <ElCard class="mb-3" shadow="never">
      <SummaryToneCards :items="summaryItems" class="md:grid-cols-5" />
    </ElCard>

    <!-- 明細 -->
    <ElCard shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('detaillist') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('num')" prop="id" width="90" />
        <ElTableColumn :label="tl('ordernum')" min-width="170">
          <template #default="{ row }">
            <div>{{ row.transaction_no }}</div>
            <div
              v-if="row.third_party_order_no"
              class="text-muted-foreground text-xs"
            >
              {{ row.third_party_order_no }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :formatter="(row: TransactionRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn :label="tl('agent_account')" min-width="110">
          <template #default="{ row }">
            <span class="font-medium text-green-600">
              <AgentAccount :username="row.agent_username" />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('deposit')}/${tl('withdrawal')}`"
          width="110"
        >
          <template #default="{ row }">
            {{ tl(`transaction_type.${row.type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('agent_account_to')"
          prop="to_agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.to_agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <div
              :class="row.platform === 1 ? 'text-info' : 'text-green-600'"
              class="font-medium"
            >
              <MemberAccountLink :username="row.member_username" />
            </div>
            <div v-if="row.member_first_name" class="text-xs">
              {{ row.member_first_name }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('change_point')" align="right" width="120">
          <template #default="{ row }">
            <span
              :class="Number(row.point) < 0 ? 'text-red-500' : 'text-blue-500'"
            >
              {{ fmt4(row.point) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="110">
          <template #default="{ row }">{{ fmt2(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('source')" prop="source" width="100" />
        <ElTableColumn
          :label="tl('execution_account')"
          prop="operator_username"
          width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('order_status')" prop="status" width="100" />
        <ElTableColumn
          :label="tl('features')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="detailRef?.open(row.id, row.to_agent_username ? 2 : 1)"
            >
              {{ tl('watch_data') }}
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

    <Sr02DetailDialog ref="detailRef" />
  </Page>
</template>
