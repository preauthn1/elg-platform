import { requestClient } from '#/api/request';

/** 後端統一信封 */
interface Body<T> {
  code: number | string;
  data?: T;
  message?: string;
  pagination?: null | { current?: number; pages?: number; total?: number };
}

// ---------- 活動設定 ----------

export interface EventInviteTitleRow {
  content?: string;
  language_id: number | string;
  title?: string;
}

export interface EventInviteImageRow {
  image?: string;
  language_id: number | string;
}

export interface EventInviteSetting {
  agent_id?: number | string;
  agent_username?: string;
  bet_point?: number | string;
  bet_reward?: number | string;
  block_type?: number | string;
  deposit_point?: number | string;
  deposit_reward?: number | string;
  id?: number | string;
  ip_block?: number | string;
  max_reward?: number | string;
  mobile_block?: number | string;
  realname_block?: number | string;
  realname_reward?: number | string;
  sort?: number | string;
  status?: number | string;
}

export interface EventInviteBetSetting {
  /** 排除遊戲商（target/tickets#4504）：與 vendor 擇一 */
  exclude_vendor?: (number | string)[];
  game_type?: (number | string)[];
  vendor?: (number | string)[];
}

export interface EventInviteViewData {
  bet_setting?: EventInviteBetSetting;
  exclude_agent?: { agent_id: number | string; agent_username: string }[];
  image?: EventInviteImageRow[];
  setting?: EventInviteSetting;
  title?: EventInviteTitleRow[];
}

export async function getEventInviteViewApi() {
  return requestClient.post<Body<EventInviteViewData>>(
    '/api/event_invite/view',
    {},
    { responseReturn: 'body' },
  );
}

/** 標題與內文；language_id 0 為預設語系，標題或內文任一為空的語系不送 */
export async function updateEventInviteTitleApi(title: EventInviteTitleRow[]) {
  return requestClient.post<Body<unknown>>(
    '/api/event_invite/update_tittle',
    { title },
    { responseReturn: 'body' },
  );
}

/** Banner；image 為空的語系不送 */
export async function updateEventInviteImageApi(payload: {
  image: EventInviteImageRow[];
  sort: number | string;
}) {
  return requestClient.post<Body<unknown>>(
    '/api/event_invite/update_image',
    payload,
    { responseReturn: 'body' },
  );
}

export async function updateEventInviteSettingApi(payload: {
  agent_id: number | string;
  bet_point: number | string;
  bet_reward: number | string;
  block_type: number | string;
  deposit_point: number | string;
  deposit_reward: number | string;
  exclude_agent: (number | string)[];
  id: number | string;
  ip_block: number;
  max_reward: number | string;
  mobile_block: number;
  realname_block: number;
  realname_reward: number | string;
  status: number;
}) {
  return requestClient.post<Body<unknown>>(
    '/api/event_invite/update_setting',
    payload,
    { responseReturn: 'body' },
  );
}

export async function updateEventInviteBetSettingApi(payload: {
  exclude_vendor: (number | string)[];
  game_type: (number | string)[];
  vendor: (number | string)[];
}) {
  return requestClient.post<Body<unknown>>(
    '/api/event_invite/update_bet_setting',
    payload,
    { responseReturn: 'body' },
  );
}

// ---------- 活動紀錄 ----------

export interface EventInviteJoinUserRow {
  bet?: number | string;
  created_at?: string;
  deposit_status?: number | string;
  id: number | string;
  invitee?: string;
  inviter?: string;
  member_id?: number | string;
  point?: number | string;
  realname_status?: number | string;
  receive?: number | string;
  status?: number | string;
}

export interface EventInviteJoinUserData {
  list: EventInviteJoinUserRow[];
  max_reward?: number | string;
  total_bet?: number | string;
  total_received?: number | string;
  total_remaining?: number | string;
  total_unlocked?: number | string;
}

export interface EventInviteJoinUserParams {
  end_date: string;
  invitee?: string;
  inviter?: string;
  page: number;
  per_page: number;
  start_date: string;
  /** 空字串代表「全部」，呼叫端不要帶這個欄位，否則後端會當成 0（進行中） */
  status?: number | string;
}

export async function getEventInviteJoinUserListApi(
  params: EventInviteJoinUserParams,
) {
  return requestClient.post<Body<EventInviteJoinUserData>>(
    '/api/event_invite/join_user_list',
    params,
    { responseReturn: 'body' },
  );
}

export async function closeEventInviteJoinUserApi(id: number | string) {
  return requestClient.post<Body<unknown>>(
    '/api/event_invite/close_join_user',
    { id },
    { responseReturn: 'body' },
  );
}

export async function openEventInviteJoinUserApi(id: number | string) {
  return requestClient.post<Body<unknown>>(
    '/api/event_invite/open_join_user',
    { id },
    { responseReturn: 'body' },
  );
}

// ---------- 日誌 ----------

export interface EventInviteEventLogRow {
  created_at?: string;
  point?: number | string;
  type?: number | string;
}

export async function getEventInviteEventLogApi(params: {
  member_id: number | string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<Body<EventInviteEventLogRow[]>>(
    '/api/event_invite/event_log',
    params,
    { responseReturn: 'body' },
  );
}

export interface EventInviteStatusLogRow {
  after_status?: number | string;
  before_status?: number | string;
  created_at?: string;
  operator?: string;
}

export async function getEventInviteStatusLogApi(params: {
  member_id: number | string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<Body<EventInviteStatusLogRow[]>>(
    '/api/event_invite/status_log',
    params,
    { responseReturn: 'body' },
  );
}

// ---------- 監控 ----------

export interface EventInviteMonitorData {
  completed_count?: number | string;
  processing_count?: number | string;
  today_new_join_count?: number | string;
  total_receive?: number | string;
  total_unlock_point?: number | string;
}

export async function getEventInviteMonitorApi() {
  return requestClient.post<Body<EventInviteMonitorData>>(
    '/api/event_invite/monitor',
    {},
    { responseReturn: 'body' },
  );
}

export interface EventInviteCloseUserRow {
  /** null 代表未命中重複；有值時該值即命中的內容 */
  duplicate_ip?: null | string;
  duplicate_mobile?: null | string;
  duplicate_realname?: null | string;
  id: number | string;
  invitee?: string;
  inviter?: string;
  status?: number | string;
}

export async function getEventInviteCloseUserListApi(params: {
  end_date: string;
  invitee?: string;
  inviter?: string;
  start_date: string;
}) {
  return requestClient.post<Body<{ list: EventInviteCloseUserRow[] }>>(
    '/api/event_invite/close_user_list',
    params,
    { responseReturn: 'body' },
  );
}

// ---------- 有效投注範圍的下拉 ----------

/** 遊戲類別下拉，只取啟用中 */
export async function getEventInviteGameTypesApi() {
  return requestClient.post<{ id: number; name: string }[]>(
    '/api/gameType/list',
    { status: 1 },
  );
}

// ---------- Banner 圖片上傳 ----------

/** 寫圖庫（type:1）並回傳圖片路徑；失敗時丟出後端的錯誤訊息 */
export async function uploadEventInviteImageApi(payload: {
  agent_id: number | string;
  base64Image: string;
}) {
  const res = await requestClient.post<Body<{ path?: string }>>(
    '/api/image/base64/upload',
    { type: 1, ...payload },
    { responseReturn: 'body' },
  );
  if (Number(res.code) !== 1000) throw new Error(res.message || '');
  return res.data?.path ?? '';
}
