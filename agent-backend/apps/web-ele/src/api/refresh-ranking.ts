import type { PagedResult, Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 活動下拉 */
export interface RefreshRankingEvent {
  agent_username?: string;
  end_time?: string;
  id: number | string;
  start_time?: string;
  title?: string;
}

export async function getRefreshRankingEventListApi() {
  return requestClient.post<RefreshRankingEvent[]>(
    '/api/refresh_ranking/event_list',
    {},
  );
}

/** 期數列表 */
export interface RefreshRankingPeriod {
  detail_id?: number | string;
  end_time?: string;
  no?: number | string;
  point?: number | string;
  start_time?: string;
}

export async function getRefreshRankingListApi(params: {
  event_id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<RefreshRankingPeriod>>(
    '/api/refresh_ranking/list',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
}

/** 派發獎勵列表 */
export interface RefreshRankingPayoutRow {
  end_time?: string;
  id: number | string;
  no?: number | string;
  start_time?: string;
  status?: number | string;
}

export async function getRefreshRankingPayoutListApi(params: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<RefreshRankingPayoutRow>>(
    '/api/refresh_ranking/get_refresh_ranking',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 派發名單 */
export interface RefreshRankingRankingRow {
  agent_username?: string;
  bet_amount?: number | string;
  member_username?: string;
  ranking?: number | string;
}

export async function getRefreshRankingPayoutRankingApi(params: {
  id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<RefreshRankingRankingRow>>(
    '/api/refresh_ranking/payout_ranking',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 排除名單 */
export interface RefreshRankingExcludeRow {
  agent_username?: string;
  first_name?: string;
  last_login_ip?: number;
  member_username?: string;
  register_ip?: number;
}

export async function getRefreshRankingPayoutExcludeApi(params: {
  id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<RefreshRankingExcludeRow>>(
    '/api/refresh_ranking/payout_exclude',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 期數下拉 */
export interface RefreshRankingPeriodOption {
  end_time?: string;
  id: number | string;
  no?: number | string;
  start_time?: string;
}

export async function getRefreshRankingPeriodDropdownApi() {
  return requestClient.post<RefreshRankingPeriodOption[]>(
    '/api/dropdown/refresh_ranking',
    {},
  );
}

/** 排除會員名單 */
export interface RefreshRankingExcludeMemberRow {
  agent_username?: string;
  bet_amount?: number | string;
  bet_time?: string;
  first_name?: string;
  id: number | string;
  is_calc?: number | string;
  last_login_ip?: number;
  member_username?: string;
  mobile?: string;
  ranking?: number | string;
  register_ip?: number;
}

export async function getRefreshRankingPeriodApi(params: {
  page?: number;
  per_page?: number;
  period_id: number | string;
  show_ranking?: number | string;
  type?: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: {
      bet_cnt?: number | string;
      main_data?: RefreshRankingExcludeMemberRow[];
    };
    message: string;
    pagination?: Pagination;
  }>(
    '/api/refresh_ranking/period',
    { page: 1, per_page: 50, show_ranking: 100, type: 1, ...params },
    { responseReturn: 'body' },
  );
}

/** 下注紀錄 */
export interface RefreshRankingBetRow {
  agent_username?: string;
  bet_amount?: number | string;
  bet_time?: string;
  game_name?: string;
  game_no?: string;
  member_username?: string;
  valid_bet_amount?: number | string;
  vendor_name?: string;
  win_lose?: number | string;
}

export interface RefreshRankingBetSummary {
  bet_amount?: number | string;
  valid_bet_amount?: number | string;
  win_lose?: number | string;
}

export async function getRefreshRankingBetRecordApi(params: {
  agent_username?: string;
  end_time: string;
  game_id?: number | string;
  game_no?: string;
  game_type?: number | string;
  member_username?: string;
  page?: number;
  per_page?: number;
  sort_by?: number | string;
  start_time: string;
  vendor_id: number | string;
  whitch_time?: number | string;
}) {
  return requestClient.post<
    PagedResult<RefreshRankingBetRow> & {
      summary_data?: RefreshRankingBetSummary;
    }
  >(
    '/api/refresh_ranking/bet_record',
    {
      game_id: 0,
      game_type: 0,
      page: 1,
      per_page: 25,
      sort_by: 1,
      whitch_time: 1,
      ...params,
    },
    { responseReturn: 'body' },
  );
}

/** 遊戲商會員帳號下拉 */
export async function getRefreshRankingVendorDropdownApi() {
  return requestClient.post<Array<{ id: number | string; name: string }>>(
    '/api/refresh_ranking/vendor_dropdown_list',
    {},
  );
}

/** 遊戲商會員帳號列表 */
export interface RefreshRankingVendorAccountRow {
  agent_username?: string;
  id: number | string;
  member_created_at?: string;
  member_id?: number | string;
  member_nickname?: string;
  member_username?: string;
  vendor_id?: number | string;
  vendor_name?: string;
  vendor_username?: string;
}

export async function getRefreshRankingVendorAccountsApi(params: {
  account_name?: string;
  page?: number;
  per_page?: number;
  username?: string;
  vendor_id: number | string;
}) {
  return requestClient.post<PagedResult<RefreshRankingVendorAccountRow>>(
    '/api/refresh_ranking/vendor_accounts',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/** 活動起始日 */
export async function getRefreshRankingEventInfoApi(eventId: number | string) {
  return requestClient.post<{ code: number; data?: string; message: string }>(
    '/api/refresh_ranking/event_info',
    { event_id: eventId },
    { responseReturn: 'body' },
  );
}

/** 活動詳細 */
export interface RefreshRankingPrize {
  id?: number | string;
  level?: number | string;
  name?: string;
  number?: number | string;
  point?: number | string;
  point_percentage?: number | string;
  point_type?: number | string;
  type?: number | string;
}

export interface RefreshRankingEventView {
  agent_id?: number | string;
  agent_username?: string;
  content?: string;
  detail?: { end_time?: string; start_time?: string };
  end_time?: string;
  flow_limit_game_type?: string;
  flow_limit_multiple?: number | string;
  flow_limit_vendor_id?: string;
  languages?: Array<{
    content?: string;
    language_id: number | string;
    title?: string;
  }>;
  prizes?: RefreshRankingPrize[];
  start_time?: string;
  title?: string;
}

export async function getRefreshRankingViewApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: RefreshRankingEventView;
    message: string;
  }>('/api/refresh_ranking/view', { id }, { responseReturn: 'body' });
}

/** 更新期數獎勵點數 */
export async function updateRefreshRankingPointApi(params: {
  id: number | string;
  point: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/refresh_ranking/update_point',
    params,
    { responseReturn: 'body' },
  );
}

/** 期數派發結算 */
export async function payoutRefreshRankingApi(id: number | string) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/refresh_ranking/payout',
    { id },
    { responseReturn: 'body' },
  );
}

/** 切換排除會員是否計算 */
export async function updateRefreshRankingExcludeApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/refresh_ranking/update_exclude',
    params,
    { responseReturn: 'body' },
  );
}

/** 新增期數 */
export async function addRefreshRankingPeriodApi(params: {
  event_id: number | string;
  period: number | string;
  point: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/refresh_ranking/add_period',
    params,
    { responseReturn: 'body' },
  );
}

/** 編輯活動 */
export async function editRefreshRankingApi(params: {
  event_content?: string;
  event_languages?: Array<{
    content?: string;
    language_id: number | string;
    title?: string;
  }>;
  id: number | string;
  title?: string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/refresh_ranking/edit',
    params,
    { responseReturn: 'body' },
  );
}
