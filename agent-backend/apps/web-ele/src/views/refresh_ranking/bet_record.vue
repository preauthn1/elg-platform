<script lang="ts" setup>
import type { VNode } from 'vue';

import type { RefreshRankingBetRow, RefreshRankingBetSummary } from '#/api';

import { h, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

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
  getGameTypeListApi,
  getRefreshRankingBetRecordApi,
  getVendorListApi,
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

defineOptions({ name: 'RefreshRankingBetRecord' });

/** 不支援的平台 id */
const NO_SUPPORT = new Set([3, 10, 23]);
/** 每頁筆數選項 */
const PER_PAGE_OPTIONS = [25, 50, 75, 100, 125, 150];

/** 帳號搜尋類型 → 參數名 */
const TYPE_PARAM: Record<number, string> = {
  1: 'agent_username',
  2: 'member_username',
  3: 'game_no',
};

const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);

const filters = reactive({
  game_type: 0 as number | string,
  per_page: 25,
  searchType: 1,
  sort_by: 1,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
  username: '',
  vendor_id: '' as number | string,
  whitch_time: 1,
});

const loading = ref(false);
const list = ref<RefreshRankingBetRow[]>([]);
const summary = ref<RefreshRankingBetSummary>({});
const total = ref(0);
const page = ref(1);

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

/** 輸贏顏色：負紅、非負藍 */
function signClass(v?: null | number | string) {
  return Number(v) < 0 ? 'text-red-500' : 'text-blue-500';
}

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  loading.value = true;
  try {
    const params: Record<string, unknown> = {
      end_time: toApiTime(`${end}:59`),
      game_type: filters.game_type,
      page: page.value,
      per_page: filters.per_page,
      sort_by: filters.sort_by,
      start_time: toApiTime(`${start}:00`),
      vendor_id: filters.vendor_id || 1,
      whitch_time: filters.whitch_time,
    };
    if (filters.username) {
      const key = TYPE_PARAM[filters.searchType] ?? '';
      params[key] = toHalfWidth(filters.username);
    }
    const res = await getRefreshRankingBetRecordApi(params as any);
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      summary.value = res.summary_data ?? {};
      total.value = res.pagination?.total ?? 0;
    } else {
      list.value = [];
      summary.value = {};
      total.value = 0;
      ElMessage.warning(res.message);
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
/** 匯出 Excel：欄位（10 欄；含畫面未顯示的 payout(lottery)+commission） */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  exporting.value = true;
  try {
    const params: Record<string, unknown> = {
      end_time: toApiTime(`${end}:59`),
      game_type: filters.game_type,
      page: 1,
      per_page: 100_000,
      sort_by: filters.sort_by,
      start_time: toApiTime(`${start}:00`),
      vendor_id: filters.vendor_id || 1,
      whitch_time: filters.whitch_time,
    };
    if (filters.username) {
      const key = TYPE_PARAM[filters.searchType] ?? '';
      params[key] = toHalfWidth(filters.username);
    }
    const res = await getRefreshRankingBetRecordApi(params as any);
    const rows = Number(res.code) === 1000 ? (res.data ?? []) : [];
    await buildPlainAmounts(() =>
      exportExcel(
        `bet_record_${Date.now()}`,
        [
          tl('bet_time'),
          tl('agent_account'),
          tl('member_account'),
          tl('game_name'),
          tl('bet'),
          tl('valid_bet'),
          tl('payout'),
          tl('winlose'),
          tl('commission'),
          tl('ordernumber'),
        ],
        rows.map((r) => {
          const rr = r as Record<string, unknown> & RefreshRankingBetRow;
          return [
            fromApiTime(r.bet_time),
            r.agent_username,
            r.member_username,
            r.game_name,
            fmt4(r.bet_amount),
            fmt4(r.valid_bet_amount),
            fmt4(rr.lottery as number | string),
            fmt4(r.win_lose),
            fmt4(rr.commission as number | string),
            r.game_no,
          ];
        }),
        {
          audit: { conditions: params },
          dateRange: [start, end],
          title: tl('menu_refresh_ranking_bet_record'),
        },
      ),
    );
  } finally {
    exporting.value = false;
  }
}

/** 表尾合計列（金額 2 位） */
function summaryMethod(): Array<string | VNode> {
  const cell = (v?: null | number | string) =>
    h(
      'b',
      { class: Number(v) < 0 ? 'text-red-500' : '' },
      amountThousands(v, 2),
    );
  return [
    '',
    '',
    '',
    tl('total_count'),
    cell(summary.value.bet_amount) as any,
    cell(summary.value.valid_bet_amount) as any,
    cell(summary.value.win_lose) as any,
    '',
  ];
}

onMounted(() => {
  getVendorListApi()
    .then((d) => {
      vendors.value = (d ?? []).filter((v) => !NO_SUPPORT.has(Number(v.id)));
      if (!filters.vendor_id && vendors.value.length > 0) {
        filters.vendor_id = vendors.value[0]?.id ?? filters.vendor_id;
      }
    })
    .catch(() => {});
  getGameTypeListApi()
    .then((d) => {
      gameTypes.value = d ?? [];
    })
    .catch(() => {});
  loadData();
});
</script>

<template>
  <Page
    :title="`${tl('menu_refresh_ranking')} > ${tl('menu_refresh_ranking_bet_record')}`"
  >
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
                <ElOption :label="tl('ordernum')" :value="3" />
              </ElSelect>
            </template>
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('platform')">
            <ElSelect v-model="filters.vendor_id" class="w-full" filterable>
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('game_type')">
            <ElSelect v-model="filters.game_type" class="w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="g in gameTypes"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('field_sort')">
            <ElSelect v-model="filters.sort_by" class="w-full">
              <ElOption :label="tl('bet_time')" :value="1" />
              <ElOption :label="tl('bet_amount')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <template #label>
              <div>
                {{ tl('between') }}
                <div class="text-xs text-red-500">
                  * {{ tl('keep_for_three_months') }}
                </div>
              </div>
            </template>
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <ElFormItem :label="tl('search_time')">
            <ElSelect v-model="filters.whitch_time" class="w-full">
              <ElOption :label="tl('bet_time')" :value="1" />
              <ElOption :label="tl('settle_time')" :value="2" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('pagesize')">
            <ElSelect v-model="filters.per_page" class="w-full">
              <ElOption
                v-for="n in PER_PAGE_OPTIONS"
                :key="n"
                :label="n"
                :value="n"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="mb-2 text-xs text-red-500">
          {{ tl('query_agent_show_direct_members') }}
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

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        :data="list"
        :show-summary="list.length > 0"
        :summary-method="summaryMethod"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :formatter="(row: RefreshRankingBetRow) => fromApiTime(row.bet_time)"
          :label="tl('bet_time')"
          width="160"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="110"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('member_account')" min-width="110">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('game_name')" min-width="120">
          <template #default="{ row }">
            <span :title="row.vendor_name" class="text-info font-bold">
              {{ row.game_name }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bet')" align="right" width="120">
          <template #default="{ row }">{{ fmt4(row.bet_amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('valid_bet')" align="right" width="120">
          <template #default="{ row }">
            {{ fmt4(row.valid_bet_amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('winlose')" align="right" width="120">
          <template #default="{ row }">
            <span :class="signClass(row.win_lose)">
              {{ fmt4(row.win_lose) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ordernumber')"
          prop="game_no"
          min-width="170"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="filters.per_page"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>
  </Page>
</template>
