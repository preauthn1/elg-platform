<script lang="ts" setup>
import type {
  MemberGroupOption,
  MemberListParams,
  MemberListSummary,
  MemberRow,
} from '#/api';
import type { DragPanTableRef } from '#/composables/use-table-drag-pan';

import { computed, onActivated, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { createIconifyIcon } from '@vben/icons';

import { createReusableTemplate } from '@vueuse/core';
import {
  ElButton,
  ElCard,
  ElCheckbox,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  editMemberStatusApi,
  getCustomizedSettingApi,
  getMemberGroupDropdownApi,
  getMemberListApi,
  getTransactionMaintenanceApi,
} from '#/api';
import { setPendingChatUsername } from '#/api/cs';
import AgentAccount from '#/components/agent-account.vue';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useTableDragPan } from '#/composables/use-table-drag-pan';
import { tl } from '#/locales';
import { hasMenuUrl } from '#/router/agent-menu';
import { exportExcel } from '#/utils/export-excel';
import { amountPlain, amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import AccountTypeDialog from './components/account-type-dialog.vue';
import AdjustmentDialog from './components/adjustment-dialog.vue';
import BankAccountDialog from './components/bank-account-dialog.vue';
import ColumnSettingsDialog from './components/column-settings-dialog.vue';
import DomainDialog from './components/domain-dialog.vue';
import FlowLimitDialog from './components/flow-limit-dialog.vue';
import LoginLogDialog from './components/login-log-dialog.vue';
import MemberAccountLink from './components/member-account-link.vue';
import {
  ensureMemberDrawerAccess,
  memberChangedSeq,
  memberDrawerAllowed,
} from './components/member-detail-drawer-state';
import PasswordDialog from './components/password-dialog.vue';
import StatusLogDialog from './components/status-log-dialog.vue';
import TransactionDialog from './components/transaction-dialog.vue';
import WalletTransactionDialog from './components/wallet-transaction-dialog.vue';

defineOptions({ name: 'MemberIndex' });

const route = useRoute();
const router = useRouter();
const { hasAccessByCodes } = useAccess();

/** 單頁筆數選項；預設 10（工單4306） */
const PAGE_SIZES = [10, 20, 50, 100];
const perPage = ref(10);

/** 權限旗標 */
const canToggleStatus = hasAccessByCodes(['member_disabled_permissiom']);
const canIpInquire = hasAccessByCodes(['ip_inquire_permissiom']);
// 工單4242：帳號管理「聊天」欄（與客服群組的聊天頁權限各自獨立）
const canMemberChat = hasAccessByCodes(['member_chat']);
const ChatIcon = createIconifyIcon('lucide:message-circle');
const canShowDomain = hasAccessByCodes(['show_proxy_domain']);
// 工單4334：入口只要有開分/洗分/禮碼/補點其中一項權限就要出現，不能只看開分/洗分
const canTransaction = hasAccessByCodes([
  'agent_backend_deposit',
  'agent_backend_withdrawal',
  'agent_backend_send_rolling_chip',
  'member_make_up_point',
  // 工單4233：只有積分發放／扣除權限的代理也要能開啟開洗分彈窗
  'member_add_score',
  'member_deduct_score',
]);
const canModifyPassword = [REDACTED]'password_modify_permission']);
const canModifyPaymentPassword = [REDACTED]
  'payment_password_permission',
]);
const canRevealPhone = hasAccessByCodes(['reveal_phone_number']);
const canExport = hasAccessByCodes(['member_export_permission']);
const canFlowLimit = hasAccessByCodes(['member_list_flow_limit']);
const canAdjustment = hasAccessByCodes(['member_adjustment']);
const canModifyType = hasAccessByCodes(['member_modify_type']);

/** 功能下拉的報表捷徑（有該報表選單權限才顯示） */
const REPORT_SHORTCUTS = [
  { titleKey: 'menu_report_sr02t', url: 'report/sr02t' },
  { titleKey: 'menu_report_ssr07', url: 'report/ssr07' },
  { titleKey: 'menu_report_sr12', url: 'report/sr12' },
  { titleKey: 'menu_report_sr14', url: 'report/sr14' },
].filter((r) => hasMenuUrl(r.url));

/** 開啟報表頁並帶入會員帳號查詢 */
function goReport(url: string, row: MemberRow) {
  router.push({ path: `/${url}`, query: { member_username: row.username } });
}

/** 代理自訂設定（轉帳視窗用 + VIP 開關 3531） */
const settingsMap = ref<Map<number, any>>(new Map());
/** VIP 名稱欄位是否啟用（agent setting 3531） */
const vipEnable = computed(() => Number(settingsMap.value.get(3531)) === 1);
/** 多錢包開關（agent setting 6008，開啟時顯示遊戲錢包操作） */
const multipleWalletSwitch = computed(
  () => Number(settingsMap.value.get(6008)) === 1,
);
/** 交易維護中（true 時隱藏轉帳） */
const hasMaintains = ref(false);
/** 能否開啟會員銀行卡明細（target/tickets#4486，以後端 bank_account_visible 為準，預設不可） */
const bankAccountVisible = ref(false);

/** 各 dialog refs */
const statusLogRef = ref<InstanceType<typeof StatusLogDialog>>();
const loginLogRef = ref<InstanceType<typeof LoginLogDialog>>();
const domainRef = ref<InstanceType<typeof DomainDialog>>();
const passwordRef = ref<InstanceType<typeof PasswordDialog>>();
const transactionRef = ref<InstanceType<typeof TransactionDialog>>();
const walletTransactionRef =
  ref<InstanceType<typeof WalletTransactionDialog>>();
const adjustmentRef = ref<InstanceType<typeof AdjustmentDialog>>();
const flowLimitRef = ref<InstanceType<typeof FlowLimitDialog>>();
const bankAccountRef = ref<InstanceType<typeof BankAccountDialog>>();
const accountTypeRef = ref<InstanceType<typeof AccountTypeDialog>>();
const columnSettingsRef = ref<InstanceType<typeof ColumnSettingsDialog>>();

const filters = reactive({
  account_no: '',
  agent_username: '',
  certification: '',
  created_range: [] as string[],
  downline_query: false,
  first_name: '',
  fuzzy_query: false,
  login_range: [] as string[],
  member_group_id: '' as number | string,
  member_invitation_code: '',
  mobile: '',
  mobile_authentication: '',
  register_invitation_code: '',
  register_ip: '',
  status: '',
  username: '',
});

watch(
  () => filters.agent_username,
  (v) => {
    if (!v) filters.downline_query = false;
  },
);

/**
 * 工單4306：表格一欄一欄位，欄位可勾選顯示、可拖曳調整順序（「欄位設定」）。
 * 勾選與順序都存 localStorage（各自一個 key），不存後端。
 *
 * - COLUMN_SPECS 是欄位的唯一定義（順序＝預設順序）；表格依 displayColumns 以 v-for
 *   產生欄位，儲存格內容在模板裡依 key 分支。
 * - locked 欄位（帳號、功能）不可取消、不可移動，固定在最左（凍結）與最右（凍結）；
 *   「功能」欄只給不能用會員詳情抽屜的身份（4438 第 7 項），能用抽屜的整欄不出現。
 * - feature 旗標關閉的欄位（VIP 名稱、聊天）整欄不出現，連欄位設定裡也不列。
 */
interface ColumnSpec {
  align?: 'center' | 'right';
  fixed?: 'left' | 'right';
  key: string;
  label: () => string;
  locked?: boolean;
  minWidth?: number;
  tooltip?: boolean;
  width?: number;
}

const COLUMN_SPECS: ColumnSpec[] = [
  {
    key: 'username',
    label: () => tl('username'),
    locked: true,
    fixed: 'left',
    minWidth: 130,
  },
  { key: 'created_at', label: () => tl('createdate'), minWidth: 150 },
  { key: 'last_login', label: () => tl('last_login'), minWidth: 150 },
  { key: 'last_deposit', label: () => tl('last_deposit'), minWidth: 150 },
  { key: 'last_withdrawal', label: () => tl('last_withdrawal'), minWidth: 150 },
  {
    key: 'agent_username',
    label: () => `${tl('superior')}${tl('agent')}${tl('account')}`,
    minWidth: 120,
    tooltip: true,
  },
  {
    key: 'agent_code',
    label: () => `${tl('superior')}${tl('agent')}${tl('invitation_code')}`,
    minWidth: 120,
    tooltip: true,
  },
  {
    key: 'agent_member_username',
    label: () => `${tl('superior')}${tl('member')}${tl('account')}`,
    minWidth: 120,
    tooltip: true,
  },
  {
    key: 'master_agent_username',
    label: () => tl('master_agent_account'),
    minWidth: 120,
    tooltip: true,
  },
  {
    key: 'member_code',
    label: () => `${tl('member')}${tl('invitation_code')}`,
    minWidth: 110,
    tooltip: true,
  },
  { key: 'account_type', label: () => tl('account_type'), minWidth: 90 },
  {
    key: 'member_group',
    label: () => tl('member_group_belong'),
    minWidth: 100,
    tooltip: true,
  },
  { key: 'vip_name', label: () => `VIP${tl('name')}`, minWidth: 90 },
  {
    key: 'first_name',
    label: () => tl('real_name'),
    minWidth: 100,
    tooltip: true,
  },
  { key: 'mobile', label: () => tl('tel'), minWidth: 110 },
  { key: 'email', label: () => tl('email'), minWidth: 150, tooltip: true },
  {
    key: 'bank_account_count',
    label: () => tl('bank_account_count'),
    minWidth: 90,
  },
  {
    key: 'invitation_code',
    label: () => `${tl('register')}${tl('invitation_code')}`,
    minWidth: 110,
    tooltip: true,
  },
  {
    key: 'register_domain',
    label: () => tl('register_domain'),
    minWidth: 140,
    tooltip: true,
  },
  { key: 'register_ip', label: () => tl('register_ip'), minWidth: 120 },
  // 工單4455：最後登錄IP 補在註冊IP 之後；已存過欄位順序的使用者由 normalizeOrder 插回同一位置
  { key: 'last_ip', label: () => tl('last_ip'), minWidth: 120 },
  { key: 'balance', label: () => tl('balance'), minWidth: 100, align: 'right' },
  {
    key: 'rolling_chip',
    label: () => tl('rolling_chip'),
    minWidth: 100,
    align: 'right',
  },
  {
    key: 'points_balance',
    label: () => tl('member_score'),
    minWidth: 90,
    align: 'right',
  },
  {
    key: 'mobile_validate',
    label: () => tl('mobile_status_certification'),
    minWidth: 100,
  },
  {
    key: 'realname_validate',
    label: () => tl('realname_authentication'),
    minWidth: 100,
  },
  { key: 'status', label: () => tl('status_member'), minWidth: 90 },
  { key: 'risk_status', label: () => tl('risk_status'), minWidth: 90 },
  {
    key: 'login_fail_count',
    label: () => tl('login_fail_count'),
    minWidth: 90,
  },
  {
    key: 'payment_pwd_fail_count',
    label: () => tl('payment_pwd_fail_count'),
    minWidth: 90,
  },
  { key: 'memo', label: () => tl('memo'), minWidth: 90, align: 'center' },
  { key: 'chat', label: () => tl('member_chat'), width: 70, align: 'center' },
  {
    key: 'features',
    label: () => tl('features'),
    locked: true,
    fixed: 'right',
    width: 90,
    align: 'center',
  },
];

const SPEC_BY_KEY = new Map(COLUMN_SPECS.map((s) => [s.key, s]));
/** 預設順序（不含 locked） */
const DEFAULT_ORDER = COLUMN_SPECS.filter((s) => !s.locked).map((s) => s.key);

/** feature 旗標關閉的欄位：整欄不出現 */
function isFeatureEnabled(key: string) {
  if (key === 'vip_name') return vipEnable.value;
  if (key === 'chat') return canMemberChat;
  // 4438 第 7 項：能用會員詳情抽屜的身份（系統管理者、總代理含子帳號、客服）不顯示「功能」欄，
  // 會員操作改從抽屜進入；其他身份（一般代理）保留「功能」下拉，內容維持現狀
  if (key === 'features') return !memberDrawerAllowed.value;
  return true;
}

const COLUMN_VISIBILITY_KEY = 'member-list-visible-columns';
const COLUMN_ORDER_KEY = 'member-list-column-order';

function readStorage<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function writeStorage(key: string, value: unknown) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // 私密瀏覽模式等寫不進去就只在本次有效
  }
}

/**
 * 工單4455：表格排版新舊版切換。客戶不習慣 4306 的一欄一欄位，預設回到舊版的
 * 11 欄堆疊式表格（4306 新增的資料併進對應的堆疊欄）。
 *
 * - 選擇只存瀏覽器、不存後台不綁帳號（比照千分位）；讀不到或值不認得一律回舊版。
 * - 切換只換表格排版：篩選、頁碼、每頁筆數照舊，不重新查詢。
 * - 欄位設定與凍結只有新版有；新版的勾選與順序切回來後仍保留（各自的 key 不受影響）。
 */
type TableLayout = 'legacy' | 'modern';

const TABLE_LAYOUT_KEY = 'member-list-table-layout';

const tableLayout = ref<TableLayout>(
  readStorage<unknown>(TABLE_LAYOUT_KEY, 'legacy') === 'modern'
    ? 'modern'
    : 'legacy',
);
const isLegacyLayout = computed(() => tableLayout.value === 'legacy');

watch(tableLayout, (v) => writeStorage(TABLE_LAYOUT_KEY, v));

/** 「功能」下拉兩版共用同一份模板（DefineRowFeatures 定義、RowFeatures 使用） */
const [DefineRowFeatures, RowFeatures] = createReusableTemplate<{
  row: MemberRow;
}>();

/** 欄位勾選狀態：locked 欄位一律強制 true，防止舊 localStorage 資料把它關掉 */
function loadVisibleColumns(): Record<string, boolean> {
  const stored = readStorage<Record<string, boolean>>(
    COLUMN_VISIBILITY_KEY,
    {},
  );
  const map: Record<string, boolean> = {};
  for (const s of COLUMN_SPECS) {
    map[s.key] = s.locked ? true : (stored[s.key] ?? true);
  }
  return map;
}

/**
 * 欄位順序（不含 locked）：只認得現有的 key；存檔之後才新增的欄位插回它在預設順序裡
 * 前一欄的後面，不會整批被丟到最後。
 */
function normalizeOrder(stored: unknown): string[] {
  const known = Array.isArray(stored)
    ? stored.filter(
        (k): k is string => typeof k === 'string' && DEFAULT_ORDER.includes(k),
      )
    : [];
  const result = [...new Set(known)];
  DEFAULT_ORDER.forEach((key, i) => {
    if (result.includes(key)) return;
    const prev = DEFAULT_ORDER[i - 1];
    const at = prev === undefined ? 0 : result.indexOf(prev) + 1;
    result.splice(at, 0, key);
  });
  return result;
}

const visibleColumns = reactive<Record<string, boolean>>(loadVisibleColumns());
const columnOrder = ref<string[]>(
  normalizeOrder(readStorage<unknown>(COLUMN_ORDER_KEY, [])),
);

/** 依目前順序排好、且 feature 有開的全部欄位（給欄位設定 dialog） */
const orderedColumns = computed(() => {
  const keys = ['username', ...columnOrder.value, 'features'];
  return keys
    .map((k) => SPEC_BY_KEY.get(k))
    .filter((s): s is ColumnSpec => s !== undefined && isFeatureEnabled(s.key));
});

/** 表格實際渲染的欄位：依順序且有勾選 */
const displayColumns = computed(() =>
  orderedColumns.value.filter((s) => visibleColumns[s.key] ?? true),
);

const columnSettingItems = computed(() =>
  orderedColumns.value.map((s) => ({
    key: s.key,
    label: s.label(),
    locked: s.locked,
  })),
);

/**
 * 欄位組成一變就整張表重建（換 key）。ElTable 只在欄位掛載時決定欄位位置，
 * v-for 重排不會讓既有欄位換位，所以順序改了必須重建；只在欄位設定關閉、或
 * VIP／聊天這類旗標變動時發生，不會每點一下就重建。
 */
const tableKey = computed(() =>
  displayColumns.value.map((s) => s.key).join(','),
);

function openColumnSettings() {
  columnSettingsRef.value?.open({ ...visibleColumns });
}

function handleColumnSettingsChange(next: {
  order: string[];
  visible: Record<string, boolean>;
}) {
  for (const s of COLUMN_SPECS) {
    if (!s.locked && next.visible[s.key] !== undefined) {
      visibleColumns[s.key] = next.visible[s.key] as boolean;
    }
  }
  // dialog 只列 feature 有開的欄位；沒列出來的（如 VIP 關閉時的 vip_name）維持原位置
  const listed = new Set(next.order);
  const merged = [...next.order];
  columnOrder.value.forEach((key, i) => {
    if (listed.has(key)) return;
    const prev = columnOrder.value[i - 1];
    merged.splice(prev === undefined ? 0 : merged.indexOf(prev) + 1, 0, key);
  });
  columnOrder.value = normalizeOrder(merged);
  writeStorage(COLUMN_VISIBILITY_KEY, visibleColumns);
  writeStorage(COLUMN_ORDER_KEY, columnOrder.value);
}

/** 列表上方 2 項合計（工單4306）：查詢結果全部筆數的加總，不受分頁影響 */
const summary = ref<MemberListSummary>({});

/** 會員群組篩選下拉（當前代理可見的群組） */
const memberGroups = ref<MemberGroupOption[]>([]);

/**
 * 載入群組下拉。分頁受 KeepAlive 快取（元件不會重新 mount），
 * 故 onMounted 之外也在 onActivated 重抓，確保在別的分頁新增群組後切回來看得到。
 */
async function loadMemberGroups() {
  const res = await getMemberGroupDropdownApi().catch(() => null);
  memberGroups.value = res && Number(res.code) === 200 ? (res.data ?? []) : [];
}

const loading = ref(false);
const list = ref<MemberRow[]>([]);
const total = ref(0);
const page = ref(1);

/**
 * 時間欄位送出前換算成 UTC（basis 0）。
 * 此端點（MemberController@list）輸入端直接比對 UTC 欄位、輸出端 DATE_ADD +8 ——
 * 輸入輸出基準不對稱，前端在此以 basis 0 補償輸入側（原本寫死 -8 小時，
 * 現改為隨所選時區平移；預設 GMT+8 時行為與原本完全相同）。 */
function toUtcParam(v?: string) {
  return v ? toApiTime(v, 0) : undefined;
}

function long2ip(n?: number) {
  if (!n || n <= 0) return '-';

  return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(
    '.',
  );
}

const fmtAmount = (v?: number | string) =>
  Number.isFinite(Number(v)) ? amountThousands(v, 2) : (v ?? '-');

function buildParams(): MemberListParams {
  const p: MemberListParams = {
    page: page.value,
    per_page: perPage.value,
  };
  if (filters.agent_username) p.agent_username = filters.agent_username;
  if (filters.agent_username && filters.downline_query) p.downline_query = 1;
  if (filters.username) p.username = filters.username;
  if (filters.fuzzy_query) p.fuzzy_query = 1;
  if (filters.first_name) p.first_name = filters.first_name;
  if (filters.certification !== '') p.certification = filters.certification;
  if (filters.status !== '') p.status = filters.status;
  if (filters.mobile_authentication !== '')
    p.mobile_authentication = filters.mobile_authentication;
  if (filters.account_no) p.account_no = filters.account_no;
  if (filters.mobile) p.mobile = filters.mobile;
  if (filters.register_ip) p.register_ip = filters.register_ip;
  if (filters.register_invitation_code)
    p.register_invitation_code = filters.register_invitation_code;
  if (filters.member_invitation_code)
    p.member_invitation_code = filters.member_invitation_code;
  // '' = 全部（不送）；0 = 未指派群組；其餘 = 指定群組
  if (filters.member_group_id !== '')
    p.member_group_id = filters.member_group_id;
  if (filters.created_range?.length === 2) {
    p.created_start_time = toUtcParam(filters.created_range[0]);
    p.created_end_time = toUtcParam(filters.created_range[1]);
  }
  if (filters.login_range?.length === 2) {
    p.login_start_time = toUtcParam(filters.login_range[0]);
    p.login_end_time = toUtcParam(filters.login_range[1]);
  }
  return p;
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getMemberListApi(buildParams());
    bankAccountVisible.value = res.bankAccountVisible;
    // 時間區間驗證錯誤
    if ([1613, 1614, 1617, 2002].includes(res.code)) {
      ElMessage.warning(res.message);
      list.value = [];
      total.value = 0;
      return;
    }
    list.value = res.list;
    total.value = res.pagination.total;
    summary.value = res.summary ?? {};
  } finally {
    loading.value = false;
  }
}

const exporting = ref(false);

/**
 * 單次匯出上限（target/tickets#4196）。
 * 後端 member/list 為「先撈 id、再 whereIn 取明細」兩段式查詢，
 * id 數超過 MySQL 65535 個 placeholder 上限即整支失敗（VWorld admin 視角逾 42 萬會員）。
 * 一萬筆實測約 2 秒、數 MB，離上限有充分餘裕；超過即請使用者縮小條件。
 */
const EXPORT_MAX_ROWS = 10_000;

/** 匯出 Excel（比照舊版 member_account，全量撈取後產表） */
async function handleExport() {
  if (total.value > EXPORT_MAX_ROWS) {
    ElMessage.warning(tl('export_row_limit_exceeded'));
    return;
  }
  exporting.value = true;
  try {
    const params = { ...buildParams(), page: 1, per_page: 100_000 };
    const res = await getMemberListApi(params);
    const rows = (res.list ?? []).map((r) => [
      fromApiTime(r.created_at),
      fromApiTime(r.last_login),
      r.agent_username ?? '',
      r.agent_code ?? '',
      r.username ?? '',
      r.first_name ?? '',
      canRevealPhone ? (r.mobile ?? '') : '-',
      r.email ?? '',
      r.register_domain ?? '',
      long2ip(r.register_ip),
      long2ip(r.last_ip),
      amountPlain(r.balance ?? 0, 2),
      amountPlain(r.rolling_chip ?? 0, 2),
      certText(r.mobile_validate),
      r.birthday ?? '',
      Number(r.status) === 1 ? tl('enable') : tl('disable'),
    ]);
    await exportExcel(
      `member_${Date.now()}`,
      [
        tl('createdate'),
        tl('last_login'),
        tl('agent_account'),
        tl('invitation_code'),
        tl('username'),
        tl('real_name'),
        tl('tel'),
        tl('email'),
        tl('register_domain'),
        tl('register_ip'),
        tl('last_ip'),
        tl('balance'),
        tl('rolling_chip'),
        tl('mobile_status_certification'),
        tl('birthday'),
        tl('status'),
      ],
      rows,
      { audit: { conditions: params }, title: tl('member_account') },
    );
  } finally {
    exporting.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handleReset() {
  Object.assign(filters, {
    account_no: '',
    agent_username: '',
    certification: '',
    created_range: [],
    downline_query: false,
    first_name: '',
    fuzzy_query: false,
    login_range: [],
    member_group_id: '',
    member_invitation_code: '',
    mobile: '',
    mobile_authentication: '',
    register_invitation_code: '',
    register_ip: '',
    status: '',
    username: '',
  });
  handleSearch();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

/** 改單頁筆數一律回第 1 頁：留在原頁碼可能已超出新的總頁數 */
function handleSizeChange(size: number) {
  perPage.value = size;
  page.value = 1;
  loadData();
}

/** 表格拖曳平移（工單4306後續回饋）：只有新版表格掛上，舊版不需要（工單4455）；實作見 useTableDragPan */
const tableRef: DragPanTableRef = ref();
const { onTableDragStart } = useTableDragPan(tableRef);

/** 狀態顯示 */
function statusTag(status?: number) {
  if (status === 1) return { text: tl('enable'), type: 'success' as const };
  if (status === 2) return { text: tl('user_block'), type: 'warning' as const };
  return { text: tl('disable'), type: 'danger' as const };
}

/** 已認證／未認證（工單4306 逐欄比對：不是「通過」，tl('pass') 是別處在用的字） */
function certText(v?: number) {
  return v === 1 ? tl('verified') : tl('not_certified');
}

function riskText(v?: number) {
  return !v || v === 0 ? '-' : tl(`risk.${v}`);
}

/** 工單4242：開啟會員聊天——目標帳號放模組 store 後跳既有客服聊天頁（無房由 chat 頁開房） */
function openMemberChat(row: MemberRow) {
  setPendingChatUsername(String(row.username ?? ''));
  router.push('/cs/chat');
}

/** 啟用/停用（需填備註） */
async function toggleStatus(row: MemberRow) {
  const next = row.status === 1 ? 0 : 1;
  const title = next === 1 ? tl('enable') : tl('disable');
  try {
    const { value: remark } = await ElMessageBox.prompt(
      `${tl('enter_memo')}...`,
      title,
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('submit'),
        inputType: 'textarea',
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    const res = await editMemberStatusApi({
      member_id: row.id,
      remark: remark ?? '',
      status: next,
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消
  }
}

/** 列表與會員詳情抽屜保持同步（4438）：抽屜裡改了會員（狀態、開洗分、調帳、遊戲錢包、帳號類型）→ 列表重抓 */
watch(memberChangedSeq, () => {
  loadData();
});

// 登入者能不能用抽屜（4438 第 7 項）：決定「功能」欄要不要出現
ensureMemberDrawerAccess();

// 分頁被 KeepAlive 快取時 onMounted 不會重跑，切回分頁時重抓群組清單
onActivated(() => {
  loadMemberGroups();
});

onMounted(() => {
  // 鈴鐺「今日註冊」帶 created_today 進來，區間由本頁自己算
  if (route.query.created_today === '1') {
    filters.created_range = [
      nowInUserTz().format('YYYY-MM-DD 00:00:00'),
      nowInUserTz().format('YYYY-MM-DD 23:59:59'),
    ];
  }
  loadData();
  // 會員群組篩選下拉；群組功能未啟用（無資料）時為空清單，不影響其他篩選
  loadMemberGroups();
  // 代理自訂設定（轉帳視窗的型別/倍數/支付密碼開關 + VIP 開關 3531）
  getCustomizedSettingApi()
    .then((settings) => {
      settingsMap.value = new Map(
        settings.map((s) => [Number(s.setting_key), s.value]),
      );
    })
    .catch(() => {});
  // 交易維護狀態（true 時隱藏轉帳）
  getTransactionMaintenanceApi()
    .then((maintaining) => {
      hasMaintains.value = maintaining;
    })
    .catch(() => {});
});
</script>

<template>
  <Page
    :title="`${tl('member_lower_management')} > ${tl('member_account_management')}`"
  >
    <!-- 「功能」下拉：只定義不渲染，新舊版表格各自以 RowFeatures 套用。
         4438 第 7 項：只給不能用抽屜的身份（一般代理），內容維持現狀 -->
    <DefineRowFeatures v-slot="{ row }">
      <ElDropdown>
        <ElButton size="small">
          {{ tl('features') }}
        </ElButton>
        <template #dropdown>
          <ElDropdownMenu>
            <ElDropdownItem
              v-if="canFlowLimit"
              @click="flowLimitRef?.open(row.id)"
            >
              {{ tl('flow_limit') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canTransaction && !hasMaintains"
              @click="transactionRef?.open(row)"
            >
              {{
                multipleWalletSwitch
                  ? tl('operate_main_wallet')
                  : tl('transfer')
              }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="multipleWalletSwitch"
              @click="walletTransactionRef?.open(row)"
            >
              {{ tl('operate_game_wallet') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canAdjustment"
              divided
              @click="adjustmentRef?.open(row)"
            >
              {{ tl('transaction_type.33') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canToggleStatus"
              divided
              @click="toggleStatus(row)"
            >
              {{ row.status === 1 ? tl('disable') : tl('enable') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canShowDomain"
              @click="domainRef?.open(row.parent ?? 0)"
            >
              {{ tl('domain') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canIpInquire"
              @click="loginLogRef?.open(row.id)"
            >
              IP
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canModifyPassword"
              divided
              @click="passwordRef?.open(row.id, 'password')"
            >
              {{ tl('modify_password') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canModifyPaymentPassword"
              @click="passwordRef?.open(row.id, 'payment_password')"
            >
              {{ tl('modify_payment_password') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-if="canModifyType"
              @click="accountTypeRef?.open(row)"
            >
              {{ tl('account_type') }}
            </ElDropdownItem>
            <ElDropdownItem
              v-for="(r, i) in REPORT_SHORTCUTS"
              :key="r.url"
              :divided="i === 0"
              @click="goReport(r.url, row)"
            >
              {{ tl(r.titleKey) }}
            </ElDropdownItem>
          </ElDropdownMenu>
        </template>
      </ElDropdown>
    </DefineRowFeatures>

    <!-- 搜尋 -->
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm
        :model="filters"
        label-width="auto"
        @submit.prevent="handleSearch"
      >
        <div class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('agent_account')">
            <div class="flex w-full items-center gap-2">
              <ElInput v-model="filters.agent_username" clearable />
              <ElCheckbox
                v-model="filters.downline_query"
                :disabled="!filters.agent_username"
                class="shrink-0"
              >
                {{ tl('query_all_downline_members') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('username')">
            <div class="flex w-full items-center gap-2">
              <ElInput v-model="filters.username" clearable />
              <ElCheckbox v-model="filters.fuzzy_query">
                {{ tl('fuzzy_query') }}
              </ElCheckbox>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('real_name')">
            <ElInput v-model="filters.first_name" clearable />
          </ElFormItem>

          <ElFormItem :label="tl('status_certification')">
            <ElSelect v-model="filters.certification" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('pass')" value="1" />
              <ElOption :label="tl('not_certified')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('enable')" value="1" />
              <ElOption :label="tl('disable')" value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('mobile_authentication')">
            <ElSelect v-model="filters.mobile_authentication" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('pass')" value="1" />
              <ElOption :label="tl('not_certified')" value="0" />
            </ElSelect>
          </ElFormItem>

          <ElFormItem :label="tl('account_no')">
            <ElInput v-model="filters.account_no" clearable />
          </ElFormItem>
          <ElFormItem v-if="canRevealPhone" :label="tl('mobile')">
            <ElInput v-model="filters.mobile" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('register_ip')">
            <ElInput v-model="filters.register_ip" clearable />
          </ElFormItem>

          <ElFormItem :label="tl('createdate')">
            <DateRangeFields
              v-model="filters.created_range"
              :quick="false"
              format="YYYY-MM-DD HH:mm:ss"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
            />
          </ElFormItem>
          <ElFormItem :label="tl('last_login_time')">
            <DateRangeFields
              v-model="filters.login_range"
              :quick="false"
              format="YYYY-MM-DD HH:mm:ss"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
            />
          </ElFormItem>
          <ElFormItem :label="`${tl('register')}${tl('invitation_code')}`">
            <ElInput v-model="filters.register_invitation_code" clearable />
          </ElFormItem>
          <ElFormItem :label="`${tl('member')}${tl('invitation_code')}`">
            <ElInput v-model="filters.member_invitation_code" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('member_group_belong')">
            <ElSelect v-model="filters.member_group_id" clearable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="g in memberGroups"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <!-- 工單4455：操作鈕併入最後一排（會員群組同排），不另佔一列 -->
          <ElFormItem>
            <div class="flex w-full justify-end gap-2">
              <ElButton
                v-if="canExport"
                :loading="exporting"
                type="success"
                @click="handleExport"
              >
                {{ tl('excel') }}
              </ElButton>
              <ElButton :loading="loading" native-type="submit" type="primary">
                {{ tl('search') }}
              </ElButton>
              <ElButton @click="handleReset">{{ tl('clear') }}</ElButton>
            </div>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <!-- 列表上方合計（工單4306）：查詢結果全部筆數的加總，不隨分頁／欄位勾選變動 -->
    <ElCard class="mb-3" shadow="never">
      <div class="grid grid-cols-1 gap-3 text-center sm:grid-cols-2">
        <div class="rounded border p-3">
          <div class="text-muted-foreground text-xs">
            {{ tl('balance_total') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmtAmount(summary.balance_total) }}
          </div>
        </div>
        <div class="rounded border p-3">
          <div class="text-muted-foreground text-xs">
            {{ tl('rolling_chip_total') }}
          </div>
          <div class="text-lg font-semibold">
            {{ fmtAmount(summary.rolling_chip_total) }}
          </div>
        </div>
      </div>
    </ElCard>

    <!-- 列表 -->
    <ElCard shadow="never">
      <div class="mb-3 flex justify-end gap-2">
        <!-- 工單4455：舊版／新版表格切換（工具列最左邊） -->
        <ElRadioGroup v-model="tableLayout">
          <ElRadioButton value="legacy">{{ tl('legacy_table') }}</ElRadioButton>
          <ElRadioButton value="modern">{{ tl('new_table') }}</ElRadioButton>
        </ElRadioGroup>
        <ElButton v-if="!isLegacyLayout" @click="openColumnSettings">
          {{ tl('column_settings') }}
        </ElButton>
      </div>

      <!--
        舊版表格（工單4455，預設）：4306 改版前的 11 欄堆疊式，欄序與欄寬照舊，
        4306 新增的資料依 PM 指定位置併入堆疊欄。不凍結任何欄位；欄寬不可拖拉
        （同新版，4306 回饋「線格會被拉動」）。也沒有新版的拖曳平移（舊版不需要）。
      -->
      <ElTable
        v-if="isLegacyLayout"
        v-loading="loading"
        :data="list"
        border
        stripe
        size="small"
      >
        <!-- 建立時間 / 最後登入 / 最後存款 / 最後出款 -->
        <ElTableColumn :resizable="false" width="170">
          <template #header>
            <div>{{ tl('createdate') }}</div>
            <div>{{ tl('last_login') }}</div>
            <div>{{ tl('last_deposit') }}</div>
            <div>{{ tl('last_withdrawal') }}</div>
          </template>
          <template #default="{ row }">
            <div>{{ fromApiTime(row.created_at) || '-' }}</div>
            <div class="text-muted-foreground">
              {{ fromApiTime(row.last_login) || '-' }}
            </div>
            <div class="text-muted-foreground">
              {{ fromApiTime(row.last_deposit_time) || '-' }}
            </div>
            <div class="text-muted-foreground">
              {{ fromApiTime(row.last_withdrawal_time) || '-' }}
            </div>
          </template>
        </ElTableColumn>

        <!-- 上級代理帳號 / 上級代理邀請碼 / 上級會員帳號 / 總代帳號 -->
        <ElTableColumn :resizable="false" min-width="140">
          <template #header>
            <div>{{ `${tl('superior')}${tl('agent')}${tl('account')}` }}</div>
            <div>
              {{ `${tl('superior')}${tl('agent')}${tl('invitation_code')}` }}
            </div>
            <div>{{ `${tl('superior')}${tl('member')}${tl('account')}` }}</div>
            <div>{{ tl('master_agent_account') }}</div>
          </template>
          <template #default="{ row }">
            <div class="font-medium">
              <AgentAccount :username="row.agent_username" />
            </div>
            <div class="text-success text-xs">{{ row.agent_code || '-' }}</div>
            <div class="text-success text-xs">
              {{ row.agent_member_username || '-' }}
            </div>
            <div class="text-xs">
              <AgentAccount :username="row.master_agent_username" />
            </div>
          </template>
        </ElTableColumn>

        <!-- 帳號 / 會員邀請碼 / 帳號類型 / 會員群組 -->
        <ElTableColumn :resizable="false" min-width="130">
          <template #header>
            <div>{{ tl('username') }}</div>
            <div>{{ `${tl('member')}${tl('invitation_code')}` }}</div>
            <div>{{ tl('account_type') }}</div>
            <div>{{ tl('member_group_belong') }}</div>
          </template>
          <template #default="{ row }">
            <!-- 工單4274：會員帳號可點開詳情抽屜 -->
            <div>
              <MemberAccountLink :member-id="row.id" :username="row.username" />
            </div>
            <div class="text-success text-xs">{{ row.member_code || '-' }}</div>
            <div class="text-success text-xs">{{ row.account_type || '' }}</div>
            <div class="text-xs">{{ row.member_group_name || '-' }}</div>
          </template>
        </ElTableColumn>

        <!-- [VIP名稱] / 真實姓名 / 電話 / 電子郵件 / 銀行卡數量 -->
        <ElTableColumn :resizable="false" min-width="130">
          <template #header>
            <div v-if="vipEnable">{{ `VIP${tl('name')}` }}</div>
            <div>{{ tl('real_name') }}</div>
            <div>{{ tl('tel') }}</div>
            <div>{{ tl('email') }}</div>
            <div>{{ tl('bank_account_count') }}</div>
          </template>
          <template #default="{ row }">
            <div v-if="vipEnable">{{ row.vip_name || '-' }}</div>
            <div>{{ row.first_name || '-' }}</div>
            <div class="text-muted-foreground text-xs">
              {{ canRevealPhone && row.mobile ? row.mobile : '-' }}
            </div>
            <div class="text-muted-foreground break-all text-xs">
              {{ row.email || '-' }}
            </div>
            <div>
              <ElButton
                v-if="bankAccountVisible"
                link
                size="small"
                type="primary"
                @click="bankAccountRef?.open(row.id, row.username)"
              >
                {{ row.bank_account_count ?? 0 }}
              </ElButton>
              <template v-else>{{ row.bank_account_count ?? 0 }}</template>
            </div>
          </template>
        </ElTableColumn>

        <!-- 註冊邀請碼 / 註冊網域 / 註冊IP / 最後登錄IP -->
        <ElTableColumn :resizable="false" min-width="140">
          <template #header>
            <div>{{ `${tl('register')}${tl('invitation_code')}` }}</div>
            <div>{{ tl('register_domain') }}</div>
            <div>{{ tl('register_ip') }}</div>
            <div>{{ tl('last_ip') }}</div>
          </template>
          <template #default="{ row }">
            <div class="text-xs">{{ row.invitation_code || '-' }}</div>
            <div class="text-xs">{{ row.register_domain || '-' }}</div>
            <div class="text-xs">{{ long2ip(row.register_ip) }}</div>
            <div class="text-xs">{{ long2ip(row.last_ip) }}</div>
          </template>
        </ElTableColumn>

        <!-- 餘額 / 禮碼 / 積分（工單4233：積分為 0 顯示 0，不顯示 -） -->
        <ElTableColumn align="right" :resizable="false" width="130">
          <template #header>
            <div class="text-info">{{ tl('balance') }}</div>
            <div>{{ tl('rolling_chip') }}</div>
            <div>{{ tl('member_score') }}</div>
          </template>
          <template #default="{ row }">
            <div class="text-info">{{ fmtAmount(row.balance) }}</div>
            <div class="text-muted-foreground">
              {{ fmtAmount(row.rolling_chip) }}
            </div>
            <div class="text-muted-foreground">
              {{ fmtAmount(row.score ?? 0) }}
            </div>
          </template>
        </ElTableColumn>

        <!-- 手機認證 / 實名認證 / 會員狀態 -->
        <ElTableColumn :resizable="false" width="120">
          <template #header>
            <div>{{ tl('mobile_status_certification') }}</div>
            <div>{{ tl('realname_authentication') }}</div>
            <div>{{ tl('status_member') }}</div>
          </template>
          <template #default="{ row }">
            <div
              class="text-xs"
              :class="{ 'text-warning': row.mobile_validate !== 1 }"
            >
              {{ certText(row.mobile_validate) }}
            </div>
            <div
              class="text-xs"
              :class="{ 'text-warning': row.realname_validate !== 1 }"
            >
              {{ certText(row.realname_validate) }}
            </div>
            <ElTag :type="statusTag(row.status).type" size="small">
              {{ statusTag(row.status).text }}
            </ElTag>
          </template>
        </ElTableColumn>

        <!-- 風控狀態 / 登入失敗次數 / 支付密碼失敗次數 -->
        <ElTableColumn :resizable="false" width="100">
          <template #header>
            <div>{{ tl('risk_status') }}</div>
            <div>{{ tl('login_fail_count') }}</div>
            <div>{{ tl('payment_pwd_fail_count') }}</div>
          </template>
          <template #default="{ row }">
            <div class="text-xs">{{ riskText(row.risk_status) }}</div>
            <div class="text-xs">{{ row.login_fail_count ?? 0 }}</div>
            <div class="text-xs">{{ row.payment_pwd_fail_count ?? 0 }}</div>
          </template>
        </ElTableColumn>

        <!-- 備註 -->
        <ElTableColumn
          :label="tl('memo')"
          align="center"
          :resizable="false"
          width="90"
        >
          <template #default="{ row }">
            <ElButton
              link
              size="small"
              type="info"
              @click="statusLogRef?.open(row.id, row.username)"
            >
              {{ tl('watch_data') }}
            </ElButton>
          </template>
        </ElTableColumn>

        <!-- 工單4242：聊天入口（獨立欄，無 member_chat 權限時整欄不顯示） -->
        <ElTableColumn
          v-if="canMemberChat"
          :label="tl('member_chat')"
          align="center"
          :resizable="false"
          width="70"
        >
          <template #default="{ row }">
            <ElButton
              circle
              size="small"
              :title="tl('member_chat')"
              @click="openMemberChat(row as MemberRow)"
            >
              <ChatIcon class="size-4" />
            </ElButton>
          </template>
        </ElTableColumn>

        <!-- 功能（舊版不凍結；能用抽屜的身份不顯示，見 isFeatureEnabled） -->
        <ElTableColumn
          v-if="!memberDrawerAllowed"
          :label="tl('features')"
          align="center"
          :resizable="false"
          width="90"
        >
          <template #default="{ row }">
            <RowFeatures :row="row as MemberRow" />
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <ElTable
        v-else
        ref="tableRef"
        :key="tableKey"
        v-loading="loading"
        class="drag-pan-table"
        :data="list"
        border
        stripe
        size="small"
        @mousedown="onTableDragStart"
      >
        <!--
          工單4306：一欄一欄位、單行高度；欄位與順序由 COLUMN_SPECS／「欄位設定」決定。
          欄寬一律不可拖拉（resizable=false），避免拉動格線讓版面跑掉。
        -->
        <ElTableColumn
          v-for="col in displayColumns"
          :key="col.key"
          :align="col.align"
          :fixed="col.fixed"
          :label="col.label()"
          :min-width="col.minWidth"
          :resizable="false"
          :show-overflow-tooltip="col.tooltip"
          :width="col.width"
        >
          <template #default="{ row }">
            <!-- 帳號（左側凍結，不可取消）；工單4274：可點開詳情抽屜 -->
            <MemberAccountLink
              v-if="col.key === 'username'"
              :member-id="row.id"
              :username="row.username"
            />
            <template v-else-if="col.key === 'created_at'">
              {{ fromApiTime(row.created_at) || '-' }}
            </template>
            <template v-else-if="col.key === 'last_login'">
              {{ fromApiTime(row.last_login) || '-' }}
            </template>
            <template v-else-if="col.key === 'last_deposit'">
              {{ fromApiTime(row.last_deposit_time) || '—' }}
            </template>
            <template v-else-if="col.key === 'last_withdrawal'">
              {{ fromApiTime(row.last_withdrawal_time) || '—' }}
            </template>
            <span v-else-if="col.key === 'agent_username'" class="font-medium">
              <AgentAccount :username="row.agent_username" />
            </span>
            <span v-else-if="col.key === 'agent_code'" class="text-success">
              {{ row.agent_code || '-' }}
            </span>
            <span
              v-else-if="col.key === 'agent_member_username'"
              class="text-success"
            >
              {{ row.agent_member_username || '-' }}
            </span>
            <AgentAccount
              v-else-if="col.key === 'master_agent_username'"
              :username="row.master_agent_username"
            />
            <span v-else-if="col.key === 'member_code'" class="text-success">
              {{ row.member_code || '-' }}
            </span>
            <span v-else-if="col.key === 'account_type'" class="text-success">
              {{ row.account_type || '' }}
            </span>
            <template v-else-if="col.key === 'member_group'">
              {{ row.member_group_name || '—' }}
            </template>
            <template v-else-if="col.key === 'vip_name'">
              {{ row.vip_name || '-' }}
            </template>
            <template v-else-if="col.key === 'first_name'">
              {{ row.first_name || '-' }}
            </template>
            <span
              v-else-if="col.key === 'mobile'"
              class="text-muted-foreground"
            >
              {{ canRevealPhone && row.mobile ? row.mobile : '-' }}
            </span>
            <template v-else-if="col.key === 'email'">
              {{ row.email || '—' }}
            </template>
            <ElButton
              v-else-if="col.key === 'bank_account_count' && bankAccountVisible"
              link
              size="small"
              type="primary"
              @click="bankAccountRef?.open(row.id, row.username)"
            >
              {{ row.bank_account_count ?? 0 }}
            </ElButton>
            <template v-else-if="col.key === 'bank_account_count'">
              {{ row.bank_account_count ?? 0 }}
            </template>
            <template v-else-if="col.key === 'invitation_code'">
              {{ row.invitation_code || '-' }}
            </template>
            <template v-else-if="col.key === 'register_domain'">
              {{ row.register_domain || '-' }}
            </template>
            <template v-else-if="col.key === 'register_ip'">
              {{ long2ip(row.register_ip) }}
            </template>
            <template v-else-if="col.key === 'last_ip'">
              {{ long2ip(row.last_ip) }}
            </template>
            <span v-else-if="col.key === 'balance'" class="text-info">
              {{ fmtAmount(row.balance) }}
            </span>
            <span
              v-else-if="col.key === 'rolling_chip'"
              class="text-muted-foreground"
            >
              {{ fmtAmount(row.rolling_chip) }}
            </span>
            <!-- 積分（後端欄位為 score；工單4233：積分為 0 顯示 0，不顯示 -） -->
            <span
              v-else-if="col.key === 'points_balance'"
              class="text-muted-foreground"
            >
              {{ fmtAmount(row.score ?? 0) }}
            </span>
            <span
              v-else-if="col.key === 'mobile_validate'"
              :class="{ 'text-warning': row.mobile_validate !== 1 }"
            >
              {{ certText(row.mobile_validate) }}
            </span>
            <span
              v-else-if="col.key === 'realname_validate'"
              :class="{ 'text-warning': row.realname_validate !== 1 }"
            >
              {{ certText(row.realname_validate) }}
            </span>
            <ElTag
              v-else-if="col.key === 'status'"
              :type="statusTag(row.status).type"
              size="small"
            >
              {{ statusTag(row.status).text }}
            </ElTag>
            <template v-else-if="col.key === 'risk_status'">
              {{ riskText(row.risk_status) }}
            </template>
            <template v-else-if="col.key === 'login_fail_count'">
              {{ row.login_fail_count ?? 0 }}
            </template>
            <template v-else-if="col.key === 'payment_pwd_fail_count'">
              {{ row.payment_pwd_fail_count ?? 0 }}
            </template>
            <ElButton
              v-else-if="col.key === 'memo'"
              link
              size="small"
              type="info"
              @click="statusLogRef?.open(row.id, row.username)"
            >
              {{ tl('watch_data') }}
            </ElButton>
            <!-- 工單4242：聊天入口（無 member_chat 權限時整欄不出現） -->
            <ElButton
              v-else-if="col.key === 'chat'"
              circle
              size="small"
              :title="tl('member_chat')"
              @click="openMemberChat(row as MemberRow)"
            >
              <ChatIcon class="size-4" />
            </ElButton>
            <!-- 功能（右側凍結，不可取消） -->
            <RowFeatures
              v-else-if="col.key === 'features'"
              :row="row as MemberRow"
            />
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <TablePagination
        :current-page="page"
        layout="total, sizes, prev, pager, next, jumper"
        :page-size="perPage"
        :page-sizes="PAGE_SIZES"
        :total="total"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </ElCard>

    <!-- 各功能視窗 -->
    <StatusLogDialog ref="statusLogRef" />
    <LoginLogDialog ref="loginLogRef" />
    <DomainDialog ref="domainRef" />
    <PasswordDialog ref="passwordRef" />
    <TransactionDialog
      ref="transactionRef"
      :settings="settingsMap"
      @success="loadData"
    />
    <WalletTransactionDialog
      ref="walletTransactionRef"
      :settings="settingsMap"
      @success="loadData"
    />
    <AdjustmentDialog ref="adjustmentRef" @success="loadData" />
    <FlowLimitDialog ref="flowLimitRef" />
    <BankAccountDialog ref="bankAccountRef" />
    <AccountTypeDialog ref="accountTypeRef" @success="loadData" />
    <ColumnSettingsDialog
      ref="columnSettingsRef"
      :columns="columnSettingItems"
      :default-order="DEFAULT_ORDER"
      @change="handleColumnSettingsChange"
    />
  </Page>
</template>

<style scoped>
/* 拖曳平移游標提示（只有新版表格）：非互動格子顯示可抓取游標，實際拖曳中由 JS 切成 grabbing */
.drag-pan-table :deep(.el-table__body-wrapper .cell) {
  cursor: grab;
}
</style>
