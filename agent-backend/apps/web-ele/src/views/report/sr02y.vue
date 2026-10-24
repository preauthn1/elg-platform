<script lang="ts" setup>
import type { SummaryToneCard } from './components/summary-tone-cards.vue';

import type {
  CashFlowForTpRow,
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
  getCashFlowsForTpApi,
  getEventRewardsApi,
  getTransactionListNewApi,
  getTransactionTotalNewApi,
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

defineOptions({ name: 'ReportSr02y' });

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

const cashFlows = ref<CashFlowForTpRow[]>([]);
const eventRewards = ref<Array<{ event_reward_id: number | string }>>([]);
const orderTypes = ref<Array<{ name: string; order_type_id: number | string }>>(
  [],
);
/** 是否顯示訂單類型下拉（僅存款金流且 need_order_type_selection） */
const showOrderTypes = ref(false);

/**
 * 來源下拉。TP 金流的值為 5 段式：
 * type.cash_flow_id.agent_cash_flow_id.order_type_id.need_selection
 */
const sourceOptions = computed(() => {
  const opts: Array<{ label: string; value: string }> = [
    { label: tl('all'), value: '0' },
    { label: tl('cs_system'), value: '1' },
  ];
  for (const cf of cashFlows.value) {
    const sourceType = cf.type === 1 || cf.type === 2 ? 3 : 4;
    const need = cf.need_order_type_selection ? 1 : 0;
    opts.push({
      label: `${sourceType === 3 ? tl('deposit') : tl('withdrawal')} - ${cf.name}`,
      value: `${sourceType}.${cf.cash_flow_id}.${cf.agent_cash_flow_id}.${cf.order_type_id ?? 0}.${need}`,
    });
  }
  for (const ev of eventRewards.value) {
    opts.push({
      label: `${tl('reward')} - ${tl(`transaction_type.${ev.event_reward_id}`)}`,
      value: `5.${ev.event_reward_id}`,
    });
  }
  // SR02Y 專屬：佔成錢包（source=8）。後端只有帶 sr02y=1 時才會把
  // wallet_transaction_log_agents（代理之間互轉）那段 UNION 進來，且條件是
  // source 為 0 或 8（API_Agent ReportController::transactionTotalNew）。
  // SR02T 沒有這兩個選項，這也是兩張表金額不同的來源。
  opts.push(
    {
      label: `${tl('wallet_type.3')} - ${tl('transaction_type.profit_share_deposit')}`,
      value: '8.1',
    },
    {
      label: `${tl('wallet_type.3')} - ${tl('transaction_type.profit_share_withdrawal')}`,
      value: '8.2',
    },
  );
  return opts;
});

/** 拆 5 段式來源值 */
const sourceParts = computed(() => {
  const parts = filters.source_temp.split('.');
  return {
    agentCashFlowId: parts[2] ?? 0,
    baseOrderTypeId: parts[3] ?? 0,
    cashFlowId: parts[1] ?? '',
    main: parts[0] ?? '0',
    needSelection: parts[4] === '1',
  };
});
const isDeposit = computed(() => sourceParts.value.main === '3');
const isWithdrawal = computed(() => sourceParts.value.main === '4');
const isEvent = computed(
  () =>
    sourceParts.value.main === '5' &&
    !['105', '108'].includes(String(sourceParts.value.cashFlowId)),
);
/** 股東/佔成獎勵與代理錢包 → filter 鎖「客服對代理轉帳」 */
const isTransferOnly = computed(
  () =>
    sourceParts.value.main === '8' ||
    (sourceParts.value.main === '5' &&
      ['105', '108'].includes(String(sourceParts.value.cashFlowId))),
);

const filterOptions = computed(() => {
  if (isDeposit.value || isWithdrawal.value || isEvent.value) {
    return [{ label: tl('all_members'), value: 2 }];
  }
  if (isTransferOnly.value) {
    return [{ label: tl('cs_to_agent_transfer'), value: 3 }];
  }
  return [
    { label: tl('agent_cs_deposit_withdrawal'), value: 1 },
    { label: tl('all_members'), value: 2 },
    { label: tl('cs_to_agent_transfer'), value: 3 },
  ];
});

/** 來源變更：鎖 filter、依 need_selection 載入訂單類型 */
watch(
  () => filters.source_temp,
  async () => {
    const sp = sourceParts.value;
    orderTypes.value = [];
    showOrderTypes.value = false;
    filters.order_type_id = sp.baseOrderTypeId;
    if (isDeposit.value || isWithdrawal.value || isEvent.value) {
      filters.filter = 2;
    } else if (isTransferOnly.value) {
      filters.filter = 3;
    }
    if (isDeposit.value && sp.needSelection) {
      try {
        const r = await getAgentOrderTypesApi(sp.cashFlowId, {
          agent_cash_flow_id: sp.agentCashFlowId,
          parent_id: sp.baseOrderTypeId,
        });
        if (Number(r.code) === 1000) orderTypes.value = r.data ?? [];
        showOrderTypes.value = orderTypes.value.length > 0;
      } catch {
        orderTypes.value = [];
      }
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
  /** 佔成加分（wallet_transaction_log_agents type=1）；SR02Y 專屬 */
  profitShareIn: '0',
  /** 佔成洗分（wallet_transaction_log_agents type=2）；SR02Y 專屬 */
  profitShareOut: '0',
  reward: '0',
  totalIn: '0',
  totalOut: '0',
});

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

/** 合計卡片（7 張；較 SR02T 多佔成加分／佔成洗分兩張，只有差額依正負上色） */
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
    // 舊版用 $lang['total_profit_share_in']，但 15 個語系檔都沒有這個 key（靠
    // ?? '佔成加分' 硬寫中文）；這裡改用各語系都有翻譯的 transaction_type.* key。
    // 舊版這兩張是 info / dark，對應此元件新增的 cyan / slate；不能沿用
    // blue / pink，那是總開分／總洗分的顏色，並排會分不出來。
    {
      key: 'profit_share_in',
      label: tl('transaction_type.profit_share_deposit'),
      tone: 'cyan',
      value: fmt4(totals.profitShareIn),
    },
    {
      key: 'profit_share_out',
      label: tl('transaction_type.profit_share_withdrawal'),
      tone: 'slate',
      value: fmt4(totals.profitShareOut),
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
  const sp = sourceParts.value;
  const isCoded = filters.source_temp.includes('.');
  const p: TransactionNewFilterParams = {
    agent_cash_flow_id: isCoded ? sp.agentCashFlowId : 0,
    cash_flow_id: isCoded ? sp.cashFlowId : '',
    deposit_to: filters.deposit_to,
    end_date: toApiTime(`${end}:59`),
    filter: filters.filter,
    is_created_period: filters.is_created_period,
    order_type_id: filters.order_type_id,
    source: Number(sp.main),
    sr02y: 1,
    start_date: toApiTime(`${start}:00`),
  };
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
    totals.profitShareIn = sumAmounts(rows.map((r) => r.total_profit_share_in));
    totals.profitShareOut = sumAmounts(
      rows.map((r) => r.total_profit_share_out),
    );
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
/** 匯出 Excel：欄位（13 欄；較 SR02T 少「當下點數」，理由同表格欄位註解） */
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
        `sr02y_${Date.now()}`,
        [
          tl('num'),
          tl('ordernum'),
          tl('date'),
          tl('agent_account'),
          tl('type'),
          tl('agent_account_to'),
          tl('member_account'),
          tl('real_name'),
          tl('change_point'),
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
            r.source,
            r.operator_username,
            r.status,
            rr.remark as string,
          ];
        }),
        {
          audit: { conditions: { ...params } },
          dateRange: filters.time_range as [string, string],
          title: tl('sr02y'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

// 支援帶 query 進入
const route = useRoute();
onMounted(() => {
  if (route.query.member_username) {
    filters.searchType = 2;
    filters.username = String(route.query.member_username);
    filters.filter = 2;
  }
  loadData();
  getCashFlowsForTpApi()
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
  <Page :title="`${tl('statistical_report')} > ${tl('sr02y')}`">
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
          <ElFormItem v-if="showOrderTypes" :label="tl('type')">
            <ElSelect v-model="filters.order_type_id" class="w-full">
              <ElOption
                :label="tl('all')"
                :value="sourceParts.baseOrderTypeId"
              />
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
        <!-- 舊版 SR02Y 這欄的標題是「類型」而非「開分/洗分」：帶入佔成錢包後
             會出現代理之間互轉的列，用開分/洗分描述不準確 -->
        <ElTableColumn :label="tl('type')" width="110">
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
        <!-- 舊版 SR02Y 沒有「當下點數」欄：佔成錢包那段 UNION 的 select 只給
             total_payout / total_profit_share_in / total_profit_share_out，
             沒有對應的當下餘額可填，顯示出來會是誤導 -->
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
            <!-- 舊版 SR02Y 的條件：平台別 2 與 type 108 沒有可看的明細，不出按鈕 -->
            <ElButton
              v-if="!(row.platform === 2 || Number(row.type) === 108)"
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
