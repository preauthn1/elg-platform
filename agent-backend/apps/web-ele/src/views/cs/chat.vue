<script lang="ts" setup>
import type {
  CsFriend,
  CsMe,
  CsMessage,
  CsMessageTemplate,
  CsPagination,
  CsRoom,
  CsSummary,
  CsUserView,
} from '#/api/cs';
import type { CsTag } from '#/api/cs-tag';

import {
  computed,
  nextTick,
  onActivated,
  onBeforeUnmount,
  onDeactivated,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue';

import { Page } from '@vben/common-ui';
import { createIconifyIcon, FileText, ImagePlus } from '@vben/icons';
import { usePreferences } from '@vben/preferences';

import {
  useDebounceFn,
  useDocumentVisibility,
  useIntervalFn,
} from '@vueuse/core';
import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElEmpty,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTag,
} from 'element-plus';

import {
  createCsMessageTemplateApi,
  enterCsRoomApi,
  getCsContactListApi,
  getCsMeApi,
  getCsMessageTemplatesApi,
  getCsRoomHistoryApi,
  getCsRoomListApi,
  getCsSummaryApi,
  getCsUserViewApi,
  hideCsRoomApi,
  readCsMessageApi,
  recallCsMessageApi,
  recoverCsRoomApi,
  runCsBootstrap,
  sendCsMessageApi,
  takePendingChatUsername,
} from '#/api/cs';
import { getCsTagListApi } from '#/api/cs-tag';
import UserAvatar from '#/components/user-avatar.vue';
import { tl } from '#/locales';

import MemberAccountLink from '../member/components/member-account-link.vue';
import CsBankDialog from './components/bank-dialog.vue';
import CsContacts from './components/contacts.vue';
import CsFlowLimitDialog from './components/flow-limit-dialog.vue';
import CsNotesDrawer from './components/notes-drawer.vue';
import CsTagSelectDropdown from './components/tag-select-dropdown.vue';
import CsTagTabs from './components/tag-tabs.vue';
import CsTransferDialog from './components/transfer-dialog.vue';
import {
  disconnectCsSocket,
  initCsSocket,
  sendCsTyping,
  subscribeNewMessage,
  subscribeRoom,
} from './cs-socket';

defineOptions({ name: 'CsChat' });

const RedEnvelope = createIconifyIcon('rivet-icons:money');
/** 撤回：沿用舊後台的折返箭頭（target/tickets#4189） */
const UndoIcon = createIconifyIcon('mdi:undo-variant');

const { isMobile } = usePreferences();

const PER_PAGE = 30;
/** 「已隱藏」分頁走真分頁，每頁筆數 */
const HIDE_ROOMS_PER_PAGE = 50;
/** 批次隱藏一次併送的筆數：全部一起送會把後端與瀏覽器連線數打滿 */
const HIDE_BATCH_SIZE = 10;
const STATUS: Record<string, number> = { ai: 3, hide: 2, show: 1 };
/** 訊息可撤回時限：發送後 3 分鐘（target/tickets#4189；CS Socket 端有同樣把關） */
const RECALL_WINDOW_MS = 3 * 60 * 1000;

// 交易訊息類型 → 標籤 key + 顏色（type 3/6-11）。
// label 在 txOf() 渲染時才用 tl() 解析，避免模組載入時語系字典尚未就緒被凍結。
const TX: Record<number, { cls: string; key: string }> = {
  3: { cls: 'text-red-500', key: 'list.send_redEnvelope' },
  6: { cls: 'text-red-500', key: 'list.receive_redEnvelope' },
  7: { cls: 'text-green-500', key: 'list.send_redEnvelope' },
  8: { cls: 'text-red-500', key: 'message.transfer_gift' },
  9: { cls: 'text-yellow-500', key: 'list.transfer_makeUp' },
  10: { cls: 'text-green-500', key: 'cs.send_game_wallet' },
  11: { cls: 'text-red-500', key: 'cs.deduct_game_wallet' },
};
const TX_TYPES = new Set([3, 6, 7, 8, 9, 10, 11]);
// transaction_type==2 的房間，6/7/8/9/10 顯示為紅包圖卡
const ENVELOPE_TYPES = new Set([6, 7, 8, 9, 10]);

const me = ref<CsMe>({});
/**
 * 底部導覽（#4204）：原本的 mode（聊天／聯絡人）與 tab（顯示／隱藏／AI）
 * 收成四顆——「顯示」與「聊天」同義（都只看 status=1）故消失。
 */
const nav = ref<'ai' | 'chat' | 'contacts' | 'hide'>('chat');
/** 房間清單目前的狀態集合。聯絡人只替換左欄，清單語意沿用「聊天」（status=1） */
const tab = computed<'ai' | 'hide' | 'show'>(() => {
  if (nav.value === 'ai') return 'ai';
  return nav.value === 'hide' ? 'hide' : 'show';
});
const rooms = ref<CsRoom[]>([]);
/** room/list 的分頁資訊；進行中／AI 未帶分頁參數時只用 total 判斷是否被截斷 */
const roomsPagination = ref<CsPagination>({});
/** 「已隱藏」分頁的目前頁碼（其餘分頁不分頁，維持 1） */
const hidePage = ref(1);
/** 徽章彙總數字（#4204）：後端 /cs/api/summary 出；未部署前為 null、徽章不顯示 */
const summary = ref<CsSummary | null>(null);
// 標籤頻道（#4169）：啟用中的標籤清單 + 目前選中的頻道（'' = 全部）
const csTags = ref<CsTag[]>([]);
const activeTagId = ref<'' | number | string>('');
const roomsLoading = ref(false);
const bootstrapError = ref(false);
// 多選隱藏（僅「顯示」分頁）：選取的房間 code
const selectedCodes = ref<Set<string>>(new Set());
const hiding = ref(false);

const activeCode = ref('');
const activeRoomId = ref<number | string>('');
const activeMemberId = ref<number | string>('');
const notesOpen = ref(false);
const transferOpen = ref(false);
const flowLimitOpen = ref(false);
const bankOpen = ref(false);
const agentView = ref<CsUserView>({});
const roomInfo = ref<Record<string, any>>({});
const messages = ref<CsMessage[]>([]);
const msgLoading = ref(false);
const historyPage = ref(1);
const noMoreHistory = ref(false);
const peerTyping = ref(false);

const input = ref('');
const sending = ref(false);
/** 定期推進的「現在」，供撤回時限重新求值（target/tickets#4189） */
const recallTick = ref(Date.now());
const threadRef = ref<HTMLElement>();
const imageInput = ref<HTMLInputElement>();

const templates = ref<CsMessageTemplate[]>([]);
const showTpl = ref(false);
const tplSaving = ref(false);
const tplForm = reactive({ content: '', title: '' });

const status = computed(() => STATUS[tab.value] ?? 1);
const roomTt = computed(() => Number(roomInfo.value.transaction_type));
// AI 房間（status 3）：輸入區改為「恢復為人工」
const isAiRoom = computed(() => tab.value === 'ai');
// 手機板單欄切換：未選房顯示列表、選房顯示對話；桌機雙欄並存
const showListPane = computed(() => !isMobile.value || !activeCode.value);
const showThreadPane = computed(() => !isMobile.value || !!activeCode.value);
// 銀行帳號鈕：玩家客服諮詢房（isThird==1）且非 AI；
// #4567 營運商（CS role 2）不顯示（後端也拒絕營運商查詢），取不到角色時照常顯示
const showBankBtn = computed(
  () =>
    !isAiRoom.value &&
    Number(roomInfo.value.isThird) === 1 &&
    !isOperator.value,
);
// 銀行帳號可編輯：操作者為總代理(1)或客服(3)
const bankEditable = computed(
  () =>
    Number(agentView.value.role_id) === 1 ||
    Number(agentView.value.role_id) === 3,
);
// 訊息範本功能（圖標按鈕＋面板）：僅總代理(1)或客服(3)可見
const canUseTemplates = computed(
  () =>
    Number(agentView.value.role_id) === 1 ||
    Number(agentView.value.role_id) === 3,
);
// #4486 營運商（CS role 2）隱藏 AI 分頁、筆記、稽核流水；
// 取不到角色時照常顯示，不誤藏總代理與客服的功能
const isOperator = computed(() => Number(agentView.value.role_id) === 2);

let audio: HTMLAudioElement | undefined;
function playSound() {
  try {
    if (!audio) audio = new Audio('/cs-message.mp3');
    audio.currentTime = 0;
    void audio.play();
  } catch {
    // ignore
  }
}

function scrollToBottom() {
  nextTick(() => {
    const el = threadRef.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

/** 上一次 room/list 成功回來的時刻（Date.now()），供回前景刷新的節流判斷 */
let lastRoomsLoadedAt = 0;

/** 不吞錯誤的原始版本，bootstrap() 才需要知道有沒有真的失敗（供重試判斷） */
async function fetchRooms() {
  // 只有「已隱藏」帶分頁參數走真分頁；進行中／AI 不帶，後端回最近 N 間
  const paging =
    tab.value === 'hide'
      ? { page: hidePage.value, per_page: HIDE_ROOMS_PER_PAGE }
      : undefined;
  const res = await getCsRoomListApi(status.value, paging);
  rooms.value = res.data ?? [];
  roomsPagination.value = res.pagination ?? {};
  lastRoomsLoadedAt = Date.now();
}

/** 進行中／AI：後端回的總數大於實際筆數＝較舊的房間被截掉，要明講不要默默少 */
const roomsTruncated = computed(
  () =>
    tab.value !== 'hide' &&
    Number(roomsPagination.value.total ?? 0) > rooms.value.length,
);

/** 「已隱藏」翻頁：pagination 的 previous/next 為空代表已到頭 */
function turnHidePage(p: unknown) {
  if (p === '' || p === null || p === undefined) return;
  hidePage.value = Number(p);
  loadRooms();
}

/** 徽章彙總數字。端點失敗（後端未部署）就整批隱藏，不擋聊天主流程 */
async function loadSummary() {
  try {
    summary.value = await getCsSummaryApi();
  } catch {
    summary.value = null;
  }
}

/**
 * 去抖版彙總數字：summary 在 CS 後端是多表 join 的重量查詢，
 * 一則訊息就打一次會把後端拖垮（target/op_tickets#3179）。
 * 連續事件合併成 3 秒後一次，徽章數字慢個幾秒沒有影響。
 */
const loadSummaryDebounced = useDebounceFn(() => {
  // 去抖到期時分頁可能已回到背景或本頁已停用（實測回前景只閃 1 秒的情況），那就不打
  if (!canBackgroundRefresh()) return;
  void loadSummary();
}, 3000);

// ===== 客服標籤頻道（#4169，與玩家標籤 member_tags 是兩套）=====
/** 目前頻道下可見的房間：'' = 全部；其餘依會員身上的客服標籤純前端過濾 */
const visibleRooms = computed(() => {
  if (activeTagId.value === '') return rooms.value;
  return rooms.value.filter((r) =>
    (r.cs_tags ?? []).some(
      (t) => String(t.id ?? t.name ?? '') === String(activeTagId.value),
    ),
  );
});

/** 標籤清單載入失敗不擋聊天主流程（後端未就緒時頻道列直接不顯示） */
async function loadCsTags() {
  try {
    const tags = await getCsTagListApi({ status: 1 });
    csTags.value = tags.filter((t) => Number(t.status) === 1);
  } catch {
    csTags.value = [];
  }
}

/**
 * pin/unpin 樂觀更新時同步當前房與左側列表的客服標籤。
 * 這裡不立即 loadRooms 重撈——樂觀 emit 發生在 API 完成之前，
 * 重撈會跟未 commit 的寫入賽跑、用舊資料蓋掉樂觀值；
 * 之後的 socket 刷新／30 秒輪詢自然會對齊伺服器狀態。
 */
function onMemberTagsChange(
  tags: Array<Record<string, any>>,
  memberId: number | string,
) {
  // 樂觀值與失敗回滾都可能在切房之後才送達：對不上當前會員就丟棄，
  // 否則會把上一位會員的標籤寫進現在這間房與左側列表
  if (String(memberId) !== String(activeMemberId.value)) return;
  roomInfo.value.cs_tags = tags;
  const room = rooms.value.find((r) => r.code === activeCode.value);
  if (room) room.cs_tags = tags;
}
/** room/list 是否還在路上；背景刷新用來避免請求疊加（target/op_tickets#3179） */
let roomsInFlight = false;

/**
 * silent=true 用於 socket 觸發的背景刷新：不開 loading 遮罩、失敗也不清空，
 * 否則每收一則訊息左側列表就閃一次白。
 * 背景刷新另外有 in-flight 保護：後端一慢（DB 鎖等待）原本會一路疊加請求，
 * 把 CS 後端與瀏覽器連線數一起打滿；使用者主動觸發的（非 silent）照常送出。
 */
async function loadRooms(silent = false) {
  if (silent && roomsInFlight) return;
  roomsInFlight = true;
  if (!silent) roomsLoading.value = true;
  // 清單有動靜（輪詢／socket／切導覽）就順手刷新徽章數字，但走去抖版不逐次打
  void loadSummaryDebounced();
  try {
    await fetchRooms();
  } catch {
    if (!silent) rooms.value = [];
  } finally {
    roomsInFlight = false;
    if (!silent) roomsLoading.value = false;
  }
}

/**
 * 分頁在背景期間有 socket 事件說「清單裡沒有這間房」，不在背景打 room/list，
 * 只記下清單已過期；回前景那一次補撈不受 10 秒節流限制。
 * 背景分頁一律零請求：輪詢暫停、socket 事件也不觸發重撈（對齊舊版行為）。
 */
let roomsStaleWhileHidden = false;

/**
 * 本頁是否為 KeepAlive 目前啟用中的頁面。
 * 停用（客服切去看其他頁）時元件還活著，watch(documentVisibility)、window focus、
 * 去抖 timer 都照常觸發——實測切到用戶管理頁後仍每 30 秒打 room/list，就是
 * visibilitychange 把輪詢 resume 回來。所以「能不能發背景請求」要同時看
 * 分頁可見與本頁啟用兩個條件。
 */
let pageActive = false;

/** 背景刷新（輪詢／socket／回前景）允許發出的條件：分頁可見且本頁啟用中 */
function canBackgroundRefresh() {
  return !document.hidden && pageActive;
}

/**
 * 去抖版背景刷新：只給「清單裡沒有這間房」的 socket 事件用，
 * 一次進來一串新房間事件時合併成 2 秒後撈一次；到期時若不能發就只標過期。
 */
const loadRoomsDebounced = useDebounceFn(() => {
  if (!canBackgroundRefresh()) {
    roomsStaleWhileHidden = true;
    return;
  }
  void loadRooms(true);
}, 2000);

/** 回前景補撈的最小間隔：focus 與 visibilitychange 會連著觸發，不節流會撈兩次 */
const ROOMS_REFRESH_MIN_GAP_MS = 10_000;

/** 距上次成功載入不足 10 秒就不撈（回前景／window focus 共用同一把節流）；背景期間有漏掉的新房間則一定撈 */
function refreshRoomsThrottled() {
  if (roomsStaleWhileHidden) {
    roomsStaleWhileHidden = false;
    loadRooms(true);
    return;
  }
  if (Date.now() - lastRoomsLoadedAt < ROOMS_REFRESH_MIN_GAP_MS) return;
  loadRooms(true);
}

/** message_id 後端可能給數字或字串，一律轉字串比對 */
function sameMessageId(a?: number | string, b?: number | string) {
  return a !== undefined && b !== undefined && String(a) === String(b);
}

const hasVal = (v: unknown): v is number | string =>
  v !== undefined && v !== null && v !== '';

/** 排序鍵：message_id 可能是數字或字串；缺 id 的（樂觀顯示）留在最後 */
function messageSeq(m: CsMessage) {
  const n = Number(m.message_id);
  return Number.isFinite(n) ? n : Number.MAX_SAFE_INTEGER;
}

/** 本分頁送出過的 message_id：判定歸屬最可靠的依據，不受登入資料影響 */
const sentIds = new Set<string>();

/**
 * 訊息是否為自己發出（右側）。不能只比 me.member_id——部分環境（正式站）
 * CS 登入回應查不到 member_id 會給空字串，比對永遠不成立，自己的回音全被
 * 判成對方（重整後才正確，因為歷史訊息的 is_self 是後端算的）。
 * 依可靠度逐層退回：
 * 1. 本分頁送出過的 message_id
 * 2. 比對自己的 member_id（有值才比；行為同舊版）
 * 3. 反向比對房間對方的 member_id（房間一對一，發話者不是對方就是自己）
 * 4. 比對操作員 user_id（客服端發言都會帶）
 */
function resolveIsSelf(m: CsMessage): boolean {
  if (m.message_id !== undefined && sentIds.has(String(m.message_id))) {
    return true;
  }
  if (hasVal(me.value.member_id) && hasVal(m.member_id)) {
    return String(m.member_id) === String(me.value.member_id);
  }
  const other = rooms.value.find((r) => r.code === m.code)?.members;
  if (hasVal(other) && hasVal(m.member_id)) {
    return String(m.member_id) !== String(other);
  }
  if (hasVal(me.value.id) && hasVal(m.user_id)) {
    return String(m.user_id) === String(me.value.id);
  }
  return false;
}

// ===== socket 事件 =====
/**
 * 客服自己的 newMessage 私有頻道：任何房間有新訊息都會推來，
 * payload 形狀與房間頻道的 MessageSent 相同（{ message: {...}, code }）。
 * 房間已在清單裡就只做本地更新（預覽、時間、未讀、置頂），不重撈——
 * room/list 是七表 join 的重量查詢，一則訊息撈一份完整清單會把 CS 後端
 * 拖垮（target/op_tickets#3179）。只有清單裡還沒有這間房（新開的房間）
 * 才需要重撈，且走去抖版合併連續事件。
 */
function onNewMessage(payload: any) {
  const m: CsMessage = payload?.message ?? {};
  const code = m.code;
  if (code && rooms.value.some((r) => r.code === code)) {
    // read 的判定與 onRoomSent 一致：當前開著的房算已讀，其餘看是不是自己發的
    bumpRoomPreview(m, code === activeCode.value ? true : resolveIsSelf(m));
  } else if (document.hidden) {
    // 分頁在背景不重撈（對齊舊版），回前景由 refreshRoomsThrottled 補上
    roomsStaleWhileHidden = true;
  } else {
    // 沒有 code（形狀不符）也退回重撈，至少不會漏掉新訊息
    void loadRoomsDebounced();
  }
  if (code && code !== activeCode.value) playSound();
}
function onRoomSent(payload: any) {
  const m: CsMessage = payload?.message ?? {};
  if (!m.code) return;
  const isMine = resolveIsSelf(m);
  // 進過的房間都持續訂閱：非當前房間也就地更新左側列表與未讀，
  // 不依賴 newMessage 頻道（頻道名用登入回應的 member_id 算，缺值時訂不到）
  if (m.code !== activeCode.value) {
    bumpRoomPreview(m, isMine);
    if (!isMine) playSound();
    return;
  }
  m.is_self = isMine ? 0 : 1;
  const i = messages.value.findIndex((x) =>
    sameMessageId(x.message_id, m.message_id),
  );
  const existing = i === -1 ? undefined : messages.value[i];
  if (existing) {
    // 樂觀顯示的那則用伺服器版本原地取代（時間、圖片網址以伺服器為準），
    // 歸屬沿用原本的判定——回音絕不能把右側訊息翻到左側
    messages.value.splice(i, 1, {
      ...m,
      is_self: existing.is_self ?? m.is_self,
    });
  } else {
    messages.value.push(m);
  }
  bumpRoomPreview(m, true);
  scrollToBottom();
  if (!isMine) playSound();
}

/** 已累計過未讀的 message_id；超過這個量就整批清掉，長時間掛著的分頁不要無限成長 */
const UNREAD_COUNTED_IDS_MAX = 500;
const unreadCountedIds = new Set<string>();

/**
 * 佔用某則訊息的「未讀已計」名額：第一次呼叫回 true（這次可以加未讀），
 * 同一 message_id 再呼叫回 false。
 * newMessage（客服自己的頻道）與 MessageSent（進過的房間頻道）會推同一則
 * 訊息，兩邊都會呼叫 bumpRoomPreview，未讀數不能被加兩次。
 * 沒有 message_id 時無從去重，一律放行（維持原本行為）。
 */
function claimUnreadCount(messageId?: number | string): boolean {
  if (messageId === undefined || messageId === null || messageId === '') {
    return true;
  }
  const id = String(messageId);
  if (unreadCountedIds.has(id)) return false;
  if (unreadCountedIds.size >= UNREAD_COUNTED_IDS_MAX) {
    unreadCountedIds.clear();
  }
  unreadCountedIds.add(id);
  return true;
}

/**
 * 左側列表的最後一則訊息就地更新並移到最前；read=false 標為未讀（紅點）。
 * 房間列表原本只在 newMessage 廣播抵達時整份重撈，送出後要等好幾秒才會變。
 */
function bumpRoomPreview(m: CsMessage, read: boolean) {
  const room = rooms.value.find((r) => r.code === m.code);
  if (!room) return;
  room.message_content = m.content;
  room.message_time = m.created_at ?? m.time ?? room.message_time;
  room.message_read = read ? 1 : 0;
  // 未讀徽章（#4204）在下次 room/list 刷新前先就地推進，跟紅點同節奏
  if (room.unread_count !== undefined) {
    if (read) {
      room.unread_count = 0;
    } else if (claimUnreadCount(m.message_id)) {
      room.unread_count = Number(room.unread_count) + 1;
    }
  }
  const i = rooms.value.indexOf(room);
  if (i > 0) rooms.value.unshift(...rooms.value.splice(i, 1));
}
function onRoomRecalled(payload: any) {
  const id = payload?.message?.message_id;
  if (id === undefined || id === null) return;
  messages.value = messages.value.filter(
    (x) => !sameMessageId(x.message_id, id),
  );
  // 被收回的可能是列表預覽的最後一則，靜默重撈同步（對齊舊版）
  loadRooms(true);
}
let typingResetTimer: any;
function onPeerTyping(data: any) {
  peerTyping.value = Number(data?.is_typing) === 1;
  clearTimeout(typingResetTimer);
  if (peerTyping.value) {
    typingResetTimer = setTimeout(() => (peerTyping.value = false), 3000);
  }
}

/**
 * 開啟對話：code 與 friend_id 擇一即可（後端 room/enter 都給以 code 為主）。
 * 房號一律以回應的 room.code 為準——從聯絡人進來時前端還不知道房號
 * （沒聊過的好友甚至還沒有房間，是這一步才建的）。
 */
async function openRoom(p: {
  code?: string;
  friendId?: number | string;
  memberId?: number | string;
  roomId?: number | string;
}) {
  if (!p.code && !hasVal(p.friendId)) return;
  activeCode.value = p.code ?? '';
  activeMemberId.value = p.memberId ?? '';
  peerTyping.value = false;
  typingSent = false;
  msgLoading.value = true;
  historyPage.value = 1;
  noMoreHistory.value = false;
  try {
    const res = await enterCsRoomApi({
      code: p.code,
      friend_id: p.friendId,
    });
    roomInfo.value = res.room ?? {};
    // memberId 以列表帶進來的為主；從聯絡人進來時可能沒有，退回房間詳情的
    // member_id——少了它，標頭的客服標籤下拉整個不會渲染（改不了標籤）
    if (!hasVal(activeMemberId.value)) {
      activeMemberId.value = res.room?.member_id ?? '';
    }
    messages.value = res.message ?? [];
    const code = res.room?.code || p.code || '';
    activeCode.value = code;
    // 房間列表點進來的以列表的 id 為主（行為同舊版）；聯絡人進來的只能靠回應
    activeRoomId.value = p.roomId ?? res.room?.id ?? '';
    const last = messages.value.at(-1);
    if (code && last?.message_id) {
      readCsMessageApi({ code, message_id: last.message_id }).catch(() => {});
    }
    const listed = rooms.value.find((r) => r.code === code);
    if (listed) {
      listed.message_read = 1;
      listed.unread_count = 0;
    }
    if (code) {
      subscribeRoom(code, {
        onRecalled: onRoomRecalled,
        onSent: onRoomSent,
        onTyping: onPeerTyping,
      });
    }
    scrollToBottom();
  } catch {
    // 失敗就退回未選房，否則對話區會停在空白（或上一間房）的殘留狀態。
    // 錯誤訊息不在這裡彈——requestClient 的全域攔截器已經彈過一次了
    activeCode.value = '';
    messages.value = [];
  } finally {
    msgLoading.value = false;
  }
}

function enterRoom(room: CsRoom) {
  return openRoom({
    code: room.code,
    friendId: room.friend_id,
    memberId: room.members,
    roomId: room.id,
  });
}

/** 房間成員：後端 room/list 的 members 可能是逗號分隔的清單（"1,2,3"），逐個比對 */
function roomHasMember(room: CsRoom, memberId: number | string) {
  return String(room.members ?? '')
    .split(',')
    .some((id) => id.trim() === String(memberId));
}

/**
 * 工單4242：帳號管理「聊天」欄入口——目標帳號由 setPendingChatUsername 跨頁帶入，
 * 以 username 比對（CS 端與 apis 的數字 id 是兩套體系）：有房直接進；沒房用聯絡人
 * 搜尋換 friend_id，交給後端 room/enter 開新房。
 */
async function openMemberRoomFromEntry() {
  const username = takePendingChatUsername();
  if (!username) return;
  const room = rooms.value.find((r) => r.username === username);
  if (room) {
    enterRoom(room);
    return;
  }
  try {
    // 先搜「客戶」（聊過的）再搜「好友」，精確比對帳號
    for (const customer of [true, false]) {
      const res = await getCsContactListApi({
        customer,
        fuzzy: false,
        page: 1,
        username,
      });
      const friend = (res.data ?? []).find(
        (f) => f.username === username && hasVal(f.friend_id),
      );
      if (friend) {
        await openRoom({
          friendId: friend.friend_id,
          memberId: friend.member_id,
        });
        // 新開的房補進左欄列表
        await loadRooms(true);
        return;
      }
    }
  } catch {
    // fallthrough：統一走下方錯誤提示
  }
  ElMessage.error(tl('cs.open_chat_failed'));
}

/**
 * 聯絡人 → 開啟對話（#4157）。沒聊過的好友還沒有房間，只有 friend_id，
 * 交給後端找／開房；客戶清單若沒帶 friend_id，退回用房間列表比對 member_id。
 */
async function openContactRoom(friend: CsFriend) {
  const memberId = friend.member_id;
  const code = hasVal(memberId)
    ? rooms.value.find((r) => roomHasMember(r, memberId))?.code
    : undefined;
  if (!hasVal(friend.friend_id) && !code) {
    ElMessage.error(tl('cs.open_chat_failed'));
    return;
  }
  // #4204 起聯絡人只佔左欄、對話區常駐，點聯絡人直接在右邊開房，不再切走
  await openRoom({
    code,
    friendId: friend.friend_id,
    memberId: friend.member_id,
  });
  // 沒配到既有房間＝這次才建的房，刷新讓左側列表也看得到
  if (!code && activeCode.value) await loadRooms(true);
}

async function loadOlder() {
  if (!activeCode.value || noMoreHistory.value) return;
  const next = historyPage.value + 1;
  let older: CsMessage[];
  try {
    older = await getCsRoomHistoryApi({
      code: activeCode.value,
      page: next,
      per_page: PER_PAGE,
    });
  } catch {
    // 網路／後端錯誤不能當成「沒有更早訊息」，否則一次失敗就永久關掉載入更早
    ElMessage.error(tl('cs.load_failed'));
    return;
  }
  if (older.length === 0) {
    noMoreHistory.value = true;
    return;
  }
  // room/history 是 message_id desc、room/enter 是 asc，合併後一律按 id 重排，
  // 不依賴端點給的方向
  messages.value = [...older, ...messages.value].toSorted(
    (a, b) => messageSeq(a) - messageSeq(b),
  );
  historyPage.value = next;
}

/**
 * 對齊舊版 messageActions.js：只在「有字／沒字」翻轉時送一次，
 * 不是每隔一段時間就重送。
 */
let typingSent = false;
function setTyping(typing: boolean) {
  if (!activeCode.value || typing === typingSent) return;
  typingSent = typing;
  sendCsTyping(activeCode.value, typing);
}

/** 後端時間一律 UTC+8、格式 YYYY-MM-DD HH:mm:ss，樂觀顯示時比照，免得跟其他訊息格式不一致 */
function serverNow() {
  return new Date(Date.now() + 8 * 3_600_000)
    .toISOString()
    .slice(0, 19)
    .replace('T', ' ');
}

/**
 * 訊息時間（UTC+8 字串）轉成毫秒。Date.parse 會把無時區的字串當本地時間，
 * 故補回 +08:00 才不會因瀏覽器時區而算錯（target/tickets#4189）。
 */
function messageTimeMs(m: CsMessage): number {
  const raw = (m.created_at || m.time || '').trim();
  if (!raw) return Number.NaN;
  return Date.parse(`${raw.replace(' ', 'T')}+08:00`);
}

/**
 * 送出成功先把自己的訊息顯示出來，不必等 socket 回音（實測會慢好幾秒）。
 * 靠後端回的 message_id 去重：socket 回音抵達時 onRoomSent 會原地取代這一則。
 * 拿不到 message_id 就不樂觀顯示——沒有 id 就無法去重，會變成兩則。
 */
function pushSelfMessage(
  content: string,
  type: number,
  messageId?: number | string,
) {
  if (messageId === undefined || messageId === null) return;
  sentIds.add(String(messageId));
  // 回音比 HTTP 回應先到：那則已在列表裡，把歸屬修正成自己即可
  const existing = messages.value.find((x) =>
    sameMessageId(x.message_id, messageId),
  );
  if (existing) {
    existing.is_self = 0;
    return;
  }
  const m: CsMessage = {
    code: activeCode.value,
    content,
    created_at: serverNow(),
    is_self: 0,
    message_id: messageId,
    type,
  };
  messages.value.push(m);
  bumpRoomPreview(m, true);
  scrollToBottom();
}

async function send() {
  const content = input.value.trim();
  const code = activeCode.value;
  if (!content || !code) return;
  // 先清空輸入框再送，不必等往返（送失敗才把文字放回去）。
  // 也因此連打 Enter 不會重送同一句，不需要用 sending 擋住下一則。
  input.value = '';
  sending.value = true;
  try {
    const res = await sendCsMessageApi({ code, content, type: 1 });
    if (Number(res.code) === 1000) {
      pushSelfMessage(content, 1, res.data?.message_id);
    } else {
      input.value = input.value || content;
      ElMessage.error(res.message || tl('cs.send_failed'));
    }
  } catch (error: any) {
    input.value = input.value || content;
    ElMessage.error(error?.message || tl('cs.send_failed'));
  } finally {
    sending.value = false;
  }
}

// ===== 圖片（上傳 + 貼上）=====
// 送出前先縮到 1200px、JPEG 0.7，避免過大的 base64
function resizeImage(
  src: string,
  width = 1200,
  quality = 0.7,
): Promise<string> {
  return new Promise((resolve) => {
    const img = new Image();
    img.addEventListener('load', () => {
      const w = Math.min(img.width || width, width);
      const h = img.width ? (w / img.width) * img.height : img.height;
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      if (!ctx) {
        resolve(src);
        return;
      }
      ctx.drawImage(img, 0, 0, w, h);
      resolve(canvas.toDataURL('image/jpeg', quality));
    });
    img.addEventListener('error', () => resolve(src));
    img.src = src;
  });
}

async function sendImageBase64(base64: string) {
  if (!activeCode.value || sending.value) return;
  sending.value = true;
  try {
    const res = await sendCsMessageApi({
      code: activeCode.value,
      content: base64,
      type: 2,
    });
    if (Number(res.code) === 1000) {
      // 樂觀顯示的是本地 base64，socket 回音會帶伺服器上傳後的網址原地取代
      pushSelfMessage(base64, 2, res.data?.message_id);
    } else {
      ElMessage.error(res.message || tl('cs.send_failed'));
    }
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.send_failed'));
  } finally {
    sending.value = false;
  }
}
function pickImage() {
  imageInput.value?.click();
}
function onImagePicked(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (imageInput.value) imageInput.value.value = '';
  if (!file || !activeCode.value) return;
  if (!file.type.startsWith('image/')) {
    ElMessage.warning(tl('cs.image_only'));
    return;
  }
  const reader = new FileReader();
  reader.addEventListener('load', () => {
    void resizeImage(String(reader.result)).then((b) => sendImageBase64(b));
  });
  reader.readAsDataURL(file);
}

// 貼上圖片：先開預覽彈窗確認再送
const pasteImage = reactive({ base64: '', visible: false });
function onPaste(event: ClipboardEvent) {
  const items = event.clipboardData?.items;
  if (!items || !activeCode.value) return;
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      event.preventDefault();
      const file = item.getAsFile();
      if (!file) return;
      const reader = new FileReader();
      reader.addEventListener('load', () => {
        pasteImage.base64 = String(reader.result);
        pasteImage.visible = true;
      });
      reader.readAsDataURL(file);
      return;
    }
  }
}
function cancelPasteImage() {
  pasteImage.visible = false;
  pasteImage.base64 = '';
}
async function confirmPasteImage() {
  if (!pasteImage.base64) return;
  const resized = await resizeImage(pasteImage.base64);
  pasteImage.visible = false;
  pasteImage.base64 = '';
  await sendImageBase64(resized);
}
function openImage(url: string) {
  if (url) window.open(url, '_blank', 'noopener');
}

// ===== 訊息範本 =====
/** notify：初始化時靜默載入，使用者自己觸發的重載才提示失敗 */
async function loadTemplates(notify = true) {
  try {
    templates.value = await getCsMessageTemplatesApi();
  } catch {
    templates.value = [];
    if (notify) ElMessage.error(tl('cs.load_failed'));
  }
}
function useTemplate(t: CsMessageTemplate) {
  input.value = `${input.value}${t.content ?? ''}`;
  showTpl.value = false;
}
async function addTemplate() {
  if (tplSaving.value) return;
  if (!tplForm.title || !tplForm.content) {
    ElMessage.warning(tl('cs.enter_title_content'));
    return;
  }
  tplSaving.value = true;
  try {
    await createCsMessageTemplateApi({
      content: tplForm.content,
      title: tplForm.title,
    });
    tplForm.title = '';
    tplForm.content = '';
    await loadTemplates();
    ElMessage.success(tl('cs.template_added'));
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.add_failed'));
  } finally {
    tplSaving.value = false;
  }
}

async function recall(msg: CsMessage) {
  if (!msg.message_id) return;
  try {
    await ElMessageBox.confirm(
      tl('cs.confirm_recall_message'),
      tl('cs.recall'),
      {
        type: 'warning',
      },
    );
  } catch {
    return;
  }
  try {
    await recallCsMessageApi(msg.message_id);
    messages.value = messages.value.filter(
      (m) => m.message_id !== msg.message_id,
    );
    ElMessage.success(tl('cs.recalled'));
  } catch (error: any) {
    ElMessage.error(error?.message || tl('cs.recall_failed'));
  }
}

async function recoverRoom(room: CsRoom) {
  await recoverCsRoomApi(room.code).catch(() => {});
  await loadRooms();
}

// ===== 多選隱藏（僅「顯示」分頁，比照原本客服的全選/隱藏列） =====
// 全選只作用在目前標籤頻道可見的房間，避免把被過濾掉的房間一起隱藏
const allSelected = computed(
  () =>
    visibleRooms.value.length > 0 &&
    visibleRooms.value.every((r) => r.code && selectedCodes.value.has(r.code)),
);
function toggleSelectAll() {
  selectedCodes.value = allSelected.value
    ? new Set()
    : new Set(
        visibleRooms.value.map((r) => r.code).filter(Boolean) as string[],
      );
}
function toggleSelect(
  code: string | undefined,
  checked: boolean | number | string,
) {
  if (!code) return;
  const next = new Set(selectedCodes.value);
  if (checked) next.add(code);
  else next.delete(code);
  selectedCodes.value = next;
}
async function bulkHideRooms() {
  if (hiding.value || selectedCodes.value.size === 0) return;
  hiding.value = true;
  try {
    const codes = [...selectedCodes.value];
    // 每批 10 個依序送，避免一次併發數百個請求
    for (let i = 0; i < codes.length; i += HIDE_BATCH_SIZE) {
      await Promise.all(
        codes
          .slice(i, i + HIDE_BATCH_SIZE)
          .map((code) => hideCsRoomApi(code).catch(() => {})),
      );
    }
    if (selectedCodes.value.has(activeCode.value)) activeCode.value = '';
    selectedCodes.value = new Set();
    await loadRooms();
  } finally {
    hiding.value = false;
  }
}
// AI 房間取回人工：恢復為 status 1 後從 AI 列表移除
async function recoverAiRoom() {
  if (!activeCode.value) return;
  await recoverCsRoomApi(activeCode.value).catch(() => {});
  ElMessage.success(tl('cs.restored_to_manual'));
  activeCode.value = '';
  await loadRooms();
}

/**
 * 底部導覽切換（#4204，取代原 onTabChange）。聊天↔聯絡人之間狀態集合不變
 * （都是 status=1），保留當前對話；切到 AI／已隱藏（或從其離開）才清對話並
 * 重撈清單。勾選不再清空——原本 onTabChange 會把選到一半的勾選無聲清掉
 * （分頁列就在批次列正上方、很容易誤觸）；批次列只在「聊天」清單顯示，
 * 切走再回來勾選仍在。
 */
function onNavChange(next: 'ai' | 'chat' | 'contacts' | 'hide') {
  if (nav.value === next) return;
  const prevTab = tab.value;
  nav.value = next;
  if (tab.value !== prevTab) {
    activeCode.value = '';
    messages.value = [];
    hidePage.value = 1;
    // 一併清掉舊分頁資訊：留著的話 hide→show 會拿舊 total 閃一下截斷橫幅，
    // show→hide 則會先顯示上一個分頁的頁碼
    roomsPagination.value = {};
    // 切到已隱藏分頁時放掉選中的標籤頻道：該分頁不顯示頻道列，
    // 若還帶著上一個分頁選的頻道，visibleRooms 會把整頁篩成空
    if (tab.value === 'hide') activeTagId.value = '';
    loadRooms();
  }
}

// 角色載入前已切到 AI 分頁時，確認是營運商就退回聊天（#4486）
watch(isOperator, (op) => {
  if (op && nav.value === 'ai') onNavChange('chat');
});

watch(input, (value) => setTyping(value.trim() !== ''));

// ===== 底部導覽與徽章（#4204）=====
const NAV_ITEMS = [
  { emoji: '💬', key: 'chat', labelKey: 'menu.chat' },
  { emoji: '👥', key: 'contacts', labelKey: 'cs.contacts' },
  { emoji: '🤖', key: 'ai', labelKey: '' },
  { emoji: '📦', key: 'hide', labelKey: 'cs.hidden_rooms' },
] as const;

// 營運商不顯示 AI 分頁（#4486），其餘按鈕 flex-1 自動平均分寬
const navItems = computed<(typeof NAV_ITEMS)[number][]>(() =>
  isOperator.value ? NAV_ITEMS.filter((i) => i.key !== 'ai') : [...NAV_ITEMS],
);

/** AI 不進語系檔（各語系皆為 AI） */
function navLabel(item: (typeof NAV_ITEMS)[number]): string {
  return item.labelKey ? tl(item.labelKey) : 'AI';
}

/** 未讀類數字上限 99+，避免三位數撐破版面（標籤、導覽、房間列同規則） */
function cap99(n: number | string | undefined): string {
  const v = Number(n ?? 0);
  return v > 99 ? '99+' : String(v);
}

function roomUnread(room: CsRoom): number {
  return Number(room.unread_count ?? 0);
}

/** 導覽「聊天」的進行中未讀（紅徽章）；人在 AI／已隱藏時前端拿不到，只能由彙總出 */
const showUnread = computed(() =>
  Number(summary.value?.status?.show_unread ?? 0),
);
/** 導覽「AI」的總對話數（青藍徽章，參考資訊，刻意與紅色未讀不同色相） */
const aiTotal = computed(() => Number(summary.value?.status?.ai_total ?? 0));

/**
 * 房間列表時間戳短格式（#4204）：今天 HH:mm、今年 MM-DD、跨年 YYYY-MM-DD。
 * 全格式 113px 吃掉內容欄 54%，縮短後名稱拿回約 78px。讀 recallTick 讓
 * 跨日／跨年時自動重新求值；非預期格式原樣顯示。
 */
function shortRoomTime(s?: string): string {
  void recallTick.value;
  const raw = (s ?? '').trim();
  if (!/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw;
  const now = serverNow();
  if (raw.slice(0, 10) === now.slice(0, 10)) return raw.slice(11, 16);
  if (raw.slice(0, 4) === now.slice(0, 4)) return raw.slice(5, 10);
  return raw.slice(0, 10);
}

// ===== 訊息渲染輔助 =====
/**
 * 訊息的發話者頭像／名稱（#4159、target/tickets#4183）。房間是一對一，右側
 * （is_self===0）是客服端、左側是房間對方。
 *
 * 右側優先吃訊息自帶的 user_nickname/user_username（實際發話的操作員，
 * room/enter、room/history、socket 推播三條路徑都會帶），沒有才退回登入客服
 * ——否則同房多位客服/機器人發過話時，每一則都會標成目前登入的客服（#4183）。
 * 訊息頂層的 nickname/username/thumbnail 是房間對方（會員），不可用於右側。
 * 頭像同理優先吃 user_thumbnail（CS Socket 4183 起回傳），未設頭像的操作員
 * 退回登入客服頭像／預設圖。
 */
const agentName = computed(
  () =>
    agentView.value.nickname ||
    agentView.value.username ||
    me.value.member_username ||
    '',
);
const peerName = computed(
  () => roomInfo.value.name || roomInfo.value.member_username || '',
);
/** 對方帳號：代理與客服的 third_party_id 是 '0'，改以 member_username 為主 */
const peerAccount = computed(
  () => roomInfo.value.member_username || roomInfo.value.partner_username || '',
);
/** 代理與客服自己那筆成員的所屬帳號就是自己，同名即代表對方不是會員，不顯示上級 */
const peerSuperiorAgent = computed(() => {
  const agent = String(roomInfo.value.agent_username ?? '');
  return agent && agent !== String(roomInfo.value.member_username ?? '')
    ? agent
    : '';
});
const peerThumbnail = computed(
  () =>
    roomInfo.value.thumbnail ||
    rooms.value.find((r) => r.code === activeCode.value)?.thumbnail ||
    '',
);
function senderName(m: CsMessage): string {
  if (m.is_self === 0)
    return m.user_nickname || m.user_username || agentName.value;
  return m.nickname || m.username || peerName.value;
}
function senderThumbnail(m: CsMessage): string {
  if (m.is_self === 0)
    return m.user_thumbnail || agentView.value.thumbnail || '';
  return m.thumbnail || peerThumbnail.value;
}

function isImage(m: CsMessage): boolean {
  return Number(m.type) === 2;
}
function isTx(m: CsMessage): boolean {
  return TX_TYPES.has(Number(m.type));
}
function isEnvelope(m: CsMessage): boolean {
  return roomTt.value === 2 && ENVELOPE_TYPES.has(Number(m.type));
}
/**
 * 可撤回：自己發的文字／圖片，且距發送時間未超過 RECALL_WINDOW_MS（target/tickets#4189）。
 * 讀 recallTick 讓時限到達時能重新求值、按鈕自動消失（Vue 不會因時間流逝重算）。
 * 時間無法解析時不給撤回，避免舊訊息誤開。
 */
function isRecallable(m: CsMessage): boolean {
  if (m.is_self !== 0) return false;
  if (Number(m.type) !== 1 && Number(m.type) !== 2) return false;
  const sent = messageTimeMs(m);
  return Number.isFinite(sent) && recallTick.value - sent < RECALL_WINDOW_MS;
}
function txOf(type?: number) {
  const t = TX[Number(type)];
  return t
    ? { cls: t.cls, label: tl(t.key) }
    : { cls: '', label: tl('cs.transaction') };
}
function textContent(m: CsMessage): string {
  if (Number(m.type) === 1 && m.transaction_id) {
    if (Number(m.transaction_status) === 1) {
      return `${tl('message.received')}${tl('message.transaction_id')} ${m.transaction_id}`;
    }
    if (Number(m.transaction_status) === 2) {
      return `${tl('message.rejected')}${tl('message.transaction_id')} ${m.transaction_id}`;
    }
  }
  return m.content ?? '';
}
function tagStyle(tag: any) {
  return { background: tag?.background_color, color: tag?.text_color };
}

async function onTransferSuccess() {
  await loadRooms();
  const room = rooms.value.find((r) => r.code === activeCode.value);
  if (room) await enterRoom(room);
}

async function bootstrap() {
  // 進客服要先換 CS token，整段期間就顯示載入中（避免空白幾秒才出現轉圈）
  roomsLoading.value = true;
  bootstrapError.value = false;
  try {
    await runCsBootstrap(async () => {
      me.value = await getCsMeApi();
      if (me.value.id !== null && me.value.id !== undefined) {
        agentView.value = await getCsUserViewApi(me.value.id);
      }
      await fetchRooms();
    });
    // socket 訂閱不可重複，放在重試迴圈之外、全部成功後只做一次
    if (me.value.socketUrl) {
      initCsSocket(me.value.socketUrl);
      if (me.value.newMessageChannel) {
        subscribeNewMessage(me.value.newMessageChannel, onNewMessage);
      }
    }
    // 範本 API 只給總代理(1)／客服(3)（客服後端 #4451 對其他角色回 9003），
    // 全域攔截器會直接跳「權限不足」，notify=false 擋不住，所以沒權限就不打
    if (canUseTemplates.value) void loadTemplates(false);
    void loadCsTags();
    void loadSummary();
  } catch {
    bootstrapError.value = true;
  } finally {
    roomsLoading.value = false;
  }
}
// ===== 房間列表輪詢（target/op_tickets#3179）=====
// CS 後端的 summary 與 room/list 都是七表 join 的重量查詢，一次幾百毫秒到數秒，
// 資料庫一有鎖等待就直接 504。所以這裡刻意「低頻輪詢 + socket 事件驅動」：
//   - 輪詢 30 秒一次，只當 socket 訂不到（newMessage 頻道名要靠登入回應的
//     member_id 算，缺值時訂不到）時的兜底，不是主要的更新來源。
//   - 分頁切到背景就 pause，回前景才 resume 並補撈一次（套 10 秒節流）。
//   - 真正的即時更新靠 socket 事件就地改本地資料（見 onNewMessage /
//     onRoomSent），不重撈整份清單。
// 要調整節奏前請先確認後端那兩支查詢已經最佳化，別把頻率調回 15 秒。
const RECALL_TICK_MS = 10_000;
const ROOMS_POLL_MS = 30_000;

let recallTickTimer: ReturnType<typeof setInterval> | undefined;

/** 分頁可見性；hidden 時停掉輪詢，回前景再接上 */
const documentVisibility = useDocumentVisibility();

const { pause: pauseRoomsPoll, resume: resumeRoomsPoll } = useIntervalFn(
  () => {
    // 雙重保險：visibilitychange／onDeactivated 已會 pause，這裡再擋一次，
    // 確保分頁隱藏或本頁停用期間零請求
    if (!canBackgroundRefresh()) return;
    // 聯絡人分頁不看房間清單；已隱藏分頁的房間幾乎不會變動、又是真分頁，
    // 都不值得每 30 秒撈一次 room/list + summary，切回聊天分頁時 onNavChange 會重撈
    if (nav.value === 'contacts' || nav.value === 'hide') return;
    loadRooms(true);
  },
  ROOMS_POLL_MS,
  { immediate: false },
);

/**
 * 回前景要「穩定可見」多久才算真的回來。
 * 實測（dev、macOS Chrome）：Chrome 的視窗遮擋偵測會在其他視窗蓋住／移開時，
 * 讓分頁短暫 visible 約 1 秒又回 hidden；若 visible 一瞬間就補撈，每閃一次
 * 就多打一組 room/list + summary，看起來像「隱藏期間每 30 秒仍刷一次」。
 */
const VISIBLE_SETTLE_MS = 2000;
let visibleSettleTimer: ReturnType<typeof setTimeout> | undefined;

/** 回前景（visibilitychange／focus 共用）：等穩定可見 2 秒再恢復輪詢並補撈一次 */
function scheduleVisibleRefresh() {
  clearTimeout(visibleSettleTimer);
  visibleSettleTimer = setTimeout(() => {
    if (!canBackgroundRefresh()) return;
    resumeRoomsPoll();
    refreshRoomsThrottled();
  }, VISIBLE_SETTLE_MS);
}

/** window focus 也算回前景（切視窗不一定觸發 visibilitychange）；分頁隱藏或本頁停用時不理 */
function refreshOnFocus() {
  if (!canBackgroundRefresh()) return;
  scheduleVisibleRefresh();
}

watch(documentVisibility, (visibility) => {
  if (visibility === 'hidden') {
    clearTimeout(visibleSettleTimer);
    pauseRoomsPoll();
    return;
  }
  // 本頁停用中（客服在別的頁面）就不接回輪詢，等 onActivated 再處理
  if (!pageActive) return;
  scheduleVisibleRefresh();
});

// 工單4242：已開著聊天分頁時再從帳號管理點會員——KeepAlive 切回本頁時消費 pending。
// 首次掛載 activated 也會觸發，但當下 bootstrap 未完（roomsLoading=true）先跳過，
// 由下方 onMounted 的 bootstrap 完成後統一處理。
onActivated(() => {
  // 本頁被 KeepAlive 快取：切到其他頁時 onDeactivated 先停掉輪詢，切回來才接上並補撈一次
  // （首次掛載 bootstrap 尚未完成時不補撈，避免與 bootstrap 的 fetchRooms 並發）
  pageActive = true;
  if (documentVisibility.value !== 'hidden') resumeRoomsPoll();
  if (!roomsLoading.value) {
    refreshRoomsThrottled();
    openMemberRoomFromEntry();
  }
});

onDeactivated(() => {
  // 客服切去看其他頁面時聊天頁還活著（KeepAlive），不該繼續每 30 秒打 room/list + summary；
  // 也要把「回前景補撈」的 settle timer 取消，並讓 visibility／focus 監聽在停用期間不接回輪詢
  pageActive = false;
  clearTimeout(visibleSettleTimer);
  pauseRoomsPoll();
});

onMounted(() => {
  bootstrap().then(() => openMemberRoomFromEntry());
  // useIntervalFn 建立時不自動啟動（immediate: false），前景才開始跑
  if (documentVisibility.value !== 'hidden') resumeRoomsPoll();
  // 定期推進「現在」，讓超過時限的撤回按鈕自動收掉（target/tickets#4189）
  recallTickTimer = setInterval(() => {
    recallTick.value = Date.now();
  }, RECALL_TICK_MS);
  window.addEventListener('focus', refreshOnFocus);
});

onBeforeUnmount(() => {
  pauseRoomsPoll();
  clearTimeout(visibleSettleTimer);
  clearInterval(recallTickTimer);
  window.removeEventListener('focus', refreshOnFocus);
  disconnectCsSocket();
});
</script>

<template>
  <!-- 不給 title：Page 頁首整塊 v-if 不渲染，聊天視窗往上延展（target/tickets#4185） -->
  <Page auto-content-height content-class="flex flex-col">
    <!-- 標籤頻道列上移通欄（#4204）：原本關在 300px 列表面板裡，4 個標籤就橫向溢出 -->
    <!-- 已隱藏分頁不顯示標籤頻道列：房間被隱藏時後端會把成員身上的標籤全部移除
         （ChatController@changeStatus，#4204），隱藏列表裡的房間本來就沒有標籤可篩 -->
    <CsTagTabs
      v-if="tab !== 'hide'"
      v-model="activeTagId"
      class="mb-1"
      :rooms="rooms"
      :summary-tags="summary?.tags ?? undefined"
      :tags="csTags"
    />

    <div class="flex min-h-0 flex-1 overflow-hidden rounded border">
      <!-- 左欄：房間列表／聯絡人（#4204 聯絡人只替換左欄，對話區保留）＋底部導覽 -->
      <div
        v-show="showListPane"
        class="flex flex-col border-r"
        :class="isMobile ? 'w-full' : 'w-[300px]'"
      >
        <CsContacts
          v-if="nav === 'contacts'"
          class="min-h-0 flex-1"
          @open="openContactRoom"
        />
        <template v-else>
          <!-- 批次操作：勾選任一房間才浮出（#4204），平常頂端 0px -->
          <div
            v-if="tab === 'show' && selectedCodes.size > 0"
            class="flex flex-none items-center gap-1 border-b px-2 py-1.5"
          >
            <span class="text-xs text-muted-foreground">
              {{ tl('cs.selected_n') }} {{ selectedCodes.size }}
            </span>
            <div class="flex-1"></div>
            <ElButton link size="small" @click="toggleSelectAll">
              {{ tl('selectAll') }}
            </ElButton>
            <ElButton
              size="small"
              type="primary"
              :loading="hiding"
              @click="bulkHideRooms"
            >
              {{ tl('hidden') }}
            </ElButton>
            <ElButton size="small" @click="selectedCodes = new Set()">
              {{ tl('cancel') }}
            </ElButton>
          </div>
          <div
            v-if="bootstrapError"
            class="flex flex-1 flex-col items-center justify-center gap-3"
          >
            <span class="text-muted-foreground">
              {{ tl('cs.load_failed') }}
            </span>
            <ElButton type="primary" @click="bootstrap">
              {{ tl('retry') }}
            </ElButton>
          </div>
          <div v-else v-loading="roomsLoading" class="flex-1 overflow-y-auto">
            <div
              v-if="roomsTruncated"
              class="border-b border-amber-300 bg-amber-50 px-3 py-1.5 text-xs text-amber-700"
            >
              {{ tl('cs.room_list_truncated') }}（{{ rooms.length }} /
              {{ roomsPagination.total }}）
            </div>
            <ElEmpty
              v-if="visibleRooms.length === 0"
              :description="tl('cs.no_rooms')"
            />
            <div
              v-for="room in visibleRooms"
              :key="room.code"
              class="flex cursor-pointer gap-2 border-b px-3 py-2 hover:bg-accent"
              :class="{ 'bg-accent': activeCode === room.code }"
              @click="enterRoom(room)"
            >
              <ElCheckbox
                v-if="tab === 'show'"
                class="mt-1 flex-none"
                :model-value="!!room.code && selectedCodes.has(room.code)"
                @click.stop
                @change="toggleSelect(room.code, $event)"
              />
              <UserAvatar :src="room.thumbnail" :size="36" class="flex-none" />
              <div class="min-w-0 flex-1">
                <div class="flex items-center">
                  <span class="truncate text-sm font-medium">
                    {{ room.name }}
                  </span>
                  <span
                    v-if="room.username"
                    class="ml-1 truncate text-xs text-muted-foreground"
                  >
                    ({{ room.username }})
                  </span>
                  <!-- 時間戳短格式（#4204）：全格式 113px 吃掉內容欄 54%，
                       名稱只剩 45px；完整時間放 title -->
                  <span
                    :title="room.message_time"
                    class="ml-auto flex-none pl-1 text-xs text-muted-foreground"
                  >
                    {{ shortRoomTime(room.message_time) }}
                  </span>
                </div>
                <div class="flex items-center gap-1">
                  <span
                    class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                  >
                    {{ room.message_content }}
                  </span>
                  <!-- 未讀徽章放第二行右側（#4204，第一行已被時間戳吃光）。
                       只顯示未讀數字、不再退回紅點（驗收定案）——舊 message_read
                       旗標是「讀指標==最後一則」的精確比對，指標指向已撤回訊息
                       時會誤判成未讀，紅點只會把這個既有缺陷帶回來 -->
                  <span
                    v-if="roomUnread(room) > 0"
                    class="flex-none rounded-full bg-red-500 px-1.5 text-[11px] leading-4 text-white"
                  >
                    {{ cap99(roomUnread(room)) }}
                  </span>
                </div>
                <!-- 「顯示」分頁的個別隱藏鈕已移除，改用勾選＋隱藏（#4165） -->
                <div v-if="tab === 'hide'" class="mt-1">
                  <ElButton
                    plain
                    size="small"
                    type="primary"
                    @click.stop="recoverRoom(room)"
                  >
                    {{ tl('recover') }}
                  </ElButton>
                </div>
              </div>
            </div>
          </div>
          <!-- 「已隱藏」走真分頁：上下頁比照聯絡人列表（contacts.vue） -->
          <div
            v-if="tab === 'hide' && !bootstrapError"
            class="flex flex-none items-center justify-between border-t px-3 py-2 text-sm"
          >
            <ElButton
              link
              size="small"
              @click="turnHidePage(roomsPagination.previous)"
            >
              ‹
            </ElButton>
            <span>
              {{ roomsPagination.current || 0 }} /
              {{ roomsPagination.pages || 0 }}
            </span>
            <ElButton
              link
              size="small"
              @click="turnHidePage(roomsPagination.next)"
            >
              ›
            </ElButton>
          </div>
        </template>

        <!-- 底部導覽（#4204）：聊天／聯絡人／AI／已隱藏。圖示＋文字橫排，
             高 30px；文字截斷＋tooltip（14 語系寬度不一，徽章不可把文字擠掉） -->
        <div class="flex flex-none border-t">
          <button
            v-for="item in navItems"
            :key="item.key"
            type="button"
            class="relative flex h-[30px] min-w-0 flex-1 cursor-pointer items-center justify-center gap-1 text-xs"
            :class="
              nav === item.key
                ? 'text-primary font-medium'
                : 'text-muted-foreground hover:text-foreground'
            "
            :title="navLabel(item)"
            @click="onNavChange(item.key)"
          >
            <span class="flex-none leading-none">{{ item.emoji }}</span>
            <span class="truncate">{{ navLabel(item) }}</span>
            <!-- 聊天：進行中未讀（紅，要立刻處理）；AI：總對話數（青藍，參考
                 資訊，刻意不同色相避免與未讀混淆）；已隱藏不帶數字 -->
            <span
              v-if="item.key === 'chat' && showUnread > 0"
              class="absolute right-1 top-0.5 flex-none rounded-full bg-red-500 px-1 text-[10px] leading-4 text-white"
            >
              {{ cap99(showUnread) }}
            </span>
            <span
              v-else-if="item.key === 'ai' && aiTotal > 0"
              :title="tl('cs.ai_total')"
              class="absolute right-1 top-0.5 flex-none rounded-full px-1 text-[10px] leading-4"
              style="color: #dcf1f6; background-color: #2a7787"
            >
              {{ cap99(aiTotal) }}
            </span>
          </button>
        </div>
      </div>

      <!-- 對話區 -->
      <div
        v-show="showThreadPane"
        class="flex flex-col"
        :class="isMobile ? 'w-full' : 'flex-1'"
      >
        <template v-if="activeCode">
          <div
            class="flex flex-none flex-wrap items-center gap-2 border-b px-4 py-2"
          >
            <ElButton
              v-if="isMobile"
              link
              size="small"
              class="!px-0"
              @click="activeCode = ''"
            >
              {{ tl('cs.back') }}
            </ElButton>
            <span class="font-medium">
              <!-- 工單4274：會員帳號可點開詳情抽屜（以 username 開啟） -->
              <MemberAccountLink
                v-if="roomInfo.member_username"
                :label="roomInfo.name || roomInfo.member_username"
                :username="roomInfo.member_username"
              />
              <template v-else>{{ roomInfo.name || activeCode }}</template>
            </span>
            <span v-if="peerAccount" class="text-muted-foreground">
              ({{ peerAccount }})
            </span>
            <span v-if="peerSuperiorAgent" class="text-muted-foreground">
              {{ tl('list.superiorAgent') }} {{ peerSuperiorAgent }}
            </span>
            <span
              v-for="(tag, i) in roomInfo.member_tags || []"
              :key="i"
              class="rounded px-1.5 py-0.5 text-xs"
              :style="tagStyle(tag)"
            >
              {{ tag.name }}
            </span>
            <!-- 客服標籤（#4169）：與上面的玩家標籤是兩套，由右側下拉 pin/unpin -->
            <span
              v-for="(tag, i) in roomInfo.cs_tags || []"
              :key="`cs-${i}`"
              class="rounded-full px-1.5 py-0.5 text-xs"
              :style="tagStyle(tag)"
            >
              {{ tag.name }}
            </span>
            <div class="flex-1"></div>
            <!-- 標籤只開放總代理與客服（#4204）：無角色權限時後端 tag/list 回空、
                 房間也不帶 cs_tags，這裡一併把空殼下拉藏起來 -->
            <CsTagSelectDropdown
              v-if="
                !isAiRoom &&
                activeMemberId &&
                (csTags.length > 0 || (roomInfo.cs_tags || []).length > 0)
              "
              :all-tags="csTags"
              :member-id="activeMemberId"
              :tags="roomInfo.cs_tags || []"
              @update:tags="onMemberTagsChange"
            />
            <ElButton
              v-if="showBankBtn && roomInfo.partner_username"
              plain
              size="small"
              @click="bankOpen = true"
            >
              {{ tl('list.bank_account') }}
            </ElButton>
            <ElButton
              v-if="
                Number(agentView.cs_flow_limit_button_visible) === 1 &&
                activeMemberId
              "
              plain
              size="small"
              @click="flowLimitOpen = true"
            >
              {{ tl('cs.flow_limit_record') }}
            </ElButton>
            <ElButton
              v-if="!isOperator"
              plain
              size="small"
              @click="notesOpen = true"
            >
              {{ tl('cs.note') }}
            </ElButton>
          </div>
          <div
            ref="threadRef"
            v-loading="msgLoading"
            class="flex-1 overflow-y-auto bg-muted/30 p-4"
          >
            <!-- 訊息內容限寬置中（#4204）：對話區 1364px，一行短訊息從最左
                 跑到最右、眼睛要橫掃，行長收到 ~880px -->
            <div class="mx-auto w-full max-w-[880px] space-y-3">
              <div class="text-center">
                <ElButton
                  v-if="!noMoreHistory"
                  link
                  size="small"
                  @click="loadOlder"
                >
                  {{ tl('cs.load_earlier_messages') }}
                </ElButton>
                <span v-else class="text-xs text-muted-foreground">
                  {{ tl('cs.no_earlier_messages') }}
                </span>
              </div>

              <div
                v-for="m in messages"
                :key="m.message_id"
                class="flex items-start gap-2"
                :class="m.is_self === 0 ? 'justify-end' : 'justify-start'"
              >
                <!-- 發話者頭像＋名稱（#4159）：自己的訊息用 order-last 排到氣泡右邊 -->
                <div
                  class="w-14 flex-none text-center"
                  :class="m.is_self === 0 ? 'order-last' : ''"
                >
                  <UserAvatar
                    :src="senderThumbnail(m)"
                    :size="36"
                    class="mx-auto"
                  />
                  <div class="truncate text-[11px] text-muted-foreground">
                    {{ senderName(m) }}
                  </div>
                </div>
                <div class="group max-w-[70%]">
                  <!-- 交易訊息（type 3/6-11）-->
                  <template v-if="isTx(m)">
                    <!-- 紅包圖卡：transaction_type==2 的 6/7/8/9/10 -->
                    <div v-if="isEnvelope(m)" class="relative w-[170px]">
                      <img
                        src="/cs-red-envelope.png"
                        class="w-full select-none"
                        :alt="tl('redEnvelope.redEnvelope')"
                      />
                      <div
                        class="absolute inset-x-0 top-[16%] px-2 text-center text-xs font-semibold"
                        style="color: gold"
                      >
                        {{ txOf(m.type).label }} {{ m.content }}
                        {{ tl('message.point') }}
                      </div>
                      <div
                        v-if="m.transaction_id"
                        class="absolute inset-x-0 bottom-[8%] px-2 text-center text-[11px] text-white/90"
                      >
                        {{ m.transaction_id }}
                      </div>
                    </div>
                    <!-- 一般交易文字 -->
                    <div
                      v-else
                      class="rounded bg-muted px-3 py-2 text-sm text-foreground"
                    >
                      <template v-if="Number(m.type) === 3">
                        <span v-if="m.username">{{ m.username }} </span>
                        <span class="font-semibold text-red-500">
                          {{ tl('list.send_redEnvelope') }}
                        </span>
                        <span v-if="m.content"> ({{ m.content }})</span>
                      </template>
                      <template v-else>
                        {{ tl('message.for_user') }}
                        <span class="font-semibold" :class="txOf(m.type).cls">
                          {{ txOf(m.type).label }}
                        </span>
                        {{ m.content }} {{ tl('message.point') }}
                      </template>
                      <span v-if="m.transaction_id" class="text-xs opacity-70">
                        {{ tl('message.transaction_id') }}
                        {{ m.transaction_id }}
                      </span>
                    </div>
                  </template>
                  <!-- 圖片 -->
                  <img
                    v-else-if="isImage(m)"
                    :src="m.content"
                    class="max-w-[220px] cursor-pointer rounded"
                    @click="openImage(m.content ?? '')"
                  />
                  <!-- 文字 -->
                  <div
                    v-else
                    class="whitespace-pre-wrap break-words rounded px-3 py-2 text-sm"
                    :class="
                      m.is_self === 0
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-card text-card-foreground'
                    "
                  >
                    {{ textContent(m) }}
                  </div>
                  <div
                    class="mt-0.5 flex items-center gap-2 text-[11px] text-muted-foreground"
                    :class="m.is_self === 0 ? 'justify-end' : ''"
                  >
                    <span>{{ m.created_at || m.time }}</span>
                    <!-- 撤回：發送後 3 分鐘內常駐顯示，逾時自動消失（target/tickets#4189） -->
                    <ElButton
                      v-if="isRecallable(m)"
                      link
                      size="small"
                      :title="tl('cs.recall')"
                      @click="recall(m)"
                    >
                      <UndoIcon class="size-4" />
                    </ElButton>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="h-5 flex-none px-4 text-xs text-muted-foreground">
            <span v-if="peerTyping">{{ tl('cs.peer_typing') }}</span>
          </div>

          <!-- 訊息範本面板：僅總代理(1)或客服(3)可見；只留點選帶入與新增，刪除改到「快速回復訊息」管理頁（#4485） -->
          <div
            v-if="canUseTemplates && showTpl && !isAiRoom"
            class="max-h-40 flex-none overflow-y-auto border-t bg-muted/30 p-2"
          >
            <div class="mb-2 flex flex-wrap gap-1">
              <ElTag
                v-for="t in templates"
                :key="t.id"
                class="cursor-pointer"
                @click="useTemplate(t)"
              >
                {{ t.title }}
              </ElTag>
              <span
                v-if="templates.length === 0"
                class="text-xs text-muted-foreground"
              >
                {{ tl('cs.no_templates') }}
              </span>
            </div>
            <div class="flex items-end gap-1">
              <ElInput
                v-model="tplForm.title"
                size="small"
                maxlength="50"
                :placeholder="tl('list.title')"
                style="width: 120px"
              />
              <ElInput
                v-model="tplForm.content"
                class="flex-1"
                type="textarea"
                :autosize="{ maxRows: 4, minRows: 1 }"
                resize="none"
                maxlength="1000"
                show-word-limit
                :placeholder="tl('list.content')"
              />
              <ElButton
                size="small"
                type="primary"
                :loading="tplSaving"
                @click="addTemplate"
              >
                {{ tl('list.create') }}
              </ElButton>
            </div>
          </div>

          <div class="flex-none border-t p-3">
            <div
              v-if="isAiRoom"
              class="flex items-center justify-between gap-2"
            >
              <span class="text-sm text-muted-foreground">
                {{ tl('cs.room_ai_handling') }}
              </span>
              <ElButton type="primary" size="small" @click="recoverAiRoom">
                {{ tl('cs.restore_to_manual') }}
              </ElButton>
            </div>
            <div v-else>
              <!-- 工具列（icon，獨立一排）-->
              <div class="mb-1 flex items-center gap-1">
                <ElButton
                  v-if="canUseTemplates"
                  :title="tl('cs.template')"
                  text
                  class="!px-2"
                  :type="showTpl ? 'primary' : 'default'"
                  @click="showTpl = !showTpl"
                >
                  <FileText :size="18" />
                </ElButton>
                <ElButton
                  :title="tl('image')"
                  text
                  class="!px-2"
                  @click="pickImage"
                >
                  <ImagePlus :size="18" />
                </ElButton>
                <ElButton
                  v-if="
                    Number(agentView.red_envelope_button_visible) === 1 &&
                    activeMemberId
                  "
                  :title="tl('redEnvelope.redEnvelope')"
                  text
                  class="!px-2"
                  @click="transferOpen = true"
                >
                  <RedEnvelope :width="16" :height="16" />
                </ElButton>
              </div>
              <div class="flex items-end gap-1">
                <ElInput
                  v-model="input"
                  class="flex-1"
                  type="textarea"
                  :autosize="{ maxRows: 4, minRows: 1 }"
                  resize="none"
                  :placeholder="tl('cs.message_input_hint')"
                  @keydown.enter.exact.prevent="send"
                  @paste="onPaste"
                />
                <ElButton type="primary" :loading="sending" @click="send">
                  {{ tl('form.send') }}
                </ElButton>
              </div>
              <input
                ref="imageInput"
                type="file"
                accept="image/*"
                class="hidden"
                @change="onImagePicked"
              />
            </div>
          </div>
        </template>
        <ElEmpty v-else class="m-auto" :description="tl('cs.pick_room')" />
      </div>
    </div>

    <CsNotesDrawer
      v-if="!isOperator"
      v-model="notesOpen"
      :room-id="activeRoomId"
    />
    <CsFlowLimitDialog
      v-model="flowLimitOpen"
      :member-id="activeMemberId"
      :user-id="me.id ?? ''"
    />
    <CsBankDialog
      v-model="bankOpen"
      :editable="bankEditable"
      :mask-private="!bankEditable"
      :operator-id="me.id ?? ''"
      :username="roomInfo.partner_username || ''"
    />
    <CsTransferDialog
      v-model="transferOpen"
      :agent="agentView"
      :member-id="activeMemberId"
      :member-name="roomInfo.name || roomInfo.member_username"
      @success="onTransferSuccess"
    />

    <!-- 貼上圖片預覽 -->
    <ElDialog
      v-model="pasteImage.visible"
      append-to-body
      :title="tl('message.send_image')"
      :width="isMobile ? '92%' : '400'"
      @close="cancelPasteImage"
    >
      <div class="text-center">
        <img
          v-if="pasteImage.base64"
          :src="pasteImage.base64"
          class="mx-auto max-h-[300px] max-w-full rounded"
        />
      </div>
      <template #footer>
        <ElButton @click="cancelPasteImage">{{ tl('form.cancel') }}</ElButton>
        <ElButton type="primary" :loading="sending" @click="confirmPasteImage">
          {{ tl('form.send') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
