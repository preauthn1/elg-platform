<script lang="ts" setup>
import type { VNode } from 'vue';

import type { Sr28Metrics, Sr28Params, Sr28Result, Sr28Row } from '#/api/sr28';
import type { DragPanTableRef } from '#/composables/use-table-drag-pan';

import { computed, h, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { createIconifyIcon } from '@vben/icons';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTooltip,
} from 'element-plus';

import { getSr28ListApi } from '#/api/sr28';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useTableDragPan } from '#/composables/use-table-drag-pan';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import { amountThousands, buildPlainAmounts } from '#/utils/format';
import { currentTzOffset, nowInUserTz } from '#/utils/timezone-shift';

defineOptions({ name: 'ReportSr28' });

const InfoIcon = createIconifyIcon('lucide:info');
const ChevronUpIcon = createIconifyIcon('lucide:chevron-up');

/** 查詢條件卡片可收合，讓表格多出空間；不記憶，每次進頁面預設展開 */
const searchCollapsed = ref(false);

/**
 * SR28 代理營運彙總表（target/tickets#4362）。
 *
 * 欄位定義集中在 COLUMN_GROUPS：表頭（含群組與 ⓘ 提示）、儲存格、表底合計、Excel 匯出
 * 全部由它產生，25 欄的順序、單位與上色規則只在這裡寫一次。
 */
type MetricKey = keyof Sr28Metrics;

interface MetricColumn {
  key: MetricKey;
  /** int＝人數／筆數；amount＝金額（2 位小數千分位） */
  kind: 'amount' | 'int';
  labelKey: string;
  minWidth: number;
  /** 正綠負紅：只上在正負有意義的欄位，比照需求文件示意圖 */
  signed?: boolean;
  /** 表頭欄名旁 ⓘ 提示的語系 key：推導欄位放算式、代理錢包餘額放即時值說明（需求文件規定要隨語系翻譯） */
  tipKey?: string;
}

interface ColumnGroup {
  /** 無群組名的欄位（代理錢包餘額、註冊人數…）直接單欄呈現 */
  groupKey?: string;
  columns: MetricColumn[];
}

const COLUMN_GROUPS: ColumnGroup[] = [
  {
    columns: [
      {
        key: 'wallet_balance',
        kind: 'amount',
        labelKey: 'sr28_wallet_balance',
        minWidth: 130,
        tipKey: 'sr28_wallet_balance_tip',
      },
    ],
  },
  {
    columns: [
      {
        key: 'register_count',
        kind: 'int',
        labelKey: 'sr28_register_count',
        minWidth: 80,
      },
    ],
  },
  {
    columns: [
      {
        key: 'active_player_count',
        kind: 'int',
        labelKey: 'sr28_active_player_count',
        minWidth: 60,
        tipKey: 'sr28_active_player_tip',
      },
    ],
  },
  {
    groupKey: 'sr28_ftd',
    columns: [
      { key: 'ftd_count', kind: 'int', labelKey: 'sr28_people', minWidth: 60 },
      {
        key: 'ftd_amount',
        kind: 'amount',
        labelKey: 'sr28_amount',
        minWidth: 110,
      },
    ],
  },
  {
    groupKey: 'sr28_deposit',
    columns: [
      {
        key: 'deposit_count',
        kind: 'int',
        labelKey: 'sr28_count',
        minWidth: 60,
      },
      {
        key: 'deposit_amount',
        kind: 'amount',
        labelKey: 'sr28_amount',
        minWidth: 110,
      },
      {
        key: 'deposit_fee',
        kind: 'amount',
        labelKey: 'sr28_fee',
        minWidth: 100,
      },
    ],
  },
  {
    groupKey: 'sr28_withdrawal',
    columns: [
      {
        key: 'withdrawal_count',
        kind: 'int',
        labelKey: 'sr28_count',
        minWidth: 60,
      },
      {
        key: 'withdrawal_amount',
        kind: 'amount',
        labelKey: 'sr28_amount',
        minWidth: 110,
      },
      {
        key: 'withdrawal_fee',
        kind: 'amount',
        labelKey: 'sr28_fee',
        minWidth: 100,
      },
    ],
  },
  {
    columns: [
      {
        key: 'net_deposit',
        kind: 'amount',
        labelKey: 'sr28_net_deposit',
        minWidth: 80,
        signed: true,
        tipKey: 'sr28_net_deposit_formula',
      },
    ],
  },
  {
    groupKey: 'sr28_bet',
    columns: [
      { key: 'bet_count', kind: 'int', labelKey: 'sr28_count', minWidth: 80 },
      {
        key: 'bet_amount',
        kind: 'amount',
        labelKey: 'sr28_amount',
        minWidth: 110,
      },
      {
        key: 'valid_bet',
        kind: 'amount',
        labelKey: 'sr28_valid_bet',
        minWidth: 110,
      },
      {
        key: 'payout',
        kind: 'amount',
        labelKey: 'sr28_payout',
        minWidth: 110,
      },
      {
        key: 'member_win_lose',
        kind: 'amount',
        labelKey: 'sr28_member_win_lose',
        minWidth: 110,
        signed: true,
      },
    ],
  },
  {
    groupKey: 'sr28_company_win_lose',
    columns: [
      {
        key: 'ggr',
        kind: 'amount',
        labelKey: 'sr28_ggr',
        minWidth: 80,
        signed: true,
        tipKey: 'sr28_ggr_formula',
      },
      {
        key: 'ngr',
        kind: 'amount',
        labelKey: 'sr28_ngr',
        minWidth: 80,
        signed: true,
        tipKey: 'sr28_ngr_formula',
      },
    ],
  },
  {
    columns: [
      {
        key: 'bonus',
        kind: 'amount',
        labelKey: 'sr28_bonus',
        minWidth: 80,
        tipKey: 'sr28_bonus_formula',
      },
    ],
  },
  {
    groupKey: 'sr28_adjustment',
    columns: [
      {
        key: 'adjust_count',
        kind: 'int',
        labelKey: 'sr28_adjust_count',
        minWidth: 60,
      },
      {
        key: 'adjust_in',
        kind: 'amount',
        labelKey: 'sr28_adjust_in',
        minWidth: 80,
      },
      {
        key: 'adjust_out',
        kind: 'amount',
        labelKey: 'sr28_adjust_out',
        minWidth: 80,
      },
      {
        key: 'adjust_net',
        kind: 'amount',
        labelKey: 'sr28_adjust_net',
        minWidth: 80,
        signed: true,
        tipKey: 'sr28_adjust_net_formula',
      },
    ],
  },
];

const ALL_COLUMNS: MetricColumn[] = COLUMN_GROUPS.flatMap((g) => g.columns);
const COLUMN_BY_KEY = new Map(ALL_COLUMNS.map((c) => [c.key, c]));

/*
 * ---- 欄位設定：以「大項」為單位勾選，存 localStorage ----
 *
 * 表頭有跨欄群組，所以一個群組（首存、存款、提款、下注、公司輸贏、調整）一個勾選框，勾掉整組隱藏；
 * 沒有群組的單欄（代理錢包餘額、註冊人數…）各自一個。代理商帳號是報表主體、左側凍結，固定顯示。
 */

/** 大項的識別：有群組用群組 key，單欄用欄位 key */
function groupId(g: ColumnGroup): string {
  return g.groupKey ?? g.columns[0]?.key ?? '';
}

/** 這個 key 專屬 SR28，不與其他報表的欄位設定共用——共用會互相覆蓋（SR27 踩過） */
const COLUMN_STORAGE_KEY = 'sr28-visible-column-groups';

const visibleGroupIds = reactive<Record<string, boolean>>(
  Object.fromEntries(COLUMN_GROUPS.map((g) => [groupId(g), true])),
);
const columnSettingsVisible = ref(false);

function isGroupVisible(id: string): boolean {
  return visibleGroupIds[id] !== false;
}

/** 私密瀏覽模式讀寫 localStorage 會丟例外，讀不到就用全部顯示的預設值 */
function loadColumnPrefs() {
  try {
    const raw = localStorage.getItem(COLUMN_STORAGE_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw) as Record<string, boolean>;
    for (const g of COLUMN_GROUPS) {
      const value = saved[groupId(g)];
      if (typeof value === 'boolean') visibleGroupIds[groupId(g)] = value;
    }
  } catch {
    // 讀不到就維持預設
  }
}

function saveColumnPrefs() {
  try {
    localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(visibleGroupIds));
  } catch {
    // 存不了不影響當下顯示
  }
}

function toggleGroup(id: string, checked: boolean) {
  visibleGroupIds[id] = checked;
  saveColumnPrefs();
}

function setAllColumns(checked: boolean) {
  for (const g of COLUMN_GROUPS) visibleGroupIds[groupId(g)] = checked;
  saveColumnPrefs();
}

loadColumnPrefs();

/** 設定窗的選項：群組顯示群組名，單欄顯示欄名 */
const columnOptions = computed(() =>
  COLUMN_GROUPS.map((g) => ({
    id: groupId(g),
    label: tl(g.groupKey ?? g.columns[0]?.labelKey ?? ''),
  })),
);

/** 表格實際顯示的大項 */
const visibleGroups = computed(() =>
  COLUMN_GROUPS.filter((g) => isGroupVisible(groupId(g))),
);

/**
 * 欄位組成一變就整張表重建：ElTable 只在欄位掛載時決定位置，事後重新顯示的欄位會被排到最後，
 * 換 key 才會照定義的順序重排（與帳號管理的欄位設定同一個做法）。
 */
const tableKey = computed(() =>
  visibleGroups.value.map((g) => groupId(g)).join(','),
);

/** 欄位多、水平捲軸在表格最底部：比照帳號管理，表格內按住即可左右拖曳平移 */
const tableRef: DragPanTableRef = ref();
const { onTableDragStart } = useTableDragPan(tableRef);

/** 上方 7 張卡片：最常看的指標，其餘數值以表底合計列呈現（需求文件「summary 分兩層」） */
const CARD_KEYS: Array<{ key: MetricKey; labelKey: string }> = [
  { key: 'register_count', labelKey: 'sr28_register_count' },
  { key: 'active_player_count', labelKey: 'sr28_active_player_count' },
  { key: 'deposit_amount', labelKey: 'sr28_deposit_amount' },
  { key: 'withdrawal_amount', labelKey: 'sr28_withdrawal_amount' },
  { key: 'net_deposit', labelKey: 'sr28_net_deposit' },
  { key: 'ggr', labelKey: 'sr28_ggr' },
  { key: 'bonus', labelKey: 'sr28_bonus' },
];

const PAGE_SIZES = [20, 50, 100];
const EXPORT_PER_PAGE = 100_000;

/**
 * 預設昨天 00:00 ~ 今天 23:59（與 SR29 一致）。期間只到「小時」：SR28 定位為非即時報表，資料源以整點彙整表為主
 * （member_hourly_statistics、summary_bet_records），起始固定整點、結束固定 59 分（使用者 2026-10-07 定案）。
 */
function defaultRange(): string[] {
  return [
    nowInUserTz().subtract(1, 'day').format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ];
}

const filters = reactive({
  agent_username: '',
  time_range: defaultRange(),
});

/** 按「查詢」當下的條件快照：換頁、匯出都用它，不受表單事後改動影響 */
const applied = ref<null | { end: string; start: string; username: string }>(
  null,
);
const result = ref<null | Sr28Result>(null);
const page = ref(1);
const perPage = ref(PAGE_SIZES[0] ?? 20);
const loading = ref(false);
const exporting = ref(false);

const rows = computed<Sr28Row[]>(() => result.value?.list ?? []);
const summary = computed<null | Sr28Metrics>(
  () => result.value?.summary ?? null,
);
const total = computed(() => result.value?.pagination.total ?? 0);
const periodLabel = computed(() =>
  applied.value ? `${applied.value.start} ~ ${applied.value.end}` : '',
);

/** apis 成功碼為 200；失敗時顯示後端訊息 */
function ok(res: { code: number | string; message?: string }): boolean {
  if (Number(res.code) === 200) return true;
  if (res.message) ElMessage.error(res.message);
  return false;
}

function guard(): boolean {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('required_column'));
    return false;
  }
  // 後端 Request 也擋一次；這裡先擋是為了不用送一趟才知道
  if (dayjs(end).isAfter(dayjs(start).add(3, 'month'))) {
    ElMessage.warning(tl('period_max_3_months'));
    return false;
  }
  return true;
}

function paramsFor(p: number, size: number): null | Sr28Params {
  if (!applied.value) return null;
  return {
    agent_username: applied.value.username || undefined,
    // 期間已含時分，這裡只補秒：起補 :00、迄補 :59（皆為所選時區的牆鐘）
    end_time: `${applied.value.end}:59`,
    page: p,
    per_page: size,
    start_time: `${applied.value.start}:00`,
    tz_offset: currentTzOffset(),
  };
}

async function fetchPage() {
  const params = paramsFor(page.value, perPage.value);
  if (!params) return;
  loading.value = true;
  try {
    const res = await getSr28ListApi(params);
    result.value = ok(res) ? res.data : null;
  } finally {
    loading.value = false;
  }
}

async function search() {
  if (!guard()) return;
  const [start = '', end = ''] = filters.time_range ?? [];
  applied.value = { end, start, username: filters.agent_username.trim() };
  page.value = 1;
  await fetchPage();
}

function handleReset() {
  filters.agent_username = '';
  filters.time_range = defaultRange();
}

async function handlePageChange(p: number) {
  page.value = p;
  await fetchPage();
}

/** 改每頁筆數一律回第 1 頁：留在原頁碼可能已超出新的總頁數 */
async function handleSizeChange(size: number) {
  perPage.value = size;
  page.value = 1;
  await fetchPage();
}

/* ---- 顯示格式 ---- */
function fmt(col: MetricColumn, v?: null | number | string): string {
  if (v === null || v === undefined) return '—';
  return col.kind === 'int' ? amountThousands(v, 0) : amountThousands(v, 2);
}

/** 正綠負紅；0 或 null 不上色。只有 signed 欄位上色 */
function signClass(col: MetricColumn | undefined, v?: null | number | string) {
  if (!col?.signed) return '';
  const n = Number(v);
  if (v === null || v === undefined || Number.isNaN(n) || n === 0) return '';
  return n > 0 ? 'text-green-600' : 'text-red-500';
}

function cardText(key: MetricKey): string {
  const col = COLUMN_BY_KEY.get(key);
  const v = summary.value?.[key];
  return col ? fmt(col, v) : '—';
}

function cardClass(key: MetricKey): string {
  return signClass(COLUMN_BY_KEY.get(key), summary.value?.[key]);
}

/**
 * 表底合計列（需求文件：全部筆數的合計，不是當頁）。一律取後端的 summary，不在前端加總
 * rows：活躍玩家是不重複人數，各列相加會多算。
 */
function summaryMethod({
  columns,
}: {
  columns: Array<{ property: string }>;
}): Array<string | VNode> {
  return columns.map((column, index) => {
    if (index === 0) return tl('sr28_total');
    const col = COLUMN_BY_KEY.get(column.property as MetricKey);
    if (!col || !summary.value) return '';
    const v = summary.value[col.key];
    const cls = signClass(col, v);
    // 不換行：table-layout auto 會依內容撐寬欄位，合計通常是最長的值
    return h('span', { class: ['whitespace-nowrap', cls] }, fmt(col, v));
  });
}

/* ---- Excel 匯出：查詢結果的全部筆數，欄位與畫面一致，合計列放在表底 ---- */
function exportHeader(col: MetricColumn, groupKey?: string): string {
  return groupKey ? `${tl(groupKey)}・${tl(col.labelKey)}` : tl(col.labelKey);
}

/** 匯出的欄位跟著畫面走：欄位設定隱藏的大項不匯出（需求文件：欄位與畫面一致） */
function exportColumns(): Array<{ col: MetricColumn; groupKey?: string }> {
  return visibleGroups.value.flatMap((g) =>
    g.columns.map((col) => ({ col, groupKey: g.groupKey })),
  );
}

function metricCells(
  m: Sr28Metrics,
  columns: Array<{ col: MetricColumn }>,
): Array<number | string> {
  return columns.map(({ col }) => m[col.key] ?? (col.kind === 'int' ? 0 : '0'));
}

async function handleExport() {
  const period = applied.value;
  const params = paramsFor(1, EXPORT_PER_PAGE);
  if (!period || !params || rows.value.length === 0) {
    ElMessage.warning(tl('no_data'));
    return;
  }

  exporting.value = true;
  try {
    const full = await getSr28ListApi(params);
    if (!ok(full)) return;

    const columns = exportColumns();
    const headers = [
      tl('agent_account'),
      ...columns.map(({ col, groupKey }) => exportHeader(col, groupKey)),
    ];
    const body: Array<Array<number | string>> = full.data.list.map((r) => [
      r.agent_username,
      ...metricCells(r, columns),
    ]);
    body.push([tl('sr28_total'), ...metricCells(full.data.summary, columns)]);

    const menuName = tl('menu_report_sr28');
    await buildPlainAmounts(() =>
      exportExcel('sr28', headers, body, {
        audit: {
          conditions: { ...params },
          menuName,
          // body 最後一列是合計、不是資料，不給的話稽核筆數會多算 1
          recordCount: full.data.list.length,
        },
        dateRange: [period.start, period.end],
        title: menuName,
      }),
    );
  } finally {
    exporting.value = false;
  }
}
</script>

<template>
  <Page :title="`${tl('statistical_report')} > ${tl('menu_report_sr28')}`">
    <ElCard
      :body-class="searchCollapsed ? '!hidden' : undefined"
      class="mb-3"
      :header-class="searchCollapsed ? '!border-b-0' : undefined"
      shadow="never"
    >
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('search') }}</span>
          <ElButton
            :aria-expanded="!searchCollapsed"
            :aria-label="tl('search')"
            circle
            size="small"
            text
            @click="searchCollapsed = !searchCollapsed"
          >
            <ChevronUpIcon
              class="size-4 transition-transform"
              :class="searchCollapsed ? 'rotate-180' : ''"
            />
          </ElButton>
        </div>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.agent_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')" required>
            <DateRangeFields
              v-model="filters.time_range"
              format="YYYY-MM-DD HH:mm"
              hour-only
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />
          </ElFormItem>
          <!-- 按鈕擺在期間右邊那格，不另佔一列；順序照需求文件：清除、Excel、查詢 -->
          <ElFormItem label-width="0">
            <div class="flex w-full justify-end gap-2">
              <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
              <ElButton
                :loading="exporting"
                type="success"
                @click="handleExport"
              >
                {{ tl('excel') }}
              </ElButton>
              <ElButton :loading="loading" native-type="submit" type="primary">
                {{ tl('search') }}
              </ElButton>
            </div>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <!-- 上方 7 張卡片：查詢結果全部筆數的合計，不隨分頁變動 -->
    <div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
      <ElCard
        v-for="card in CARD_KEYS"
        :key="card.key"
        body-class="!p-3"
        shadow="never"
      >
        <div class="text-muted-foreground text-xs">{{ tl(card.labelKey) }}</div>
        <div class="mt-1 text-lg font-semibold" :class="cardClass(card.key)">
          {{ cardText(card.key) }}
        </div>
      </ElCard>
    </div>

    <ElCard shadow="never">
      <!-- 代理錢包餘額的口徑（即時值、不隨查詢期間變動）提示放在該欄欄名旁的 icon，這裡不重複 -->
      <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="ml-auto flex items-center gap-2">
          <ElTag v-if="periodLabel" size="large" type="primary">
            {{ periodLabel }}
          </ElTag>
          <ElButton size="small" @click="columnSettingsVisible = true">
            {{ tl('column_settings') }}
          </ElButton>
        </div>
      </div>

      <!-- relative：給表格底部的 SR28 浮水印定位 -->
      <div class="relative">
        <!-- table-layout auto：欄寬依內容撐開，min-width 只是下限；金額一律不換行，超過就把欄位撐寬 -->
        <ElTable
          ref="tableRef"
          :key="tableKey"
          class="drag-pan-table"
          table-layout="auto"
          v-loading="loading"
          border
          :data="rows"
          :empty-text="applied ? undefined : tl('please_search_first')"
          show-summary
          size="small"
          :summary-method="summaryMethod"
          @mousedown="onTableDragStart"
        >
          <!-- 代理帳號：全站共用元件，只加複製 icon、不可點（#4342；抽屜是會員專用） -->
          <ElTableColumn
            fixed="left"
            :label="tl('agent_account')"
            min-width="140"
            prop="agent_username"
          >
            <template #default="{ row }">
              <AgentAccount :username="row.agent_username" />
            </template>
          </ElTableColumn>

          <template
            v-for="(group, gi) in visibleGroups"
            :key="group.groupKey ?? `single-${gi}`"
          >
            <ElTableColumn
              v-if="group.groupKey"
              align="center"
              :label="tl(group.groupKey)"
            >
              <ElTableColumn
                v-for="col in group.columns"
                :key="col.key"
                align="right"
                :min-width="col.minWidth"
                :prop="col.key"
              >
                <template #header>
                  <span class="inline-flex items-center gap-1">
                    {{ tl(col.labelKey) }}
                    <ElTooltip
                      v-if="col.tipKey"
                      :content="tl(col.tipKey)"
                      placement="top"
                    >
                      <InfoIcon class="size-3.5 cursor-help" />
                    </ElTooltip>
                  </span>
                </template>
                <template #default="{ row }">
                  <span
                    class="whitespace-nowrap"
                    :class="signClass(col, row[col.key])"
                  >
                    {{ fmt(col, row[col.key]) }}
                  </span>
                </template>
              </ElTableColumn>
            </ElTableColumn>

            <template v-else>
              <ElTableColumn
                v-for="col in group.columns"
                :key="col.key"
                align="right"
                :min-width="col.minWidth"
                :prop="col.key"
              >
                <template #header>
                  <span class="inline-flex items-center gap-1">
                    {{ tl(col.labelKey) }}
                    <ElTooltip
                      v-if="col.tipKey"
                      :content="tl(col.tipKey)"
                      placement="top"
                    >
                      <InfoIcon class="size-3.5 cursor-help" />
                    </ElTooltip>
                  </span>
                </template>
                <template #default="{ row }">
                  <span
                    class="whitespace-nowrap"
                    :class="signClass(col, row[col.key])"
                  >
                    {{ fmt(col, row[col.key]) }}
                  </span>
                </template>
              </ElTableColumn>
            </template>
          </template>
        </ElTable>
        <!-- 浮水印：壓在表格底部右側，淡色、不擋點擊與選取 -->
        <div
          aria-hidden="true"
          class="text-foreground pointer-events-none absolute bottom-10 right-6 z-10 select-none text-7xl font-black tracking-widest opacity-[0.07]"
        >
          SR28
        </div>
      </div>

      <TablePagination
        v-if="applied"
        :current-page="page"
        layout="total, sizes, prev, pager, next, jumper"
        :page-size="perPage"
        :page-sizes="PAGE_SIZES"
        :total="total"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <ElDialog
      v-model="columnSettingsVisible"
      :title="tl('column_settings')"
      width="560px"
    >
      <div class="mb-3 flex gap-2">
        <ElButton size="small" @click="setAllColumns(true)">
          {{ tl('select_all') }}
        </ElButton>
        <ElButton size="small" @click="setAllColumns(false)">
          {{ tl('deselect_all') }}
        </ElButton>
      </div>
      <div class="grid grid-cols-2 gap-x-4 gap-y-1">
        <!-- 代理商帳號是報表主體、左側凍結，固定顯示不可取消 -->
        <ElCheckbox :label="tl('agent_account')" disabled :model-value="true" />
        <ElCheckbox
          v-for="c in columnOptions"
          :key="c.id"
          :label="c.label"
          :model-value="isGroupVisible(c.id)"
          @update:model-value="(v) => toggleGroup(c.id, v === true)"
        />
      </div>
    </ElDialog>
  </Page>
</template>

<style scoped>
/* 拖曳平移游標提示：格子顯示可抓取游標，實際拖曳中由 useTableDragPan 切成 grabbing */
.drag-pan-table :deep(.el-table__body-wrapper .cell) {
  cursor: grab;
}
</style>
