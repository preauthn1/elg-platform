import { requestClient } from '#/api/request';

/**
 * 代理數據看板（target/tickets#4180 第一階段）。
 *
 * 時間規則：`start_time` / `end_time` 直接送「使用者所選時區的牆鐘」，時區由 `tz_offset` 表達。
 * 本組端點是時區感知的，**不要**再套 `toApiTime()` / `fromApiTime()`（既有報表那些呼叫是為了
 * 補「端點不懂時區」的過渡做法，與本組無關）。
 */

/** 七項成本；`vendor_fee` 來自注單彙總，其餘來自事件彙總 */
export interface AgentDashboardCost {
  event: string;
  /** 代收（充值）的金流商手續費 */
  payment_fee: string;
  /** 活動 V2 錢包剩餘回收（正數；計入 NGR 時為加回） */
  recycle: string;
  /** 返水成本＝三層代分潤＋無限代獎勵（對齊 SR11 的 3 layers of agents mode ＋ 全民代理） */
  rebate: string;
  /** 禮碼成本（對齊 SR11 的 rollingchip） */
  rolling_chip: string;
  sms: string;
  vendor_fee: string;
  vip: string;
  /** 代付（提現）的金流商手續費 */
  withdrawal_fee: string;
}

export interface AgentDashboardSourceBreakdown {
  admin: string;
  auto: string;
  third_party: string;
}

/** 一組指標的原始量；率、人均、NGR、貢獻佔比皆由前端推導 */
export interface AgentDashboardMetrics {
  bet: { amount: string; ggr: string; payout: string };
  cost: AgentDashboardCost;
  deposit: { amount: string; member_count: number };
  first_deposit: { amount: string; count: number };
  real_bet_member_count: number;
  registration: { count: number };
  repeat_deposit: { amount: string; count: number };
  withdrawal: { amount: string; member_count: number };
  /** 充值來源明細：三方充值 / 自助上分 / 後台開分（member_hourly_statistics 的 *_deposit_amount） */
  deposit_sources: AgentDashboardSourceBreakdown;
  /** 提現來源明細：三方提現 / 自助洗分 / 後台洗分 */
  withdrawal_sources: AgentDashboardSourceBreakdown;
}

export interface AgentDashboardParams {
  agent_username?: string;
  end_time: string;
  /** line=含所有下級（預設）；self=僅直屬 */
  scope?: 'line' | 'self';
  start_time: string;
  /** 上述時間的時區偏移小時（−12〜+12） */
  tz_offset: number;
}

export interface AgentDashboardOverview {
  /** 貢獻佔比的分母：登入者可視範疇的同期合計 */
  scope_total: AgentDashboardMetrics;
  target: AgentDashboardMetrics;
}

export interface AgentDashboardRetention {
  denominator: number;
  matrix: Record<
    'bet' | 'deposit' | 'login',
    { d1: number; d7: number; d30: number }
  >;
}

export interface AgentDashboardAgentRow {
  agent_id: number;
  has_children: boolean;
  hierarchy: number;
  metrics: AgentDashboardMetrics;
  nickname: null | string;
  parent_username: null | string;
  username: string;
}

export interface AgentDashboardAgentList {
  list: AgentDashboardAgentRow[];
  page_summary: AgentDashboardMetrics;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  total_summary: AgentDashboardMetrics;
}

interface Envelope<T> {
  code: number | string;
  data?: T;
  message?: string;
}

function post<T>(path: string, params: object) {
  return requestClient.post<Envelope<T>>(
    `/agent/api/report/agent-dashboard/${path}`,
    params,
    { responseReturn: 'body' },
  );
}

/** 指標總覽（指標卡 ①〜⑤） */
export async function getAgentDashboardOverviewApi(
  params: AgentDashboardParams,
) {
  return post<AgentDashboardOverview>('overview', params);
}

/** 留存矩陣（指標卡 ⑥） */
export async function getAgentDashboardRetentionApi(
  params: AgentDashboardParams,
) {
  return post<AgentDashboardRetention>('retention', params);
}

/** 代理明細列表；`parent_agent_id` 空＝查詢對象的直屬下級，帶值＝該代理的下一層 */
export async function getAgentDashboardAgentListApi(
  params: AgentDashboardParams & {
    page?: number;
    parent_agent_id?: number;
    per_page?: number;
  },
) {
  return post<AgentDashboardAgentList>('agent-list', params);
}

/** 明細分組：一組指標一張明細（同組的指標格子點下去開同一張表） */
export type AgentDashboardDetailGroup =
  | 'bet'
  | 'cost_event'
  | 'cost_payment_fee'
  | 'cost_rebate'
  | 'cost_recycle'
  | 'cost_rolling_chip'
  | 'cost_sms'
  | 'cost_vendor_fee'
  | 'cost_vip'
  | 'cost_withdrawal_fee'
  | 'deposit'
  | 'deposit_admin'
  | 'deposit_auto'
  | 'deposit_third_party'
  | 'first_deposit'
  | 'registration'
  | 'repeat_deposit'
  | 'retention'
  | 'withdrawal'
  | 'withdrawal_admin'
  | 'withdrawal_auto'
  | 'withdrawal_third_party';

export interface AgentDashboardDetailColumn {
  key: string;
  label_key: string;
  type: 'bool' | 'datetime' | 'int' | 'money' | 'text';
}

/** 一列＝一位會員的期間彙總；共同欄位固定，其餘由 columns 決定 */
export interface AgentDashboardDetailRow {
  agent_username: null | string;
  hierarchy: number;
  member_id: number;
  registered_at: null | string;
  username: string;
  [key: string]: boolean | null | number | string;
}

export interface AgentDashboardDetail {
  columns: AgentDashboardDetailColumn[];
  group: AgentDashboardDetailGroup;
  list: AgentDashboardDetailRow[];
  page_summary: Record<string, number | string>;
  pagination: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  /** 輸出時間欄的時區基準（等同送出的 tz_offset），前端不需再平移 */
  time_basis: string;
  total_summary: Record<string, number | string>;
}

/** 匯出用：一次回全部（上限 `limit`），不含分頁與加總 */
export interface AgentDashboardDetailExport {
  columns: AgentDashboardDetailColumn[];
  group: AgentDashboardDetailGroup;
  /** 後端一次回的列數上限；列數等於它就代表被截斷了 */
  limit: number;
  list: AgentDashboardDetailRow[];
  time_basis: string;
}

/** 指標明細下鑽 */
export async function getAgentDashboardDetailApi(
  params: AgentDashboardParams & {
    group: AgentDashboardDetailGroup;
    page?: number;
    per_page?: number;
    retention_day?: number;
    username?: string;
  },
) {
  return post<AgentDashboardDetail>('detail', params);
}

/**
 * 指標明細匯出：與 `detail` 同一份名單與欄位，但一次回全部（上限 10 萬列）。
 *
 * 後端不算總筆數也不做加總——匯出檔只要資料列，省下的那幾段查詢在全站範圍差十幾秒。
 * 因此**不要**帶 `page` / `per_page`，帶了也會被忽略。
 */
export async function getAgentDashboardDetailExportApi(
  params: AgentDashboardParams & {
    group: AgentDashboardDetailGroup;
    retention_day?: number;
    username?: string;
  },
) {
  return post<AgentDashboardDetailExport>('detail-export', params);
}
