<script lang="ts" setup>
import type { SummaryToneCard } from './components/summary-tone-cards.vue';

import type {
  Sr02nCashFlowTreeNode,
  Sr02nFilterParams,
  Sr02nPair,
  Sr02nRow,
  Sr02nSourceKind,
} from '#/api';

import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

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
  getSr02nCashFlowTreeApi,
  getSr02nListApi,
  getSr02nTotalApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  amountThousands,
  buildPlainAmounts,
  toHalfWidth,
} from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import Sr02DetailDialog from './components/sr02-detail-dialog.vue';
import SummaryToneCards from './components/summary-tone-cards.vue';

defineOptions({ name: 'ReportSr02n' });

const PER_PAGE = 10;
const MAX_DAYS = 60;

const detailRef = ref<InstanceType<typeof Sr02DetailDialog>>();

/** 帳號搜尋類型 */
const TYPE_PARAM: Record<number, keyof Sr02nFilterParams> = {
  1: 'agent_username',
  2: 'member_username',
  3: 'member_nickname',
  4: 'transaction_no',
  5: 'execution_username',
};

/** 篩選配對（admin 另依登入者身分決定是否列出） */
const BASE_PAIRS: Sr02nPair[] = [
  'ga_cs',
  'ga_agent',
  'ga_member',
  'cs_agent',
  'cs_member',
  'agent_agent',
  'agent_member',
];

/** 非金流來源的顯示文字鍵 */
const SOURCE_KIND_KEY: Record<Exclude<Sr02nSourceKind, 'cash_flow'>, string> = {
  customer_service: 'cs_system',
  ota: 'ota',
  system: 'system',
};

/** 代理兩段的 status 為代碼（success / fail），需翻譯 */
const AGENT_SEGMENTS = new Set(['agent_credit', 'agent_wallet']);

const userStore = useUserStore();
/** Admin(id=1) 或其子帳號(host_id=1) 才能選「admin」配對 */
const isAdminOrSub = computed(() => {
  const u = userStore.userInfo as any;
  return Number(u?.id) === 1 || Number(u?.host_id) === 1;
});

const pairOptions = computed(() => {
  const pairs: Sr02nPair[] = [...BASE_PAIRS];
  if (isAdminOrSub.value) pairs.push('admin');
  return pairs.map((value) => ({ label: tl(`pair_${value}`), value }));
});

const filters = reactive({
  agent_cash_flow_id: '' as number | string,
  is_created_period: 0,
  order_type_id: '' as number | string,
  pairs: [] as Sr02nPair[],
  searchType: 1,
  source_temp: '0' as string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
});

const cashFlowTree = ref<Sr02nCashFlowTreeNode[]>([]);

/** 來源下拉：0=全部、1=客服系統、3.X=存款金流、4.X=取款金流 */
const sourceOptions = computed(() => {
  const opts: Array<{ label: string; value: string }> = [
    { label: tl('all'), value: '0' },
    { label: tl('cs_system'), value: '1' },
  ];
  for (const cf of cashFlowTree.value) {
    const sourceType = cf.type === 1 || cf.type === 2 ? 3 : 4;
    opts.push({
      label: `${sourceType === 3 ? tl('deposit') : tl('withdrawal')} - ${cf.name}`,
      value: `${sourceType}.${cf.cash_flow_id}`,
    });
  }
  return opts;
});

/** 目前選到的支付商（來源為 3.X / 4.X 時才有） */
const selectedCashFlow = computed(() => {
  if (!filters.source_temp.includes('.')) return undefined;
  const cfId = Number(filters.source_temp.split('.')[1]);
  return cashFlowTree.value.find((cf) => cf.cash_flow_id === cfId);
});

/** 支付商戶：選了支付商才有 */
const merchantOptions = computed(() => selectedCashFlow.value?.children ?? []);

/** 支付通道：選了商戶且該商戶底下有通道才有 */
const channelOptions = computed(() => {
  if (filters.agent_cash_flow_id === '') return [];
  const merchant = merchantOptions.value.find(
    (m) => m.agent_cash_flow_id === Number(filters.agent_cash_flow_id),
  );
  return merchant?.children ?? [];
});

/** 換支付商：清空商戶與通道 */
watch(
  () => filters.source_temp,
  () => {
    filters.agent_cash_flow_id = '';
    filters.order_type_id = '';
  },
);

/** 換商戶：清空通道 */
watch(
  () => filters.agent_cash_flow_id,
  () => {
    filters.order_type_id = '';
  },
);

/** 快速日期 */
const loading = ref(false);
const list = ref<Sr02nRow[]>([]);
const total = ref(0);
const page = ref(1);
const totals = reactive({
  totalActual: 0,
  totalIn: 0,
  totalOut: 0,
  totalTax: 0,
});

const fmtAmount = (v?: null | number | string) => amountThousands(v, 2);
/**
 * 手續費 / 實際金額：非提款列後端回 null，顯示「—」。
 * amountThousands 會把 null 當 0 格式化成 0.00（fallback 只管非數值字串），所以先判空。
 */
const fmtFee = (v?: null | number | string) =>
  v === null || v === undefined || v === '' ? '—' : amountThousands(v, 2);

/** 來源欄文字：金流顯示名稱，其餘翻譯 */
function sourceText(row: Sr02nRow): string {
  if (row.source_kind === 'cash_flow') return row.source_name ?? '';
  const key = SOURCE_KIND_KEY[row.source_kind];
  return key ? tl(key) : '';
}

/** 狀態欄文字：代理列翻譯代碼，會員列直接顯示帳戶暱稱 */
function statusText(row: Sr02nRow): string {
  const status = row.status ?? '';
  return AGENT_SEGMENTS.has(row.segment ?? '') ? tl(status) : status;
}

/** 合計卡片（5 張；只有差額依正負上色） */
const summaryItems = computed<SummaryToneCard[]>(() => {
  const diff = totals.totalIn - totals.totalOut;
  return [
    {
      key: 'in',
      label: tl('total_deposit'),
      tone: 'blue',
      value: fmtAmount(totals.totalIn),
    },
    {
      key: 'out',
      label: tl('total_withdrawl'),
      tone: 'pink',
      value: fmtAmount(totals.totalOut),
    },
    {
      key: 'diff',
      label: `${tl('total_deposit')}-${tl('total_withdrawl')}`,
      signed: diff,
      tone: 'gray',
      value: fmtAmount(diff),
    },
    {
      key: 'tax',
      label: tl('total_handling_fee'),
      tone: 'yellow',
      value: fmtAmount(totals.totalTax),
    },
    {
      key: 'actual',
      label: tl('total_actual_amount'),
      tone: 'green',
      value: fmtAmount(totals.totalActual),
    },
  ];
});

function buildParams(): null | Sr02nFilterParams {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return null;
  }
  // 區間上限 60 天
  if (dayjs(end).diff(dayjs(start), 'day') > MAX_DAYS) {
    ElMessage.warning(
      tl('query_period_max_days').replace('{value}', String(MAX_DAYS)),
    );
    return null;
  }
  const p: Sr02nFilterParams = {
    end_date: toApiTime(`${end}:59`),
    is_created_period: filters.is_created_period,
    pairs: [...filters.pairs],
    source: 0,
    start_date: toApiTime(`${start}:00`),
  };
  // 來源編碼拆解（3.X / 4.X → source + cash_flow_id），再帶商戶 / 通道
  if (filters.source_temp.includes('.')) {
    const [src, cfId] = filters.source_temp.split('.');
    p.source = Number(src);
    p.cash_flow_id = Number(cfId);
    if (filters.agent_cash_flow_id !== '') {
      p.agent_cash_flow_id = Number(filters.agent_cash_flow_id);
    }
    if (filters.order_type_id !== '') {
      p.order_type_id = Number(filters.order_type_id);
    }
  } else {
    p.source = Number(filters.source_temp);
  }
  // 帳號搜尋（依類型決定參數名；全形轉半形）
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
    const [totalRes, listRes] = await Promise.all([
      getSr02nTotalApi(params).catch(() => null),
      getSr02nListApi({
        ...params,
        page: page.value,
        per_page: PER_PAGE,
      }),
    ]);
    const sum =
      totalRes && Number(totalRes.code) === 200 ? totalRes.data : undefined;
    totals.totalIn = Number(sum?.total_in ?? 0);
    totals.totalOut = Number(sum?.total_out ?? 0);
    totals.totalTax = Number(sum?.total_tax ?? 0);
    totals.totalActual = Number(sum?.total_actual ?? 0);
    if (Number(listRes.code) === 200) {
      list.value = listRes.data?.items ?? [];
      total.value = listRes.data?.pagination?.total ?? 0;
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
/** 匯出 Excel：欄位（16 欄；含錢包別後綴、手續費 / 實際金額非提款列為「—」） */
async function handleExport() {
  const params = buildParams();
  if (!params) return;
  exporting.value = true;
  try {
    const res = await getSr02nListApi({
      ...params,
      page: 1,
      per_page: 100_000,
    });
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `sr02n_${Date.now()}`,
        [
          tl('num'),
          tl('ordernum'),
          tl('date'),
          tl('agent_account'),
          tl('operate'),
          tl('agent_account_to'),
          tl('member_account'),
          tl('real_name'),
          tl('change_point'),
          tl('handling_fee'),
          tl('actual_amount'),
          tl('moment_point'),
          tl('source'),
          tl('execution_account'),
          tl('order_status'),
          tl('memo'),
        ],
        rows.map((r) => {
          const wallet =
            Number(r.wallet_type) === 1 ? tl('main_wallet') : tl('game_wallet');
          return [
            r.id,
            r.third_party_order_no
              ? `${r.transaction_no}\n${r.third_party_order_no}`
              : r.transaction_no,
            fromApiTime(r.created_at),
            r.agent_username,
            `${tl(`transaction_type.${r.type}`)}(${wallet})`,
            r.to_agent_username,
            r.member_username,
            r.member_first_name,
            fmtAmount(r.point),
            fmtFee(r.tax),
            fmtFee(r.actual_point),
            fmtAmount(r.balance),
            sourceText(r),
            r.operator_username,
            statusText(r),
            r.remark,
          ];
        }),
        {
          audit: { conditions: { ...params } },
          dateRange: filters.time_range as [string, string],
          title: tl('sr02n'),
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
  }
  loadData();
  getSr02nCashFlowTreeApi()
    .then((res) => {
      cashFlowTree.value = Number(res.code) === 200 ? (res.data ?? []) : [];
    })
    .catch(() => {});
});
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('sr02n')}`">
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
          <ElFormItem :label="tl('filter')">
            <ElSelect
              v-model="filters.pairs"
              :placeholder="tl('all')"
              class="w-full"
              clearable
              collapse-tags
              collapse-tags-tooltip
              multiple
            >
              <ElOption
                v-for="o in pairOptions"
                :key="o.value"
                :label="o.label"
                :value="o.value"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem
            v-if="merchantOptions.length > 0"
            :label="tl('payment_merchant')"
          >
            <ElSelect
              v-model="filters.agent_cash_flow_id"
              class="w-full"
              filterable
            >
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="m in merchantOptions"
                :key="m.agent_cash_flow_id"
                :label="m.name"
                :value="m.agent_cash_flow_id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem
            v-if="channelOptions.length > 0"
            :label="tl('payment_channel')"
          >
            <ElSelect v-model="filters.order_type_id" class="w-full" filterable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="c in channelOptions"
                :key="c.order_type_id"
                :label="c.name"
                :value="c.order_type_id"
              />
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

    <!-- 合計（5 卡，2 位小數；差額正綠負紅） -->
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
          :formatter="(row: Sr02nRow) => fromApiTime(row.created_at)"
          :label="tl('date')"
          width="160"
        />
        <ElTableColumn :label="tl('agent_account')" min-width="110">
          <template #default="{ row }">
            <span class="text-green-600 font-medium">
              <AgentAccount :username="row.agent_username" />
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('operate')" width="110">
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
        <ElTableColumn :label="tl('change_point')" align="right" width="110">
          <template #default="{ row }">{{ fmtAmount(row.point) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('handling_fee')" align="right" width="110">
          <template #default="{ row }">{{ fmtFee(row.tax) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('actual_amount')" align="right" width="110">
          <template #default="{ row }">
            {{ fmtFee(row.actual_point) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('moment_point')" align="right" width="110">
          <template #default="{ row }">{{ fmtAmount(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('source')" width="100">
          <template #default="{ row }">
            {{ sourceText(row as Sr02nRow) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('execution_account')"
          prop="operator_username"
          width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.operator_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('account_nickname')" width="100">
          <template #default="{ row }">
            {{ statusText(row as Sr02nRow) }}
          </template>
        </ElTableColumn>
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
