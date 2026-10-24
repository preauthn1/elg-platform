<script lang="ts" setup>
import type {
  AgentDashboardAgentRow,
  AgentDashboardDetailGroup,
  AgentDashboardMetrics,
  AgentDashboardParams,
  AgentDashboardRetention,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getAgentDashboardAgentListApi,
  getAgentDashboardOverviewApi,
  getAgentDashboardRetentionApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { exportExcelWorkbook } from '#/utils/export-excel';
import { amountThousands, numberFormat } from '#/utils/format';
import { sixTypesQuickRangeDate } from '#/utils/quick-range';
import { currentTzLabel, currentTzOffset } from '#/utils/timezone-shift';

import AgentDashboardDetailDialog from './components/agent-dashboard-detail-dialog.vue';

/**
 * 代理數據看板（target/tickets#4180 第一階段）。
 *
 * 時間規則：`start_time` / `end_time` 直送使用者所選時區的牆鐘，時區以 `tz_offset` 表達。
 * 本頁端點是時區感知的，**不呼叫** `toApiTime()` / `fromApiTime()`。
 *
 * 推導值（各種率、人均、NGR、貢獻佔比）全部在前端算，所以切成本開關不重打 API。
 */
defineOptions({ name: 'ReportAgentDashboard' });

/** 表格列＝代理列，外加最後兩列加總（加總列的 agent_id 只當 row-key 用，取負數避開真代理） */
type AgentTableRow = AgentDashboardAgentRow & { is_summary?: boolean };

const PER_PAGE = 10;
/** 下級代理一次載入的上限：ElTable 的樹狀展開無法在節點內分頁 */
const CHILDREN_LIMIT = 100;

/** 整頁匯出的代理列上限（代理列表是逐層的，同一層不會有這麼多） */
const EXPORT_LIMIT = 5000;

type CostKey =
  | 'event'
  | 'fee'
  | 'rake'
  | 'rebate'
  | 'recycle'
  | 'rolling_chip'
  | 'sms'
  | 'vip'
  | 'withdrawal_fee';
type TileKind = 'count' | 'money' | 'percent';

interface Tile {
  /** 屬於哪個成本開關；關閉時灰化且不計入 NGR（回收金額關閉時不加回） */
  cost?: CostKey;
  /** 點擊後開哪一張明細；未指定＝不可下鑽（NGR 與貢獻佔比） */
  detailGroup?: AgentDashboardDetailGroup;
  formulaKey: string;
  key: string;
  kind: TileKind;
  labelKey: string;
  value: (m: AgentDashboardMetrics) => null | number;
}

const filters = reactive({
  agent_username: '',
  scope: 'line' as 'line' | 'self',
  time_range: sixTypesQuickRangeDate('today') as [string, string],
});

/** 九項成本全部可勾選是否計入 NGR，預設全計入；回收金額計入時為加回 */
const costOn = reactive<Record<CostKey, boolean>>({
  event: true,
  rebate: true,
  rolling_chip: true,
  vip: true,
  fee: true,
  withdrawal_fee: true,
  rake: true,
  sms: true,
  recycle: true,
});

/** 成本開關的顯示順序與標籤（勾選列與匯出的「已計入」文字共用） */
const costSwitches: Array<{ key: CostKey; labelKey: string }> = [
  { key: 'event', labelKey: 'ad_cost_event' },
  { key: 'rebate', labelKey: 'ad_cost_rebate' },
  { key: 'rolling_chip', labelKey: 'ad_cost_rolling_chip' },
  { key: 'vip', labelKey: 'ad_cost_vip' },
  { key: 'fee', labelKey: 'ad_cost_payment_fee' },
  { key: 'withdrawal_fee', labelKey: 'ad_cost_withdrawal_fee' },
  { key: 'rake', labelKey: 'ad_cost_vendor_fee' },
  { key: 'sms', labelKey: 'ad_cost_sms' },
  { key: 'recycle', labelKey: 'ad_cost_recycle' },
];

const showFormula = ref(false);
const loading = ref(false);
const target = ref<AgentDashboardMetrics | null>(null);
const scopeTotal = ref<AgentDashboardMetrics | null>(null);
const retention = ref<AgentDashboardRetention | null>(null);

const agentRows = ref<AgentDashboardAgentRow[]>([]);
const agentPageSummary = ref<AgentDashboardMetrics | null>(null);
const agentTotalSummary = ref<AgentDashboardMetrics | null>(null);
const agentTotal = ref(0);
const agentPage = ref(1);
const agentLoading = ref(false);

const num = (v?: null | number | string) => Number(v ?? 0) || 0;
const money = (v?: null | number | string) => amountThousands(v, 2);
const percent = (v: null | number) =>
  v === null ? '-' : `${(Math.round(v * 100) / 100).toFixed(2)}%`;
const ratio = (a: number, b: number) => (b === 0 ? null : (a / b) * 100);

/** 總成本＝勾選的八項成本合計 − 勾選的回收金額（回收是活動錢包收回來的錢，與 newsr11 總咬分同向） */
function totalCost(m: AgentDashboardMetrics): number {
  return (
    (costOn.event ? num(m.cost.event) : 0) +
    (costOn.rebate ? num(m.cost.rebate) : 0) +
    (costOn.rolling_chip ? num(m.cost.rolling_chip) : 0) +
    (costOn.vip ? num(m.cost.vip) : 0) +
    (costOn.fee ? num(m.cost.payment_fee) : 0) +
    (costOn.withdrawal_fee ? num(m.cost.withdrawal_fee) : 0) +
    (costOn.rake ? num(m.cost.vendor_fee) : 0) +
    (costOn.sms ? num(m.cost.sms) : 0) -
    (costOn.recycle ? num(m.cost.recycle) : 0)
  );
}

function ngr(m: AgentDashboardMetrics): number {
  return num(m.bet.ggr) - totalCost(m);
}

/** 貢獻佔比的分母＝登入者可視範疇（平台端登入時才等於全站） */
function contribution(m: AgentDashboardMetrics): null | number {
  const denominator = scopeTotal.value ? ngr(scopeTotal.value) : 0;

  return denominator === 0 ? null : (ngr(m) / denominator) * 100;
}

/**
 * 指標分組：主要照業務漏斗（註冊 → 首充 → 複充 → 充提 → 投注 → 成本）排，
 * 業務歸屬模糊時才用「來源表 ＋ 衍生關係」定案。
 *
 * 例：`真金投注人數` 需求原稿放在「註冊與首充」，但它與總投注／派獎／GGR 同源
 * （`summary_bet_records`），又是本組 `人均投注金額` 的分母，故歸入「投注與 GGR」。
 *
 * tile 全部是資料驅動，要調整分組或順序改這張表即可，template 不動。
 */
/**
 * 指標卡分組：**卡片是給人讀的（漏斗順序），不等於二階的明細分組**。
 *
 * 二階明細以「名單」（列出哪些會員）為單位，同一名單才同一張明細；而名單比卡片細——
 * 例如本卡的註冊／首充／複充是三份層層收斂的名單（註冊 ⊃ 首充 ⊃ 複充），充值與提現、
 * 六項成本也各自是不同名單。所以**明細對映做在 tile 層級**（每個 tile 的 detailGroup
 * 欄位），不是卡片層級；`ad_group_registration` / `ad_group_first_deposit` 等 key 保留給
 * 明細視窗當標題用。
 *
 * `真金投注人數` 歸「投注與 GGR」：與總投注／派獎／GGR 同名單，且是本組人均投注的分母。
 *
 * tile 全部資料驅動，調整分組或順序改這張表即可，template 不動。
 */
const GROUPS: { tiles: Tile[]; titleKey: string }[] = [
  {
    titleKey: 'ad_group_funnel',
    tiles: [
      {
        key: 'registration_count',
        detailGroup: 'registration',
        labelKey: 'ad_registration_count',
        formulaKey: 'ad_f_registration_count',
        kind: 'count',
        value: (m) => m.registration.count,
      },
      {
        key: 'first_deposit_rate',
        detailGroup: 'registration',
        labelKey: 'ad_first_deposit_rate',
        formulaKey: 'ad_f_first_deposit_rate',
        kind: 'percent',
        value: (m) => ratio(m.first_deposit.count, m.registration.count),
      },
      {
        key: 'first_deposit_count',
        detailGroup: 'first_deposit',
        labelKey: 'ad_first_deposit_count',
        formulaKey: 'ad_f_first_deposit_count',
        kind: 'count',
        value: (m) => m.first_deposit.count,
      },
      {
        key: 'first_deposit_amount',
        detailGroup: 'first_deposit',
        labelKey: 'ad_first_deposit_amount',
        formulaKey: 'ad_f_first_deposit_amount',
        kind: 'money',
        value: (m) => num(m.first_deposit.amount),
      },
      {
        key: 'repeat_deposit_count',
        detailGroup: 'repeat_deposit',
        labelKey: 'ad_repeat_deposit_count',
        formulaKey: 'ad_f_repeat_deposit_count',
        kind: 'count',
        value: (m) => m.repeat_deposit.count,
      },
      {
        key: 'repeat_deposit_rate',
        detailGroup: 'repeat_deposit',
        labelKey: 'ad_repeat_deposit_rate',
        formulaKey: 'ad_f_repeat_deposit_rate',
        kind: 'percent',
        value: (m) => ratio(m.repeat_deposit.count, m.first_deposit.count),
      },
      {
        key: 'repeat_deposit_amount',
        detailGroup: 'repeat_deposit',
        labelKey: 'ad_repeat_deposit_amount',
        formulaKey: 'ad_f_repeat_deposit_amount',
        kind: 'money',
        value: (m) => num(m.repeat_deposit.amount),
      },
    ],
  },
  {
    titleKey: 'ad_group_deposit_withdrawal',
    tiles: [
      {
        key: 'deposit_member_count',
        detailGroup: 'deposit',
        labelKey: 'ad_deposit_member_count',
        formulaKey: 'ad_f_deposit_member_count',
        kind: 'count',
        value: (m) => m.deposit.member_count,
      },
      {
        key: 'deposit_amount',
        detailGroup: 'deposit',
        labelKey: 'ad_deposit_amount',
        formulaKey: 'ad_f_deposit_amount',
        kind: 'money',
        value: (m) => num(m.deposit.amount),
      },
      {
        key: 'avg_deposit_amount',
        detailGroup: 'deposit',
        labelKey: 'ad_avg_deposit_amount',
        formulaKey: 'ad_f_avg_deposit_amount',
        kind: 'money',
        value: (m) =>
          m.deposit.member_count === 0
            ? null
            : num(m.deposit.amount) / m.deposit.member_count,
      },
      {
        key: 'withdrawal_member_count',
        detailGroup: 'withdrawal',
        labelKey: 'ad_withdrawal_member_count',
        formulaKey: 'ad_f_withdrawal_member_count',
        kind: 'count',
        value: (m) => m.withdrawal.member_count,
      },
      {
        key: 'withdrawal_amount',
        detailGroup: 'withdrawal',
        labelKey: 'ad_withdrawal_amount',
        formulaKey: 'ad_f_withdrawal_amount',
        kind: 'money',
        value: (m) => num(m.withdrawal.amount),
      },
      // 充提六類來源明細（member_hourly_statistics 的來源欄），明細與卡片同欄
      {
        key: 'third_party_deposit_amount',
        detailGroup: 'deposit_third_party',
        labelKey: 'ad_third_party_deposit_amount',
        formulaKey: 'ad_f_third_party_deposit_amount',
        kind: 'money',
        value: (m) => num(m.deposit_sources.third_party),
      },
      {
        key: 'auto_deposit_amount',
        detailGroup: 'deposit_auto',
        labelKey: 'ad_auto_deposit_amount',
        formulaKey: 'ad_f_auto_deposit_amount',
        kind: 'money',
        value: (m) => num(m.deposit_sources.auto),
      },
      {
        key: 'admin_deposit_amount',
        detailGroup: 'deposit_admin',
        labelKey: 'ad_admin_deposit_amount',
        formulaKey: 'ad_f_admin_deposit_amount',
        kind: 'money',
        value: (m) => num(m.deposit_sources.admin),
      },
      {
        key: 'third_party_withdrawal_amount',
        detailGroup: 'withdrawal_third_party',
        labelKey: 'ad_third_party_withdrawal_amount',
        formulaKey: 'ad_f_third_party_withdrawal_amount',
        kind: 'money',
        value: (m) => num(m.withdrawal_sources.third_party),
      },
      {
        key: 'auto_withdrawal_amount',
        detailGroup: 'withdrawal_auto',
        labelKey: 'ad_auto_withdrawal_amount',
        formulaKey: 'ad_f_auto_withdrawal_amount',
        kind: 'money',
        value: (m) => num(m.withdrawal_sources.auto),
      },
      {
        key: 'admin_withdrawal_amount',
        detailGroup: 'withdrawal_admin',
        labelKey: 'ad_admin_withdrawal_amount',
        formulaKey: 'ad_f_admin_withdrawal_amount',
        kind: 'money',
        value: (m) => num(m.withdrawal_sources.admin),
      },
    ],
  },
  {
    titleKey: 'ad_group_bet_ggr',
    tiles: [
      {
        key: 'real_bet_member_count',
        detailGroup: 'bet',
        labelKey: 'ad_real_bet_member_count',
        formulaKey: 'ad_f_real_bet_member_count',
        kind: 'count',
        value: (m) => m.real_bet_member_count,
      },
      {
        key: 'bet_amount',
        detailGroup: 'bet',
        labelKey: 'ad_bet_amount',
        formulaKey: 'ad_f_bet_amount',
        kind: 'money',
        value: (m) => num(m.bet.amount),
      },
      {
        key: 'payout_amount',
        detailGroup: 'bet',
        labelKey: 'ad_payout_amount',
        formulaKey: 'ad_f_payout_amount',
        kind: 'money',
        value: (m) => num(m.bet.payout),
      },
      {
        key: 'ggr',
        detailGroup: 'bet',
        labelKey: 'ggr',
        formulaKey: 'ad_f_ggr',
        kind: 'money',
        value: (m) => num(m.bet.ggr),
      },
      {
        key: 'avg_bet_amount',
        detailGroup: 'bet',
        labelKey: 'ad_avg_bet_amount',
        formulaKey: 'ad_f_avg_bet_amount',
        kind: 'money',
        value: (m) =>
          m.real_bet_member_count === 0
            ? null
            : num(m.bet.amount) / m.real_bet_member_count,
      },
    ],
  },
  {
    titleKey: 'ad_group_cost_ngr',
    tiles: [
      {
        key: 'cost_event',
        detailGroup: 'cost_event',
        labelKey: 'ad_cost_event',
        formulaKey: 'ad_f_cost_event',
        kind: 'money',
        cost: 'event',
        value: (m) => num(m.cost.event),
      },
      {
        // 明細只列發給會員的三層代／無限代分潤；代理本人份無會員可歸戶，明細加總會小於卡片
        key: 'cost_rebate',
        detailGroup: 'cost_rebate',
        labelKey: 'ad_cost_rebate',
        formulaKey: 'ad_f_cost_rebate',
        kind: 'money',
        cost: 'rebate',
        value: (m) => num(m.cost.rebate),
      },
      {
        key: 'cost_rolling_chip',
        detailGroup: 'cost_rolling_chip',
        labelKey: 'ad_cost_rolling_chip',
        formulaKey: 'ad_f_cost_rolling_chip',
        kind: 'money',
        cost: 'rolling_chip',
        value: (m) => num(m.cost.rolling_chip),
      },
      {
        key: 'cost_vip',
        detailGroup: 'cost_vip',
        labelKey: 'ad_cost_vip',
        formulaKey: 'ad_f_cost_vip',
        kind: 'money',
        cost: 'vip',
        value: (m) => num(m.cost.vip),
      },
      {
        key: 'cost_payment_fee',
        detailGroup: 'cost_payment_fee',
        labelKey: 'ad_cost_payment_fee',
        formulaKey: 'ad_f_cost_payment_fee',
        kind: 'money',
        cost: 'fee',
        value: (m) => num(m.cost.payment_fee),
      },
      {
        key: 'cost_withdrawal_fee',
        detailGroup: 'cost_withdrawal_fee',
        labelKey: 'ad_cost_withdrawal_fee',
        formulaKey: 'ad_f_cost_withdrawal_fee',
        kind: 'money',
        cost: 'withdrawal_fee',
        value: (m) => num(m.cost.withdrawal_fee),
      },
      {
        key: 'cost_vendor_fee',
        detailGroup: 'cost_vendor_fee',
        labelKey: 'ad_cost_vendor_fee',
        formulaKey: 'ad_f_cost_vendor_fee',
        kind: 'money',
        cost: 'rake',
        value: (m) => num(m.cost.vendor_fee),
      },
      {
        key: 'cost_sms',
        detailGroup: 'cost_sms',
        labelKey: 'ad_cost_sms',
        formulaKey: 'ad_f_cost_sms',
        kind: 'money',
        cost: 'sms',
        value: (m) => num(m.cost.sms),
      },
      {
        key: 'cost_recycle',
        detailGroup: 'cost_recycle',
        labelKey: 'ad_cost_recycle',
        formulaKey: 'ad_f_cost_recycle',
        kind: 'money',
        cost: 'recycle',
        value: (m) => num(m.cost.recycle),
      },
      {
        key: 'ngr',
        labelKey: 'ad_ngr',
        formulaKey: 'ad_f_ngr',
        kind: 'money',
        value: (m) => ngr(m),
      },
      {
        key: 'contribution_ratio',
        labelKey: 'ad_contribution_ratio',
        formulaKey: 'ad_f_contribution_ratio',
        kind: 'percent',
        value: (m) => contribution(m),
      },
    ],
  },
];

/** 留存三列（D1 / D7 / D30 由 matrix 提供） */
const RETENTION_ROWS = [
  { key: 'login', labelKey: 'ad_retention_login' },
  { key: 'bet', labelKey: 'ad_retention_bet' },
  { key: 'deposit', labelKey: 'ad_retention_deposit' },
] as const;

const DAYS = ['d1', 'd7', 'd30'] as const;

/** 指標定義表：直接由指標卡的 metadata 展開，不另維護一份文案 */
const definitionRows = computed(() => [
  ...GROUPS.flatMap((group) =>
    group.tiles.map((tile) => ({
      formula: tl(tile.formulaKey),
      group: tl(group.titleKey),
      key: `${group.titleKey}_${tile.key}`,
      metric: tl(tile.labelKey),
    })),
  ),
  ...RETENTION_ROWS.map((row) => ({
    formula: tl(`ad_f_retention_${row.key}`),
    group: tl('ad_group_retention'),
    key: `retention_${row.key}`,
    metric: tl(row.labelKey),
  })),
]);

function tileText(tile: Tile, m: AgentDashboardMetrics): string {
  const value = tile.value(m);
  if (value === null) return '-';
  if (tile.kind === 'percent') return percent(value);
  return tile.kind === 'money' ? money(value) : numberFormat(value, 0);
}

function baseParams(): AgentDashboardParams {
  const [start, end] = filters.time_range ?? [];
  const params: AgentDashboardParams = {
    // 篩選只到日：起日補 00:00:00、迄日補 23:59:59（皆為所選時區的牆鐘）
    end_time: `${end} 23:59:59`,
    scope: filters.scope,
    start_time: `${start} 00:00:00`,
    tz_offset: currentTzOffset(),
  };
  if (filters.agent_username) params.agent_username = filters.agent_username;

  return params;
}

function guard(): boolean {
  const [start, end] = filters.time_range ?? [];
  if (!start || !end) {
    ElMessage.warning(tl('required_column'));

    return false;
  }

  return true;
}

/** apis 成功碼為 200；失敗時清空該區塊並顯示後端訊息 */
function ok(res: { code: number | string; message?: string }): boolean {
  if (Number(res.code) === 200) return true;
  if (res.message) ElMessage.error(res.message);

  return false;
}

async function loadOverview() {
  const res = await getAgentDashboardOverviewApi(baseParams());
  if (!ok(res)) {
    target.value = null;
    scopeTotal.value = null;

    return;
  }
  target.value = res.data?.target ?? null;
  scopeTotal.value = res.data?.scope_total ?? null;
}

async function loadRetention() {
  const res = await getAgentDashboardRetentionApi(baseParams());
  retention.value = ok(res) ? (res.data ?? null) : null;
}

async function loadAgentList() {
  agentLoading.value = true;
  try {
    const res = await getAgentDashboardAgentListApi({
      ...baseParams(),
      page: agentPage.value,
      per_page: PER_PAGE,
    });
    if (!ok(res)) {
      agentRows.value = [];

      return;
    }
    agentRows.value = res.data?.list ?? [];
    agentPageSummary.value = res.data?.page_summary ?? null;
    agentTotalSummary.value = res.data?.total_summary ?? null;
    agentTotal.value = res.data?.pagination.total ?? 0;
  } finally {
    agentLoading.value = false;
  }
}

/** 樹狀展開：點 ▸ 才向後端要該代理的下一層 */
async function loadChildren(
  row: AgentTableRow,
  _treeNode: unknown,
  resolve: (rows: AgentTableRow[]) => void,
) {
  const res = await getAgentDashboardAgentListApi({
    ...baseParams(),
    page: 1,
    parent_agent_id: row.agent_id,
    per_page: CHILDREN_LIMIT,
  });
  if (!ok(res)) {
    resolve([]);

    return;
  }
  const list = res.data?.list ?? [];
  if ((res.data?.pagination.total ?? 0) > list.length) {
    ElMessage.warning(tl('ad_children_capped'));
  }
  resolve(list);
}

async function search() {
  if (!guard()) return;
  loading.value = true;
  agentPage.value = 1;
  try {
    await Promise.all([loadOverview(), loadRetention(), loadAgentList()]);
  } finally {
    loading.value = false;
  }
}

function handlePageChange(page: number) {
  agentPage.value = page;
  loadAgentList();
}

function handleReset() {
  filters.agent_username = '';
  filters.scope = 'line';
  filters.time_range = sixTypesQuickRangeDate('today');
}

/**
 * 加總併進代理明細表格的最後兩列（另開一張表欄寬各自算，跟上面對不齊）。
 *
 * 兩份加總都只涵蓋「當前展開的這一層」——同層皆兄弟節點，不會父子重複計算；
 * 整條線的總計看上方指標總覽。故只有一頁時兩列數字相同（後端亦如此）。
 */
const agentTableRows = computed<AgentTableRow[]>(() => {
  if (agentRows.value.length === 0) return [];

  const rows: AgentTableRow[] = [...agentRows.value];
  const summaries: [string, AgentDashboardMetrics | null][] = [
    ['ad_page_summary', agentPageSummary.value],
    ['ad_total_summary', agentTotalSummary.value],
  ];

  for (const [index, [labelKey, metrics]] of summaries.entries()) {
    if (!metrics) continue;
    rows.push({
      agent_id: -1 - index,
      has_children: false,
      hierarchy: 0,
      is_summary: true,
      metrics,
      nickname: null,
      parent_username: null,
      username: tl(labelKey),
    });
  }

  return rows;
});

/** 留存矩陣的列：把數值在 script 內算好，template 不再索引 matrix */
const retentionRows = computed(() => {
  const data = retention.value;
  if (!data) return [];

  return RETENTION_ROWS.map((row) => ({
    cells: DAYS.map((day) => {
      const count = data.matrix[row.key][day];

      return {
        count,
        denominator: data.denominator,
        rate: ratio(count, data.denominator),
      };
    }),
    key: row.key,
    label: tl(row.labelKey),
  }));
});

/** 指標定義表上方的通用規則（口徑說明） */
const NOTE_KEYS = [
  'ad_note_scope',
  'ad_note_people',
  'ad_note_money',
  'ad_note_retention',
  'ad_note_timezone',
  'ad_note_detail',
];

const detailDialog = ref<InstanceType<typeof AgentDashboardDetailDialog>>();

/** 點指標格子開明細：同組的格子開同一張表；未設 detailGroup 的格子不可點 */
function openTileDetail(tile: Tile) {
  if (!tile.detailGroup || !guard()) return;

  detailDialog.value?.open({
    group: tile.detailGroup,
    params: baseParams(),
    title: tl(tile.labelKey),
  });
}

/** 留存矩陣：點任一格帶該格的 D 日 */
function openRetentionDetail(rowKey: string, day: (typeof DAYS)[number]) {
  if (!guard()) return;

  detailDialog.value?.open({
    group: 'retention',
    params: baseParams(),
    retentionDay: Number(day.replace('d', '')),
    title: `${tl(`ad_retention_${rowKey}`)} ${day.toUpperCase()}`,
  });
}

/**
 * 代理明細的匯出欄位（對齊畫面上的表格欄位）。
 *
 * 前三欄取自代理本身，其餘取自 metrics —— 加總列沒有帳號/上級/層級，只共用後段，
 * 所以拆成兩段而不是一張 12 欄的表。
 */
const AGENT_METRIC_COLUMNS: {
  kind: TileKind;
  titleKey: string;
  value: (m: AgentDashboardMetrics) => null | number;
}[] = [
  {
    titleKey: 'ad_registration_count',
    kind: 'count',
    value: (m) => m.registration.count,
  },
  {
    titleKey: 'ad_first_deposit_count',
    kind: 'count',
    value: (m) => m.first_deposit.count,
  },
  {
    titleKey: 'ad_first_deposit_rate',
    kind: 'percent',
    value: (m) => ratio(m.first_deposit.count, m.registration.count),
  },
  {
    titleKey: 'ad_deposit_amount',
    kind: 'money',
    value: (m) => num(m.deposit.amount),
  },
  { titleKey: 'ad_bet_amount', kind: 'money', value: (m) => num(m.bet.amount) },
  { titleKey: 'ggr', kind: 'money', value: (m) => num(m.bet.ggr) },
  { titleKey: 'ad_col_total_cost', kind: 'money', value: (m) => totalCost(m) },
  { titleKey: 'ad_ngr', kind: 'money', value: (m) => ngr(m) },
  {
    titleKey: 'ad_contribution_ratio',
    kind: 'percent',
    value: (m) => contribution(m),
  },
];

/** 匯出儲存格：金額與人數給數字（Excel 才加得起來），率給帶 % 的字串 */
function cellValue(kind: TileKind, value: null | number): number | string {
  if (value === null) return '-';
  if (kind === 'percent') return percent(value);

  return kind === 'money' ? Math.round(value * 100) / 100 : value;
}

function metricCells(m: AgentDashboardMetrics): (number | string)[] {
  return AGENT_METRIC_COLUMNS.map((column) =>
    cellValue(column.kind, column.value(m)),
  );
}

const exporting = ref(false);

/**
 * 整頁匯出：四個分頁（查詢條件 / 指標總覽 / 留存矩陣 / 代理明細）。
 *
 * 各分頁自己一組表頭與欄寬，所以不必為了塞進同一張表把最寬的那段擺最上面。
 * 成本開關會影響總成本 / NGR / 貢獻佔比，故把當下計入哪幾項寫進查詢條件分頁。
 */
async function handleExport() {
  if (!guard()) return;
  const metrics = target.value;
  if (!metrics) return;
  exporting.value = true;
  try {
    const params = { ...baseParams(), page: 1, per_page: EXPORT_LIMIT };
    const res = await getAgentDashboardAgentListApi(params);
    if (!ok(res)) return;
    const list = res.data?.list ?? [];
    if ((res.data?.pagination.total ?? 0) > list.length) {
      ElMessage.warning(tl('ad_export_capped'));
    }

    const [start, end] = filters.time_range ?? [];
    const dateRange: [string, string] = [start, end];

    await exportExcelWorkbook({
      audit: { conditions: params, recordCount: list.length },
      sheets: [
        {
          name: tl('search_criteria'),
          title: tl('search_criteria'),
          columns: [tl('entry'), tl('content')],
          rows: [
            [tl('report_between'), `${start} ~ ${end}`],
            [tl('timezone'), currentTzLabel()],
            ...conditionRows(),
            [],
            [tl('ad_note_title')],
            ...NOTE_KEYS.map((key) => [tl(key)]),
          ],
        },
        {
          name: tl('ad_export_overview'),
          title: tl('ad_export_overview'),
          dateRange,
          columns: [
            tl('ad_group'),
            tl('ad_metric'),
            tl('value'),
            tl('ad_formula'),
          ],
          rows: GROUPS.flatMap((group) =>
            group.tiles.map((tile) => [
              tl(group.titleKey),
              tl(tile.labelKey),
              cellValue(tile.kind, tile.value(metrics)),
              tl(tile.formulaKey),
            ]),
          ),
        },
        {
          name: tl('ad_group_retention'),
          title: `${tl('ad_group_retention')}（${tl('ad_retention_denominator')}：${retention.value?.denominator ?? 0}）`,
          dateRange,
          columns: [
            tl('ad_retention_behavior'),
            ...DAYS.flatMap((day) => [
              `${day.toUpperCase()} ${tl('ad_export_retention_rate')}`,
              `${day.toUpperCase()} ${tl('ad_export_retention_count')}`,
            ]),
          ],
          rows: retentionRows.value.map((row) => [
            row.label,
            ...row.cells.flatMap((cell) => [percent(cell.rate), cell.count]),
          ]),
        },
        {
          name: tl('ad_agent_detail'),
          title: tl('ad_agent_detail'),
          dateRange,
          columns: [
            tl('ad_col_agent'),
            tl('ad_col_parent'),
            tl('level'),
            ...AGENT_METRIC_COLUMNS.map((column) => tl(column.titleKey)),
          ],
          rows: [
            ...list.map((row) => [
              row.nickname
                ? `${row.username}（${row.nickname}）`
                : row.username,
              row.parent_username ?? '',
              row.hierarchy,
              ...metricCells(row.metrics),
            ]),
            // 加總取這次匯出請求回的 total_summary；畫面上的本頁加總只涵蓋當前 10 列，不適用
            ...(res.data?.total_summary
              ? [
                  [
                    tl('ad_total_summary'),
                    '',
                    '',
                    ...metricCells(res.data.total_summary),
                  ],
                ]
              : []),
          ],
        },
      ],
      title: tl('menu_report_agent_dashboard'),
      tzOffset: currentTzOffset(),
    });
  } finally {
    exporting.value = false;
  }
}

/** 匯出檔的查詢條件列：拿到檔案的人要看得出這份數字是用什麼條件跑出來的 */
function conditionRows(): (number | string)[][] {
  return [
    [tl('label_agent_account'), filters.agent_username || tl('all')],
    [
      tl('ad_stat_scope'),
      filters.scope === 'line' ? tl('agent_line') : tl('ad_scope_self'),
    ],
    [tl('ad_export_cost_included'), costIncludedText()],
  ];
}

/** 目前計入 NGR 的成本；全關就標「無」 */
function costIncludedText(): string {
  const labels = costSwitches
    .filter((s) => costOn[s.key])
    .map((s) => tl(s.labelKey));

  return labels.length > 0 ? labels.join('、') : tl('none');
}

const treeHint = computed(() =>
  filters.scope === 'line' ? tl('ad_tree_hint_line') : tl('ad_tree_hint_self'),
);

onMounted(search);
</script>

<template>
  <Page
    :title="`${tl('menu_statistics')} > ${tl('menu_report_agent_dashboard')}`"
  >
    <!-- 查詢條件 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <div class="flex flex-wrap items-center gap-3">
          <span class="font-medium">{{ tl('search') }}</span>
          <span class="text-muted-foreground text-xs">
            {{ tl('ad_amount_unit_hint') }}
          </span>
          <span class="text-muted-foreground text-xs">
            {{ tl('ad_timezone_hint') }}（{{ currentTzLabel() }}）
          </span>
          <ElCheckbox v-model="showFormula" class="ml-auto">
            {{ tl('ad_show_formula') }}
          </ElCheckbox>
        </div>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('label_agent_account')">
            <ElInput
              v-model="filters.agent_username"
              :placeholder="tl('please_enter')"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('ad_stat_scope')">
            <ElSelect v-model="filters.scope">
              <ElOption :label="tl('agent_line')" value="line" />
              <ElOption :label="tl('ad_scope_self')" value="self" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('between')" required>
            <DateRangeFields v-model="filters.time_range" />
          </ElFormItem>
        </div>
        <div class="flex justify-end gap-2">
          <ElButton @click="handleReset">{{ tl('reset') }}</ElButton>
          <ElButton :loading="exporting" type="success" @click="handleExport">
            {{ tl('excel') }}
          </ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <!-- 指標卡 ①〜⑤ -->
    <ElCard
      v-for="(group, index) in GROUPS"
      :key="group.titleKey"
      v-loading="loading"
      class="mb-3"
      shadow="never"
    >
      <template #header>
        <div class="flex flex-wrap items-center gap-3">
          <span
            class="bg-primary text-primary-foreground grid h-5 w-5 place-items-center rounded text-xs"
          >
            {{ index + 1 }}
          </span>
          <span class="font-medium">{{ tl(group.titleKey) }}</span>
          <span
            v-if="group.titleKey === 'ad_group_cost_ngr'"
            class="flex flex-wrap items-center gap-3"
          >
            <span class="text-muted-foreground text-xs">
              {{ tl('ad_cost_switch_hint') }}
            </span>
            <ElCheckbox
              v-for="s in costSwitches"
              :key="s.key"
              v-model="costOn[s.key]"
            >
              {{ tl(s.labelKey) }}
            </ElCheckbox>
          </span>
        </div>
      </template>
      <div
        v-if="target"
        class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4"
      >
        <button
          v-for="tile in group.tiles"
          :key="tile.key"
          class="rounded border px-3 py-2 text-left"
          :class="[
            tile.cost && !costOn[tile.cost]
              ? 'opacity-40'
              : 'bg-card border-border',
            tile.detailGroup
              ? 'hover:border-primary cursor-pointer'
              : 'cursor-default',
          ]"
          type="button"
          @click="openTileDetail(tile)"
        >
          <div class="text-muted-foreground text-xs">
            {{ tl(tile.labelKey) }}
          </div>
          <!-- 算法緊接在標題下，不放數字下面（數字才是視線落點） -->
          <div
            v-if="showFormula"
            class="text-muted-foreground mt-0.5 text-[11px] leading-4"
          >
            {{ tl(tile.formulaKey) }}
          </div>
          <div class="mt-1 text-lg font-semibold">
            {{ tileText(tile, target) }}
          </div>
        </button>
      </div>
      <div v-else class="text-muted-foreground py-4 text-center">
        {{ tl('no_data') }}
      </div>
    </ElCard>

    <!-- 指標卡 ⑥ 留存矩陣 -->
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <template #header>
        <div class="flex flex-wrap items-center gap-3">
          <span
            class="bg-primary text-primary-foreground grid h-5 w-5 place-items-center rounded text-xs"
          >
            {{ GROUPS.length + 1 }}
          </span>
          <span class="font-medium">{{ tl('ad_group_retention') }}</span>
          <span class="text-muted-foreground text-xs">
            {{ tl('ad_retention_hint') }}
          </span>
        </div>
      </template>
      <div v-if="retention">
        <div class="text-muted-foreground mb-2 text-xs">
          {{ tl('ad_retention_denominator') }}：
          {{ numberFormat(retention.denominator, 0) }}
        </div>
        <ElTable :data="retentionRows" border size="small">
          <ElTableColumn
            :label="tl('ad_retention_behavior')"
            prop="label"
            width="160"
          />
          <ElTableColumn
            v-for="(day, index) in DAYS"
            :key="day"
            :label="day.toUpperCase()"
            align="right"
          >
            <template #default="{ row }">
              <!-- 留存率為主、人數/分母為輔；點任一格開該 D 日的明細 -->
              <button
                class="hover:text-primary w-full cursor-pointer text-right"
                type="button"
                @click="openRetentionDetail(row.key, day)"
              >
                <div class="font-medium">
                  {{ percent(row.cells[index].rate) }}
                </div>
                <div class="text-muted-foreground text-xs">
                  {{ numberFormat(row.cells[index].count, 0) }} /
                  {{ numberFormat(row.cells[index].denominator, 0) }}
                </div>
              </button>
            </template>
          </ElTableColumn>
        </ElTable>
      </div>
      <div v-else class="text-muted-foreground py-4 text-center">
        {{ tl('no_data') }}
      </div>
    </ElCard>

    <!-- 代理明細 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <div class="flex flex-wrap items-center gap-3">
          <span class="font-medium">{{ tl('ad_agent_detail') }}</span>
          <span class="text-muted-foreground text-xs">{{ treeHint }}</span>
        </div>
      </template>
      <ElTable
        v-loading="agentLoading"
        :data="agentTableRows"
        :row-class-name="({ row }) => (row.is_summary ? 'is-summary-row' : '')"
        :tree-props="{ children: 'children', hasChildren: 'has_children' }"
        border
        lazy
        row-key="agent_id"
        size="small"
        :load="loadChildren"
      >
        <ElTableColumn :label="tl('ad_col_agent')" min-width="180">
          <template #default="{ row }">
            <div><AgentAccount :username="row.username" /></div>
            <div v-if="!row.is_summary" class="text-muted-foreground text-xs">
              {{ row.nickname }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_col_parent')"
          prop="parent_username"
          width="140"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('level')" width="90">
          <template #default="{ row }">
            <ElTag v-if="!row.is_summary" size="small">
              {{ row.hierarchy }}
            </ElTag>
            <span v-else>—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_registration_count')"
          align="right"
          width="110"
        >
          <template #default="{ row }">
            {{ numberFormat(row.metrics.registration.count, 0) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_first_deposit_count')"
          align="right"
          width="110"
        >
          <template #default="{ row }">
            {{ numberFormat(row.metrics.first_deposit.count, 0) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_first_deposit_rate')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            {{
              percent(
                ratio(
                  row.metrics.first_deposit.count,
                  row.metrics.registration.count,
                ),
              )
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_deposit_amount')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ money(row.metrics.deposit.amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_bet_amount')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ money(row.metrics.bet.amount) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ggr')" align="right" min-width="120">
          <template #default="{ row }">
            {{ money(row.metrics.bet.ggr) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_col_total_cost')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ money(totalCost(row.metrics)) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('ad_ngr')" align="right" min-width="120">
          <template #default="{ row }">
            {{ money(ngr(row.metrics)) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('ad_contribution_ratio')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            {{ percent(contribution(row.metrics)) }}
          </template>
        </ElTableColumn>
      </ElTable>

      <TablePagination
        :current-page="agentPage"
        :page-size="PER_PAGE"
        :total="agentTotal"
        @current-change="handlePageChange"
      />
    </ElCard>

    <AgentDashboardDetailDialog ref="detailDialog" />

    <!-- 指標定義 -->
    <ElCard shadow="never">
      <template #header>
        <div class="flex flex-wrap items-center gap-3">
          <span class="font-medium">{{ tl('ad_definition_title') }}</span>
          <span class="text-muted-foreground text-xs">
            {{ tl('ad_definition_hint') }}
          </span>
        </div>
      </template>
      <div class="mb-3">
        <div class="mb-1 text-sm font-medium">{{ tl('ad_note_title') }}</div>
        <ul
          class="text-muted-foreground list-disc space-y-1 pl-5 text-xs leading-5"
        >
          <li v-for="key in NOTE_KEYS" :key="key">{{ tl(key) }}</li>
        </ul>
      </div>
      <ElTable :data="definitionRows" border class="w-auto" size="small">
        <ElTableColumn :label="tl('ad_group')" prop="group" width="110" />
        <ElTableColumn :label="tl('ad_metric')" prop="metric" width="190" />
        <ElTableColumn :label="tl('ad_formula')" prop="formula" width="560" />
      </ElTable>
    </ElCard>
  </Page>
</template>

<style scoped>
/* 加總列：跟資料列同一張表，靠底色與粗體區分 */
:deep(.el-table .is-summary-row > td.el-table__cell) {
  font-weight: 600;
  background: var(--el-fill-color-light);
}
</style>
