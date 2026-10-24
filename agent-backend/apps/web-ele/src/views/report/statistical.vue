<script lang="ts" setup>
import type { StatisticalRow } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { CircleHelp } from '@vben/icons';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElPopover,
  ElRadioButton,
  ElRadioGroup,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getReportStatisticalApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useExcelExport } from '#/composables/use-excel-export';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { currentTzOffset, nowInUserTz } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportStatistical' });

const PER_PAGE = 10;

const filters = reactive({
  only_direct_member: false,
  /** 每日：逐日列出（現況）；彙總：整段查詢區間合併為單列（後端待實作） */
  report_mode: 'daily' as 'daily' | 'summary',
  time_range: [
    nowInUserTz().format('YYYY-MM-DD'),
    nowInUserTz().format('YYYY-MM-DD'),
  ] as string[],
  username: '',
});

/** 表格顯示區塊：勾選才顯示對應欄位群組（純前端顯示切換，不影響查詢） */
const display = reactive({
  adjustment: true,
  bet: true,
  deposit_withdraw: true,
  member: true,
  reward: true,
});

/** 六鈕快速日期（statistical 為 Y-m-d，取日期部分） */
const loading = ref(false);
const list = ref<StatisticalRow[]>([]);
const total = ref(0);
const page = ref(1);
/** 總摘要（整段查詢區間合計，不受分頁影響；後端待實作，未提供前顯示 0） */
const totalSummary = ref<StatisticalRow>({});
/**
 * 投注統計未計入的遊戲商（target/tickets#4418），「投注」表頭問號顯示用。
 * 名單由後端隨查詢結果一起回傳，與實際排除的是同一份，前端不自行維護。
 */
const excludedVendors = ref<Array<{ id: number; name: string }>>([]);

const NUMERIC_KEYS: Array<Exclude<keyof StatisticalRow, 'sdate'>> = [
  'reg_count',
  'login_count',
  'today_register_deposit_user_number',
  'next_day_bet_user_number',
  'bet_user_30_day_number',
  'first_deposit_count',
  'first_deposit_point',
  'total_deposit',
  'total_deposit_count',
  'total_deposit_users',
  'deposit_fee',
  'total_withdraw',
  'total_withdraw_count',
  'total_withdraw_users',
  'withdraw_fee',
  'total_bet',
  'bet_count',
  'bet_record_count',
  'total_win_lose',
  'total_adjustment_in_count',
  'total_adjustment_out_count',
  'total_adjustment_count',
  'total_adjustment_in',
  'total_adjustment_out',
  'total_adjustment',
  'total_prize_reward',
  'total_agent_prize_reward',
];

/** 頁面摘要：加總目前這頁（已載入）的每一個數值欄位 */
function sumRows(rows: StatisticalRow[]): StatisticalRow {
  const out: StatisticalRow = {};
  for (const key of NUMERIC_KEYS) {
    out[key] = rows.reduce((acc, r) => acc + Number(r[key] ?? 0), 0);
  }
  return out;
}

/** 表格實際渲染資料：每日模式且有資料時，最後補上「頁面摘要」「總摘要」兩列；彙總模式不出現 */
const tableData = computed<StatisticalRow[]>(() => {
  if (filters.report_mode !== 'daily' || list.value.length === 0) {
    return list.value;
  }
  return [
    ...list.value,
    { ...sumRows(list.value), sdate: tl('page_summary') },
    { ...totalSummary.value, sdate: tl('total_summary') },
  ];
});

const fmt = (v?: null | number | string) => amountThousands(v, 4);

/** RTP =（投注額 + 輸贏）/ 投注額 × 100（2 位四捨五入；投注 0 顯示 0%） */
function rtp(row: StatisticalRow) {
  const bet = Number(row.total_bet ?? 0);
  if (bet === 0) return '0%';
  const v = ((bet + Number(row.total_win_lose ?? 0)) / bet) * 100;
  return `${v.toLocaleString('en-US', { maximumFractionDigits: 2, minimumFractionDigits: 2 })}%`;
}

/** 存提差 = 充值金額 − 取款金額（前端計算，後端未提供此欄位） */
function depositWithdrawDiff(row: StatisticalRow) {
  return Number(row.total_deposit ?? 0) - Number(row.total_withdraw ?? 0);
}

/**
 * 公司輸贏 ＝ 玩家輸贏 × (−1)（target/tickets#4418）。
 *
 * 後端 `total_win_lose` 是**玩家角度**（玩家贏為正）。這一欄改以公司角度呈現，
 * 換算只做在前端，後端欄位語意不動。
 */
function companyWinLose(row: StatisticalRow) {
  return -Number(row.total_win_lose ?? 0);
}

/**
 * NGR ＝ 公司輸贏 − 彩金與獎勵（會員＋代理，不扣手續費；前端計算）。
 *
 * 🔴 兩項都要扣：`total_prize_reward`（會員）與 `total_agent_prize_reward`（代理）。
 * 原本只扣會員那一項，代理的彩金與獎勵漏減（target/tickets#4418）。
 * 實測 2026-09-23：450.30 − 135.47 − 21.65 ＝ 293.18。
 *
 * 🔴 正負號：原本直接把玩家角度的 `total_win_lose` 當 GGR 代入，玩家輸錢
 * （公司實際賺）時 NGR 會算成負值，必須用公司角度的值。
 *
 * 註：需求文件③的示意算式只減了會員那一項（450 − 200 ＝ 250，代理的 50 未扣），
 * 與此處不一致；以使用者 2026-09-24 拿實際資料確認的口徑為準，文件待更正。
 */
function ngr(row: StatisticalRow) {
  return (
    companyWinLose(row) -
    Number(row.total_prize_reward ?? 0) -
    Number(row.total_agent_prize_reward ?? 0)
  );
}

async function loadData() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  if (dayjs(start).isAfter(dayjs(end))) {
    ElMessage.warning('start_date great than end_date');
    return;
  }
  loading.value = true;
  try {
    // Tier B 改造：後端（ReportSummaryController@getStatistical）依 tz 換算日界與查詢窗，
    // sdate 已是使用者時區日界、直接顯示。後端未部署時 tz 被忽略、行為同 GMT+8。
    const res = await getReportStatisticalApi({
      end_date: `${end} 23:59:59`,
      only_direct_member: filters.only_direct_member ? 1 : '',
      page: page.value,
      per_page: PER_PAGE,
      start_date: `${start} 00:00:00`,
      summary: filters.report_mode === 'summary' ? 1 : '',
      tz: currentTzOffset(),
      username: filters.username,
    });
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      total.value = res.pagination?.total ?? list.value.length;
      totalSummary.value = res.summary ?? {};
      excludedVendors.value = res.excluded_vendors ?? [];
    } else {
      list.value = [];
      total.value = 0;
      totalSummary.value = {};
      excludedVendors.value = [];
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

const { exporting, runExport } = useExcelExport();
/** 匯出 Excel：依當前查詢條件（含每日/彙總）重新抓一次不分頁的完整資料 */
async function handleExport() {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('between'));
    return;
  }
  if (dayjs(start).isAfter(dayjs(end))) {
    ElMessage.warning('start_date great than end_date');
    return;
  }
  const params = {
    end_date: `${end} 23:59:59`,
    only_direct_member: filters.only_direct_member ? 1 : '',
    page: 1,
    per_page: 100_000,
    start_date: `${start} 00:00:00`,
    summary: filters.report_mode === 'summary' ? 1 : '',
    tz: currentTzOffset(),
    username: filters.username,
  };
  /** 匯出欄位跟著「表格顯示區塊」勾選狀態走，跟畫面上看到的一致 */
  const segments: Array<{
    enabled: boolean;
    headers: string[];
    values: (r: StatisticalRow) => Array<null | number | string | undefined>;
  }> = [
    {
      enabled: true,
      headers: [tl('date')],
      values: (r) => [r.sdate],
    },
    {
      enabled: display.member,
      headers: [
        tl('register_number'),
        tl('login_number'),
        tl('today_register_deposit_user_number'),
        tl('next_day_bet_user_number'),
        tl('bet_user_30_day_number'),
      ],
      values: (r) => [
        r.reg_count,
        r.login_count,
        r.today_register_deposit_user_number ?? 0,
        r.next_day_bet_user_number ?? 0,
        r.bet_user_30_day_number ?? 0,
      ],
    },
    {
      enabled: display.deposit_withdraw,
      headers: [
        `${tl('first_deposit')}${tl('count_times')}`,
        `${tl('first_deposit')}${tl('grand_total')}`,
        `${tl('paytype_deposit')}${tl('people_count')}`,
        `${tl('paytype_deposit')}${tl('count_times')}`,
        `${tl('paytype_deposit')}${tl('amount')}`,
        `${tl('paytype_deposit')}${tl('handling_fee')}`,
        `${tl('withdraw_group')}${tl('people_count')}`,
        `${tl('withdraw_group')}${tl('count_times')}`,
        `${tl('withdraw_group')}${tl('amount')}`,
        `${tl('withdraw_group')}${tl('handling_fee')}`,
        tl('deposit_sub_withdrawal_amount'),
      ],
      values: (r) => [
        r.first_deposit_count ?? 0,
        fmt(r.first_deposit_point),
        r.total_deposit_users,
        r.total_deposit_count,
        fmt(r.total_deposit),
        fmt(r.deposit_fee),
        r.total_withdraw_users,
        r.total_withdraw_count,
        fmt(r.total_withdraw),
        fmt(r.withdraw_fee),
        fmt(depositWithdrawDiff(r)),
      ],
    },
    {
      enabled: display.bet,
      headers: [
        `${tl('bet_group')}${tl('people_count')}`,
        `${tl('bet_group')}${tl('count_times')}`,
        `${tl('bet_group')}${tl('amount')}`,
        tl('company_win_lose'),
        tl('rtp'),
      ],
      values: (r) => [
        r.bet_count ?? 0,
        r.bet_record_count ?? 0,
        fmt(r.total_bet),
        fmt(companyWinLose(r)),
        rtp(r),
      ],
    },
    {
      enabled: display.adjustment,
      headers: [
        `${tl('adjustment_count')}${tl('adjustment_in')}`,
        `${tl('adjustment_count')}${tl('adjustment_out')}`,
        `${tl('adjustment_count')}${tl('adjustment_total')}`,
        `${tl('adjustment_amount')}${tl('adjustment_in')}`,
        `${tl('adjustment_amount')}${tl('adjustment_out')}`,
        `${tl('adjustment_amount')}${tl('adjustment_total')}`,
      ],
      values: (r) => [
        r.total_adjustment_in_count ?? 0,
        r.total_adjustment_out_count ?? 0,
        r.total_adjustment_count ?? 0,
        fmt(r.total_adjustment_in),
        fmt(r.total_adjustment_out),
        fmt(r.total_adjustment),
      ],
    },
    {
      enabled: display.reward,
      headers: [
        `${tl('prize_and_reward')}${tl('member')}`,
        `${tl('prize_and_reward')}${tl('agent')}`,
      ],
      values: (r) => [
        fmt(r.total_prize_reward),
        fmt(r.total_agent_prize_reward),
      ],
    },
    {
      enabled: display.bet && display.reward,
      headers: [tl('ngr')],
      values: (r) => [fmt(ngr(r))],
    },
  ];
  const activeSegments = segments.filter((s) => s.enabled);

  await runExport(
    () => getReportStatisticalApi(params),
    `statistical_${Date.now()}`,
    activeSegments.flatMap((s) => s.headers),
    (r) => activeSegments.flatMap((s) => s.values(r)),
    {
      audit: { conditions: params },
      dateRange: [start, end],
      title: `${tl('statistical_report')} > ${tl('data_analysis')}`,
    },
  );
}

// 頁面載入即查詢
onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('data_analysis')}`">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <div class="flex w-full flex-col gap-2">
              <ElInput v-model="filters.username" clearable />
              <ElCheckbox v-model="filters.only_direct_member">
                {{ tl('only_direct_member') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <DateRangeFields v-model="filters.time_range" />
          </ElFormItem>
          <ElFormItem :label="tl('display_settings')">
            <div class="display-checkboxes flex flex-wrap items-center gap-4">
              <ElCheckbox v-model="display.member">
                {{ tl('member') }}
              </ElCheckbox>
              <ElCheckbox v-model="display.deposit_withdraw">
                {{ tl('deposit_withdraw') }}
              </ElCheckbox>
              <ElCheckbox v-model="display.bet">
                {{ tl('bet_group') }}
              </ElCheckbox>
              <ElCheckbox v-model="display.adjustment">
                {{ tl('adjustment') }}
              </ElCheckbox>
              <ElCheckbox v-model="display.reward">
                {{ tl('prize_and_reward') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('query_type')">
            <div
              class="flex flex-1 flex-wrap items-center justify-between gap-2"
            >
              <ElRadioGroup v-model="filters.report_mode">
                <ElRadioButton value="daily">{{ tl('daily') }}</ElRadioButton>
                <ElRadioButton value="summary">
                  {{ tl('aggregate') }}
                </ElRadioButton>
              </ElRadioGroup>
              <div class="flex flex-wrap items-center gap-2">
                <ElButton
                  :loading="exporting"
                  type="success"
                  @click="handleExport"
                >
                  {{ tl('excel') }}
                </ElButton>
                <ElButton
                  :loading="loading"
                  native-type="submit"
                  type="primary"
                >
                  {{ tl('search') }}
                </ElButton>
              </div>
            </div>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable
        v-loading="loading"
        class="statistical-table"
        :data="tableData"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          v-if="filters.report_mode === 'daily'"
          :label="tl('date')"
          fixed="left"
          prop="sdate"
          min-width="95"
        />
        <ElTableColumn
          v-if="display.member"
          :label="tl('member')"
          align="center"
        >
          <ElTableColumn
            :label="tl('register_number')"
            align="right"
            prop="reg_count"
            min-width="70"
          />
          <ElTableColumn
            :label="tl('login_number')"
            align="right"
            prop="login_count"
            min-width="70"
          />
          <ElTableColumn
            :label="tl('today_register_deposit_user_number')"
            align="right"
            min-width="85"
          >
            <template #default="{ row }">
              {{ row.today_register_deposit_user_number ?? 0 }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('next_day_bet_user_number')"
            align="right"
            min-width="70"
          >
            <template #default="{ row }">
              {{ row.next_day_bet_user_number ?? 0 }}
            </template>
          </ElTableColumn>
          <!-- 表頭「前30天不重複活躍」實測需 113px，min-width 給 120 才不截斷 -->
          <ElTableColumn
            :label="tl('bet_user_30_day_number')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              {{ row.bet_user_30_day_number ?? 0 }}
            </template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.deposit_withdraw"
          :label="tl('first_deposit')"
          align="center"
        >
          <ElTableColumn
            :label="tl('count_times')"
            align="right"
            min-width="60"
          >
            <template #default="{ row }">
              {{ row.first_deposit_count ?? 0 }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('grand_total')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              {{ fmt(row.first_deposit_point) }}
            </template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.deposit_withdraw"
          :label="tl('paytype_deposit')"
          align="center"
        >
          <ElTableColumn
            :label="tl('people_count')"
            align="right"
            prop="total_deposit_users"
            min-width="60"
          />
          <ElTableColumn
            :label="tl('count_times')"
            align="right"
            prop="total_deposit_count"
            min-width="60"
          />
          <ElTableColumn :label="tl('amount')" align="right" min-width="120">
            <template #default="{ row }">{{ fmt(row.total_deposit) }}</template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('handling_fee')"
            align="right"
            min-width="80"
          >
            <template #default="{ row }">{{ fmt(row.deposit_fee) }}</template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.deposit_withdraw"
          :label="tl('withdraw_group')"
          align="center"
        >
          <ElTableColumn
            :label="tl('people_count')"
            align="right"
            prop="total_withdraw_users"
            min-width="60"
          />
          <ElTableColumn
            :label="tl('count_times')"
            align="right"
            prop="total_withdraw_count"
            min-width="60"
          />
          <ElTableColumn :label="tl('amount')" align="right" min-width="120">
            <template #default="{ row }">
              {{ fmt(row.total_withdraw) }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('handling_fee')"
            align="right"
            min-width="80"
          >
            <template #default="{ row }">{{ fmt(row.withdraw_fee) }}</template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.deposit_withdraw"
          :label="tl('deposit_sub_withdrawal_amount')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span :class="{ 'text-red-500': depositWithdrawDiff(row) < 0 }">{{
              fmt(depositWithdrawDiff(row))
            }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.bet"
          :label="tl('bet_group')"
          align="center"
        >
          <!-- target/tickets#4418：投注統計排除了部分遊戲商，表頭問號列出是哪些（名單取自後端） -->
          <template #header>
            {{ tl('bet_group') }}
            <ElPopover
              v-if="excludedVendors.length > 0"
              placement="bottom"
              :title="tl('statistical_excluded_vendors')"
              trigger="click"
              :width="260"
            >
              <template #reference>
                <CircleHelp
                  class="ml-1 inline-block size-4 cursor-pointer align-text-bottom text-gray-400 hover:text-indigo-500"
                />
              </template>
              <ul class="space-y-1 text-left text-sm">
                <li v-for="v in excludedVendors" :key="v.id">
                  <span class="text-gray-400">({{ v.id }})</span> {{ v.name }}
                </li>
              </ul>
            </ElPopover>
          </template>
          <ElTableColumn
            :label="tl('people_count')"
            align="right"
            prop="bet_count"
            min-width="60"
          />
          <ElTableColumn
            :label="tl('count_times')"
            align="right"
            min-width="60"
          >
            <template #default="{ row }">
              {{ row.bet_record_count ?? 0 }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('amount')" align="right" min-width="120">
            <template #default="{ row }">{{ fmt(row.total_bet) }}</template>
          </ElTableColumn>
          <!--
            target/tickets#4418：改以公司角度呈現。
            🔴 欄寬維持 min-width="140"，與本表其他金額欄一致。需求文件②寫「不再是
            固定 140px」是對 min-width 的誤解——它是下限不是上限，本來就會依內容撐開；
            拿掉會掉回 ElTable 預設的 80px，大額數字反而被截斷（實測 278,478.6… ）。
          -->
          <ElTableColumn
            :label="tl('company_win_lose')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              <span
                :class="{
                  'text-red-500': companyWinLose(row) < 0,
                  'text-green-500': companyWinLose(row) > 0,
                }"
                >{{ fmt(companyWinLose(row)) }}</span
              >
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('rtp')" align="right" min-width="70">
            <template #default="{ row }">{{ rtp(row) }}</template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.adjustment"
          :label="tl('adjustment_count')"
          align="center"
        >
          <ElTableColumn
            :label="tl('adjustment_in')"
            align="right"
            min-width="60"
          >
            <template #default="{ row }">
              {{ row.total_adjustment_in_count ?? 0 }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('adjustment_out')"
            align="right"
            min-width="60"
          >
            <template #default="{ row }">
              {{ row.total_adjustment_out_count ?? 0 }}
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('adjustment_total')"
            align="right"
            min-width="60"
          >
            <template #default="{ row }">
              {{ row.total_adjustment_count ?? 0 }}
            </template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.adjustment"
          :label="tl('adjustment_amount')"
          align="center"
        >
          <ElTableColumn
            :label="tl('adjustment_in')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              <span class="text-green-500">{{
                fmt(row.total_adjustment_in)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('adjustment_out')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              <span class="text-red-500">{{
                fmt(row.total_adjustment_out)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('adjustment_total')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              {{ fmt(row.total_adjustment) }}
            </template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.reward"
          :label="tl('prize_and_reward')"
          align="center"
        >
          <ElTableColumn :label="tl('member')" align="right" min-width="120">
            <template #default="{ row }">
              {{ fmt(row.total_prize_reward) }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('agent')" align="right" min-width="120">
            <template #default="{ row }">
              {{ fmt(row.total_agent_prize_reward) }}
            </template>
          </ElTableColumn>
        </ElTableColumn>
        <ElTableColumn
          v-if="display.bet && display.reward"
          :label="tl('ngr')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            <span
              :class="{
                'text-red-500': ngr(row) < 0,
                'text-green-500': ngr(row) > 0,
              }"
              >{{ fmt(ngr(row)) }}</span
            >
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

<style scoped>
/*
 * Element Plus 對 .el-checkbox__label 有自己的 color（勾選後換主題色），
 * 顯示設定這排勾選項預設全勾，若不蓋掉會整排變主題藍，跟其他查詢欄位的文字色不一致。
 * 比照專案既有寫法（agent/edit.vue）用 :deep() 指到 label 並補 !important。
 */
.display-checkboxes :deep(.el-checkbox__label) {
  color: inherit !important;
}

/* 表頭/資料一律不換行：改自動寬度後短標籤不再被拉寬，但數字換行會很難看 */
.statistical-table :deep(.cell) {
  white-space: nowrap;
}
</style>
