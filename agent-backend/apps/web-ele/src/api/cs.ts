import { ref } from 'vue';

import {
  baseRequestClient,
  isCsTokenInvalid,
  markCsTokenValid,
  requestClient,
} from '#/api/request';

/**
 * 客服系統 API（改走 gateway /proxy/cs/*）。
 * 業務 API 走 /cs/api/<真實路徑>，由 gateway 用 CS 金鑰加密轉發、注入 csToken。 */

export interface CsMe {
  domain?: string;
  id?: number | string;
  loggedIn?: boolean;
  member_id?: number | string;
  member_username?: string;
  newMessageChannel?: string;
  role_id?: number;
  socketUrl?: string;
}

let csLoginPromise: null | Promise<void> = null;

/** 是否正在（重新）建立 CS session，頁面可綁這個顯示讀取條，避免空狀態閃一下才跳資料 */
export const csLoginLoading = ref(false);

const CS_LOGIN_MAX_ATTEMPTS = 5;
/** 重試間隔的基底；第 n 次失敗後等 base * 2^(n-1) 毫秒（1、2、4、8 秒） */
const CS_LOGIN_RETRY_BASE_DELAY_MS = 1000;

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * 目前 CS 登入者的客服角色（role_id），由 csLoginOnce() 寫入。
 * 取不到時維持 null，呼叫端（例如快速回復訊息選單）要當成「沒有權限」處理（target/tickets#4485）。
 */
let csRoleId: null | number = null;

/** 把後端回傳的 role_id 正規化成數字；空值或非數字一律回 null */
function normalizeCsRoleId(value: unknown): null | number {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isNaN(n) ? null : n;
}

/** 目前 CS 登入者的客服角色；尚未登入或取不到時為 null */
export function getCsRoleId(): null | number {
  return csRoleId;
}

/** 訊息範本可用角色：總代理(1)／客服(3)，與 chat.vue canUseTemplates 一致 */
export const CS_TEMPLATE_ROLE_IDS: readonly number[] = [1, 3];

/** 紀錄查詢側欄可見角色：平台商(1)／客服(3)／超級管理員(5)（target/tickets#4487）。
 *  營運商(2) 等其他角色側欄隱藏，但路由仍註冊（cs-menu.ts ALWAYS_ROUTED_CODES），彈窗深連結照常可開。 */
export const CS_TRANSACTION_LOG_ROLE_IDS: readonly number[] = [1, 3, 5];

async function csLoginOnce(): Promise<void> {
  // 每次（重新）登入都先清掉，避免沿用上一個帳號／上一次 session 的角色
  csRoleId = null;
  // /cs/me 一律回 200（loggedIn 旗標），不會觸發全域 401 登出
  // bootstrap 期間一律用 baseRequestClient（不帶全域錯誤攔截器）
  const meRes: any = await baseRequestClient.get('/cs/me').catch(() => null);
  const me = meRes?.data as CsMe | undefined;
  if (me?.loggedIn) {
    csRoleId = normalizeCsRoleId(me.role_id);
  } else {
    const loginRes: any = await baseRequestClient.post('/cs/login', {});
    const body = loginRes?.data ?? loginRes;
    if (Number(body?.code) !== 1000 && Number(body?.code) !== 200) {
      throw new Error(body?.message || 'cs login failed');
    }
    // gateway 只從登入回應刪掉 token，role_id 仍在 data 裡
    csRoleId = normalizeCsRoleId(body?.data?.role_id);
  }
  // 登入後抓選單 action 欄（供頁面動作權限判斷）。選單本身是固定的，
  const nodesRes: any = await baseRequestClient
    .post('/cs/api/userMenu/list', {})
    .catch(() => null);
  const nodesBody = nodesRes?.data ?? nodesRes;
  const nodes =
    Number(nodesBody?.code) === 1000 || Number(nodesBody?.code) === 200
      ? ((nodesBody?.data as CsMenuNode[]) ?? [])
      : [];
  setCsMenuNodes(nodes);
  markCsTokenValid();
}

/**
 * 確保已取得 CS session：先試還原（/cs/me），沒有就用 agent token 自動登入
 * （/cs/login，免再登入）。idempotent —— 多個 CS 頁同時掛載只會登入一次；失敗可重試。
 * 若期間有 CS API 回過 9998（token 失效），下次呼叫會忽略快取重新走一次登入。
 * 單次嘗試不重試——重試策略統一由 runCsBootstrap 管（涵蓋登入＋首批資料載入）。 */
export function ensureCsLogin(): Promise<void> {
  if (!csLoginPromise || isCsTokenInvalid()) {
    csLoginLoading.value = true;
    csLoginPromise = csLoginOnce().finally(() => {
      csLoginLoading.value = false;
    });
    csLoginPromise.catch(() => {
      csLoginPromise = null;
    });
  }
  return csLoginPromise;
}

/**
 * 跑一段 CS 頁面的初始化（登入＋首批資料載入），失敗自動重試：
 * 最多 5 次（= 4 次重試），失敗間隔指數退避 1、2、4、8 秒，
 * 全部失敗才丟錯給呼叫端顯示重試按鈕。
 * 剛登入完 CS 後端偶爾還沒就緒，第一批資料呼叫會失敗、過一下就好——
 * 重試必須涵蓋資料載入這段，只包登入是不夠的（登入常常是成功的）。
 * 退避而非固定 1 秒：CS 後端是被壓垮（DB 鎖等待）時整批客服同時定頻重試，
 * 只會把後端壓得更死（target/op_tickets#3179）。
 */
export async function runCsBootstrap(task: () => Promise<void>): Promise<void> {
  let lastError: unknown;
  for (let attempt = 1; attempt <= CS_LOGIN_MAX_ATTEMPTS; attempt++) {
    try {
      await ensureCsLogin();
      await task();
      return;
    } catch (error) {
      lastError = error;
      if (attempt < CS_LOGIN_MAX_ATTEMPTS) {
        await sleep(CS_LOGIN_RETRY_BASE_DELAY_MS * 2 ** (attempt - 1));
      }
    }
  }
  throw lastError instanceof Error ? lastError : new Error(String(lastError));
}

/**
 * 登入身分改變時呼叫。這些是模組層變數，resetAllStores() 清不到，
 * 不清的話換帳號會沿用上一個帳號的 CS session 與動作權限。
 */
/**
 * 工單4242：帳號管理「聊天」欄跨頁傳遞的目標會員帳號（取用即清）。
 * 用 username 當鑰匙——CS 端與 apis 的數字 id 是兩套體系，帳號才是共同鍵。
 */
let pendingChatUsername = '';

export function setPendingChatUsername(username: string) {
  pendingChatUsername = username;
}

export function takePendingChatUsername(): string {
  const u = pendingChatUsername;
  pendingChatUsername = '';
  return u;
}

export function resetCsState() {
  csLoginPromise = null;
  csLoginLoading.value = false;
  csRoleId = null;
  setCsMenuNodes([]);
}

/** 取得目前 CS 登入者資訊 */
export function getCsMeApi() {
  return requestClient.get<CsMe>('/cs/me');
}

// ===== 客服選單權限（userMenu/list 的 action 欄）=====

export interface CsMenuNode {
  action?: string;
  code: string;
  id: number;
  name: string;
  parent: number;
  symbol?: string;
  url: string;
}

let csMenuNodes: CsMenuNode[] = [];

/** 由 cs-menu 在取得 userMenu/list 後寫入，供頁面判斷動作權限。 */
export function setCsMenuNodes(nodes: CsMenuNode[]) {
  csMenuNodes = nodes ?? [];
}

/** 目前已知的客服選單節點（供左側選單依實際權限決定顯示哪些項目）。 */
export function getCsMenuNodes(): CsMenuNode[] {
  return csMenuNodes;
}

/**
 * 選單節點 id 對照（比照舊版 WEB_CustomerServiceBackend Index.vue 的 btn()：
 * 用固定 id 判斷，不是用 code 或 url 比對）：2=營運商 3=客服人員 13=用戶
 */
const MENU_NODE_ID_BY_KIND: Record<string, number> = {
  agent: 2,
  customer_service: 3,
  user: 13,
};

/** 取某選單節點開放的動作 */
export function csMenuActions(kind: string): string[] {
  const id = MENU_NODE_ID_BY_KIND[kind];
  const node = csMenuNodes.find((n) => n.id === id);
  return (node?.action ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

// ===== 營運商 / 用戶 / 客服人員管理（api/user/*，三頁共用）=====

export interface CsUserRow {
  balance?: null | number;
  game_wallet?: null | number;
  id: number | string;
  is_sub?: number;
  limit_of_credit?: number;
  nickname?: string;
  status?: number;
  thumbnail?: string;
  used_credit?: number;
  username: string;
  withdraw_confirm?: number | string;
}

export interface CsUserView {
  balance?: null | number | string;
  cs_downline_balance_visible?: number | string;
  cs_flow_limit_button_visible?: number | string;
  deduct_point_button_visible?: number | string;
  flow_limit_modify_permission?: number | string;
  game_wallet?: null | number | string;
  gift_red_envelope_button_visible?: number | string;
  id?: number | string;
  is_multiple_wallet?: number | string;
  limit_of_credit?: number | string;
  nickname?: string;
  point_button_visible?: number | string;
  receive_red_envelope_button_visible?: number | string;
  red_envelope_button_visible?: number | string;
  role_id?: number;
  send_red_envelope_button_visible?: number | string;
  thumbnail?: string;
  transfer_member_flow_limit_multiple?: number | string;
  used_credit?: number | string;
  username?: string;
}

/**
 * 列表：role_id 2=營運商、3=客服人員。伺服器端分頁（per_page 後端上限 1000）。
 * keyword：同時 like 比對 username 或 nickname；username_exact：精確比對 username。
 * 兩者皆為可選，空字串／undefined 一律不送出該欄位（由本層統一過濾，呼叫端直接傳）。
 */
export async function getCsUserListApi(params: {
  keyword?: string;
  page: number;
  per_page: number;
  roleId: number;
  username_exact?: string;
}) {
  const body: Record<string, any> = {
    page: params.page,
    per_page: params.per_page,
    role_id: params.roleId,
  };
  if (params.keyword) body.keyword = params.keyword;
  if (params.username_exact) body.username_exact = params.username_exact;

  const res = await requestClient.post<{
    code: number | string;
    data?: CsUserRow[] | null;
    message?: string;
    pagination?: CsPagination | null;
  }>('/cs/api/user/list', body, { responseReturn: 'body' });

  return {
    code: res.code,
    data: res.data ?? [],
    message: res.message,
    pagination: { total: Number(res.pagination?.total ?? 0) },
  };
}

/** 目前操作者的設定/權限 */
export function getCsUserViewApi(id: number | string) {
  return requestClient.post<CsUserView>('/cs/api/user/view', { id });
}

export function editCsUserApi(data: {
  id: number | string;
  nickname?: string;
  status?: number | string;
  thumbnail?: string;
  withdraw_confirm?: number | string;
}) {
  return requestClient.post('/cs/api/user/edit', data);
}

/**
 * 停用用戶。用 baseRequestClient（不帶全域錯誤攔截器）避免跟呼叫端自己的
 * ElMessage.error 疊在一起顯示兩次同樣的錯誤訊息。
 */
export async function deleteCsUserApi(id: number | string) {
  const res: any = await baseRequestClient.post('/cs/api/user/delete', {
    id,
  });
  const body = res?.data ?? res;
  if (Number(body?.code) !== 1000) {
    throw new Error(body?.message || 'delete failed');
  }
  return body;
}

export interface CsTransactionPayload {
  amount: number | string;
  id: number | string;
  payment_password?: string;
  remark: string;
  type?: number;
}

export interface CsApiResult {
  code?: number;
  data?: any;
  message?: string;
}

/** 開洗分 / 開分。用 body 模式取完整回應，才能讀 7001（需付款密碼）等業務碼。 */
export function csUserTransactionApi(data: CsTransactionPayload) {
  return requestClient.post<CsApiResult>('/cs/api/user/transaction', data, {
    responseReturn: 'body',
  });
}

// ===== 紀錄查詢（開洗分紀錄 / 紅包紀錄）=====

export interface CsHistorySearch {
  end_time: string;
  start_time: string;
  username?: string;
}

export interface CsTransactionRow {
  amount?: number | string;
  created_at?: string;
  id?: number | string;
  member_nickname?: string;
  member_username?: string;
  remark?: string;
  status?: number;
  type?: number;
  user_username?: string;
}

export interface CsRedEnvelopeRow {
  amount?: number | string;
  created_at?: string;
  other_username?: string;
  self_username?: string;
  type?: number;
}

/** 開洗分紀錄。 */
export function getCsTransactionListApi(s: CsHistorySearch) {
  return requestClient.post<CsTransactionRow[]>('/cs/api/transaction/list', {
    end_time: s.end_time,
    page: 1,
    per_page: 1000,
    start_time: s.start_time,
    username: s.username,
  });
}

/** 紅包紀錄。 */
export function getCsRedEnvelopeListApi(s: CsHistorySearch) {
  return requestClient.post<CsRedEnvelopeRow[]>('/cs/api/redEnvelope/record', {
    end_time: s.end_time,
    page: 1,
    per_page: 1000,
    start_time: s.start_time,
    username: s.username,
  });
}

// ===== 開洗分統計表 sr02 =====

export interface CsSr02Total {
  total_in?: number | string;
  total_out?: number | string;
}
export interface CsSr02Row {
  total_in?: number | string;
  total_out?: number | string;
  username?: string;
}
export interface CsSr02Result {
  code?: number;
  data?: CsSr02Row[];
  total?: CsSr02Total[];
}

/** 開洗分統計表。total（合計）在回應頂層，故用 body 模式取完整回應。 */
export function getCsSr02Api(p: {
  end_date: string;
  start_date: string;
  username?: string;
}) {
  return requestClient.post<CsSr02Result>(
    '/cs/api/report/sr02',
    { end_date: p.end_date, start_date: p.start_date, username: p.username },
    { responseReturn: 'body' },
  );
}

// ===== 系統設定 =====

export interface CsSystemSetting {
  api_url?: string;
  background_color?: string;
  background_image?: string;
  header_background_color?: string;
  header_font_color?: string;
  name?: string;
}

export function getCsSystemSettingApi() {
  return requestClient.post<CsSystemSetting>('/cs/api/setting/view', {});
}

export function saveCsSystemSettingApi(data: CsSystemSetting) {
  return requestClient.post('/cs/api/setting/save', data);
}

// ===== 聊天主控台 =====

export interface CsRoom {
  code: string;
  friend_id?: number | string;
  id?: number | string;
  /** 客服標籤（#4169 標籤頻道過濾用；CS 後端 room/list 帶出，與玩家標籤 member_tags 是兩套） */
  cs_tags?: Array<Record<string, any>>;
  members?: number | string;
  message_content?: string;
  message_read?: number;
  message_time?: string;
  name?: string;
  thumbnail?: string;
  /** 未讀訊息數（target/tickets#4204；CS 後端 room/list 補上前為 undefined，前端退回紅點） */
  unread_count?: number | string;
  username?: string;
}

export interface CsMessage {
  code?: string;
  content?: string;
  /** room/enter 與歷史訊息的時間欄（已 +8 小時） */
  created_at?: string;
  is_self?: number;
  member_id?: number | string;
  message_id?: number | string;
  /** 發話者暱稱（room/enter、room/history 每則訊息都會帶） */
  nickname?: string;
  room_id?: number | string;
  /** 發話者頭像（room/enter、room/history 每則訊息都會帶） */
  thumbnail?: string;
  /** socket MessageSent 推來的時間欄（同樣 +8 小時，但欄名不同） */
  time?: string;
  transaction_id?: number | string;
  transaction_status?: number;
  type?: number;
  /** 客服端發言才有：操作員（users 表）id */
  user_id?: number | string;
  /** 客服端發言才有：實際發話操作員暱稱（target/tickets#4183） */
  user_nickname?: string;
  /** 客服端發言才有：實際發話操作員頭像（target/tickets#4183） */
  user_thumbnail?: string;
  /** 客服端發言才有：實際發話操作員帳號（target/tickets#4183） */
  user_username?: string;
  username?: string;
}

export interface CsRoomEnter {
  message?: CsMessage[];
  room?: Record<string, any> & { code?: string; name?: string };
}

/**
 * 房間列表：status 1=顯示 2=隱藏 3=AI。
 * 不帶 paging 時後端只回最近 N 間（目前 500），pagination.total 可判斷是否被截斷；
 * 帶 paging（page + per_page）才走真分頁。
 * responseReturn: 'body' 下攔截器不檢查 code，失敗（含 token 失效的 9998）要自己丟出來，
 * 否則呼叫端的 runCsBootstrap 收不到失敗訊號、不會重試。
 */
export async function getCsRoomListApi(
  status: number,
  paging?: { page: number; per_page: number },
) {
  const res = await requestClient.post<{
    code: number | string;
    data?: CsRoom[] | null;
    message?: string;
    pagination?: CsPagination | null;
  }>('/cs/api/room/list', { status, ...paging }, { responseReturn: 'body' });

  if (Number(res.code) !== 1000 && Number(res.code) !== 200) {
    throw new Error(res.message || 'room list failed');
  }

  return { data: res.data ?? [], pagination: res.pagination ?? {} };
}

// ===== 數字彙總（target/tickets#4204）=====

export interface CsSummaryTag {
  id?: number | string;
  /** 貼了此標籤的會員數 */
  member_count?: number | string;
  /** 此標籤下進行中（status=1）房間的未讀「訊息」數 */
  unread_count?: number | string;
}

export interface CsSummary {
  status?: {
    /** AI 頻道裡的人數（去重的對方成員數，非頻道數） */
    ai_total?: number | string;
    /** 進行中有未讀的房間數（未讀人數，一對一房＝人） */
    show_unread?: number | string;
  };
  tags?: CsSummaryTag[];
}

/**
 * 標籤／導覽徽章的彙總數字。這五個數字前端都算不出來（room/list 一次只回
 * 一個 status），統一由 CS 後端出。後端未部署前會失敗：走 baseRequestClient
 * 避免全域錯誤攔截器每次輪詢都彈一次錯誤訊息，失敗回 null、徽章不顯示。
 */
export async function getCsSummaryApi(): Promise<CsSummary | null> {
  const res: any = await baseRequestClient.post('/cs/api/summary', {});
  const body = res?.data ?? res;
  if (Number(body?.code) !== 1000 && Number(body?.code) !== 200) return null;
  return (body?.data as CsSummary) ?? null;
}

/**
 * 進入房間：回 { room, message }。
 * code 與 friend_id 擇一即可（都給以 code 為主）——從聯絡人開啟對話時還沒有房號，
 * 只能給 friend_id 讓後端找出（或開新的）房間，房號再從回應的 room.code 取。
 */
export function enterCsRoomApi(p: {
  code?: string;
  friend_id?: number | string;
}) {
  return requestClient.post<CsRoomEnter>('/cs/api/room/enter', {
    code: p.code,
    friend_id: p.friend_id ?? '',
  });
}

/** 房間歷史訊息 */
export function getCsRoomHistoryApi(p: {
  code: string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<CsMessage[]>('/cs/api/room/history', {
    code: p.code,
    page: p.page,
    per_page: p.per_page,
  });
}

/** 隱藏房間。 */
export function hideCsRoomApi(code: string) {
  return requestClient.post('/cs/api/room/changeStatus', { code, status: 2 });
}

/**
 * 恢復房間至「顯示」：取消隱藏，或把 AI 房間取回人工。
 * 後端用 changeStatus status=1（room/recover 路由在此後端為 404）。 */
export function recoverCsRoomApi(code: string) {
  return requestClient.post('/cs/api/room/changeStatus', { code, status: 1 });
}

/** 訊息已讀。 */
export function readCsMessageApi(p: {
  code: string;
  message_id: number | string;
}) {
  return requestClient.post('/cs/api/readMessage', {
    code: p.code,
    message_id: p.message_id,
  });
}

/** 發送訊息 */
export function sendCsMessageApi(p: {
  code: string;
  content: string;
  type: number;
}) {
  return requestClient.post<CsApiResult>(
    '/cs/api/sendMessage',
    { code: p.code, content: p.content, type: p.type },
    { responseReturn: 'body' },
  );
}

/** 收回訊息。 */
export function recallCsMessageApi(messageId: number | string) {
  return requestClient.post('/cs/api/recallMessage', { message_id: messageId });
}

// ===== 訊息範本 =====

export interface CsMessageTemplate {
  content?: string;
  created_at?: string;
  created_by?: number | string;
  id?: number | string;
  memo?: string;
  title?: string;
  type?: number;
  type_name?: string;
  updated_at?: string;
  updated_by?: number | string;
  /** 最後修改者帳號（後端 join updated_by） */
  username?: string;
}

export function getCsMessageTemplatesApi() {
  return requestClient.post<CsMessageTemplate[]>(
    '/cs/api/message-template/list',
    {},
  );
}

export function createCsMessageTemplateApi(data: {
  content: string;
  memo?: string;
  title: string;
}) {
  return requestClient.post('/cs/api/message-template/create', {
    // memo 資料表欄位 NOT NULL 但後端無條件寫入 $request->memo，
    // 不送會炸 SQL 23000（target/tickets#4182）
    memo: '',
    type: 1,
    ...data,
  });
}

export function updateCsMessageTemplateApi(data: {
  content: string;
  id: number | string;
  memo: string;
  title: string;
}) {
  // 客服後端 rules_update 驗證參數名為 targetID（target/tickets#4485）
  return requestClient.post('/cs/api/message-template/update', {
    targetID: data.id,
    title: data.title,
    content: data.content,
    memo: data.memo,
  });
}

export function deleteCsMessageTemplateApi(id: number | string) {
  // CS Socket 的 rules_delete 驗證參數名為 targetID（target/tickets#4182）
  return requestClient.post('/cs/api/message-template/delete', {
    targetID: id,
  });
}

// ===== 聯絡人 / 好友 =====

export interface CsFriend {
  friend_id?: number | string;
  member_id?: number | string;
  nickname?: string;
  room_id?: number | string;
  status?: number;
  third_party_id?: number | string;
  thumbnail?: string;
  type?: number;
  url?: string;
  username?: string;
}

export interface CsPagination {
  current?: number | string;
  next?: number | string;
  pages?: number | string;
  previous?: number | string;
  total?: number | string;
}

export interface CsContactListResult {
  data?: CsFriend[];
  pagination?: CsPagination;
}

/** 聯絡人列表：customer=true 走客戶（聊過的）、false 走好友。 */
export function getCsContactListApi(p: {
  customer?: boolean;
  fuzzy?: boolean;
  page?: number;
  username?: string;
}) {
  return requestClient.post<CsContactListResult>(
    p.customer ? '/cs/api/customer/list' : '/cs/api/friend/list',
    {
      fuzzy: p.fuzzy ?? false,
      page: p.page ?? 1,
      per_page: 10,
      status: 1,
      username: p.username ?? '',
    },
    { responseReturn: 'body' },
  );
}

export function editCsFriendApi(data: { nickname?: string; status?: number }) {
  return requestClient.post('/cs/api/friend/edit', data);
}

// ===== 客戶筆記（ChatNote）=====
// 前台把此功能的 UI 藏起來（display:none / 「NOTE功能關閉中」），但後端 SP 仍可用。
// 筆記掛在房間（room_id）下，內容掛在筆記（note_id）下。

export interface CsNote {
  create_user?: string;
  created_at?: string;
  id: number | string;
  member_id?: number | string;
  member_username?: string;
  room_id?: number | string;
  status?: number; // 0=未完成 1=完成
  title?: string;
  update_user?: string;
}

export interface CsNoteContent {
  content?: string;
  create_user?: string;
  created_at?: string;
  id: number | string;
}

/** 某房間（客戶）的筆記標題列表。 */
export function getCsNoteListApi(roomId: number | string) {
  return requestClient.post<CsNote[]>('/cs/api/note/list', {
    page: 1,
    per_page: 1000,
    room_id: roomId,
  });
}

/** 新增筆記標題 */
export function addCsNoteApi(data: {
  room_id: number | string;
  status?: number;
  title: string;
}) {
  return requestClient.post('/cs/api/note/add', {
    room_id: data.room_id,
    status: data.status ?? 0,
    title: data.title,
  });
}

/** 編輯筆記標題 / 狀態。 */
export function editCsNoteApi(data: {
  note_id: number | string;
  status?: number;
  title: string;
}) {
  return requestClient.post('/cs/api/note/edit', {
    note_id: data.note_id,
    status: data.status ?? 0,
    title: data.title,
  });
}

/** 某筆記的內容列表。 */
export function getCsNoteContentListApi(noteId: number | string) {
  return requestClient.post<CsNoteContent[]>('/cs/api/note_content/list', {
    note_id: noteId,
    page: 1,
    per_page: 1000,
  });
}

export function addCsNoteContentApi(data: {
  content: string;
  note_id: number | string;
}) {
  return requestClient.post('/cs/api/note_content/add', data);
}

export function editCsNoteContentApi(data: {
  content: string;
  content_id: number | string;
}) {
  return requestClient.post('/cs/api/note_content/edit', data);
}

export function deleteCsNoteContentApi(contentId: number | string) {
  return requestClient.post('/cs/api/note_content/delete', {
    content_id: contentId,
  });
}

// ===== 聊天室內 紅包/開洗分轉帳（ChatWindow Points 對話框）=====

export interface CsMemberView {
  balance?: null | number | string;
  friend_id?: null | number | string;
  game_wallet?: null | number | string;
  id?: number | string;
  nickname?: string;
  status?: number;
  thumbnail?: null | string;
  username?: string;
}

/** 目標會員資訊（餘額 / 遊戲錢包），供轉帳對話框顯示。 */
export function getCsMemberViewApi(memberId: number | string) {
  return requestClient.post<CsMemberView>('/cs/api/member/view', {
    member_id: memberId,
  });
}

export interface CsTransactionAddPayload {
  amount: number | string;
  audit_amount?: number | string;
  member_id: number | string;
  payment_password?: string;
  platform?: number | string;
  remark?: string;
  type: number;
}

/**
 * 送出開洗分 / 紅包轉帳（transaction/add）。
 * 用 body 模式取完整回應以判讀業務碼：1000 成功、7001 需付款密碼、1414 流量上限等。 */
export function addCsTransactionApi(data: CsTransactionAddPayload) {
  return requestClient.post<CsApiResult>('/cs/api/transaction/add', data, {
    responseReturn: 'body',
  });
}

// ===== 聊天室 限流紀錄（ChatWindow showFlowLimitRecord）=====

export interface CsFlowLimitRow {
  bonus?: number | string;
  created_at?: string;
  flow_limit?: number | string;
  status?: string;
  type?: string;
  updated_at?: string;
  valid_bet?: number | string;
}

/**
 * 會員限流（洗碼量）紀錄。member/flowLimit。
 * 後端 per_page 上限 1000，超過會被靜默夾到 1000（不回錯誤）；
 * 限流紀錄是單一會員的，筆數不會到這個量，一次取回後由對話框直接渲染。
 */
export function getCsFlowLimitApi(p: {
  memberId: number | string;
  userId: number | string;
}) {
  return requestClient.post<CsFlowLimitRow[]>('/cs/api/member/flowLimit', {
    member_id: p.memberId,
    page: 1,
    per_page: 1000,
    user_id: p.userId,
  });
}

// ===== 聊天室 會員銀行帳號（ChatWindow showEditBankAccount）=====
// 兩種模式：has_bank_card_setting==1 走「銀行卡(多張)」，否則走「銀行帳號(單筆)」。

/** 銀行選項 */
export interface CsBankOption {
  code?: string;
  id: number | string;
  name: string;
}
export function getCsBankOptionsApi() {
  return requestClient.post<CsBankOption[]>('/cs/api/orderType/list', {});
}

export interface CsBankCardSetting {
  bank_card_amount?: number | string;
  has_bank_card_setting?: number | string;
}
export function getCsBankCardSettingApi(username: string) {
  return requestClient.post<CsBankCardSetting>(
    '/cs/api/members/hasBankCardSetting',
    { username },
  );
}

export interface CsBankAccount {
  account_name?: string;
  account_name_modify?: number | string;
  account_no?: string;
  bank_id?: number | string;
  bsb_code_alias?: string;
  enable_bsb_code?: number | string;
  id?: number | string;
  is_first?: number | string;
}
export function getCsBankAccountApi(username: string) {
  return requestClient.post<CsBankAccount>('/cs/api/members/bank-account', {
    username,
  });
}
export function saveCsBankAccountApi(data: {
  account_name: string;
  account_no: string;
  bank_id: number | string;
  exist_data_id?: number | string;
  operator_cs: number | string;
  username: string;
}) {
  return requestClient.post('/cs/api/members/bank-account/create', data);
}

export interface CsBankCard {
  account_name?: string;
  account_no?: string;
  bank_id?: number | string;
  id?: number | string;
}
export interface CsBankCardData {
  account?: CsBankCard[];
  account_name_modify?: number | string;
  is_first?: number | string;
}
export function getCsBankCardApi(username: string) {
  return requestClient.post<CsBankCardData>('/cs/api/members/bank-card', {
    username,
  });
}
export function saveCsBankCardApi(data: {
  account: {
    account_name: string;
    account_no: string;
    bank_id?: number | string;
    exist_data_id?: number | string;
  }[];
  operator_cs: number | string;
  username: string;
}) {
  return requestClient.post('/cs/api/members/bank-card/create', data);
}
export function deleteCsBankCardApi(data: {
  operator_cs: number | string;
  username: string;
}) {
  return requestClient.post('/cs/api/members/bank-card/delete', data);
}
