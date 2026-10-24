<script lang="ts" setup>
import type { ShareholderPerfRow } from '#/api';

import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getCustomizedSettingApi,
  getShareholderPerformanceApi,
  getShareholderSettlementCategoryApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { amountThousands, sumAmounts } from '#/utils/format';

import PerformanceDetailDialog from './performance-detail-dialog.vue';

defineOptions({ name: 'ShareholderPerformanceQuery2Page' });

const route = useRoute();

const n = (v: unknown) => Number(v) || 0;
const fmt = (v: number | string) => amountThousands(v, 2);
const yesterdayStr = dayjs().subtract(1, 'day').format('YYYY-MM-DD');

const filters = reactive({
  start_date: (route.query.start_date as string) || yesterdayStr,
  end_date: (route.query.end_date as string) || yesterdayStr,
  category_type: (route.query.category_type as string) || '0',
  agent_id: (route.query.agent_id as string) || '0',
});

const loading = ref(false);
const searched = ref(false);
const categories = ref<Array<{ id: number | string; name: string }>>([]);
const logoImage = ref('');

const summary = ref<ShareholderPerfRow[]>([]);
const direct = ref<ShareholderPerfRow[]>([]);
const team = ref<ShareholderPerfRow[]>([]);
const generalAgent = ref<{ nickname?: string }>({});
const searchAgent = ref<Array<{ id: number | string; username: string }>>([]);
const generalSharePoint = ref<number | string>(0);

const title = computed(
  () =>
    `${tl('menu_cj7_shareholder_report')} > ${tl('menu_2_in_1_dividend_report')}`,
);

/** query2：同一天即顯示佔成（不檢查 share_visible）。 */
const showSharePoint = computed(
  () => !!filters.start_date && filters.start_date === filters.end_date,
);

const detailVisible = ref(false);
const detailCtx = reactive({
  mode: 'detail' as 'detail' | 'direct',
  shareholderCategoryId: '' as number | string,
  shareholderCategoryName: '',
  agentId: '' as number | string,
  startTime: '',
  endTime: '',
});

/** 橋接 start_date/end_date ↔ DateRangeFields 的 [start, end] */
const timeRange = computed({
  get: () => [filters.start_date, filters.end_date] as string[],
  set: (v: string[]) => {
    filters.start_date = v?.[0] ?? '';
    filters.end_date = v?.[1] ?? '';
  },
});

async function loadCategories() {
  const res = await getShareholderSettlementCategoryApi();
  if (Number(res.code) === 1000) categories.value = res.data ?? [];
}

async function loadSettings() {
  const settings = await getCustomizedSettingApi();
  const find = (k: string) =>
    (settings ?? []).find((x: any) => String(x.setting_key) === k)?.value;
  logoImage.value = String(find('8008') ?? '');
}

async function search() {
  loading.value = true;
  searched.value = true;
  const startTime = `${filters.start_date} 00:00:00`;
  const endTime = `${filters.end_date} 23:59:59`;
  try {
    const res = await getShareholderPerformanceApi(
      {
        agent_id: filters.agent_id,
        category_type: filters.category_type,
        end_time: endTime,
        start_time: startTime,
      },
      true,
    );
    if (Number(res.code) === 1000) {
      const d = res.data ?? {};
      summary.value = d.summary ?? [];
      direct.value = d.direct ?? [];
      team.value = d.team ?? [];
      generalAgent.value = d.general_agent ?? {};
      searchAgent.value = d.search_agent ?? [];
      generalSharePoint.value = d.share_point?.general_agent ?? 0;
    } else {
      summary.value = [];
      direct.value = [];
      team.value = [];
      generalAgent.value = {};
      searchAgent.value = [];
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  filters.agent_id = '0';
  search();
}

function drillAgent(agentId: number | string) {
  filters.agent_id = String(agentId);
  search();
}

function openDetail(mode: 'detail' | 'direct', row: ShareholderPerfRow) {
  detailCtx.mode = mode;
  detailCtx.shareholderCategoryId = row.shareholder_category_id ?? '';
  detailCtx.shareholderCategoryName = row.name ?? '';
  detailCtx.agentId = row.agent_id ?? '';
  detailCtx.startTime = `${filters.start_date} 00:00:00`;
  detailCtx.endTime = `${filters.end_date} 23:59:59`;
  detailVisible.value = true;
}

/** commission_ratio==0 的類別集合（win_profit/performance_bonus 顯示 '-'）。 */
const commissionRatioZero = computed(() => {
  const set = new Set<string>();
  for (const r of summary.value) {
    if (n(r.commission_ratio) <= 0) set.add(String(r.shareholder_category_id));
  }
  return set;
});

const summaryTotals = computed(() => {
  const rows = summary.value;
  const paid = rows.filter((r) => n(r.commission_ratio) > 0);
  const bet = Number(sumAmounts(rows.map((r) => r.bet_bonus)));
  const perf = Number(sumAmounts(paid.map((r) => r.performance_bonus)));
  return {
    t: Number(sumAmounts(rows.map((r) => r.turnover))),
    s: Number(sumAmounts(rows.map((r) => r.game_spending))),
    p: Number(sumAmounts(rows.map((r) => r.game_profit))),
    win: Number(sumAmounts(paid.map((r) => r.win_profit))),
    bet,
    perf,
    bonus: Number(sumAmounts([bet, perf])),
  };
});

function lowerTotals(rows: ShareholderPerfRow[]) {
  const paid = rows.filter(
    (r) => !commissionRatioZero.value.has(String(r.shareholder_category_id)),
  );
  const np = Number(sumAmounts(rows.map((r) => r.nett_profit)));
  const perf = Number(sumAmounts(paid.map((r) => r.performance_bonus)));
  return {
    t: Number(sumAmounts(rows.map((r) => r.turnover))),
    np,
    win: Number(sumAmounts(paid.map((r) => r.win_profit))),
    perf,
    profit: Number(sumAmounts([np, perf])),
  };
}

const directTotals = computed(() => lowerTotals(direct.value));
const teamTotals = computed(() => lowerTotals(team.value));

function winShow(row: ShareholderPerfRow) {
  return !commissionRatioZero.value.has(String(row.shareholder_category_id));
}

function shareText(row: ShareholderPerfRow, isTeam: boolean) {
  if (!showSharePoint.value) return '-- / --';
  const sp = isTeam
    ? n(row.upper_share_point) - n(row.share_point)
    : n(row.share_point);
  return `${sp} / ${generalSharePoint.value}`;
}

async function init() {
  await Promise.all([loadCategories(), loadSettings()]);
  if (route.query.act === '1') await search();
}
init();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="timeRange" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect
              v-model="filters.category_type"
              class="!w-full"
              filterable
            >
              <ElOption :label="tl('all')" value="0" />
              <ElOption
                v-for="c in categories"
                :key="c.id"
                :label="c.name"
                :value="String(c.id)"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end gap-2">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" v-loading="loading" shadow="never">
      <div class="mb-2 flex items-start justify-between">
        <div v-if="searchAgent.length > 0" class="text-sm">
          <b>{{ tl('search_history') }}:</b>
          <template v-for="(a, i) in searchAgent" :key="a.id">
            <span v-if="i > 0"> &gt; </span>
            <a
              class="cursor-pointer font-bold text-indigo-500"
              @click="drillAgent(a.id)"
            >
              {{ a.username }}
            </a>
          </template>
        </div>
        <img v-if="logoImage" :src="logoImage" class="max-h-10 max-w-[150px]" />
      </div>

      <!-- summary -->
      <div class="mb-4 overflow-auto">
        <table
          class="q2-table w-full border-collapse text-center text-sm [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1"
        >
          <thead>
            <tr>
              <th rowspan="2">{{ tl('2in1_username') }}</th>
              <th rowspan="2">{{ tl('gaming_platform_group') }}</th>
              <th colspan="4">{{ tl('company_win_loss_report') }}</th>
              <th colspan="2">{{ tl('2in1_bonus_report') }}</th>
            </tr>
            <tr>
              <th>{{ tl('total_bet_amount2') }}</th>
              <th>{{ tl('2in1_bonus_payout') }}</th>
              <th>{{ tl('company_net_profit') }}</th>
              <th>{{ tl('total_positive_performance') }}</th>
              <th>{{ tl('betting_turnover_bonus') }}</th>
              <th>{{ tl('performance_bonus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in summary" :key="i" class="font-bold">
              <td>{{ generalAgent.nickname }}</td>
              <td class="text-indigo-500">{{ r.name }}</td>
              <td
                class="text-right"
                :class="n(r.turnover) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.turnover ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.game_spending) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.game_spending ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.game_profit) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.game_profit ?? 0) }}
              </td>
              <td class="text-right">
                {{ n(r.commission_ratio) === 0 ? '-' : fmt(r.win_profit ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.bet_bonus) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.bet_bonus ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.performance_bonus) < 0 ? 'text-red-500' : ''"
              >
                {{
                  n(r.commission_ratio) === 0
                    ? '-'
                    : fmt(r.performance_bonus ?? 0)
                }}
              </td>
            </tr>
            <template v-if="summary.length > 0">
              <tr class="q2-total font-bold">
                <td colspan="2">Total</td>
                <td
                  class="text-right"
                  :class="summaryTotals.t < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(summaryTotals.t) }}
                </td>
                <td
                  class="text-right"
                  :class="summaryTotals.s < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(summaryTotals.s) }}
                </td>
                <td
                  class="text-right"
                  :class="summaryTotals.p < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(summaryTotals.p) }}
                </td>
                <td
                  class="text-right"
                  :class="summaryTotals.win < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(summaryTotals.win) }}
                </td>
                <td
                  class="text-right"
                  :class="summaryTotals.bet < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(summaryTotals.bet) }}
                </td>
                <td
                  class="text-right"
                  :class="summaryTotals.perf < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(summaryTotals.perf) }}
                </td>
              </tr>
              <tr class="font-bold">
                <td colspan="8" class="text-left">{{ tl('settle') }}</td>
              </tr>
              <tr class="q2-total font-bold">
                <td>{{ generalAgent.nickname }}</td>
                <td class="text-indigo-500">{{ tl('total_bonus') }}</td>
                <td colspan="5"></td>
                <td class="text-right">{{ fmt(summaryTotals.bonus) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- direct -->
      <div v-if="direct.length > 0" class="mb-4 overflow-auto">
        <table
          class="q2-table w-full border-collapse text-center text-sm [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1"
        >
          <thead>
            <tr>
              <th rowspan="2">{{ tl('2in1_username') }}</th>
              <th rowspan="2">{{ tl('nickname') }}</th>
              <th rowspan="2">{{ tl('gaming_platform_group') }}</th>
              <th rowspan="2">{{ tl('shareholding') }}</th>
              <th colspan="4">{{ tl('2in1_bonus_report') }}</th>
            </tr>
            <tr>
              <th>{{ tl('total_bet_amount2') }}</th>
              <th>{{ tl('total_positive_performance') }}</th>
              <th>{{ tl('betting_turnover_bonus') }}</th>
              <th>{{ tl('performance_bonus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in direct" :key="i" class="font-bold">
              <td>{{ r.agent_username }}</td>
              <td>{{ r.agent_nickname }}</td>
              <td>
                <a
                  class="cursor-pointer italic text-indigo-500"
                  @click="openDetail('direct', r)"
                  >{{ r.name }}</a
                >
              </td>
              <td class="text-right">{{ shareText(r, false) }}</td>
              <td
                class="text-right"
                :class="n(r.turnover) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.turnover ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.win_profit) < 0 ? 'text-red-500' : ''"
              >
                {{ winShow(r) ? fmt(r.win_profit ?? 0) : '-' }}
              </td>
              <td
                class="text-right"
                :class="n(r.nett_profit) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.nett_profit ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.performance_bonus) < 0 ? 'text-red-500' : ''"
              >
                {{ winShow(r) ? fmt(r.performance_bonus ?? 0) : '-' }}
              </td>
            </tr>
            <template v-if="direct.length > 0">
              <tr class="q2-total font-bold">
                <td colspan="4">Total</td>
                <td
                  class="text-right"
                  :class="directTotals.t < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(directTotals.t) }}
                </td>
                <td
                  class="text-right"
                  :class="directTotals.win < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(directTotals.win) }}
                </td>
                <td
                  class="text-right"
                  :class="directTotals.np < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(directTotals.np) }}
                </td>
                <td
                  class="text-right"
                  :class="directTotals.perf < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(directTotals.perf) }}
                </td>
              </tr>
              <tr class="font-bold">
                <td colspan="8" class="text-left">{{ tl('settle') }}</td>
              </tr>
              <tr class="q2-total font-bold">
                <td colspan="7" class="text-indigo-500">
                  {{ tl('total_profit') }}
                </td>
                <td class="text-right">{{ fmt(directTotals.profit) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- team -->
      <div v-if="team.length > 0" class="overflow-auto">
        <table
          class="q2-table w-full border-collapse text-center text-sm [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:px-2 [&_th]:py-1"
        >
          <thead>
            <tr>
              <th rowspan="2">{{ tl('2in1_username') }}</th>
              <th rowspan="2">{{ tl('nickname') }}</th>
              <th rowspan="2">{{ tl('gaming_platform_group') }}</th>
              <th rowspan="2">{{ tl('shareholding') }}</th>
              <th colspan="4">{{ tl('2in1_bonus_report') }}</th>
            </tr>
            <tr>
              <th>{{ tl('total_bet_amount2') }}</th>
              <th>{{ tl('total_positive_performance') }}</th>
              <th>{{ tl('betting_turnover_bonus') }}</th>
              <th>{{ tl('performance_bonus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in team" :key="i" class="font-bold">
              <td>
                <a
                  class="cursor-pointer text-indigo-500"
                  @click="drillAgent(r.agent_id ?? '')"
                  >{{ r.agent_username }}</a
                >
              </td>
              <td>{{ r.agent_nickname }}</td>
              <td>
                <a
                  class="cursor-pointer italic text-indigo-500"
                  @click="openDetail('detail', r)"
                  >{{ r.name }}</a
                >
              </td>
              <td class="text-right">{{ shareText(r, true) }}</td>
              <td
                class="text-right"
                :class="n(r.turnover) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.turnover ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.win_profit) < 0 ? 'text-red-500' : ''"
              >
                {{ winShow(r) ? fmt(r.win_profit ?? 0) : '-' }}
              </td>
              <td
                class="text-right"
                :class="n(r.nett_profit) < 0 ? 'text-red-500' : ''"
              >
                {{ fmt(r.nett_profit ?? 0) }}
              </td>
              <td
                class="text-right"
                :class="n(r.performance_bonus) < 0 ? 'text-red-500' : ''"
              >
                {{ winShow(r) ? fmt(r.performance_bonus ?? 0) : '-' }}
              </td>
            </tr>
            <template v-if="team.length > 0">
              <tr class="q2-total font-bold">
                <td colspan="4">Total</td>
                <td
                  class="text-right"
                  :class="teamTotals.t < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(teamTotals.t) }}
                </td>
                <td
                  class="text-right"
                  :class="teamTotals.win < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(teamTotals.win) }}
                </td>
                <td
                  class="text-right"
                  :class="teamTotals.np < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(teamTotals.np) }}
                </td>
                <td
                  class="text-right"
                  :class="teamTotals.perf < 0 ? 'text-red-500' : ''"
                >
                  {{ fmt(teamTotals.perf) }}
                </td>
              </tr>
              <tr class="font-bold">
                <td colspan="8" class="text-left">{{ tl('settle') }}</td>
              </tr>
              <tr class="q2-total font-bold">
                <td colspan="7" class="text-indigo-500">
                  {{ tl('total_profit') }}
                </td>
                <td class="text-right">{{ fmt(teamTotals.profit) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </ElCard>

    <PerformanceDetailDialog
      v-model="detailVisible"
      :agent-id="detailCtx.agentId"
      :end-time="detailCtx.endTime"
      :mode="detailCtx.mode"
      :shareholder-category-id="detailCtx.shareholderCategoryId"
      :shareholder-category-name="detailCtx.shareholderCategoryName"
      :start-time="detailCtx.startTime"
      variant2
    />
  </Page>
</template>

<style scoped>
.q2-table th {
  height: 50px;
  vertical-align: middle;
  color: #fff;
  text-align: center;
  background: #274e13;
}

.q2-total td {
  color: #303133;
  background: #e8ffe0;
}

.q2-total td.text-red-500 {
  color: #ef4444;
}
</style>
