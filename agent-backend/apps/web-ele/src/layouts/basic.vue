<script lang="ts" setup>
import type { NotificationItem } from '@vben/layouts';

import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { AuthenticationLoginExpiredModal } from '@vben/common-ui';
import { useWatermark } from '@vben/hooks';
import {
  Bell,
  Clock,
  IconifyIcon,
  Languages,
  ListChecks,
  LogOut,
  MoonStar,
} from '@vben/icons';
import { BasicLayout, GlobalSearch } from '@vben/layouts';
import { $t } from '@vben/locales';
import {
  preferences,
  preferencesManager,
  updatePreferences,
  usePreferences,
} from '@vben/preferences';
import { useAccessStore, useTabbarStore, useUserStore } from '@vben/stores';

import { useQRCode } from '@vueuse/integrations/useQRCode';
import {
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElMessageBox,
  ElSwitch,
} from 'element-plus';

import {
  disable2faApi,
  getAgentRealNameValidateNoticeApi,
  getAgentSelfDepositNoticeApi,
  getAgentSelfWithdrawalNoticeApi,
  getAgentViewByIdApi,
  getAutoDepositThresholdNoticeApi,
  getAutoDepositWithdrawNoticeApi,
  getCustomizedSettingApi,
  getLanguagesApi,
  getMarqueeDisplayListApi,
  getPlatformNotificationUnreadApi,
  getProfitShareDomainApi,
  getRealnameValidateNoticeApi,
  getRemoveFlowLimitRequestNoticeApi,
  getSystemNotificationUnreadApi,
  getTodayRegisterNoticeApi,
} from '#/api';
import {
  chromeLocaleFor,
  frameworkToCode,
  getBackendLang,
  setBackendLang,
  tl,
} from '#/locales';
import { useAuthStore } from '#/store';
import { useKUnitStore } from '#/store/k-unit';
import { amountThousands } from '#/utils/format';
import { currentTzOffset, nowInUserTz } from '#/utils/timezone-shift';
import LoginForm from '#/views/_core/authentication/login.vue';
import AgentPasswordDialog from '#/views/agent/components/agent-password-dialog.vue';
import TwoFaOtpDialog from '#/views/agent/components/two-fa-otp-dialog.vue';
import TwoFaSetupDialog from '#/views/agent/components/two-fa-setup-dialog.vue';
import MemberDetailDrawer from '#/views/member/components/member-detail-drawer.vue';

import ForcedSetupOverlay from './forced-setup-overlay.vue';

const notifications = ref<NotificationItem[]>([]);

/**
 * 今日註冊是當日累計、不像其他通知處理完會歸零，整天都大於 0。
 * 徽章只算「還沒看過的新增數」，看過就不再計入也不再閃。
 */
const TODAY_REGISTER_KEY = 'today_register';
/** 已看過的今日註冊數；null 代表還沒拿到第一輪 */
const registerSeen = ref<null | number>(null);
const registerCount = ref(0);
const registerUnseen = computed(() =>
  registerSeen.value === null
    ? 0
    : Math.max(0, registerCount.value - registerSeen.value),
);

const router = useRouter();
const route = useRoute();
const userStore = useUserStore();
const authStore = useAuthStore();
const accessStore = useAccessStore();
const kUnitStore = useKUnitStore();

// ---------------- Header 通知中心----------------
const NOTICE_INTERVAL_MS = 10_000;
let noticeTimer: ReturnType<typeof setInterval> | undefined;
let noticeAudio: HTMLAudioElement | undefined;
// 記住每則通知上一輪的數量，用來判斷是否要播語音（新單才響）
const prevCounts = new Map<string, number>();

// 語音提示開關
const soundEnabled = ref(localStorage.getItem('notice-sound') !== '0');
watch(soundEnabled, (v) => localStorage.setItem('notice-sound', v ? '1' : '0'));

function hasCode(code: string) {
  return accessStore.accessCodes.includes(code);
}

/**
 * 提示訊息選單各待審項目的權限碼，須與 collectNotices() 裡的 hasCode 判斷一致；
 * 語音權限碼（*_voice、notice_voice）只管響不響，不列入（target/tickets#4453）。
 */
const NOTICE_ACCESS_CODES = [
  'auto_deposit_and_auto_withdraw',
  'agent_self_withdrawal_notice',
  'agent_self_deposit_notice',
  'today_register_notice',
  'remove_flow_limit_request_notice',
  'auto_deposit_limit_notice',
  'realname_validate_request_notice',
  'agent_real_name_validate_request_notice',
];

/** 待審項目權限全無時，隱藏提示訊息 icon 並停止背景查詢（看權限不看數量） */
const hasAnyNoticeAccess = computed(() =>
  NOTICE_ACCESS_CODES.some((code) => hasCode(code)),
);

function playNoticeVoice() {
  if (!soundEnabled.value) return;
  if (!noticeAudio) {
    noticeAudio = new Audio('/voice/autodeposit.mp3');
  }
  noticeAudio.muted = false;
  // 瀏覽器未經使用者互動可能擋自動播放，忽略 reject 即可
  noticeAudio.play().catch(() => {});
}

/** 切換語音開關：開啟時立刻試播一次（使用者手勢，可解除瀏覽器自動播放限制並確認有聲） */
function onSoundToggle(v: boolean | number | string) {
  if (v) playNoticeVoice();
}

interface NoticeLine {
  count: number;
  key: string;
  link: string;
  query?: Record<string, any>;
  title: string;
  /** 該則是否開啟語音、且本輪需要響 */
  voiced: boolean;
}

const NOTICE_ORDER = [
  'realname_validate',
  'agent_real_name_validate',
  'auto_deposit_threshold',
  'remove_flow_limit_request',
  'today_register',
  'auto_deposit',
  'auto_withdraw',
  'agent_self_withdrawal',
  'agent_self_deposit',
];

/** 把單則 count 通知轉成 NoticeLine（count===-1 代表後端判定不顯示） */
function countLine(opts: {
  count: number;
  key: string;
  link: string;
  query?: Record<string, any>;
  title: string;
  voiceOn: boolean;
}): NoticeLine | undefined {
  if (Number(opts.count) === -1) return undefined;
  const count = Number(opts.count) || 0;
  return {
    count,
    key: opts.key,
    link: opts.link,
    query: opts.query,
    title: opts.title,
    voiced: count > 0,
  };
}

async function collectNotices(): Promise<NoticeLine[]> {
  const lines: NoticeLine[] = [];
  const tasks: Array<Promise<void>> = [];

  if (hasCode('auto_deposit_and_auto_withdraw')) {
    const voiceOn = hasCode('notice_voice');
    tasks.push(
      getAutoDepositWithdrawNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const dep = countLine({
            count: res.data.Deposit,
            key: 'auto_deposit',
            link: '/order/index',
            query: { status: 1 },
            title: tl('menu_auto_deposit'),
            voiceOn,
          });
          const wd = countLine({
            count: res.data.Withdraw,
            key: 'auto_withdraw',
            link: '/withdrawal/index',
            query: { status: 1 },
            title: tl('menu_auto_withdrawal'),
            voiceOn,
          });
          if (dep) lines.push(dep);
          if (wd) lines.push(wd);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('agent_self_withdrawal_notice')) {
    const voiceOn = hasCode('agent_self_withdrawal_notice_voice');
    tasks.push(
      getAgentSelfWithdrawalNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.withdraw_count,
            key: 'agent_self_withdrawal',
            link: '/agent_self_withdrawal/index',
            query: { status: 1 },
            title: tl('menu_agent_self_withdrawal_index'),
            voiceOn,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('agent_self_deposit_notice')) {
    const voiceOn = hasCode('agent_self_deposit_notice_voice');
    tasks.push(
      getAgentSelfDepositNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.deposit_count,
            key: 'agent_self_deposit',
            link: '/agent_self_deposit/bank_index',
            query: { status: 1 },
            title: tl('menu_agent_self_deposit_bank_index'),
            voiceOn,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('today_register_notice')) {
    tasks.push(
      getTodayRegisterNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.count,
            key: 'today_register',
            // 連帳號管理而非會員活動記錄：後者的後端對
            // summary_transaction_logs 是 inner join，只列有金流的會員，
            // 數字對不上通知的註冊數。區間由該頁自己算，這裡只給旗標
            link: '/member/index',
            query: { created_today: 1 },
            title: tl('today_register'),
            voiceOn: Number(res.data.voice) === 1,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('remove_flow_limit_request_notice')) {
    tasks.push(
      getRemoveFlowLimitRequestNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.count,
            key: 'remove_flow_limit_request',
            link: '/member/unlock_event_mode',
            query: { status: 0 },
            title: tl('remove_flow_limit_request'),
            voiceOn: Number(res.data.voice) === 1,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('auto_deposit_limit_notice')) {
    tasks.push(
      getAutoDepositThresholdNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.count,
            key: 'auto_deposit_threshold',
            link: '/bank_management/index',
            title: tl('auto_deposit_achieved'),
            voiceOn: Number(res.data.voice) === 1,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('realname_validate_request_notice')) {
    tasks.push(
      getRealnameValidateNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.count,
            key: 'realname_validate',
            link: '/member/realname_validate/index',
            query: { status: 0 },
            title: tl('menu_realname_validate_request'),
            voiceOn: Number(res.data.voice) === 1,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }
  if (hasCode('agent_real_name_validate_request_notice')) {
    tasks.push(
      getAgentRealNameValidateNoticeApi()
        .then((res) => {
          if (Number(res.code) !== 1000 || !res.data) return;
          const line = countLine({
            count: res.data.count,
            key: 'agent_real_name_validate',
            link: '/agent/realname_validate/index',
            query: { status: 0 },
            title: tl('menu_agent_real_name_validate'),
            voiceOn: Number(res.data.voice) === 1,
          });
          if (line) lines.push(line);
        })
        .catch(() => {}),
    );
  }

  await Promise.all(tasks);
  return lines.toSorted(
    (a, b) => NOTICE_ORDER.indexOf(a.key) - NOTICE_ORDER.indexOf(b.key),
  );
}

/** 該則是否待處理：紅底並閃爍，否則灰底 */
function noticeLit(key: string, count: number) {
  return key === TODAY_REGISTER_KEY ? registerUnseen.value > 0 : count > 0;
}

async function refreshNotices() {
  const lines = await collectNotices();
  const register = lines.find((line) => line.key === TODAY_REGISTER_KEY);
  if (register) {
    registerCount.value = register.count;
    // 首輪與跨日歸零都當作已看過，之後只算新增
    if (registerSeen.value === null || register.count < registerSeen.value) {
      registerSeen.value = register.count;
    }
  }
  let shouldPlay = false;
  notifications.value = lines.map((line) => {
    // 數量比上一輪多才響語音（避免每 10 秒重複播）
    const prev = prevCounts.get(line.key) ?? 0;
    if (line.voiced && line.count > prev) shouldPlay = true;
    prevCounts.set(line.key, line.count);
    return {
      id: line.key,
      avatar: example.invalid.defaultAvatar,
      date: '',
      isRead: !noticeLit(line.key, line.count),
      link: line.link,
      message: String(line.count),
      query: line.query,
      title: line.title,
    } satisfies NotificationItem;
  });
  if (shouldPlay) playNoticeVoice();
}

function startNoticePolling() {
  stopNoticePolling();
  // 尚未取得權限碼、或待審項目權限全無時不啟動
  if (!hasAnyNoticeAccess.value) return;
  refreshNotices();
  noticeTimer = setInterval(refreshNotices, NOTICE_INTERVAL_MS);
}

function stopNoticePolling() {
  if (noticeTimer) {
    clearInterval(noticeTimer);
    noticeTimer = undefined;
  }
}

// ---------------- 後台通知未讀紅點（鈴鐺）----------------
// 對齊舊版 _top.php get_backend_notice_unread_count：
// 最新消息 + 系統通知未讀加總；舊版每次頁面載入取一次（無輪詢），
// SPA 等價為掛載時 + 每次路由切換時刷新。
const backendNoticeUnread = ref(0);
async function refreshBackendNoticeUnread() {
  const [sys, plat] = await Promise.all([
    getSystemNotificationUnreadApi().catch(() => null),
    getPlatformNotificationUnreadApi().catch(() => null),
  ]);
  backendNoticeUnread.value =
    (Number(sys?.data?.unread) || 0) + (Number(plat?.data?.unread) || 0);
}
watch(
  () => route.path,
  (path) => {
    // 進入後台通知頁時，該頁會先 readAll，延後取數避免搶跑仍顯示舊值
    if (path === '/backend_notice/index') {
      setTimeout(refreshBackendNoticeUnread, 1500);
    } else {
      refreshBackendNoticeUnread();
    }
  },
);

onMounted(() => {
  startNoticePolling();
  loadAgentSettingFlags();
  loadSelfHas2fa();
  loadMarquees();
  loadShareDomain();
  loadLanguages();
  refreshBackendNoticeUnread();
  resyncTabTitles();
  // session 裡的 balance 是登入當下的快照，重新整理後要重抓
  authStore.refreshBalance();
});

// sessionStorage 還原的分頁帶著開頁當下語言的標題快照，
// 用當前路由表（已依所選語言重建）重新解析一次
const tabbarStore = useTabbarStore();
async function resyncTabTitles() {
  await router.isReady();
  let changed = false;
  for (const tab of tabbarStore.tabs) {
    const fullPath = (tab.fullPath ?? tab.path) as string;
    if (!fullPath) continue;
    const resolved = router.resolve(fullPath);
    const title = resolved?.meta?.title;
    if (title && title !== tab.meta.title) {
      tab.meta.title = title;
      changed = true;
    }
  }
  // tabbar 的 currentTabs 只 watch tabs 陣列/updateTime，改 meta.title 不會觸發，要手動捅
  if (changed) {
    tabbarStore.setUpdateTime();
  }
}
onBeforeUnmount(stopNoticePolling);
// 權限碼載入完成（登入後）才開始輪詢
watch(
  () => accessStore.accessCodes.length,
  (len, prev) => {
    if (len > 0 && !noticeTimer) startNoticePolling();
    else if (len === 0 && prev) stopNoticePolling();
  },
);
const { destroyWatermark, updateWatermark } = useWatermark();
const { globalSearchShortcutKey, isDark } = usePreferences();
// 提示訊息選單總數：今日註冊只計還沒看過的新增數，其餘照計數加總。
const noticeTotal = computed(() =>
  notifications.value.reduce(
    (sum, item) =>
      sum +
      (item.id === TODAY_REGISTER_KEY
        ? registerUnseen.value
        : Number(item.message) || 0),
    0,
  ),
);
/** 有任何一則待處理才閃爍（並轉紅底） */
const noticeBlink = computed(() =>
  notifications.value.some((item) => !item.isRead),
);

// ---------------- 使用者下拉選單----------------
// 旗標：8007 股東佔成設定頁、2517 修改手機、5304 支付密碼相關。
// 工單4506：「修改密碼」每個站都顯示；「修改支付密碼」只在 5304 關閉時顯示，
// 5304 開啟的站沿用「修改代理支付密碼」「忘記代理支付密碼」。
const passwordDialogRef = ref<InstanceType<typeof AgentPasswordDialog>>();
const agentSettingFlags = ref<Record<string, number>>({});
// 9106：分享碼特殊網域（顯示在 QR 標題），為字串故另存。
const shareCodeSpecialDomain = ref('');
async function loadAgentSettingFlags() {
  const list = await getCustomizedSettingApi().catch(() => []);
  const map: Record<string, number> = {};
  for (const row of list ?? []) {
    const key = String((row as any).setting_key);
    map[key] = Number((row as any).value);
    if (key === '9106') {
      shareCodeSpecialDomain.value = String((row as any).value ?? '').replace(
        /\/+$/,
        '',
      );
    }
  }
  agentSettingFlags.value = map;
}

// ---------------- 本人 Google 2FA（target/tickets#4521）----------------
const selfHas2fa = ref(false);
const twoFaSetupRef = ref<InstanceType<typeof TwoFaSetupDialog>>();
const twoFaOtpRef = ref<InstanceType<typeof TwoFaOtpDialog>>();
async function loadSelfHas2fa() {
  const id = Number((userStore.userInfo as any)?.id ?? 0);
  if (!id) return;
  try {
    const view: any = await getAgentViewByIdApi(id);
    selfHas2fa.value = Number(view?.has_2fa) === 1;
  } catch {
    // 取不到就維持原狀態，不擋選單
  }
}
/** 設置：彈窗顯示 QRCode 與金鑰，驗證碼正確即開啟 */
async function setupSelf2fa() {
  if (await twoFaSetupRef.value?.open()) selfHas2fa.value = true;
}
/** 關閉：要輸入目前的驗證碼，正確才關閉 */
async function disableSelf2fa() {
  const ok = await twoFaOtpRef.value?.open({
    confirmText: tl('tfa_disable_confirm'),
    danger: true,
    description: tl('tfa_disable_desc'),
    label: tl('tfa_input_code'),
    submit: (otp) => disable2faApi({ secret: [REDACTED] }),
    title: tl('tfa_disable_title'),
  });
  if (ok) selfHas2fa.value = false;
}

const menus = computed(() => {
  const me = userStore.userInfo as any;
  const id = me?.id ?? '';
  const parent = me?.parent ?? '';
  const flags = agentSettingFlags.value;
  const items: Array<{ handler: () => void; icon: string; text: string }> = [];
  // 設定：股東佔成設定頁開啟且非總代/非總代直屬 → cj7_edit，否則 agent/edit
  const settingPath =
    Number(flags['8007']) === 1 && Number(id) !== 1 && Number(parent) !== 1
      ? '/agent/cj7_edit'
      : '/agent/edit';
  items.push({
    handler: () => router.push({ path: settingPath, query: { id } }),
    icon: 'lucide:settings',
    text: tl('setting'),
  });
  if (Number(flags['2517']) === 1) {
    items.push({
      handler: () =>
        router.push({ path: '/agent/modify_mobile', query: { id } }),
      icon: 'lucide:smartphone',
      text: tl('modify_mobile'),
    });
  }
  items.push({
    handler: () => passwordDialogRef.value?.open('password'),
    icon: 'lucide:lock-keyhole',
    text: tl('modify_password'),
  });
  if (Number(flags['5304']) === 1) {
    items.push(
      {
        handler: () =>
          router.push({
            path: '/agent/modify_agent_payment_password',
            query: { id },
          }),
        icon: 'lucide:lock',
        text: tl('modify_agent_payment_password'),
      },
      {
        handler: () =>
          router.push({
            path: '/agent/forget_agent_payment_password',
            query: { id },
          }),
        icon: 'lucide:key',
        text: tl('forget_agent_payment_password'),
      },
    );
  } else {
    items.push({
      handler: () => passwordDialogRef.value?.open('payment_password'),
      icon: 'lucide:key-round',
      text: tl('modify_payment_password'),
    });
  }
  // 2FA：所有帳號都看得到，依本人狀態切換同一個項目；設定 5001 關閉 2FA 功能時才隱藏
  if (Number(flags['5001'] ?? 1) === 1) {
    items.push({
      handler: () => (selfHas2fa.value ? disableSelf2fa() : setupSelf2fa()),
      icon: 'lucide:shield-check',
      text: selfHas2fa.value ? tl('tfa_menu_disable') : tl('tfa_menu_setup'),
    });
  }
  return items;
});

const avatar = computed(() => {
  return userStore.userInfo?.avatar ?? example.invalid.defaultAvatar;
});

// ---------------- Header 餘額顯示----------------
const balanceText = computed(() => {
  const me = userStore.userInfo as any;
  if (Number(me?.is_balance_unlimited) === 1) return tl('unlimited');
  // 走共用金額格式化才會吃到千位數開關（target/tickets#4317 QA 回報）；非數值時原樣顯示
  return amountThousands(me?.balance, 4, String(me?.balance ?? ''));
});

// 點擊 header 餘額數字即刷新（target/tickets#4317）。
// 錯誤 toast 由 request.ts 攔截器統一發，這裡不再疊；loading 期間再點無效。
const balanceRefreshing = ref(false);
async function refreshBalanceByClick() {
  if (balanceRefreshing.value) return;
  balanceRefreshing.value = true;
  try {
    await authStore.refreshBalance();
  } finally {
    balanceRefreshing.value = false;
  }
}

function onUserDropdownVisibleChange(visible: boolean) {
  if (visible) authStore.refreshBalance();
}

// ---------------- Header 跑馬燈----------------
interface MarqueeLine {
  content: string;
  important: boolean;
}
const marquees = ref<MarqueeLine[]>([]);
const MARQUEE_SPEED_PX_PER_SEC = 50;
const marqueeTrackRef = ref<HTMLElement>();
const marqueeDuration = ref('30s');
async function loadMarquees() {
  const list = await getMarqueeDisplayListApi().catch(() => []);
  marquees.value = (list ?? []).map((m) => ({
    content: m.content,
    important: Number(m.type) === 2,
  }));
  await nextTick();
  const track = marqueeTrackRef.value;
  if (track) {
    marqueeDuration.value = `${Math.max(track.scrollWidth / MARQUEE_SPEED_PX_PER_SEC, 5)}s`;
  }
}

// ---------------- 分享碼 QR----------------
const shareDomain = ref('');
async function loadShareDomain() {
  const res = await getProfitShareDomainApi().catch(() => null);
  if (res && Number(res.code) === 1000)
    shareDomain.value = res.data?.domain ?? '';
}
// 無分享碼的帳號（如純客服）agents.code 存的是「-」，登入 API 原樣透傳；
// 視為沒有分享碼，header 整格不渲染、QR 亦不產生（target/tickets#4317 QA 回報）。
const shareCode = computed(() => {
  const raw = String((userStore.userInfo as any)?.code ?? '').trim();
  return raw === '-' ? '' : raw;
});
const shareUrl = computed(() => {
  const dom = shareDomain.value;
  if (!dom || !shareCode.value) return '';
  const sep = dom.includes('?') ? '' : '/Signup/';
  return `https://${dom}${sep}${shareCode.value}`;
});
const shareQr = useQRCode(shareUrl, { errorCorrectionLevel: 'H', width: 300 });
const qrVisible = ref(false);

// ---------------- 語言切換----------------
interface LangItem {
  code: string;
  id: number;
  name: string;
}
const languageList = ref<LangItem[]>([]);
async function loadLanguages() {
  const list = await getLanguagesApi().catch(() => []);
  languageList.value = (list ?? []) as LangItem[];
}
const currentLangCode = computed(
  () => getBackendLang() || frameworkToCode(example.invalid.locale),
);
const currentLangName = computed(
  () =>
    languageList.value.find((l) => l.code === currentLangCode.value)?.name ??
    '',
);
// 語言選擇彈窗（14 語系格狀，避免塞在下拉裡太長）
const langVisible = ref(false);
function openLangDialog() {
  userDropdownRef.value?.handleClose?.();
  langVisible.value = true;
}
async function handleSelectLanguage(code: string) {
  if (!code || code === currentLangCode.value) return;
  setBackendLang(code);
  updatePreferences({ app: { locale: chromeLocaleFor(code) } });
  // 偏好寫入是 debounce 的，reload 前必須先落盤，否則 locale 更新會遺失
  await preferencesManager.flush();
  // 重新載入，讓所有後端內容以新語言重抓。
  window.location.reload();
}

// ---------------- 目前時間與時區（target/tickets#4095、#4144）----------------
// 4144 裁定：時區固定 GMT+8、先不讓代理選擇，等時區搬到 apis 後再一次開放，
// 因此這裡只保留常駐顯示、不提供任何切換入口（store 由 bootstrap 釘死）。
// 各頁的平移接線維持原樣即可 —— toApiTime/fromApiTime 在 T=+8 是恆等函式。
// 顯示格式依工單附圖：2026-08-17 15:46:42 (+0800)，每秒更新。
const nowLabel = ref('');
let nowTimer: ReturnType<typeof setInterval> | undefined;
/** 偏移小時數 → RFC 822 式後綴，如 +8 → (+0800)。日後放開時自動跟著走。 */
function tzSuffix(offsetHours: number) {
  const sign = offsetHours >= 0 ? '+' : '-';
  return `(${sign}${String(Math.abs(offsetHours)).padStart(2, '0')}00)`;
}
function refreshNowLabel() {
  const offset = currentTzOffset();
  nowLabel.value = `${nowInUserTz(offset).format('YYYY-MM-DD HH:mm:ss')} ${tzSuffix(offset)}`;
}
onMounted(() => {
  refreshNowLabel();
  nowTimer = setInterval(refreshNowLabel, 1000);
});
onBeforeUnmount(() => {
  if (nowTimer) clearInterval(nowTimer);
});

// ---------------- 主題（深色）切換----------------
const themeModes = computed<
  Array<{ label: string; value: 'auto' | 'dark' | 'light' }>
>(() => [
  { label: $t('preferences.theme.light'), value: 'light' },
  { label: $t('preferences.theme.dark'), value: 'dark' },
  { label: $t('preferences.followSystem'), value: 'auto' },
]);
const currentThemeMode = computed(() => preferences.theme.mode);
const currentThemeName = computed(
  () =>
    themeModes.value.find((t) => t.value === currentThemeMode.value)?.label ??
    '',
);
// 使用者下拉內主題子選項展開狀態
const themeExpanded = ref(false);
function handleSelectTheme(mode: 'auto' | 'dark' | 'light') {
  updatePreferences({ theme: { mode } });
}

async function handleLogout() {
  await authStore.logout();
}

// ---------------- 使用者下拉（ElDropdown）----------------
const userDropdownRef = ref<{ handleClose?: () => void }>();
/** 點選單項目：執行動作後關閉下拉 */
function runMenu(handler: () => void) {
  handler();
  userDropdownRef.value?.handleClose?.();
}
/** 登出前先確認 */
async function confirmLogout() {
  userDropdownRef.value?.handleClose?.();
  try {
    await ElMessageBox.confirm(
      $t('ui.widgets.logoutTip'),
      $t('common.prompt'),
      {
        cancelButtonText: $t('common.cancel'),
        confirmButtonText: $t('common.confirm'),
        type: 'warning',
      },
    );
  } catch {
    return;
  }
  await handleLogout();
}

// 鈴鐺 → 後台通知頁（最新消息 / 系統通知）。
function goNoticePage() {
  router.push('/backend_notice/index');
}

const handleClick = (item: NotificationItem) => {
  // 點過今日註冊就當作看過，徽章不再把這些新增算進去、也不再閃
  if (item.id === TODAY_REGISTER_KEY) registerSeen.value = registerCount.value;
  // 如果通知项有链接，点击时跳转
  if (item.link) {
    navigateTo(item.link, item.query, item.state);
  }
};

function navigateTo(
  link: string,
  query?: Record<string, any>,
  state?: Record<string, any>,
) {
  if (link.startsWith('http://') || link.startsWith('https://')) {
    // 外部链接，在新标签页打开
    window.open(link, '_blank');
  } else {
    // 内部路由链接，支持 query 参数和 state
    router.push({
      path: link,
      query: query || {},
      state,
    });
  }
}

watch(
  () => ({
    enable: example.invalid.watermark,
    content: example.invalid.watermarkContent,
    isDark: isDark.value,
  }),
  async ({ enable, content, isDark: isDarkValue }) => {
    if (enable) {
      const watermarkColor = isDarkValue
        ? 'rgba(255, 255, 255, 0.12)'
        : 'rgba(0, 0, 0, 0.12)';

      await updateWatermark({
        advancedStyle: {
          colorStops: [
            {
              color: watermarkColor,
              offset: 0,
            },
            {
              color: watermarkColor,
              offset: 1,
            },
          ],
          type: 'linear',
        },
        content:
          content ||
          `${userStore.userInfo?.username} - ${userStore.userInfo?.realName}`,
      });
    } else {
      destroyWatermark();
    }
  },
  {
    immediate: true,
  },
);
</script>

<template>
  <BasicLayout
    :notice-bar-height="marquees.length > 0 ? 32 : 0"
    @clear-preferences-and-logout="handleLogout"
  >
    <template #header-left-5>
      <!-- 全域搜尋（改放左側） -->
      <GlobalSearch
        :enable-shortcut-key="globalSearchShortcutKey"
        :menus="accessStore.accessMenus"
        class="ml-2"
      />
    </template>
    <template #notice-bar>
      <div
        v-if="marquees.length > 0"
        class="marquee-bar mx-3 flex min-w-0 flex-1 items-center overflow-hidden"
      >
        <div
          ref="marqueeTrackRef"
          class="marquee-track"
          :style="{ animationDuration: marqueeDuration }"
        >
          <span
            v-for="(m, i) in marquees"
            :key="i"
            class="marquee-item"
            :class="{ 'marquee-important': m.important }"
          >
            {{ m.content }}
          </span>
        </div>
      </div>
    </template>
    <template #header-right-5>
      <!-- 目前時間＋時區常駐標籤（target/tickets#4144）：時區固定 GMT+8 不開放切換，
           純顯示不可點；同時是匯出與畫面時間口徑的可見依據 -->
      <span
        :title="tl('timezone', 'Timezone')"
        class="text-muted-foreground mr-2 hidden items-center rounded-md px-1.5 py-1 text-xs font-medium tabular-nums select-none sm:flex"
      >
        <Clock class="mr-1 size-4" />
        {{ nowLabel }}
      </span>
      <!-- 提示訊息選單（各通知計數下拉，對齊舊版）；待審項目權限全無時整顆隱藏（target/tickets#4453） -->
      <ElDropdown
        v-if="hasAnyNoticeAccess"
        trigger="click"
        class="mr-3"
        max-height="60vh"
      >
        <!-- 提示訊息 icon + 右上角紅色徽章（桌機/手機一致） -->
        <span
          class="relative flex cursor-pointer items-center rounded-md p-1.5 text-foreground hover:bg-accent"
        >
          <ListChecks class="size-5" />
          <span
            v-if="noticeTotal > 0"
            :class="{
              'notice-badge-alert': noticeBlink,
              'notice-blink': noticeBlink,
            }"
            class="notice-badge absolute -top-1 -right-1"
          >
            {{ noticeTotal }}
          </span>
        </span>
        <template #dropdown>
          <ElDropdownMenu>
            <!-- 語音提示開關（點擊不關閉選單） -->
            <div
              class="flex items-center justify-between border-b border-border px-4 py-2 text-sm"
              @click.stop
            >
              <span class="text-muted-foreground">{{ tl('voice') }}</span>
              <ElSwitch
                v-model="soundEnabled"
                size="small"
                @change="onSoundToggle"
              />
            </div>
            <ElDropdownItem
              v-for="n in notifications"
              :key="n.id"
              @click="handleClick(n)"
            >
              <span class="flex w-full items-center">
                <span>{{ n.title }}</span>
                <span
                  class="notice-badge ml-auto"
                  :class="{ 'notice-badge-alert': !n.isRead }"
                >
                  {{ n.message }}
                </span>
              </span>
            </ElDropdownItem>
            <ElDropdownItem v-if="notifications.length === 0" disabled>
              {{ tl('no_data') }}
            </ElDropdownItem>
          </ElDropdownMenu>
        </template>
      </ElDropdown>
    </template>
    <template #header-right-150>
      <!-- 分享碼／餘額常駐顯示（target/tickets#4317）：順序 分享碼 → 餘額 → 通知鈴 → 帳號名 -->
      <a
        v-if="shareCode && shareDomain"
        class="mr-3 flex cursor-pointer items-center whitespace-nowrap text-sm hover:text-primary"
        @click="qrVisible = true"
      >
        <span class="text-muted-foreground">{{ tl('sharecode') }}：</span>
        <span class="font-medium text-primary">{{ shareCode }}</span>
      </a>
      <span class="mr-3 flex items-center whitespace-nowrap text-sm">
        <span class="text-muted-foreground">{{ tl('balance') }}：</span>
        <span
          class="cursor-pointer font-medium tabular-nums underline decoration-dashed underline-offset-4 hover:text-primary"
          :class="{ 'pointer-events-none opacity-70': balanceRefreshing }"
          @click="refreshBalanceByClick"
        >
          {{ balanceText }}
        </span>
        <IconifyIcon
          v-if="balanceRefreshing"
          icon="lucide:loader-circle"
          class="ml-1 size-4 animate-spin"
        />
      </span>
    </template>
    <template #user-dropdown>
      <ElDropdown
        ref="userDropdownRef"
        trigger="click"
        placement="bottom-end"
        :hide-on-click="false"
        popper-class="user-dropdown-popper"
        @visible-change="onUserDropdownVisibleChange"
      >
        <span
          class="mr-2 ml-1 flex max-w-40 cursor-pointer items-center truncate rounded-md px-2 py-1 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
        >
          {{ userStore.userInfo?.username }}
        </span>
        <template #dropdown>
          <ElDropdownMenu>
            <!-- 帳號名稱 -->
            <div class="border-b border-border px-4 py-2">
              <div class="text-sm font-medium text-foreground">
                {{ userStore.userInfo?.username }}
              </div>
            </div>
            <!-- 選單項目（設定 / 修改手機 / 支付密碼…） -->
            <ElDropdownItem
              v-for="m in menus"
              :key="m.text"
              @click="runMenu(m.handler)"
            >
              <IconifyIcon :icon="m.icon" class="mr-2 size-4" />
              {{ m.text }}
            </ElDropdownItem>
            <!-- 主題切換（點擊展開） -->
            <ElDropdownItem divided @click="themeExpanded = !themeExpanded">
              <MoonStar class="mr-2 size-4" />
              {{ $t('preferences.theme.title') }}
              <span class="ml-auto pl-4 text-xs text-muted-foreground">
                {{ currentThemeName }}
              </span>
            </ElDropdownItem>
            <template v-if="themeExpanded">
              <ElDropdownItem
                v-for="t in themeModes"
                :key="t.value"
                :class="{
                  'font-bold text-primary': t.value === currentThemeMode,
                }"
                @click="handleSelectTheme(t.value)"
              >
                <span class="pl-6">{{ t.label }}</span>
              </ElDropdownItem>
            </template>
            <!-- 語言切換（點擊開彈窗選擇） -->
            <ElDropdownItem divided @click="openLangDialog">
              <Languages class="mr-2 size-4" />
              {{ tl('language') }}
              <span class="ml-auto pl-4 text-xs text-muted-foreground">
                {{ currentLangName }}
              </span>
            </ElDropdownItem>
            <!-- 千位數 -->
            <ElDropdownItem divided @click="kUnitStore.toggle()">
              <IconifyIcon icon="ep:coin" class="mr-2 size-4" />
              {{ tl('thousands') }}
              <ElSwitch
                :model-value="kUnitStore.enabled"
                size="small"
                class="pointer-events-none ml-auto pl-4"
              />
            </ElDropdownItem>
            <!-- 登出 -->
            <ElDropdownItem divided @click="confirmLogout">
              <LogOut class="mr-2 size-4" />
              {{ $t('common.logout') }}
            </ElDropdownItem>
          </ElDropdownMenu>
        </template>
      </ElDropdown>
    </template>
    <template #notification>
      <!-- 鈴鐺 → 後台通知頁（最新消息 / 系統通知），紅點=未讀數（>99 顯示 99+） -->
      <span
        class="relative mr-1 flex cursor-pointer items-center rounded-md p-1.5 hover:bg-accent"
        @click="goNoticePage"
      >
        <Bell class="size-5" />
        <span
          v-if="backendNoticeUnread > 0"
          class="notice-badge absolute -right-1 -top-1"
        >
          {{ backendNoticeUnread > 99 ? '99+' : backendNoticeUnread }}
        </span>
      </span>
    </template>
    <template #extra>
      <AuthenticationLoginExpiredModal
        v-model:open="accessStore.loginExpired"
        :avatar
      >
        <LoginForm />
      </AuthenticationLoginExpiredModal>
    </template>
  </BasicLayout>

  <ElDialog v-model="qrVisible" align-center width="360">
    <div class="text-center">
      <p v-if="shareCodeSpecialDomain" class="mb-1 text-sm">
        {{ shareCodeSpecialDomain }}/{{ shareCode }}
      </p>
      <p class="mb-3 text-sm font-semibold">
        {{ tl('sharecode') }} - {{ shareCode }}
      </p>
      <img
        v-if="shareQr"
        :src="shareQr"
        alt="share qrcode"
        class="mx-auto"
        width="300"
        height="300"
      />
    </div>
  </ElDialog>

  <!-- 語言選擇彈窗（14 語系格狀） -->
  <ElDialog
    v-model="langVisible"
    :title="tl('language')"
    align-center
    width="440"
  >
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
      <div
        v-for="l in languageList"
        :key="l.code"
        class="cursor-pointer rounded border px-3 py-2 text-center text-sm transition-colors hover:border-primary hover:text-primary"
        :class="
          l.code === currentLangCode
            ? 'border-primary font-bold text-primary'
            : 'border-border'
        "
        @click="handleSelectLanguage(l.code)"
      >
        {{ l.name }}
      </div>
    </div>
  </ElDialog>

  <!-- 首登強制設定全域覆蓋層（改密 / 設定支付密碼）：完成前鎖住整個畫面 -->
  <ForcedSetupOverlay />
  <AgentPasswordDialog ref="passwordDialogRef" />
  <TwoFaSetupDialog ref="twoFaSetupRef" />
  <TwoFaOtpDialog ref="twoFaOtpRef" />

  <!-- 工單4274：會員詳情抽屜（全站單一實例；各頁經 MemberAccountLink 開啟） -->
  <MemberDetailDrawer />
</template>

<style scoped>
.marquee-bar {
  width: 100%;
}

.marquee-track {
  display: inline-flex;
  padding-left: 100%;
  white-space: nowrap;
  animation: marquee-scroll 30s linear infinite;
  will-change: transform;
}

.marquee-bar:hover .marquee-track {
  animation-play-state: paused;
}

.marquee-item {
  padding-right: 80px;
  font-size: 13px;
}

.marquee-important {
  font-weight: 700;
  color: hsl(var(--destructive));
}

@keyframes marquee-scroll {
  from {
    transform: translateX(0);
  }

  to {
    transform: translateX(-100%);
  }
}

/* 有待辦數量時閃爍（比照舊版） */
.notice-blink {
  animation: notice-blink 1s steps(1, end) infinite;
}

@keyframes notice-blink {
  50% {
    opacity: 0;
  }
}

/* 數量徽章（像手機 App 右上角）：預設灰底白字 */
.notice-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  font-size: 11px;
  font-weight: 700;
  line-height: 1;
  color: #fff;
  background: hsl(var(--muted-foreground));
  border-radius: 9999px;
}

/* 有待處理才轉紅底（鈴鐺徽章同時閃爍） */
.notice-badge-alert {
  background: hsl(var(--destructive));
}
</style>
