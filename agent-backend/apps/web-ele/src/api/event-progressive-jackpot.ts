import { requestClient } from '#/api/request';

/** 後端統一信封 */
interface Body<T> {
  code: number | string;
  data?: T;
  message?: string;
  pagination?: null | { current?: number; pages?: number; total?: number };
}

// ---------- 活動設定 ----------

export interface EventProgressiveJackpotTitleRow {
  content?: string;
  language_id: number | string;
  title?: string;
}

export interface EventProgressiveJackpotImageRow {
  image?: string;
  language_id: number | string;
}

export interface EventProgressiveJackpotSetting {
  agent_id?: null | number | string;
  agent_username?: null | string;
  content?: string;
  /** 1 = 創建帳號後第一筆、2 = 活動開始後第一筆 */
  first_deposit_type?: number | string;
  id?: number | string;
  ip_block?: number | string;
  mobile_block?: number | string;
  realname_block?: number | string;
  sort?: number | string;
  status?: number | string;
  title?: string;
}

export interface EventProgressiveJackpotBetSettingRow {
  game_type_id: number | string;
  /** 原始比例，0.3 代表 30% */
  rate: number | string;
}

export interface EventProgressiveJackpotTierRow {
  deposit_point?: number | string;
  expire_day?: number | string;
  /** 0 為首儲檔，其餘依序 1、2、3... */
  level: number | string;
  max_reward?: number | string;
  reward?: number | string;
  reward_percentage?: number | string;
  /** 1 = 固定金額、2 = 百分比 */
  reward_type?: number | string;
}

export interface EventProgressiveJackpotViewData {
  bet_setting?: EventProgressiveJackpotBetSettingRow[];
  exclude_agent?: { agent_id: number | string; agent_username: string }[];
  image?: EventProgressiveJackpotImageRow[];
  setting?: EventProgressiveJackpotSetting;
  tier?: EventProgressiveJackpotTierRow[];
  title?: EventProgressiveJackpotTitleRow[];
}

export async function getEventProgressiveJackpotViewApi() {
  return requestClient.post<Body<EventProgressiveJackpotViewData>>(
    '/api/event_progressive_jackpot/view',
    {},
    { responseReturn: 'body' },
  );
}

/** 標題與內文；language_id 0 為預設語系，標題或內文任一為空的語系不送 */
export async function updateEventProgressiveJackpotTitleApi(
  title: EventProgressiveJackpotTitleRow[],
) {
  return requestClient.post<Body<unknown>>(
    '/api/event_progressive_jackpot/update_tittle',
    { title },
    { responseReturn: 'body' },
  );
}

/** Banner；image 為空的語系不送 */
export async function updateEventProgressiveJackpotImageApi(payload: {
  image: EventProgressiveJackpotImageRow[];
  sort: number | string;
}) {
  return requestClient.post<Body<unknown>>(
    '/api/event_progressive_jackpot/update_image',
    payload,
    { responseReturn: 'body' },
  );
}

export async function updateEventProgressiveJackpotSettingApi(payload: {
  agent_id: number | string;
  exclude_agent: (number | string)[];
  first_deposit_type: number | string;
  id: number | string;
  ip_block: number;
  mobile_block: number;
  realname_block: number;
  status: number;
}) {
  return requestClient.post<Body<unknown>>(
    '/api/event_progressive_jackpot/update_setting',
    payload,
    { responseReturn: 'body' },
  );
}

/** 指定參與遊戲與解鎖比例，整批取代；rate 為原始比例（畫面輸入的百分比 / 100） */
export async function updateEventProgressiveJackpotBetSettingApi(
  game_type: EventProgressiveJackpotBetSettingRow[],
) {
  return requestClient.post<Body<unknown>>(
    '/api/event_progressive_jackpot/update_bet_setting',
    { game_type },
    { responseReturn: 'body' },
  );
}

/** 送出用的檔位；level 由後端依陣列順序接續（首儲固定 0） */
export interface EventProgressiveJackpotTierPayload {
  deposit_point: number;
  expire_day: number;
  is_first_deposit: boolean;
  max_reward: number;
  reward: number;
  reward_percentage: number;
  reward_type: number;
}

/** 檔位設定，整批取代 */
export async function updateEventProgressiveJackpotTierSettingApi(
  tier: EventProgressiveJackpotTierPayload[],
) {
  return requestClient.post<Body<unknown>>(
    '/api/event_progressive_jackpot/update_tier_setting',
    { tier },
    { responseReturn: 'body' },
  );
}

// ---------- 活動紀錄 ----------

export interface EventProgressiveJackpotJoinUserRow {
  created_at?: string;
  expire_time?: null | string;
  id: number | string;
  level: number | string;
  member_id: number | string;
  member_username?: string;
  point?: number | string;
  receive?: number | string;
  reward?: number | string;
  status?: number | string;
}

export interface EventProgressiveJackpotJoinUserParams {
  end_date: string;
  /** 空字串代表「全部」，呼叫端不要帶這個欄位，否則後端會當成 0（首儲檔） */
  level?: number | string;
  member_username?: string;
  page: number;
  per_page: number;
  start_date: string;
  /** 空字串代表「全部」，呼叫端不要帶這個欄位，否則後端會當成 0（進行中） */
  status?: number | string;
}

export async function getEventProgressiveJackpotJoinUserListApi(
  params: EventProgressiveJackpotJoinUserParams,
) {
  return requestClient.post<Body<EventProgressiveJackpotJoinUserRow[]>>(
    '/api/event_progressive_jackpot/join_user_list',
    params,
    { responseReturn: 'body' },
  );
}

/** 手動解除鎖定（status=4 已鎖定 → 進行中／等待解鎖） */
export async function unlockEventProgressiveJackpotJoinUserApi(
  id: number | string,
) {
  return requestClient.post<Body<unknown>>(
    '/api/event_progressive_jackpot/unlock_join_user',
    { id },
    { responseReturn: 'body' },
  );
}

// ---------- 日誌 ----------

export interface EventProgressiveJackpotEventLogRow {
  /** type=1 為變動前彩金、2 為變動前已領、3 為變動前狀態 */
  after_value?: null | number | string;
  before_value?: null | number | string;
  bet?: number | string;
  created_at?: string;
  game_type?: number | string;
  point?: null | number | string;
  rate?: number | string;
  /** 1 = 解鎖彩金、2 = 領取彩金、3 = 修改狀態 */
  type?: number | string;
}

export async function getEventProgressiveJackpotEventLogApi(params: {
  level: number | string;
  member_id: number | string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<Body<EventProgressiveJackpotEventLogRow[]>>(
    '/api/event_progressive_jackpot/event_log',
    params,
    { responseReturn: 'body' },
  );
}

export interface EventProgressiveJackpotDepositLogRow {
  after_deposit_point?: number | string;
  amount?: number | string;
  before_deposit_point?: number | string;
  order_no?: string;
  order_time?: string;
}

export async function getEventProgressiveJackpotDepositLogApi(params: {
  level: number | string;
  member_id: number | string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<Body<EventProgressiveJackpotDepositLogRow[]>>(
    '/api/event_progressive_jackpot/deposit_log',
    params,
    { responseReturn: 'body' },
  );
}

export interface EventProgressiveJackpotMemberDetail {
  /** null 代表未命中重複；有值時該值即命中的會員帳號 */
  duplicate_ip?: null | string;
  duplicate_mobile?: null | string;
  duplicate_realname?: null | string;
  first_deposit?: number | string;
  total_deposit_point?: number | string;
}

export async function getEventProgressiveJackpotMemberDetailApi(
  member_id: number | string,
) {
  return requestClient.post<Body<EventProgressiveJackpotMemberDetail>>(
    '/api/event_progressive_jackpot/member_detail',
    { member_id },
    { responseReturn: 'body' },
  );
}

// ---------- 下拉 ----------

/** 遊戲類別下拉，只取啟用中 */
export async function getEventProgressiveJackpotGameTypesApi() {
  return requestClient.post<{ id: number; name: string }[]>(
    '/api/gameType/list',
    { status: 1 },
  );
}

/** 遊戲類別全量（不篩狀態），日誌對照舊紀錄用，避免對到已停用的類別查無名稱 */
export async function getEventProgressiveJackpotAllGameTypesApi() {
  return requestClient.post<{ id: number; name: string }[]>(
    '/api/gameType/list',
    {},
  );
}

// ---------- Banner 圖片上傳 ----------

/** 寫圖庫（type:1）並回傳圖片路徑；失敗時丟出後端的錯誤訊息 */
export async function uploadEventProgressiveJackpotImageApi(payload: {
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
