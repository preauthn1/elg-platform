import { requestClient } from '#/api/request';

/** 活動 V2（event_v2）API。 */

export interface EventV2Body<T = any> {
  code: number;
  data?: T;
  message: string;
}

export interface EventV2PagedBody<T = any> {
  code: number;
  data?: {
    items: T[];
    pagination?: { current: number; pages: number; total: number };
    /** 依同一組篩選算「全部」的統計（不是當頁），目前只有 join-list 回（target/tickets#4235） */
    summary?: EventV2JoinSummary;
  };
  message: string;
}

/**
 * 參加紀錄八張統計卡（target/tickets#4235）：total_count 是整數，其餘為 4 位小數字串；沒資料為 0。
 * 金額卡只算主錢包型、積分卡只算積分型，兩者不混。
 */
export interface EventV2JoinSummary {
  /** 已發放：主錢包型、完成，取實領 withdraw */
  total_issued?: string;
  /** 進行中：主錢包型、參加中的 reward */
  total_joining?: string;
  /** 放棄：主錢包型、放棄＋後台操作放棄的 reward */
  total_quit?: string;
  /** 重置：主錢包型、系統重置的 reward */
  total_reset?: string;
  /** 總領取次數 */
  total_count?: number;
  /** 總獎勵金額：主錢包型 reward，全部狀態 */
  total_reward?: string;
  /** 總消耗積分：實際扣掉的門票積分 */
  total_score?: string;
  /** 總獲得積分：積分型 reward */
  total_score_reward?: string;
}

/** 領取去向：1=主錢包 2=積分（apis EventV2WithdrawTypeEnum） */
export type EventV2WithdrawType = 1 | 2;

/**
 * 週期值：0=不分週期 1=日 2=週 3=月 4=年（apis EventV2PeriodEnum，target/tickets#4234）。
 * 紅包有兩個週期欄位、各自獨立（Frank 2026-09-30）：
 * - member_period「可重複參加」：同一會員多久能再抽（每人次數／金額的累計區間，也是不勾參加即領獎時的作廢週期）
 * - total_period「包括期間」：全站總次數／總金額多久重新開一池
 * 只有紅包（handler_class AngpowService）可設非 0，後端會擋；其他類型的週期由類型決定。
 */
export type EventV2Period = 0 | 1 | 2 | 3 | 4;

/** 可設定活動週期的 handler；與 apis ValidatesAngpow 同一把尺 */
export const EVENT_V2_PERIOD_HANDLER = 'AngpowService';

/**
 * 目標用戶類型：0=不限制 1=指定會員 2=會員群組（apis MemberRestrictionTargetEnum，target/tickets#4234）。
 * create／update 送 target_type＋target_ids；detail 回 target_type＋targets[{id, name}]（會員給帳號、群組給群組名）。
 */
export type EventV2TargetType = 0 | 1 | 2;

export interface EventV2ListRow {
  agent_username?: string;
  end_time?: string;
  event_detail_id: number;
  /** 活動分類名稱（event_v2.name，與「選擇活動分類」下拉同一份，target/tickets#4234） */
  event_type_name?: null | string;
  /** 活動分類 ID（event_v2.id） */
  event_v2_id?: number;
  member_period?: EventV2Period;
  name?: string;
  start_time?: string;
  total_period?: EventV2Period;
  status?: number;
  withdraw_type?: EventV2WithdrawType;
  [key: string]: any;
}

export interface EventV2JoinRow {
  agent_username?: string;
  balance_limit?: number | string;
  bonus_code?: string;
  end_time?: string;
  event_v2_detail_id?: number;
  flow_limit?: number | string;
  id?: number;
  ip?: string;
  join_time?: string;
  member_username?: string;
  name?: string;
  /** 獎勵金額：只有主錢包型有值，積分型為 null，畫面顯示「—」（target/tickets#4235） */
  reward?: null | number | string;
  /** 消耗積分：這筆參加扣掉的門票積分；沒扣分的活動（非紅包）為 null，畫面顯示「—」不顯示 0（target/tickets#4235） */
  score_cost?: null | number | string;
  /** 獲得積分：只有積分型有值，主錢包型為 null，畫面顯示「—」（target/tickets#4235） */
  score_reward?: null | number | string;
  status?: number;
  /** 洗分（實際轉進主錢包）：積分型為 null，畫面顯示「—」 */
  withdraw?: null | number | string;
  withdraw_limit?: number | string;
  /** 參加當下自活動設定凍結的領取去向 */
  withdraw_type?: EventV2WithdrawType;
  [key: string]: any;
}

export interface EventV2RecycleRow {
  agent_username?: string;
  created_at?: string;
  final_balance?: number | string;
  member_username?: string;
  name?: string;
  recycle_amount?: number | string;
  status?: number;
  withdraw?: number | string;
  [key: string]: any;
}

export interface EventV2RejectRow {
  agent_username?: string;
  allow_time?: string;
  event_v2_detail_id?: number;
  event_v2_user_join_id?: number;
  id?: number;
  ip?: string;
  member_username?: string;
  name?: string;
  reject_reason?: string;
  reject_time?: string;
  status?: number;
  [key: string]: any;
}

export interface EventV2WalletRow {
  agent_username?: string;
  balance?: number | string;
  created_at?: string;
  event_v2_detail_id?: number;
  join_id?: number;
  member_username?: string;
  name?: string;
  wallet_id?: number;
  [key: string]: any;
}

interface ListFilter {
  agent_username: string;
  end_time: string;
  event_v2_detail_id?: number | string;
  event_v2_id: number | string;
  member_username: string;
  page: number;
  per_page: number;
  start_time: string;
  status: number | string;
}

/** 活動列表 */
export async function getEventV2AdminListApi(data: {
  agent_username: string;
  page: number;
  per_page: number;
  /** 不帶＝全部 */
  withdraw_type?: EventV2WithdrawType;
}) {
  return requestClient.post<EventV2PagedBody<EventV2ListRow>>(
    '/agent/api/event-v2/list',
    data,
    { responseReturn: 'body' },
  );
}

/** 「設定條件」某一格可填的一個變數／key／整段陣列（target/tickets#4231 需求 7） */
export interface EventV2FieldParamItem {
  /** 說明 */
  description: string;
  /** 可直接複製的範例：變數給公式片段，key 給 JSON 物件，陣列給整段 */
  example: string;
  /** 畫面顯示的名稱（變數名／key 名／陣列形狀） */
  name: string;
  /** 值的型別，變數為 null */
  type: null | string;
}

/** 某一格在某種領取去向下的參數清單 */
export interface EventV2FieldParamSet {
  items: EventV2FieldParamItem[];
  /** variables=公式變數、keys=JSON key、arrays=整段陣列（items 只有一筆）、none=沒有任何類型會讀 */
  kind: 'arrays' | 'keys' | 'none' | 'variables';
}

/**
 * 某一格的參數：main 主錢包型；score 只在可選積分的類型、且只有提款限制公式／配置兩格才有，
 * 前端依目前選的領取去向決定用哪一組。
 */
export interface EventV2FieldParamEntry {
  main: EventV2FieldParamSet;
  score?: EventV2FieldParamSet;
}

export interface EventV2Category {
  /**
   * 「設定條件」十格各自可填的參數，key 為欄位名（condition_formula…flow_config），依請求語系本地化；
   * handler 對不到時後端給 null。清單由 apis 各 handler 宣告，後端加 key 前端不用改。
   */
  field_params?: null | Record<string, EventV2FieldParamEntry>;
  /** apis app/Services/EventV2 下的處理類別名，判斷該類型可否搭配積分領取去向 */
  handler_class?: null | string;
  id: number;
  name: string;
}

/**
 * 領取去向＝積分時不開放的活動類型（PM 規則：三種陣列型獎勵配置不派發積分）。
 * 以 handler_class 判定、不寫死 id——各環境的 event_v2.id 不同；與 apis
 * EventV2WithdrawTypeEnum::SCORE_UNSUPPORTED_HANDLERS 同一份清單。
 */
export const EVENT_V2_SCORE_UNSUPPORTED_HANDLERS = new Set([
  'DailyDepositComboService',
  'DailyDepositRangeService',
  'WeeklyDepositService',
]);

/** 目標用戶的一個選項：會員給帳號、群組給群組名 */
export interface EventV2TargetOption {
  id: number;
  name: string;
}

/**
 * 目標用戶可選項（target/tickets#4234）：只列「活動代理商帳號那條線」裡的會員群組，或依關鍵字搜尋會員。
 * 以活動代理為準、不是以操作者為準；與後端建立／更新驗證同一份規則（apis EventV2TargetScope）。
 */
export async function getEventV2TargetOptionsApi(data: {
  agent_id: number | string;
  /** 會員時必填才會回資料（前綴比對、限 50 筆） */
  keyword?: string;
  target_type: 1 | 2;
}) {
  return requestClient.post<EventV2Body<EventV2TargetOption[]>>(
    '/agent/api/event-v2/target-options',
    data,
    { responseReturn: 'body' },
  );
}

/** 活動類別下拉 */
export async function getEventV2CategoryListApi() {
  return requestClient.post<EventV2Body<EventV2Category[]>>(
    '/agent/api/event-v2/event-list',
    {},
    { responseReturn: 'body' },
  );
}

/** 參加紀錄 */
export async function getEventV2JoinListApi(data: ListFilter) {
  return requestClient.post<EventV2PagedBody<EventV2JoinRow>>(
    '/agent/api/event-v2/join-list',
    data,
    { responseReturn: 'body' },
  );
}

/** 回收紀錄 */
export async function getEventV2RecycleListApi(data: ListFilter) {
  return requestClient.post<EventV2PagedBody<EventV2RecycleRow>>(
    '/agent/api/event-v2/recycle-list',
    data,
    { responseReturn: 'body' },
  );
}

/** 拒絕紀錄 */
export async function getEventV2RejectListApi(data: ListFilter) {
  return requestClient.post<EventV2PagedBody<EventV2RejectRow>>(
    '/agent/api/event-v2/reject-list',
    data,
    { responseReturn: 'body' },
  );
}

/** 拒絕明細 */
export async function getEventV2RejectDetailApi(rejectJoinId: number | string) {
  return requestClient.post<EventV2Body<any[]>>(
    '/agent/api/event-v2/reject-detail',
    { reject_join_id: rejectJoinId },
    { responseReturn: 'body' },
  );
}

/** 允許參加 */
export async function eventV2AllowJoinApi(id: number | string) {
  return requestClient.post<EventV2Body>(
    '/agent/api/event-v2/allow-join',
    { id },
    { responseReturn: 'body' },
  );
}

/** 活動錢包管理列表 */
export async function getEventV2WalletListApi(data: {
  agent_username: string;
  member_username: string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<EventV2PagedBody<EventV2WalletRow>>(
    '/agent/api/event-v2/wallet-management',
    data,
    { responseReturn: 'body' },
  );
}

/** 修改活動錢包餘額 */
export async function updateEventV2WalletBalanceApi(data: {
  balance: number | string;
  wallet_id: number | string;
}) {
  return requestClient.post<EventV2Body>(
    '/agent/api/event-v2/update-wallet-balance',
    data,
    { responseReturn: 'body' },
  );
}

/** 放棄活動 */
export async function quitEventV2Api(joinId: number | string) {
  return requestClient.post<EventV2Body>(
    '/agent/api/event-v2/quit',
    { join_id: joinId },
    { responseReturn: 'body' },
  );
}

/** 建立活動 V2 */
export async function createEventV2Api(data: Record<string, any>) {
  return requestClient.post<EventV2Body>('/agent/api/event-v2/create', data, {
    responseReturn: 'body',
  });
}

/** 更新活動 V2 */
export async function updateEventV2Api(data: Record<string, any>) {
  return requestClient.post<EventV2Body>('/agent/api/event-v2/update', data, {
    responseReturn: 'body',
  });
}
