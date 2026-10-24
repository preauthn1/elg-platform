<script lang="ts" setup>
import type { EventV2JoinRow, EventV2JoinSummary } from '#/api';

import { ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getEventV2JoinListApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, sumAmounts } from '#/utils/format';
import { fromApiTime } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import EventV2SearchForm from './components/event-v2-search-form.vue';

defineOptions({ name: 'EventV2Join' });

const PER_PAGE = 10;

const STATUS_OPTIONS = [
  { label: tl('join'), value: 1 },
  { label: tl('complete'), value: 2 },
  { label: tl('give_up'), value: 3 },
  { label: tl('system_reset'), value: 4 },
  { label: tl('operater_quit'), value: 5 },
];

const STATUS_COLOR: Record<number, string> = {
  1: 'text-blue-500',
  2: 'text-green-600',
  3: 'text-red-500',
  5: 'text-purple-600',
};

const loading = ref(false);
const list = ref<EventV2JoinRow[]>([]);
const total = ref(0);
const page = ref(1);
let currentFilters: Record<string, any> = {};

/**
 * 八張統計卡（target/tickets#4235）：後端依同一組篩選算「全部」，翻頁不變；沒資料是 0。
 * 第一列總覽、第二列是主錢包型金額依狀態拆開；金額卡不含積分，積分卡不含金額。
 */
const SUMMARY_ROWS: Array<
  Array<{ key: keyof EventV2JoinSummary; label: string }>
> = [
  [
    { key: 'total_count', label: 'total_claim_count' },
    { key: 'total_reward', label: 'total_issued_amount' },
    { key: 'total_score_reward', label: 'total_score_reward' },
    { key: 'total_score', label: 'total_score_cost' },
  ],
  [
    { key: 'total_issued', label: 'total_issued' },
    { key: 'total_joining', label: 'total_joining' },
    { key: 'total_quit', label: 'total_quit' },
    { key: 'total_reset', label: 'total_reset' },
  ],
];
const summary = ref<EventV2JoinSummary>({});

function summaryText(key: keyof EventV2JoinSummary) {
  const v = summary.value[key] ?? 0;
  return key === 'total_count' ? amountThousands(v, 0) : amountThousands(v, 2);
}

/** null＝這一欄不屬於該筆的型別（積分型沒有獎勵／洗分、主錢包型沒有獲得積分、沒扣分沒有消耗積分），顯示「—」不顯示 0；數字走 #4227 千分位 */
function amountOrDash(v?: null | number | string) {
  return v === null || v === undefined || v === ''
    ? '—'
    : amountThousands(v, 2);
}

/** 匯出用：null 一律「—」，有值給原數字 */
function rawOrDash(v?: null | number | string) {
  return v === null || v === undefined || v === '' ? '—' : v;
}

/** 當頁加總，null 不計 */
function pageSum(
  pick: (r: EventV2JoinRow) => null | number | string | undefined,
) {
  return amountThousands(
    sumAmounts(
      list.value
        .map((r) => pick(r))
        .filter((v) => v !== null && v !== undefined),
    ),
    2,
  );
}

/** 當頁合計列：只加當頁的獎勵／獲得積分／消耗積分（跟統計卡的整個篩選範圍不同屬正常） */
function pageSubtotal() {
  const sums: string[] = Array.from({ length: 18 }).fill('') as string[];
  sums[0] = tl('page_subtotal');
  sums[5] = pageSum((r) => r.reward);
  sums[6] = pageSum((r) => r.score_reward);
  sums[7] = pageSum((r) => r.score_cost);
  return sums;
}

/** 領取去向（參加當下凍結值）：1 主錢包、2 積分（target/tickets#4231） */
function withdrawTypeText(t?: number) {
  return Number(t) === 2 ? tl('withdraw_type_2') : tl('withdraw_type_1');
}

function statusText(s?: number) {
  const map: Record<number, string> = {
    1: tl('join'),
    2: tl('complete'),
    3: tl('give_up'),
    4: tl('system_reset'),
    5: tl('operater_quit'),
  };
  return map[Number(s)] ?? String(s ?? '');
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getEventV2JoinListApi({
      ...(currentFilters as any),
      page: page.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
      total.value = res.data?.pagination?.total ?? 0;
      summary.value = res.data?.summary ?? {};
    } else {
      list.value = [];
      total.value = 0;
      summary.value = {};
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

function onSearch(filters: Record<string, any>) {
  currentFilters = filters;
  page.value = 1;
  loadData();
}

function onPage(p: number) {
  page.value = p;
  loadData();
}

const exporting = ref(false);
/** 匯出 Excel：欄位（14 欄；不含 eventID/joinID/紅利碼/結束時間），獲得積分、消耗積分接在獎勵後面與畫面一致（target/tickets#4235） */
async function handleExport() {
  exporting.value = true;
  try {
    const params = { ...(currentFilters as any), page: 1, per_page: 100_000 };
    const res = await getEventV2JoinListApi(params);
    const rows = Number(res.code) === 200 ? (res.data?.items ?? []) : [];
    await exportExcel({
      audit: { conditions: params },
      columns: [
        { title: tl('event_name'), width: 20 },
        { title: tl('agent_name'), width: 20 },
        { title: tl('member_name'), width: 20 },
        { title: tl('reward'), width: 20 },
        { title: tl('score_reward'), width: 14 },
        { title: tl('score_cost'), width: 14 },
        { title: tl('withdraw_type'), width: 14 },
        { title: tl('balance_limit'), width: 20 },
        { title: tl('flow_limit'), width: 20 },
        { title: tl('withdrawal_limit'), width: 20 },
        { title: tl('withdrawal'), width: 20 },
        { title: 'IP', width: 20 },
        { title: tl('join_time'), width: 30 },
        { title: tl('status'), width: 20 },
      ],
      rows: rows.map((r) => [
        r.name,
        r.agent_username,
        r.member_username,
        rawOrDash(r.reward),
        rawOrDash(r.score_reward),
        rawOrDash(r.score_cost),
        withdrawTypeText(r.withdraw_type),
        r.balance_limit,
        r.flow_limit,
        r.withdraw_limit,
        rawOrDash(r.withdraw),
        r.ip,
        fromApiTime(r.join_time),
        statusText(r.status),
      ]),
      title: tl('join_record'),
    });
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page
    :title="`${tl('event_exclusive_offers')} > ${tl('menu_event_v2')} > ${tl('join_record')}`"
  >
    <EventV2SearchForm
      :status-options="STATUS_OPTIONS"
      show-detail-id
      @search="onSearch"
    />

    <!-- 八張統計卡（target/tickets#4235）：整個篩選範圍，不是當頁；第一列總覽、第二列主錢包型金額依狀態拆 -->
    <ElCard class="mb-3" shadow="never">
      <div class="flex flex-col gap-4">
        <div
          v-for="(row, ri) in SUMMARY_ROWS"
          :key="ri"
          class="grid grid-cols-2 gap-y-4 text-center md:grid-cols-4"
        >
          <div v-for="c in row" :key="c.key">
            <div class="text-muted-foreground text-xs">{{ tl(c.label) }}</div>
            <div class="text-lg font-semibold">{{ summaryText(c.key) }}</div>
          </div>
        </div>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-2 flex justify-end">
        <ElButton :loading="exporting" type="success" @click="handleExport">
          {{ tl('excel') }}
        </ElButton>
      </div>
      <ElTable
        v-loading="loading"
        :data="list"
        :show-summary="list.length > 0"
        :summary-method="pageSubtotal"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="`${tl('event')}ID`"
          align="center"
          prop="event_v2_detail_id"
          width="80"
        />
        <ElTableColumn
          :label="`${tl('join')}ID`"
          align="center"
          prop="id"
          width="80"
        />
        <ElTableColumn
          :label="tl('event_name')"
          align="center"
          prop="name"
          min-width="140"
        />
        <ElTableColumn
          :label="tl('agent_name')"
          align="center"
          prop="agent_username"
          min-width="100"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('member_name')"
          align="center"
          min-width="100"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :formatter="(row: EventV2JoinRow) => amountOrDash(row.reward)"
          :label="tl('reward')"
          align="center"
          width="90"
        />
        <ElTableColumn
          :formatter="(row: EventV2JoinRow) => amountOrDash(row.score_reward)"
          :label="tl('score_reward')"
          align="center"
          width="100"
        />
        <ElTableColumn
          :formatter="(row: EventV2JoinRow) => amountOrDash(row.score_cost)"
          :label="tl('score_cost')"
          align="center"
          width="100"
        />
        <ElTableColumn
          :formatter="
            (row: EventV2JoinRow) => withdrawTypeText(row.withdraw_type)
          "
          :label="tl('withdraw_type')"
          align="center"
          width="100"
        />
        <ElTableColumn
          :label="tl('balance_limit')"
          align="center"
          prop="balance_limit"
          width="100"
        />
        <ElTableColumn
          :label="tl('flow_limit')"
          align="center"
          prop="flow_limit"
          width="100"
        />
        <ElTableColumn
          :label="tl('withdrawal_limit')"
          align="center"
          prop="withdraw_limit"
          width="100"
        />
        <ElTableColumn
          :formatter="(row: EventV2JoinRow) => amountOrDash(row.withdraw)"
          :label="tl('withdrawal')"
          align="center"
          width="90"
        />
        <ElTableColumn
          :label="tl('bonus_code')"
          align="center"
          prop="bonus_code"
          min-width="100"
        />
        <ElTableColumn label="IP" align="center" prop="ip" min-width="110" />
        <ElTableColumn
          :formatter="(row: EventV2JoinRow) => fromApiTime(row.join_time)"
          :label="tl('join_time')"
          align="center"
          width="150"
        />
        <ElTableColumn
          :formatter="(row: EventV2JoinRow) => fromApiTime(row.end_time)"
          :label="tl('label_end_time')"
          align="center"
          width="150"
        />
        <ElTableColumn :label="tl('status')" align="center" width="100">
          <template #default="{ row }">
            <b :class="STATUS_COLOR[Number(row.status)] ?? 'text-gray-500'">
              {{ statusText(row.status) }}
            </b>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_data') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="onPage"
      />
    </ElCard>
  </Page>
</template>
