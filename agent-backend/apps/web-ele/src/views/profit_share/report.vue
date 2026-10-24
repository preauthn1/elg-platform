<script lang="ts" setup>
import type { VNode } from 'vue';

import type {
  ProfitShareCategory,
  ProfitShareReportRow,
  ProfitShareSearchAgent,
} from '#/api';

import { computed, h, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getProfitShareCategoryApi,
  getProfitShareReportSummaryApi,
} from '#/api';
import CopyIcon from '#/components/copy-icon.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { amountThousands, sumAmounts } from '#/utils/format';

defineOptions({ name: 'ProfitShareReport' });

/** 各欄背景色 */
const BG: Record<string, string> = {
  actual_profit_loss: '#D9EAD3',
  agent_profit_share: '#D9EAD3',
  direct_profit: '#D9EAD3',
  direct_win_lose: '#D9EAD3',
  direct_win_lose_original: '#D9EAD3',
  event_payment_cost: '#FCE5CD',
  game_cost: '#FCE5CD',
  profit: '#CFE2F3',
  subordinate_contribution_loss: '#FFF2CC',
  subordinate_profit_share: '#FFF2CC',
  team_profit: '#FFF2CC',
  team_win_lose: '#FFF2CC',
  team_win_lose_extra: '#FFF2CC',
  total_win_lose: '#FFF2CC',
  weighting: '#FFFF00',
};

const yesterday = () => dayjs().subtract(1, 'day').format('YYYY-MM-DD');

const filters = reactive({
  category_type: 0 as number | string,
  end_date: yesterday(),
  start_date: yesterday(),
});

const loading = ref(false);
const categories = ref<ProfitShareCategory[]>([]);
const list = ref<ProfitShareReportRow[]>([]);
const searchAgent = ref<ProfitShareSearchAgent[]>([]);
const share = ref<number>(0);
/** 鑽取的代理 id（0=最上層） */
const agentId = ref<number | string>(0);
/** 已查詢過（決定是否顯示表格） */
const searched = ref(false);
/** 查詢當下的起訖是否同日（決定佔成率欄顯示 % 或 --） */
const sameDay = ref(false);

/** >=95 顯示「下級盈虧 5%」額外欄 */
const showExtra = computed(() => Number(share.value) >= 95);

const fmt4 = (v?: null | number | string) => amountThousands(v, 4);

function negColor(v?: null | number | string, force = false) {
  return force || Number(v) < 0 ? 'red' : '';
}

function cellStyle({ column }: { column: { property?: string } }) {
  const bg = column.property ? BG[column.property] : undefined;
  return bg ? { backgroundColor: bg, color: '#303133' } : {};
}

/** 每列實際盈虧 */
function actualProfitLoss(row: ProfitShareReportRow) {
  return (
    Number(row.direct_win_lose_original ?? 0) +
    Number(row.game_cost ?? 0) +
    Number(row.event_cost ?? 0) +
    Number(row.payment_cost ?? 0)
  );
}

function dwlTooltip(row: ProfitShareReportRow) {
  const apl = actualProfitLoss(row);
  return `${tl('company_actual_profit_rm').replace('{POINT}', String(apl))} | ${tl(
    'profit_share_win_lose',
  )} ${row.company_rate ?? ''}%`;
}

async function loadCategories() {
  const res = await getProfitShareCategoryApi();
  if (Number(res.code) === 1000) categories.value = res.data ?? [];
}

async function doSearch() {
  loading.value = true;
  sameDay.value = Boolean(
    filters.start_date &&
    filters.end_date &&
    filters.start_date === filters.end_date,
  );
  try {
    const res = await getProfitShareReportSummaryApi({
      agent_id: agentId.value,
      category_type: filters.category_type,
      end_time: `${filters.end_date || yesterday()} 23:59:59`,
      start_time: `${filters.start_date || yesterday()} 00:00:00`,
    });
    searched.value = true;
    if (Number(res.code) === 1000) {
      list.value = res.data?.data ?? [];
      searchAgent.value = res.data?.search_agent ?? [];
      share.value = Number(res.data?.share ?? 0);
    } else {
      list.value = [];
      searchAgent.value = [];
      share.value = 0;
      ElMessage.warning(res.message);
    }
  } finally {
    loading.value = false;
  }
}

/** 表單查詢：回到最上層（agent_id=0） */
function handleSearch() {
  agentId.value = 0;
  doSearch();
}

/** 鑽取某代理（列點擊或麵包屑點擊） */
function drillAgent(id: number | string) {
  agentId.value = id;
  doSearch();
}

/** 橋接 start_date/end_date ↔ DateRangeFields 的 [start, end] */
const timeRange = computed({
  get: () => [filters.start_date, filters.end_date] as string[],
  set: (v: string[]) => {
    filters.start_date = v?.[0] ?? '';
    filters.end_date = v?.[1] ?? '';
  },
});

function summaryMethod({
  columns,
}: {
  columns: Array<{ property?: string }>;
}): Array<string | VNode> {
  const sum = (key: keyof ProfitShareReportRow) =>
    Number(sumAmounts(list.value.map((r) => r[key])));

  const tDwlOriginal = sum('direct_win_lose_original');
  const tGameCost = sum('game_cost');
  const tEventCost = sum('event_cost');
  const tPaymentCost = sum('payment_cost');
  const tActual = Number(
    sumAmounts(list.value.map((r) => actualProfitLoss(r))),
  );
  const tDwl = sum('direct_win_lose');
  const tDirectProfit = sum('direct_profit');
  const tTeamWl = sum('team_win_lose');
  const tTeamWlExtra = sum('team_win_lose_extra');
  const tTeamProfit = sum('team_profit');
  const tProfit = sum('profit');

  const cell = (value: number, force = false) =>
    h(
      'span',
      { class: 'font-bold', style: { color: negColor(value, force) } },
      fmt4(value),
    );

  return columns.map((col, index) => {
    if (index === 0) return h('span', { class: 'font-bold' }, 'Total');
    switch (col.property) {
      case 'actual_profit_loss': {
        return cell(tActual);
      }
      case 'direct_profit': {
        return cell(tDirectProfit);
      }
      case 'direct_win_lose': {
        return cell(tDwl);
      }
      case 'direct_win_lose_original': {
        return cell(tDwlOriginal);
      }
      case 'event_payment_cost': {
        return cell(tEventCost + tPaymentCost, true);
      }
      case 'game_cost': {
        return cell(tGameCost);
      }
      case 'profit': {
        return cell(tProfit);
      }
      case 'subordinate_contribution_loss': {
        return cell(tTeamWl + tTeamWlExtra);
      }
      case 'team_profit': {
        return cell(tTeamProfit);
      }
      case 'team_win_lose': {
        return cell(tTeamWl);
      }
      case 'team_win_lose_extra': {
        return cell(tTeamWlExtra);
      }
      case 'total_win_lose': {
        return cell(tDirectProfit + tTeamProfit);
      }
      default: {
        return '';
      }
    }
  });
}

const route = useRoute();

onMounted(() => {
  loadCategories();
  // 由佔成派發頁帶日期進來時自動查詢
  const qs = route.query.start_date;
  const qe = route.query.end_date;
  if (typeof qs === 'string' && qs) filters.start_date = qs;
  if (typeof qe === 'string' && qe) filters.end_date = qe;
  if (qs || qe) doSearch();
});
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_shareholder_report')} > ${tl('menu_profit_share_report')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="timeRange" :fixed-offset="8" />
            <FixedTimezoneHint />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.category_type" class="!w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="c in categories"
                :key="c.id"
                :label="c.name"
                :value="c.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <div v-if="searchAgent.length > 0" class="mb-3">
        <b>{{ tl('search_history') }}:</b>
        <template v-for="(row, i) in searchAgent" :key="row.id">
          <span v-if="i > 0"> &gt; </span>
          <a
            class="cursor-pointer font-bold text-[#5867dd]"
            @click="drillAgent(row.id)"
          >
            {{ row.username }}
          </a>
        </template>
      </div>

      <div class="overflow-x-auto">
        <ElTable
          v-loading="loading"
          :cell-style="cellStyle"
          :data="list"
          :summary-method="summaryMethod"
          border
          show-summary
          size="small"
        >
          <ElTableColumn
            :label="tl('agent_account')"
            min-width="130"
            prop="agent_username"
          >
            <template #default="{ row }">
              <span>
                <a
                  class="cursor-pointer font-bold text-[#5867dd]"
                  @click="drillAgent((row as ProfitShareReportRow).agent_id)"
                >
                  {{ (row as ProfitShareReportRow).agent_username }}
                </a>
                <CopyIcon
                  :text="(row as ProfitShareReportRow).agent_username"
                />
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('profit_share_category')"
            min-width="110"
            prop="shareholder_category_name"
          />

          <ElTableColumn
            :label="`${tl('profit_share_win_lose')}100%`"
            align="right"
            min-width="130"
            prop="direct_win_lose_original"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor(
                    (row as ProfitShareReportRow).direct_win_lose_original,
                  ),
                }"
              >
                {{
                  fmt4((row as ProfitShareReportRow).direct_win_lose_original)
                }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('vendor_fee')"
            align="right"
            min-width="120"
            prop="game_cost"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor((row as ProfitShareReportRow).game_cost),
                }"
              >
                {{ fmt4((row as ProfitShareReportRow).game_cost) }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('event_payment_cost')"
            align="right"
            min-width="130"
            prop="event_payment_cost"
          >
            <template #default="{ row }">
              <span class="font-bold" style="color: red">
                {{
                  fmt4(
                    Number((row as ProfitShareReportRow).event_cost ?? 0) +
                      Number((row as ProfitShareReportRow).payment_cost ?? 0),
                  )
                }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('actual_profit_loss')"
            align="right"
            min-width="120"
            prop="actual_profit_loss"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor(
                    actualProfitLoss(row as ProfitShareReportRow),
                  ),
                }"
              >
                {{ fmt4(actualProfitLoss(row as ProfitShareReportRow)) }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('profit_share_win_lose')"
            align="right"
            min-width="130"
            prop="direct_win_lose"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor(
                    (row as ProfitShareReportRow).direct_win_lose,
                  ),
                }"
                :title="dwlTooltip(row as ProfitShareReportRow)"
              >
                {{ fmt4((row as ProfitShareReportRow).direct_win_lose) }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('agent_profit_share')"
            align="right"
            min-width="110"
            prop="agent_profit_share"
          >
            <template #default="{ row }">
              <span class="font-bold">
                {{
                  sameDay
                    ? `${(row as ProfitShareReportRow).direct_rate}%`
                    : '--'
                }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('agent_profit_loss_percentage')"
            align="right"
            min-width="130"
            prop="direct_profit"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor((row as ProfitShareReportRow).direct_profit),
                }"
              >
                {{ fmt4((row as ProfitShareReportRow).direct_profit) }}
              </span>
            </template>
          </ElTableColumn>

          <ElTableColumn
            :label="tl('subordinate_remaining_loss')"
            align="right"
            min-width="130"
            prop="team_win_lose"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor((row as ProfitShareReportRow).team_win_lose),
                }"
              >
                {{ fmt4((row as ProfitShareReportRow).team_win_lose) }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            v-if="showExtra"
            :label="`${tl('subordinate_profit_loss')}5%`"
            align="right"
            min-width="130"
            prop="team_win_lose_extra"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor(
                    (row as ProfitShareReportRow).team_win_lose_extra,
                  ),
                }"
              >
                {{ fmt4((row as ProfitShareReportRow).team_win_lose_extra) }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('subordinate_contribution_loss')"
            align="right"
            min-width="130"
            prop="subordinate_contribution_loss"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor(
                    Number((row as ProfitShareReportRow).team_win_lose ?? 0) +
                      Number(
                        (row as ProfitShareReportRow).team_win_lose_extra ?? 0,
                      ),
                  ),
                }"
              >
                {{
                  fmt4(
                    Number((row as ProfitShareReportRow).team_win_lose ?? 0) +
                      Number(
                        (row as ProfitShareReportRow).team_win_lose_extra ?? 0,
                      ),
                  )
                }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('subordinate_profit_share')"
            align="right"
            min-width="110"
            prop="subordinate_profit_share"
          >
            <template #default="{ row }">
              <span class="font-bold">
                {{
                  sameDay ? `${(row as ProfitShareReportRow).team_rate}%` : '--'
                }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('subordinate_percentage_wins_and_losses')"
            align="right"
            min-width="140"
            prop="team_profit"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor((row as ProfitShareReportRow).team_profit),
                }"
              >
                {{ fmt4((row as ProfitShareReportRow).team_profit) }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('total_win_lose')"
            align="right"
            min-width="120"
            prop="total_win_lose"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor(
                    Number((row as ProfitShareReportRow).direct_profit ?? 0) +
                      Number((row as ProfitShareReportRow).team_profit ?? 0),
                  ),
                }"
              >
                {{
                  fmt4(
                    Number((row as ProfitShareReportRow).direct_profit ?? 0) +
                      Number((row as ProfitShareReportRow).team_profit ?? 0),
                  )
                }}
              </span>
            </template>
          </ElTableColumn>

          <ElTableColumn
            :label="tl('weighting')"
            align="right"
            min-width="100"
            prop="weighting"
          >
            <template #default="{ row }">
              <span class="font-bold">
                {{
                  sameDay
                    ? `${fmt4((row as ProfitShareReportRow).weighting)}%`
                    : '--'
                }}
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('agent_actual_profit_loss')"
            align="right"
            min-width="130"
            prop="profit"
          >
            <template #default="{ row }">
              <span
                class="font-bold"
                :style="{
                  color: negColor((row as ProfitShareReportRow).profit),
                }"
              >
                {{ fmt4((row as ProfitShareReportRow).profit) }}
              </span>
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </div>
    </ElCard>
  </Page>
</template>
