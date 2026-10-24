import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 無限代（unlimit）模組 API。*/

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/* ── 代理查詢 agent_search ── */

export interface UnlimitAgentRow {
  bonus_direct?: number | string;
  bonus_team?: number | string;
  bonus_total?: number | string;
  memo?: string;
  performance_direct?: number | string;
  performance_team?: number | string;
  performance_total?: number | string;
  summary_date?: string;
  username?: string;
}

export interface UnlimitAgentSearchSummary {
  bonus?: number | string;
  performance?: number | string;
  performance_direct?: number | string;
  performance_team?: number | string;
}

export interface UnlimitAgentSearchBody {
  code: number | string;
  data?: UnlimitAgentRow[];
  data_summary?: UnlimitAgentSearchSummary;
  message: string;
  pagination?: Pagination;
}

/** 代理查詢 */
export async function getUnlimitAgentSearchApi(params: {
  agent?: string;
  end_time?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
}) {
  return requestClient.post<UnlimitAgentSearchBody>(
    '/api/unlimit/agentSearch',
    { agent: '', page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/* ── 會員查詢 member_search ── */

export interface UnlimitMemberRow {
  agent_level?: number | string;
  agent_name?: string;
  bonus_amount?: number | string;
  flow_amount?: number | string;
  first_username?: string;
  itself_bonus_amount?: number | string;
  itself_withdraw_amount?: number | string;
  member_direct_count?: number;
  member_team_count?: number;
  performance_amount?: number | string;
  reg_time?: string;
  topper_username?: string;
  username?: string;
  withdraw_amount?: number | string;
}

/** 會員查詢 */
export async function getUnlimitMemberSearchApi(params: {
  agent?: string;
  member?: string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<UnlimitMemberRow>>(
    '/api/unlimit/memberSearch',
    { agent: '', member: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/* ── 取消注單紀錄 cancel_record ── */

export interface UnlimitCancelRow {
  bet?: number | string;
  created_at?: string;
  operator?: string;
  type?: number | string;
  username?: string;
}

/** 取消注單紀錄 */
export async function getUnlimitCancelRecordApi(params: {
  end_time?: string;
  member_username?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
}) {
  return requestClient.post<PagedBody<UnlimitCancelRow>>(
    '/api/unlimit/getCancelRecord',
    { member_username: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/* ── 提現紀錄 withdrawal_record ── */

export interface UnlimitWithdrawalRow {
  amount?: number | string;
  created_at?: string;
  id?: number | string;
  itself_amount?: number | string;
  itself_reward_point?: number | string;
  remaining_itself_reward_points?: number | string;
  remaining_reward_points?: number | string;
  reward_point?: number | string;
  /** 1=通過、否則=拒絕 */
  type?: number | string;
  username?: string;
}

/** 提現紀錄 */
export async function getUnlimitWithdrawalRecordApi(params: {
  end_time?: string;
  member?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
}) {
  return requestClient.post<PagedBody<UnlimitWithdrawalRow>>(
    '/api/unlimit/withdrawalRecord',
    { member: '', order_no: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/* ── 業績查詢 performance ── */

export interface UnlimitPerformanceRow {
  agent_username?: string;
  bonus_direct?: number | string;
  bonus_team?: number | string;
  performance_direct?: number | string;
  performance_team?: number | string;
  summary_date?: string;
  /** 1=代理、否則=會員 */
  type?: number | string;
  username?: string;
}

/** 業績查詢 */
export async function getUnlimitPerformanceApi(params: {
  agent_username?: string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  const { end_time, start_time, ...rest } = params;
  return requestClient.post<PagedBody<UnlimitPerformanceRow>>(
    '/api/unlimit/performance',
    {
      agent_username: '',
      page: 1,
      per_page: 20,
      username: '',
      ...rest,
      end_time: `${end_time} 23:59:59`,
      start_time: `${start_time} 00:00:00`,
    },
    { responseReturn: 'body' },
  );
}

/* ── 開洗分明細 sr02 ── */

export interface UnlimitTransactionRow {
  amount?: number | string;
  created_at?: string;
  id?: number | string;
  nickname?: string;
  remark?: string;
  status?: string;
  to_limit_of_credit?: number | string;
  to_used_credit?: number | string;
  username?: string;
}

/** 開洗分明細 */
export async function getUnlimitTransactionListApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  const { end_time, start_time, ...rest } = params;
  return requestClient.post<PagedBody<UnlimitTransactionRow>>(
    '/api/unlimit/transactionList',
    {
      page: 1,
      per_page: 10,
      username: '',
      ...rest,
      end_time: `${end_time}:59`,
      start_time: `${start_time}:00`,
    },
    { responseReturn: 'body' },
  );
}

/* ── 投注排行 bet_ranking ── */

export interface UnlimitBetRankingRow {
  bet?: number | string;
  id?: number | string;
  /** 1=計算中（可點「禁止計算」）、否則=已禁止（可點「可計算」） */
  status?: number | string;
  username?: string;
}

/** 投注排行 */
export async function getUnlimitBetRankingApi(params: {
  fuzzy_query?: number | string;
  page?: number;
  per_page?: number;
  username?: string;
}) {
  return requestClient.post<PagedBody<UnlimitBetRankingRow>>(
    '/api/unlimit/betRanking',
    { fuzzy_query: 0, page: 1, per_page: 30, username: '', ...params },
    { responseReturn: 'body' },
  );
}

/** 切換投注是否計算 */
export async function updateUnlimitCancelApi(payload: {
  bet: number | string;
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<BodyOf<string>>(
    '/api/unlimit/updateCancel',
    payload,
    { responseReturn: 'body' },
  );
}
