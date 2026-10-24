<script lang="ts" setup>
import type { MemberRow } from '#/api';
import type {
  MemberDetailStatistics,
  MemberDetailView,
} from '#/api/member-detail';
import type { ScoreRecordRow } from '#/api/score-record';

import {
  computed,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue';
import { useRouter } from 'vue-router';

import { useAccess } from '@vben/access';
import { createIconifyIcon } from '@vben/icons';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElSkeleton,
  ElSwitch,
  ElTag,
  ElTooltip,
} from 'element-plus';

import {
  editMemberStatusApi,
  getCustomizedSettingApi,
  getTransactionMaintenanceApi,
} from '#/api';
import { setPendingChatUsername } from '#/api/cs';
import {
  getMemberDetailStatisticsApi,
  getMemberDetailVendorListApi,
  getMemberDetailVendorUsernameApi,
  getMemberDetailViewApi,
} from '#/api/member-detail';
import { getScoreRecordListApi } from '#/api/score-record';
import DateRangeFields from '#/components/date-range-fields.vue';
import { tl } from '#/locales';
import { hasMenuUrl } from '#/router/agent-menu';
import { amountThousands } from '#/utils/format';
import { fromApiTime, nowInUserTz, toApiTime } from '#/utils/timezone-shift';

import AccountTypeDialog from './account-type-dialog.vue';
import AdjustmentDialog from './adjustment-dialog.vue';
import DomainDialog from './domain-dialog.vue';
import FlowLimitDialog from './flow-limit-dialog.vue';
import LoginLogDialog from './login-log-dialog.vue';
import {
  memberDetailRefreshSeq,
  memberDetailTarget,
  notifyMemberChanged,
} from './member-detail-drawer-state';
import PasswordDialog from './password-dialog.vue';
import TransactionDialog from './transaction-dialog.vue';
import WalletTransactionDialog from './wallet-transaction-dialog.vue';

/**
 * 會員詳情抽屜（4274）：右側 480px、無遮罩、單一實例（掛於主版面）。
 *
 * 「推擠式」呈現（比照參考站）：開啟時抽屜佔住右側 480px，整個介面（含頂欄）
 * 往左壓縮，而非浮層蓋在列表上——實作為 body class ＋ CSS 讓 #app 縮寬
 * （不動既有 DOM 層級，見 4120 教訓；不可用 transform，理由見 <style> 註解），
 * 頂欄為 static 模式隨內容捲動、隨 #app 縮寬。
 * <1280px 退回全寬覆蓋（B-T5）。
 *
 * 各區塊獨立載入與重試（工單 D-T4：不可整個抽屜報錯，也不可靜默空白）。
 * 接口來自 apis（member/detail/*、score/records、member/status、member/transaction/*）；
 * 開洗分彈窗與代理自訂設定沿用帳號管理現有元件，那兩支仍打 API_Agent（#4333 批次 2 搬）。
 *
 * target/tickets#4438：帳號上方報表捷徑、開洗分、可用積分卡、「詳細」彈窗、存提最後時間移到基本資料、狀態改開關。
 * 權限與彈窗都沿用帳號管理既有的，不另設新權限。
 */
defineOptions({ name: 'MemberDetailDrawer' });

const PUSH_CLASS = 'md-drawer-push';

// —— 區塊收合（基本資料／統計摘要）：狀態記在 localStorage，下次開抽屜沿用 ——
const COLLAPSE_KEY = 'md-drawer-collapsed';
const ChevronIcon = createIconifyIcon('lucide:chevron-down');
const collapsed = reactive<{ basic: boolean; stats: boolean }>(
  (() => {
    try {
      return {
        basic: false,
        stats: false,
        ...JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '{}'),
      };
    } catch {
      return { basic: false, stats: false };
    }
  })(),
);
function toggleSection(key: 'basic' | 'stats') {
  collapsed[key] = !collapsed[key];
  try {
    localStorage.setItem(COLLAPSE_KEY, JSON.stringify(collapsed));
  } catch {
    // 私密模式等寫不進去就只在本次有效
  }
}

const router = useRouter();
const { hasAccessByCodes } = useAccess();

/** 稽核流水明細彈窗（複用帳號管理的 FlowLimitDialog）；關閉後重抓 view 讓差額同步 */
const flowLimitRef = ref<InstanceType<typeof FlowLimitDialog>>();

const canEdit = hasAccessByCodes(['add_member']);
const canChat = hasAccessByCodes(['member_chat']);
/** 狀態開關：同帳號管理「啟用／停用」權限；沒權限只顯示狀態標籤 */
const canToggleStatus = hasAccessByCodes(['member_disabled_permissiom']);
/** 稽核流水：同帳號管理原本的「稽核流水」入口權限；沒有權限時差額顯示「-」、不能點開（4438） */
const canFlowLimit = hasAccessByCodes(['member_list_flow_limit']);
/** 可用積分卡：沒有積分紀錄頁權限就不顯示，餘額區維持兩欄 */
const canScore = hasAccessByCodes(['score_record']);
/** 開洗分入口：同帳號管理，開分／洗分／禮碼／補點／積分發放／積分扣除任一即可（工單4334、4233） */
const canTransaction = hasAccessByCodes([
  'agent_backend_deposit',
  'agent_backend_withdrawal',
  'agent_backend_send_rolling_chip',
  'member_make_up_point',
  'member_add_score',
  'member_deduct_score',
]);
// 從帳號管理「功能」下拉搬進來的操作（4438），各自沿用帳號管理該項的權限；沒權限不顯示
const canAdjustment = hasAccessByCodes(['member_adjustment']);
const canModifyPassword = [REDACTED]'password_modify_permission']);
const canModifyPaymentPassword = [REDACTED]
  'payment_password_permission',
]);
const canModifyType = hasAccessByCodes(['member_modify_type']);
const canIpInquire = hasAccessByCodes(['ip_inquire_permissiom']);
const canShowDomain = hasAccessByCodes(['show_proxy_domain']);

// —— 報表捷徑（帳號上方一排圓形圖示，target/tickets#4438）——
const ArrowIcon = createIconifyIcon('lucide:arrow-right');
const DiceIcon = createIconifyIcon('lucide:dice-4');
const CashIcon = createIconifyIcon('lucide:banknote');
const StarIcon = createIconifyIcon('lucide:star');
/** 依示意圖順序；SR02 五個版本圓點內寫版本代號，其餘用圖示；每顆看該報表選單權限 */
const REPORT_LINKS = [
  {
    color: '#16a34a',
    text: '02',
    titleKey: 'menu_report_sr02',
    url: 'report/sr02',
  },
  {
    color: '#0d9488',
    text: '02W',
    titleKey: 'menu_report_ssr02',
    url: 'report/ssr02',
  },
  {
    color: '#65a30d',
    text: '02N',
    titleKey: 'menu_report_sr02n',
    url: 'report/sr02n',
  },
  {
    color: '#059669',
    text: '02T',
    titleKey: 'menu_report_sr02t',
    url: 'report/sr02t',
  },
  {
    color: '#15803d',
    text: '02Y',
    titleKey: 'menu_report_sr02y',
    url: 'report/sr02y',
  },
  {
    color: '#e5484d',
    icon: ArrowIcon,
    titleKey: 'menu_report_ssr07',
    url: 'report/ssr07',
  },
  {
    color: '#7c3aed',
    icon: DiceIcon,
    titleKey: 'menu_report_sr12',
    url: 'report/sr12',
  },
  {
    color: '#0284c7',
    icon: CashIcon,
    titleKey: 'menu_report_sr14',
    url: 'report/sr14',
  },
  {
    color: '#d97706',
    icon: StarIcon,
    titleKey: 'menu_score_record',
    url: 'event/score_record',
  },
].filter((r) => hasMenuUrl(r.url));

/**
 * 在後台內開一個新頁籤（不是瀏覽器新視窗）並帶入會員帳號查詢；抽屜保持開啟。
 * 頁籤以完整網址（含 member_username）為 key：不同會員各開一個、各自重新查詢，同一會員再點切回已開的頁籤。
 * 日期維持各報表預設（今天），SR12 由該頁自動帶第一個遊戲商。
 */
function openReport(url: string) {
  if (!member.value) return;
  router.push({
    path: `/${url}`,
    query: { member_username: member.value.username },
  });
}

// —— 開洗分（沿用帳號管理的開洗分彈窗）——
const transactionRef = ref<InstanceType<typeof TransactionDialog>>();
/** 代理自訂設定：開洗分彈窗要（流水倍數、支付密碼等），多錢包開關 6008 決定按鈕文字；字典類開過一次即可 */
const settingsMap = ref<Map<number, any>>(new Map());
const multipleWalletSwitch = computed(
  () => Number(settingsMap.value.get(6008)) === 1,
);
/** 交易維護中：隱藏開洗分 */
const transactionMaintaining = ref(false);

async function loadTransactionOptions() {
  if (canTransaction) {
    getTransactionMaintenanceApi()
      .then((maintaining) => {
        transactionMaintaining.value = maintaining;
      })
      .catch(() => {});
  }
  // 自訂設定不只開洗分用：多錢包開關（6008）決定「遊戲錢包操作」要不要出現，所以一律載入
  if (settingsMap.value.size > 0) return;
  getCustomizedSettingApi()
    .then((settings) => {
      settingsMap.value = new Map(
        settings.map((s) => [Number(s.setting_key), s.value]),
      );
    })
    .catch(() => {});
}

function openTransaction() {
  if (!member.value) return;
  transactionRef.value?.open(memberRowLike());
}

// —— 從帳號管理「功能」下拉搬進來的彈窗（4438）：彈窗沿用帳號管理同一個，用 view 的資料組成它們要的列 ——
const adjustmentRef = ref<InstanceType<typeof AdjustmentDialog>>();
const walletTransactionRef =
  ref<InstanceType<typeof WalletTransactionDialog>>();
const passwordRef = ref<InstanceType<typeof PasswordDialog>>();
const accountTypeRef = ref<InstanceType<typeof AccountTypeDialog>>();
const loginLogRef = ref<InstanceType<typeof LoginLogDialog>>();
const domainRef = ref<InstanceType<typeof DomainDialog>>();

/** 帳號類型文字：規則同帳號管理列表（type 0 且上級代理開分桶開關時顯示為份桶會員） */
const accountTypeText = computed(() => {
  const m = member.value;
  if (!m) return '';
  if (m.type === 0) {
    return m.shareholder_visible === 1
      ? tl('shareholder_member')
      : tl('normal_member');
  }
  if (m.type === 1) return tl('shareholder_member');
  if (m.type === 2) return tl('profit_share_member');
  return String(m.type);
});

/** 帳號管理各彈窗吃的是列表的一列；抽屜只有 view 的資料，組出它們會用到的欄位 */
function memberRowLike(): MemberRow {
  const m = member.value;
  return {
    account_type: accountTypeText.value,
    balance: m?.balance ?? '0',
    id: memberId.value,
    type: m?.type,
    username: m?.username ?? '',
  } as MemberRow;
}

function openAdjustment() {
  if (member.value) adjustmentRef.value?.open(memberRowLike());
}
function openWalletTransaction() {
  if (member.value) walletTransactionRef.value?.open(memberRowLike());
}
function openPassword(type: 'password' | 'payment_password') {
  passwordRef.value?.open(memberId.value, type);
}
function openAccountType() {
  if (member.value) accountTypeRef.value?.open(memberRowLike());
}
function openLoginLog() {
  loginLogRef.value?.open(memberId.value);
}
function openDomain() {
  if (member.value?.agent_id) domainRef.value?.open(member.value.agent_id);
}

// —— 可用積分卡（target/tickets#4438）——
const score = ref<null | ScoreRecordRow>(null);
const scoreLoaded = ref(false);
const scoreExpanded = ref(false);

/** 打積分紀錄列表、用會員帳號查一筆；從沒拿過積分的會員查無資料，顯示 0 與「-」 */
async function loadScore() {
  score.value = null;
  scoreLoaded.value = false;
  if (!canScore || !member.value) return;
  try {
    const res = await getScoreRecordListApi({
      page: 1,
      per_page: 1,
      username: member.value.username,
    });
    if (Number(res.code) === 200) {
      score.value =
        res.data.find((r) => r.username === member.value?.username) ?? null;
    }
  } catch {
    // 積分卡失敗不擋其他區塊，卡片顯示「-」
  } finally {
    scoreLoaded.value = true;
  }
}

const scoreRows = computed(() => {
  const r = score.value;
  const money = (v?: null | string) => amountThousands(v ?? 0, 2);
  const nextExpire =
    r?.next_expire_at && r.next_expire_score !== null
      ? `${r.next_expire_at.slice(0, 16)}（${money(r.next_expire_score)}）`
      : '-';
  return [
    { label: tl('md_score_granted'), value: money(r?.granted_score) },
    { label: tl('md_score_used'), value: money(r?.used_score) },
    { label: tl('md_score_expired'), value: money(r?.expired_score) },
    { label: tl('md_score_next_expire'), value: nextExpire },
    { label: tl('md_score_last_changed'), value: r?.last_changed_at || '-' },
  ];
});

// —— 「詳細」彈窗（電子郵件、生日、註冊國家、設備種類，從基本資料移出）——
const detailVisible = ref(false);

// —— 狀態開關：撥動後必填備註確認，取消或失敗開關彈回；成功後抽屜重新整理 ——
async function beforeStatusChange(): Promise<boolean> {
  if (!member.value) return false;
  const next = member.value.status === 1 ? 0 : 1;
  let remark: string;
  try {
    const { value } = await ElMessageBox.prompt(
      `${tl('enter_memo')}...`,
      next === 1 ? tl('enable') : tl('disable'),
      {
        appendTo: 'body',
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('submit'),
        inputType: 'textarea',
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    remark = value ?? '';
  } catch {
    return false; // 取消：開關不動
  }
  try {
    const res = await editMemberStatusApi({
      member_id: memberId.value,
      remark,
      status: next,
    });
    if (Number(res.code) !== 200) {
      ElMessage.error(res.message || tl('failure'));
      return false;
    }
  } catch {
    // apis 業務錯誤回 HTTP 400，訊息已由請求攔截器顯示，這裡只讓開關彈回
    return false;
  }
  ElMessage.success(tl('success'));
  afterMemberChanged();
  return true;
}

const visible = ref(false);
const memberId = ref(0);
/** username 開啟模式（無 member_id 的入口）：view 解析出 member_id 後才載其餘區塊 */
const pendingUsername = ref('');

/** <1280px 改全寬覆蓋（B-T5） */
const narrow = ref(false);
const media = window.matchMedia('(max-width: 1280px)');
const onMedia = () => (narrow.value = media.matches);

/** ESC 關閉（B-T3：ESC 關、點外面不關） */
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && visible.value) visible.value = false;
}

onMounted(() => {
  onMedia();
  media.addEventListener('change', onMedia);
  document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
  media.removeEventListener('change', onMedia);
  document.removeEventListener('keydown', onKeydown);
  document.body.classList.remove(PUSH_CLASS);
});

/**
 * 推擠：開啟且非窄螢幕時，#app 與頂欄讓出右側 480px（純 CSS class 切換）。
 * 頁面捲動一律留在 body，不做 body↔#app 捲動轉移（B-T4 的位置保留由瀏覽器自然達成）。
 */
watch([visible, narrow], ([v, n]) => {
  document.body.classList.toggle(PUSH_CLASS, v && !n);
});

/** 區塊狀態：loading / error（顯示錯誤＋重試鈕，不靜默） */
function blockState() {
  return { error: ref(''), loading: ref(false) };
}
const viewBlock = blockState();
const statsBlock = blockState();

const member = ref<MemberDetailView | null>(null);
const stats = ref<MemberDetailStatistics | null>(null);

const vendors = ref<Array<{ id: number; name: string }>>([]);
const selectedVendor = ref<number>();
/** 一個會員在同一遊戲商可能同時有正式線／活動線多筆帳號，改陣列逐行顯示（op_tickets#3236） */
const vendorUsernameLines = ref<string[]>([]);

/** 統計摘要單次查詢區間最長 90 天，超過即彈窗禁止查詢；限制的是區間長度，任何歷史期間都可查（target/tickets#4426） */
const STAT_MAX_DAYS = 90;

/** 預設期間＝近 90 天（今天往前 90 天 ～ 今天）；接口收 Y-m-d */
function defaultStatRange(): string[] {
  return [
    nowInUserTz().subtract(STAT_MAX_DAYS, 'day').format('YYYY-MM-DD'),
    nowInUserTz().format('YYYY-MM-DD'),
  ];
}

/** 統計摘要期間 [起, 迄]（DateRangeFields 的 v-model 形態；開抽屜／重置都回到近 90 天） */
const statRange = ref<string[]>(defaultStatRange());
const hasStatRange = () => Boolean(statRange.value[0] && statRange.value[1]);

function apiErrorText(message?: string) {
  if (message === 'member not found') return tl('md_member_not_found');
  if (message === 'member out of scope') return tl('md_no_permission_member');
  return message || tl('failure');
}

async function loadView() {
  viewBlock.loading.value = true;
  viewBlock.error.value = '';
  try {
    const res = await getMemberDetailViewApi(
      memberId.value
        ? { memberId: memberId.value }
        : { username: pendingUsername.value },
    );
    if (res.code === 200) {
      member.value = res.data;
      memberId.value = res.data.member_id;
    } else {
      member.value = null;
      viewBlock.error.value = apiErrorText(res.message);
    }
  } catch {
    viewBlock.error.value = tl('failure');
  } finally {
    viewBlock.loading.value = false;
  }
}

function isStatRangeAllowed(): boolean {
  const start = dayjs(statRange.value[0]);
  const end = dayjs(statRange.value[1]);
  return end.diff(start, 'day') <= STAT_MAX_DAYS;
}

async function loadStats() {
  if (hasStatRange() && !isStatRangeAllowed()) {
    ElMessageBox.alert(
      tl('query_period_max_days').replaceAll('{value}', String(STAT_MAX_DAYS)),
    );
    return;
  }
  statsBlock.loading.value = true;
  statsBlock.error.value = '';
  try {
    const params: any = { member_id: memberId.value };
    if (hasStatRange()) {
      // 4438 第 8 項：期間＝使用者所選時區的當天 00:00:00～23:59:59，換算成端點基準（GMT+8）牆鐘送出，
      // 後端再轉 UTC 查；純日期不會被平移，所以要補上時分秒
      params.start_time = toApiTime(`${statRange.value[0]} 00:00:00`);
      params.end_time = toApiTime(`${statRange.value[1]} 23:59:59`);
    }
    const res = await getMemberDetailStatisticsApi(params);
    if (res.code === 200) {
      stats.value = res.data;
    } else {
      statsBlock.error.value = apiErrorText(res.message);
    }
  } catch {
    statsBlock.error.value = tl('failure');
  } finally {
    statsBlock.loading.value = false;
  }
}

async function loadVendors() {
  if (vendors.value.length > 0) return; // 字典類，開過一次即可
  try {
    const res = await getMemberDetailVendorListApi();
    if (res.code === 200) vendors.value = res.data ?? [];
  } catch {
    // 下拉載入失敗時查詢按鈕自然無選項，不擋其他區塊
  }
}

async function loadVendorUsername() {
  vendorUsernameLines.value = [];
  if (!selectedVendor.value) return;
  try {
    const res = await getMemberDetailVendorUsernameApi({
      member_id: memberId.value,
      vendor_id: selectedVendor.value,
    });
    if (res.code === 200) {
      vendorUsernameLines.value =
        res.data.length > 0
          ? res.data.map((account) => {
              const suffix =
                account.vendor_info_type === 2
                  ? ` ${tl('md_vendor_account_for_event')}`
                  : '';
              return `${tl('username')}:${account.username}${suffix}`;
            })
          : [tl('md_account_not_exist')];
    } else {
      vendorUsernameLines.value = [apiErrorText(res.message)];
    }
  } catch {
    vendorUsernameLines.value = [tl('failure')];
  }
}

/** 全區塊載入（各自 loading／錯誤，互不阻塞；username 模式先由 view 解析 id） */
async function loadAll() {
  member.value = null;
  stats.value = null;
  score.value = null;
  scoreExpanded.value = false;
  selectedVendor.value = undefined;
  vendorUsernameLines.value = [];
  statRange.value = defaultStatRange();

  if (!memberId.value && pendingUsername.value) {
    // username 開啟：等 view 解析出 member_id，失敗（查無/越權）就只顯示該區塊錯誤
    await loadView();
    if (!memberId.value) return;
  } else {
    loadView().then(() => loadScore());
  }
  loadStats();
  loadVendors();
  loadTransactionOptions();
  // username 模式 view 已 await 完成，可直接載積分；member_id 模式由上面 loadView().then 處理
  if (pendingUsername.value && member.value) loadScore();
}

/** 整個抽屜重新整理（卡片、基本資料、積分、統計摘要） */
async function refreshAll() {
  await loadView();
  loadScore();
  loadStats();
}

/** 抽屜裡改了會員資料（開洗分送出、狀態切換成功）：抽屜重新整理，並通知開著的列表頁一起重抓 */
function afterMemberChanged() {
  refreshAll();
  notifyMemberChanged();
}

/** 全域開啟訊號：抽屜開啟中再點其他會員時內容直接切換（B-T2） */
watch(memberDetailTarget, (target) => {
  if (!target) return;
  memberId.value = target.memberId ?? 0;
  pendingUsername.value = target.username ?? '';
  visible.value = true;
  loadAll();
});

/**
 * 外部改了會員資料（實名驗證審核頁審完、帳號管理的遊戲錢包／調帳／帳號類型送出）→ 抽屜開著就整個重抓。
 * 只重抓自己、不呼叫 notifyMemberChanged()，避免與列表頁來回觸發。
 */
watch(memberDetailRefreshSeq, () => {
  if (visible.value && memberId.value) refreshAll();
});

// —— 會員卡片操作 ——

async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text);
    ElMessage.success(tl('md_copy_success'));
  } catch {
    ElMessage.error(tl('failure'));
  }
}

/** 編輯基本資料：左側內容區開既有編輯頁分頁（同視窗路由），抽屜保持開啟 */
function goEdit() {
  router.push({ path: '/member/edit', query: { id: String(memberId.value) } });
}

/** 聊天入口：行為與權限完全比照 #4242（帳號放模組 store 後跳客服聊天頁） */
function openChat() {
  if (!member.value) return;
  setPendingChatUsername(String(member.value.username ?? ''));
  router.push('/cs/chat');
}

/**
 * 實名驗證審核（KYC）待審核時跳「下線會員管理 › 實名驗證審核」頁：
 * 帶 request_id 讓該頁直接開審核彈窗，並預填帳號／待審核篩選作為背景列表。
 */
function goRealnameReview() {
  if (!member.value?.realname_request_id) return;
  router.push({
    path: '/member/realname_validate/index',
    query: {
      request_id: String(member.value.realname_request_id),
      status: '0',
      username: member.value.username,
    },
  });
}

// —— 統計摘要呈現 ——

/** 會員輸贏/公司輸贏正綠負紅、零維持一般字色（工單金額顏色規則，其餘欄位不上色） */
function winLoseClass(v?: string) {
  const n = Number(v ?? 0);
  if (n > 0) return 'text-success';
  if (n < 0) return 'text-red-500';
  return '';
}

const statGroups = computed(() => {
  const s = stats.value;
  if (!s) return [];
  const money = (v?: string) => amountThousands(v ?? 0, 2);
  return [
    {
      items: [
        { label: tl('md_bet_amount'), value: money(s.bet_amount) },
        { label: tl('md_total_payout'), value: money(s.total_payout) },
        {
          class: winLoseClass(s.member_win_lose),
          label: tl('md_member_win_lose'),
          value: money(s.member_win_lose),
        },
        // 公司輸贏 2026-09-08 依需求拿掉（接口仍回傳 company_win_lose，前端不顯示）
      ],
      title: tl('md_group_bet'),
    },
    {
      items: [
        { label: tl('md_rebate'), value: money(s.rebate) },
        { label: tl('md_event_bonus'), value: money(s.event_bonus) },
        { label: tl('md_vip_bonus'), value: money(s.vip_bonus) },
        { label: tl('md_rewards_total'), value: money(s.rewards_total) },
      ],
      title: tl('md_group_rewards'),
    },
    {
      items: [
        {
          label: tl('md_referral_commission'),
          value: money(s.referral_commission),
        },
        { label: tl('md_referral_bonus'), value: money(s.referral_bonus) },
      ],
      title: tl('md_group_referral'),
    },
    {
      items: [
        { label: tl('md_deposit_count'), value: String(s.deposit_count) },
        { label: tl('md_deposit_amount'), value: money(s.deposit_amount) },
        // 最近一筆存款時間 target/tickets#4438 移到基本資料（不受期間影響）
      ],
      title: tl('md_group_deposit'),
    },
    {
      items: [
        { label: tl('md_withdrawal_count'), value: String(s.withdrawal_count) },
        {
          label: tl('md_withdrawal_amount'),
          value: money(s.withdrawal_amount),
        },
        // 最後提款時間 target/tickets#4438 移到基本資料
      ],
      title: tl('md_group_withdrawal'),
    },
  ];
});

/** 手機認證／實名認證文字，同帳號管理列表 */
function certText(v?: number) {
  return v === 1 ? tl('verified') : tl('not_certified');
}

/** 實名驗證審核最新申請狀態文字（與實名驗證審核頁同字眼）；從未申請回空字串不顯示 */
const realnameRequestText = computed(() => {
  const s = member.value?.realname_request_status;
  if (s === 0) return tl('pending_review');
  if (s === 1) return tl('pass');
  if (s === 2) return tl('rejected');
  return '';
});
</script>

<template>
  <Teleport to="body">
    <aside
      :class="[visible ? 'md-open' : '', narrow ? 'md-narrow' : '']"
      class="md-detail-aside"
    >
      <div class="md-detail-header">
        <span class="font-medium">{{ tl('md_member_detail') }}</span>
        <ElButton link @click="visible = false">✕</ElButton>
      </div>
      <div class="md-detail-body space-y-4">
        <!-- 會員卡片 -->
        <ElCard shadow="never">
          <ElSkeleton v-if="viewBlock.loading.value" :rows="3" animated />
          <div v-else-if="viewBlock.error.value" class="text-center">
            <div class="text-red-500 mb-2">{{ viewBlock.error.value }}</div>
            <ElButton size="small" @click="loadView">
              {{ tl('md_retry') }}
            </ElButton>
          </div>
          <template v-else-if="member">
            <!-- 報表捷徑：滑過顯示報表名稱，點了開新分頁並帶入帳號查詢；全部沒權限整列隱藏 -->
            <div
              v-if="REPORT_LINKS.length > 0"
              class="mb-2 flex flex-wrap items-center gap-2"
            >
              <ElTooltip
                v-for="r in REPORT_LINKS"
                :key="r.url"
                :content="tl(r.titleKey)"
                placement="top"
              >
                <span
                  :style="{ '--c': r.color }"
                  class="md-report-dot"
                  @click="openReport(r.url)"
                >
                  <component :is="r.icon" v-if="r.icon" class="size-4" />
                  <b v-else>{{ r.text }}</b>
                </span>
              </ElTooltip>
            </div>
            <div class="flex items-center justify-between">
              <div>
                <span class="text-lg font-semibold">{{ member.username }}</span>
                <ElButton link size="small" @click="copyText(member.username)">
                  ⧉
                </ElButton>
                <ElTag
                  v-if="member.status !== 1"
                  class="ml-1"
                  size="small"
                  type="danger"
                >
                  {{ tl('disable') }}
                </ElTag>
                <!-- 聊天 icon 放大（PM 2026-09-09） -->
                <ElButton
                  v-if="canChat"
                  :title="tl('member_chat')"
                  class="relative -top-[10px] !ml-0 !text-2xl"
                  link
                  type="primary"
                  @click="openChat"
                >
                  💬
                </ElButton>
              </div>
            </div>
            <div class="text-muted-foreground text-sm">
              {{ member.nickname || '-' }}
            </div>
            <div class="text-sm">
              {{ tl('invitation_code') }}：{{ member.referral_code || '-' }}
              <ElButton
                v-if="member.referral_code"
                link
                size="small"
                @click="copyText(member.referral_code)"
              >
                ⧉
              </ElButton>
            </div>
            <!-- 上級代理（PM 2026-09-09：從基本資料移到卡片） -->
            <div class="text-sm">
              {{ tl('superior') }}{{ tl('agent') }}：{{
                member.agent_username || '-'
              }}
            </div>
            <div
              :class="canScore ? 'grid-cols-3' : 'grid-cols-2'"
              class="mt-2 grid gap-2 text-sm"
            >
              <div>
                <div class="text-muted-foreground">
                  {{ tl('md_main_wallet') }}
                </div>
                <!-- 4438 第 9 項：同帳號管理列表，2 位小數＋千分位，跟著頂部「千分位／K」開關 -->
                <div class="font-medium">
                  {{ amountThousands(member.balance, 2) }}
                </div>
              </div>
              <div>
                <div class="text-muted-foreground">
                  {{ tl('md_reward_wallet') }}
                </div>
                <div class="font-medium">
                  {{ amountThousands(member.rolling_chip, 2) }}
                </div>
              </div>
              <!-- 可用積分卡：點擊展開明細（預設收合），一律全期間、GMT+8 -->
              <div
                v-if="canScore"
                class="cursor-pointer select-none"
                @click="scoreExpanded = !scoreExpanded"
              >
                <div class="text-muted-foreground flex items-center gap-1">
                  {{ tl('available_score') }}
                  <ChevronIcon
                    :class="scoreExpanded ? '' : '-rotate-90'"
                    class="size-3 transition-transform"
                  />
                </div>
                <div class="font-medium">
                  {{
                    scoreLoaded
                      ? amountThousands(score?.available_score ?? 0, 2)
                      : '-'
                  }}
                </div>
              </div>
            </div>
            <div
              v-if="canScore && scoreExpanded"
              class="border-border mt-2 rounded border border-dashed px-3 py-1 text-sm"
            >
              <div
                v-for="row in scoreRows"
                :key="row.label"
                class="border-border/50 flex items-center justify-between border-b py-1 last:border-b-0"
              >
                <span class="text-muted-foreground">{{ row.label }}</span>
                <span class="tabular-nums">{{ row.value }}</span>
              </div>
            </div>
            <!-- 操作區第一排：編輯基本資料／開洗分（多錢包站點為「主錢包操作」，維護中隱藏）／調帳／遊戲錢包操作（多錢包站點才有） -->
            <div
              v-if="
                canEdit ||
                (canTransaction && !transactionMaintaining) ||
                canAdjustment ||
                multipleWalletSwitch
              "
              class="mt-3 flex flex-wrap items-center gap-3"
            >
              <ElButton
                v-if="canEdit"
                size="small"
                type="danger"
                @click="goEdit"
              >
                {{ tl('md_edit_profile') }}
              </ElButton>
              <ElButton
                v-if="canTransaction && !transactionMaintaining"
                plain
                size="small"
                type="success"
                @click="openTransaction"
              >
                {{
                  multipleWalletSwitch
                    ? tl('operate_main_wallet')
                    : tl('transfer')
                }}
              </ElButton>
              <ElButton
                v-if="canAdjustment"
                plain
                size="small"
                type="warning"
                @click="openAdjustment"
              >
                {{ tl('transaction_type.33') }}
              </ElButton>
              <ElButton
                v-if="multipleWalletSwitch"
                plain
                size="small"
                type="success"
                @click="openWalletTransaction"
              >
                {{ tl('operate_game_wallet') }}
              </ElButton>
            </div>
            <!-- 操作區第二排：修改密碼／修改支付密碼／帳號類型（從帳號管理「功能」搬進來，各自沿用該項權限） -->
            <div
              v-if="
                canModifyPassword || canModifyPaymentPassword || canModifyType
              "
              class="mt-2 flex flex-wrap items-center gap-3"
            >
              <ElButton
                v-if="canModifyPassword"
                plain
                size="small"
                @click="openPassword('password')"
              >
                {{ tl('modify_password') }}
              </ElButton>
              <ElButton
                v-if="canModifyPaymentPassword"
                plain
                size="small"
                @click="openPassword('payment_password')"
              >
                {{ tl('modify_payment_password') }}
              </ElButton>
              <ElButton
                v-if="canModifyType"
                plain
                size="small"
                @click="openAccountType"
              >
                {{ tl('account_type') }}
              </ElButton>
            </div>
          </template>
        </ElCard>

        <!-- 基本資料（可收合） -->
        <ElCard v-if="member" shadow="never">
          <template #header>
            <div
              class="flex cursor-pointer select-none items-center justify-between"
              @click="toggleSection('basic')"
            >
              <span class="font-medium">{{ tl('basic_information') }}</span>
              <div class="flex items-center gap-2">
                <ElButton
                  link
                  size="small"
                  type="primary"
                  @click.stop="detailVisible = true"
                >
                  {{ tl('detail') }}
                </ElButton>
                <ChevronIcon
                  :class="collapsed.basic ? '-rotate-90' : ''"
                  class="size-4 transition-transform"
                />
              </div>
            </div>
          </template>
          <div v-show="!collapsed.basic">
            <ElDescriptions :column="1" border size="small">
              <ElDescriptionsItem :label="tl('real_name')">
                {{ member.realname || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('tel')">
                {{ member.mobile || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('country_code_number')">
                {{ member.country_calling_code || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('md_registerdate')">
                {{ fromApiTime(member.created_at, 0) || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('register_ip')">
                {{ member.register_ip || '-' }}
              </ElDescriptionsItem>
              <!-- 最後登錄IP（4438，從帳號管理「功能」搬進來）：這列不受權限限制，只有「IP」按鈕要 IP 查詢權限 -->
              <ElDescriptionsItem :label="tl('last_ip')">
                <div class="flex flex-wrap items-center gap-2">
                  <span>{{ member.last_login_ip || '-' }}</span>
                  <ElButton
                    v-if="canIpInquire"
                    size="small"
                    @click="openLoginLog"
                  >
                    IP
                  </ElButton>
                </div>
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('register_domain')">
                <div class="flex flex-wrap items-center gap-2">
                  <span>{{ member.register_domain || '-' }}</span>
                  <!-- 「網域」：開上級代理的網域清單，同帳號管理「功能」的網域（4438） -->
                  <ElButton
                    v-if="canShowDomain && member.agent_id"
                    size="small"
                    @click="openDomain"
                  >
                    {{ tl('domain') }}
                  </ElButton>
                </div>
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('status')">
                <!-- 有停用權限：開關＝啟用（綠）、關＝停用；撥動跳必填備註確認框，取消或失敗彈回 -->
                <ElSwitch
                  v-if="canToggleStatus"
                  :before-change="beforeStatusChange"
                  :model-value="member.status === 1"
                  :active-text="tl('enable')"
                  :inactive-text="tl('disable')"
                  class="md-status-switch"
                  inline-prompt
                />
                <ElTag
                  v-else
                  :type="member.status === 1 ? 'success' : 'danger'"
                  size="small"
                >
                  {{ member.status === 1 ? tl('enable') : tl('disable') }}
                </ElTag>
              </ElDescriptionsItem>
              <!-- 4438：原「身份認證狀態（未通過認證，將無法進行提款功能）」整列拿掉（讀的是 2019 證件驗證的 is_validate，已停用），
                   改成兩列，欄名與文字同帳號管理列表 -->
              <ElDescriptionsItem :label="tl('mobile_status_certification')">
                <span :class="{ 'text-warning': member.mobile_validate !== 1 }">
                  {{ certText(member.mobile_validate) }}
                </span>
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('realname_authentication')">
                <div
                  :class="{ 'text-warning': member.realname_validate !== 1 }"
                >
                  {{ certText(member.realname_validate) }}
                </div>
                <!-- 實名驗證審核（KYC）最新申請：待審核時可直接跳審核頁開彈窗 -->
                <div
                  v-if="realnameRequestText"
                  class="mt-1 flex flex-wrap items-center gap-2 text-xs"
                >
                  <span class="text-muted-foreground">
                    {{ tl('menu_realname_validate_request') }}：{{
                      realnameRequestText
                    }}
                  </span>
                  <ElButton
                    v-if="member.realname_request_status === 0"
                    size="small"
                    type="primary"
                    @click="goRealnameReview"
                  >
                    {{ tl('review') }}
                  </ElButton>
                </div>
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('last_login')">
                {{ fromApiTime(member.last_login, 0) || '-' }}
              </ElDescriptionsItem>
              <!-- 存提最後時間：從統計摘要移過來（target/tickets#4438），規則不變：全期間、GMT+8、到秒 -->
              <ElDescriptionsItem :label="tl('md_last_deposit')">
                {{ stats?.last_deposit_at || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('md_last_withdraw')">
                {{ stats?.last_withdraw_at || '-' }}
              </ElDescriptionsItem>
              <!-- 稽核流水差額：未通過流水限制的 限制−達成，與帳號管理「稽核流水」彈窗同資料源 -->
              <ElDescriptionsItem
                :label="`${tl('flow_limit')}（${tl('md_flow_gap')}）`"
              >
                <!-- 有 member_list_flow_limit 權限：點差額開「稽核流水」彈窗看逐筆明細；沒有權限只顯示「-」 -->
                <span
                  v-if="canFlowLimit"
                  class="text-primary cursor-pointer font-medium hover:underline"
                  @click="flowLimitRef?.open(memberId)"
                >
                  {{ amountThousands(member.flow_limit_gap, 2) }}
                </span>
                <span v-else>-</span>
              </ElDescriptionsItem>
            </ElDescriptions>
          </div>
        </ElCard>

        <!-- 遊戲商資料（名稱旁附 vendor id） -->
        <ElCard v-if="member" shadow="never">
          <template #header>
            <span class="font-medium">{{ tl('vendor_information') }}</span>
          </template>
          <ElSelect
            v-model="selectedVendor"
            :placeholder="tl('choose_vendors')"
            class="w-full"
            clearable
            filterable
            @change="loadVendorUsername"
          >
            <ElOption
              v-for="v in vendors"
              :key="v.id"
              :label="`${v.name} (${v.id})`"
              :value="v.id"
            />
          </ElSelect>
          <div
            v-if="vendorUsernameLines.length > 0"
            class="mt-2 space-y-1 text-sm"
          >
            <div v-for="line in vendorUsernameLines" :key="line">
              {{ line }}
            </div>
          </div>
        </ElCard>

        <!-- 統計摘要（可收合） -->
        <ElCard shadow="never">
          <template #header>
            <div
              class="flex cursor-pointer select-none items-center justify-between"
              @click="toggleSection('stats')"
            >
              <span class="font-medium">{{ tl('md_stat_summary') }}</span>
              <ChevronIcon
                :class="collapsed.stats ? '-rotate-90' : ''"
                class="size-4 transition-transform"
              />
            </div>
          </template>
          <div v-show="!collapsed.stats">
            <!-- 期間：起迄日 ＋ 今日/昨日/本週/上週/本月/上月 快速鈕（與各報表頁同一元件）；接口只收日期 -->
            <div class="mb-3">
              <DateRangeFields v-model="statRange" />
              <div class="text-muted-foreground mt-1 text-xs">
                {{
                  tl('query_period_max_days').replaceAll(
                    '{value}',
                    String(STAT_MAX_DAYS),
                  )
                }}
              </div>
              <div class="mt-2 flex gap-2">
                <ElButton size="small" type="primary" @click="loadStats">
                  {{ tl('md_query') }}
                </ElButton>
                <ElButton
                  size="small"
                  @click="
                    statRange = defaultStatRange();
                    loadStats();
                  "
                >
                  {{ tl('md_reset') }}
                </ElButton>
              </div>
            </div>
            <div v-if="statsBlock.error.value" class="text-center">
              <div class="text-red-500 mb-2">{{ statsBlock.error.value }}</div>
              <ElButton size="small" @click="loadStats">
                {{ tl('md_retry') }}
              </ElButton>
            </div>
            <div v-else v-loading="statsBlock.loading.value" class="space-y-3">
              <div v-for="group in statGroups" :key="group.title" class="mb-4">
                <div
                  class="border-border mb-1 border-b pb-1 text-sm font-semibold"
                >
                  {{ group.title }}
                </div>
                <div
                  v-for="item in group.items"
                  :key="item.label"
                  class="border-border/50 flex items-center justify-between border-b py-1.5 text-sm last:border-b-0"
                >
                  <span class="text-muted-foreground">{{ item.label }}</span>
                  <span
                    :class="(item as any).class"
                    class="font-medium tabular-nums"
                  >
                    {{ item.value }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </ElCard>
      </div>
    </aside>
    <!-- 放在 aside 外（直接掛 body），彈窗才會以整個視窗置中，不被 480px 抽屜框住 -->
    <FlowLimitDialog ref="flowLimitRef" @closed="loadView" />
    <TransactionDialog
      ref="transactionRef"
      :settings="settingsMap"
      @success="afterMemberChanged"
    />
    <!-- 從帳號管理「功能」搬進來的彈窗（4438）：改了會員資料的，成功後抽屜重抓並通知列表 -->
    <AdjustmentDialog ref="adjustmentRef" @success="afterMemberChanged" />
    <WalletTransactionDialog
      ref="walletTransactionRef"
      :settings="settingsMap"
      @success="afterMemberChanged"
    />
    <PasswordDialog ref="passwordRef" />
    <AccountTypeDialog ref="accountTypeRef" @success="afterMemberChanged" />
    <LoginLogDialog ref="loginLogRef" />
    <DomainDialog ref="domainRef" />
    <!-- 「詳細」：唯讀、整個視窗置中，不被抽屜框住 -->
    <ElDialog
      v-model="detailVisible"
      :title="tl('detail')"
      align-center
      append-to-body
      width="420px"
    >
      <ElDescriptions v-if="member" :column="1" border size="small">
        <ElDescriptionsItem :label="tl('email')">
          {{ member.email || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('birthday')">
          {{ member.birthday || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="`${tl('register')}${tl('country')}`">
          {{ member.register_country || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('device_type')">
          {{ member.device_type || '-' }}
        </ElDescriptionsItem>
      </ElDescriptions>
    </ElDialog>
  </Teleport>
</template>

<style>
/* 推擠式：抽屜開啟時整個介面往左讓出 480px。
   #app 常駐明確寬度——開啟瞬間才掛 width 會從 auto 直接跳（auto→calc 不可動畫）。
   ⚠ 絕對不可在 #app 加 transform／will-change 當 containing block：那會把 #app 內所有
   position:fixed 元素（vben 頂欄、Element Plus 未 append-to-body 的彈窗與遮罩）綁在 #app 上，
   頁面一捲就跟著捲走——2026-09-09 實測廣告頁「編輯」彈窗與遮罩只蓋住上半截。
   頂欄要跟內容一起捲的需求改由 header.mode='static'（preferences.ts／main.ts）達成，
   static 頂欄是一般排版元素，也會隨 #app 一起縮寬。 */
#app {
  width: 100%;
  transition: width 0.7s ease;
}

/* 頁面捲動維持在 body（正常捲軸），不把捲動關進 #app——
   「捲軸卡在視窗內」的做法 2026-09-08 被退回。 */
body.md-drawer-push #app {
  width: calc(100% - 480px);
}
</style>

<style scoped>
.md-detail-aside {
  position: fixed;
  inset: 0 0 0 auto;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  width: 480px;
  background: hsl(var(--background));
  border-left: 1px solid hsl(var(--border));

  /* 滑入滑出：與 #app 縮寬同步（body.md-drawer-push 的 width transition 同秒數） */
  transform: translateX(100%);
  transition: transform 0.7s ease;
}

.md-detail-aside.md-open {
  transform: translateX(0);
}

/* <1280px 全寬覆蓋（B-T5） */
.md-detail-aside.md-narrow {
  z-index: 2000;
  width: 100%;
}

.md-detail-header {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  height: 48px;
  padding: 0 12px;
  border-bottom: 1px solid hsl(var(--border));
}

.md-detail-body {
  flex: 1;
  padding: 12px;
  overflow-y: auto;
}

/* 狀態開關：啟用＝綠（示意圖規格） */
.md-status-switch {
  --el-switch-on-color: #16a34a;
}

/* 報表捷徑圓點：底色為主色 18% 透明，字／圖示為主色（示意圖規格） */
.md-report-dot {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  font-size: 10px;
  color: var(--c);
  cursor: pointer;
  background: color-mix(in srgb, var(--c) 18%, transparent);
  border-radius: 50%;
}
</style>
