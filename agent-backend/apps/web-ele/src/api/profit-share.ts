import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 佔成（公司佔成管理）模組 API。
 * 端點命名空間 api/profit_share/*、api/shareholder/*。 */

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

/** 股東分類 */
export interface ProfitShareCategory {
  id: number | string;
  name: string;
}

/** 佔成報表分類下拉 */
export async function getProfitShareCategoryApi() {
  return requestClient.post<BodyOf<ProfitShareCategory[]>>(
    '/api/shareholder/category',
    { settlement: 1 },
    { responseReturn: 'body' },
  );
}

/** 本代理網域 */
export async function getProfitShareDomainApi() {
  return requestClient.post<BodyOf<{ domain?: string }>>(
    '/api/agentDomain/self',
    {},
    { responseReturn: 'body' },
  );
}

/** 推廣邀請碼 */
export async function getProfitShareInviteCodeApi() {
  return requestClient.post<
    BodyOf<{ profit_share?: string; shareholder?: string }>
  >('/api/profit_share/agent/invite_code', {}, { responseReturn: 'body' });
}

/** 佔成報表明細列 */
export interface ProfitShareReportRow {
  agent_id: number | string;
  agent_username?: string;
  company_rate?: number | string;
  direct_profit?: number | string;
  direct_rate?: number | string;
  direct_win_lose?: number | string;
  direct_win_lose_original?: number | string;
  event_cost?: number | string;
  game_cost?: number | string;
  payment_cost?: number | string;
  profit?: number | string;
  shareholder_category_id?: number | string;
  shareholder_category_name?: string;
  team_profit?: number | string;
  team_rate?: number | string;
  team_win_lose?: number | string;
  team_win_lose_extra?: number | string;
  team_win_lose_original?: number | string;
  weighting?: number | string;
}

/** 搜尋歷史 */
export interface ProfitShareSearchAgent {
  id: number | string;
  username: string;
}

export interface ProfitShareReportData {
  data?: ProfitShareReportRow[];
  general_agent?: unknown;
  search_agent?: ProfitShareSearchAgent[];
  /** 下級佔成（>=95 顯示額外 5% 欄） */
  share?: number | string;
}

/** 佔成報表彙總 */
export async function getProfitShareReportSummaryApi(params: {
  agent_id?: number | string;
  category_type?: number | string;
  end_time: string;
  start_time: string;
}) {
  return requestClient.post<BodyOf<ProfitShareReportData>>(
    '/api/profit_share/report/summary',
    { agent_id: 0, category_type: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** 佔成錢包帳變紀錄（已處理）列 */
export interface ProfitShareTransactionRow {
  amount?: number | string;
  balance?: number | string;
  before_balance?: number | string;
  created_at?: string;
  parent_username?: string;
  /** 1=成功（綠）、否則失敗（紅） */
  status?: number | string;
  status_name?: string;
  type_name?: string;
  username?: string;
}

/** 佔成錢包帳變紀錄 */
export async function getProfitShareTransactionLogApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  const body: Record<string, any> = {
    end_time: params.end_time,
    page: params.page ?? 1,
    per_page: params.per_page ?? 10,
    start_time: params.start_time,
  };
  if (params.username) body.username = params.username;
  return requestClient.post<PagedBody<ProfitShareTransactionRow>>(
    '/api/profit_share/agent/transaction_log',
    body,
    { responseReturn: 'body' },
  );
}

/** 未處理排程列 */
export interface ProfitShareScheduleRow {
  created_at?: string;
  data_value?: number | string;
  estimated_effective_time?: string;
  schedule_log_id?: number | string;
  /** 0=未執行（可取消） */
  schedule_status?: number | string;
  schedule_status_txt?: string;
  schedule_type_id?: number | string;
}

/** 未處理佔成排程 */
export async function getProfitShareUnexecutedScheduleApi(params: {
  username?: string;
}) {
  return requestClient.post<BodyOf<{ data?: ProfitShareScheduleRow[] }>>(
    '/api/profit_share/agent_schedule/unexecuted_list',
    { status: 0, username: params.username ?? '' },
    { responseReturn: 'body' },
  );
}

/** 佔成錢包餘額 */
export interface ProfitShareAgentWallet {
  main?: number | string;
  profit_share?: number | string;
}

export async function getProfitShareAgentWalletViewApi() {
  return requestClient.post<BodyOf<ProfitShareAgentWallet>>(
    '/api/profit_share/agent_wallet/view',
    {},
    { responseReturn: 'body' },
  );
}

/** 佔成錢包排程列表 */
export async function getProfitShareAgentScheduleListApi(params: {
  end_time?: string;
  id: number | string;
  page?: number;
  per_page?: number;
  start_time?: string;
}) {
  const body: Record<string, any> = {
    id: params.id,
    page: params.page ?? 1,
    per_page: params.per_page ?? 10,
    schedule_type_ids: [1, 2, 4],
  };
  if (params.start_time) body.start_time = params.start_time;
  if (params.end_time) body.end_time = params.end_time;
  return requestClient.post<PagedBody<ProfitShareScheduleRow>>(
    '/api/profit_share/agent_schedule/list',
    body,
    { responseReturn: 'body' },
  );
}

/** 主錢包 → 佔成錢包 */
export async function transferToProfitShareApi(params: {
  agent_id: number | string;
  point: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/agent_wallet/transfer_to_profit_share',
    params,
    { responseReturn: 'body' },
  );
}

/** 佔成錢包 → 主錢包 */
export async function transferToMainApi(params: {
  agent_id: number | string;
  point: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/agent_wallet/transfer_to_main',
    params,
    { responseReturn: 'body' },
  );
}

/** 設定佔成錢包封頂額度（-1 為不限，僅整數） */
export async function setProfitShareCapAmountApi(params: {
  cap_amount: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/agent_wallet/set_cap_amount',
    { cap_amount: String(params.cap_amount) },
    { responseReturn: 'body' },
  );
}

/** 取消佔成排程 */
export async function cancelProfitShareAgentScheduleApi(params: {
  remark: string;
  schedule_id: number | string;
  schedule_type_id?: number | string;
}) {
  const body: Record<string, any> = {
    remark: params.remark,
    schedule_id: params.schedule_id,
  };
  if (params.schedule_type_id !== undefined) {
    body.schedule_type_id = params.schedule_type_id;
  }
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/agent_schedule/cancel',
    body,
    { responseReturn: 'body' },
  );
}

/** 會員類別切換紀錄列 */
export interface ProfitShareMemberScheduleRow {
  agent_username?: string;
  created_at?: string;
  data_value?: string;
  operator?: string;
  schedule_remark?: string;
  /** 0=處理中、1=成功、2=取消、9=失敗 */
  schedule_status?: number | string;
  schedule_status_txt?: string;
  updated_at?: string;
  username?: string;
}

/** 類別佔成變動紀錄列 */
export interface ProfitShareCategoryLogRow {
  direct_value?: number | string;
  operator?: string;
  pre_direct_value?: number | string;
  pre_team_value?: number | string;
  schedule_remark?: string;
  /** 1=成功（綠）、否則紅 */
  schedule_status?: number | string;
  schedule_status_txt?: string;
  shareholder_category_name?: string;
  team_value?: number | string;
  updated_at?: string;
  username?: string;
}

/** 類別佔成變動紀錄 */
export async function getProfitShareCategoryLogApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  const body: Record<string, any> = {
    end_time: params.end_time,
    page: params.page ?? 1,
    per_page: params.per_page ?? 10,
    schedule_type_id: 3,
    start_time: params.start_time,
  };
  if (params.username) body.username = params.username;
  return requestClient.post<PagedBody<ProfitShareCategoryLogRow>>(
    '/api/profit_share/agent_schedule/list',
    body,
    { responseReturn: 'body' },
  );
}

/** 遊戲類別佔成設定：類別卡 */
export interface ProfitShareGameCategoryRow {
  agent_id?: number | string;
  /** 直屬佔成 %，null 表示未開放 */
  direct_percentage?: null | number | string;
  shareholder_category_id: number | string;
  shareholder_category_name?: string;
  /** 下級佔成 %，null 表示未開放 */
  team_percentage?: null | number | string;
}

/** 遊戲類別佔成設定列表 */
export async function getProfitShareGameCategoryListApi(params?: {
  username?: string;
}) {
  const body: Record<string, any> = {};
  if (params?.username) body.username = params.username;
  return requestClient.post<
    BodyOf<{ agent_username?: string; data?: ProfitShareGameCategoryRow[] }>
  >('/api/profit_share/game_category/list', body, { responseReturn: 'body' });
}

/** 未執行的遊戲類別佔成設定 */
export interface ProfitShareGameCategoryPendingRow {
  agent_username?: string;
  created_at?: string;
  direct_value?: number | string;
  pre_direct_value?: number | string;
  pre_team_value?: number | string;
  schedule_log_id?: number | string;
  shareholder_category_id?: number | string;
  shareholder_category_name?: string;
  team_value?: number | string;
}

export async function getProfitShareUnexecutedGameCategoryApi(params?: {
  username?: string;
}) {
  const body: Record<string, any> = {};
  if (params?.username) body.username = params.username;
  return requestClient.post<
    BodyOf<{ data?: ProfitShareGameCategoryPendingRow[] }>
  >('/api/profit_share/agent_schedule/unexecuted_game_category_list', body, {
    responseReturn: 'body',
  });
}

/** 設定類別佔成 */
export async function setProfitShareGameCategoryApi(params: {
  agent_id: number | string;
  category_id: number | string;
  direct_share: number | string;
  pre_direct_share: number | string;
  pre_sub_share: number | string;
  sub_share: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/game_category/set',
    params,
    { responseReturn: 'body' },
  );
}

/** 佔成派發列 */
export interface ProfitShareCostRow {
  agent_id?: number | string;
  agent_username?: string;
  payout_record_id?: number | string;
  status?: number | string;
  summary_time?: string;
}

/** 佔成派發列表 */
export async function getProfitShareCostApi(params: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<ProfitShareCostRow>>(
    '/api/profit_share/cost',
    { page: params.page ?? 1, per_page: params.per_page ?? 10 },
    { responseReturn: 'body' },
  );
}

interface CodeMessage {
  code: number | string;
  data?: unknown;
  message: string;
}

/** 結算 */
export async function profitShareCalcApi(params: {
  agent_id: number | string;
  payout_record_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<CodeMessage>('/api/profit_share/calc', params, {
    responseReturn: 'body',
  });
}

/** 派彩 */
export async function profitSharePayoutApi(params: {
  agent_id: number | string;
  payout_record_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<CodeMessage>('/api/profit_share/payout', params, {
    responseReturn: 'body',
  });
}

/** 發佈報表 */
export async function profitShareReleaseApi(params: {
  agent_id: number | string;
  payout_record_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<CodeMessage>('/api/profit_share/release', params, {
    responseReturn: 'body',
  });
}

/** 撤回報表 */
export async function profitShareRevokeReportApi(params: {
  payout_record_id: number | string;
}) {
  return requestClient.post<CodeMessage>(
    '/api/profit_share/revoke_report',
    params,
    { responseReturn: 'body' },
  );
}

/** 重置 */
export async function profitShareResetApi(params: {
  agent_id: number | string;
  payout_record_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<CodeMessage>('/api/profit_share/reset', params, {
    responseReturn: 'body',
  });
}

/** 實名認證可選銀行列表 */
export interface ProfitShareBank {
  bank_id: number | string;
  bank_name: string;
  currency_id: number | string;
}

export async function getProfitShareBankListApi() {
  return requestClient.post<ProfitShareBank[]>('/api/withdrawal/showBankList', {
    page: 1,
    per_page: 1000,
    status: 1,
    type: -1,
  });
}

/** 代理實名認證送出 */
export async function submitAgentRealNameAuthApi(params: {
  bank_account: string;
  bank_id: number | string;
  real_name: string;
}) {
  return requestClient.post<CodeMessage>('/api/agent/real-name-auth', params, {
    responseReturn: 'body',
  });
}

/** 盤費清冊：遊戲商盤費列 */
export interface ProfitShareVendorFee {
  vendor_fee?: number | string;
  vendor_id?: number | string;
  vendor_info_id?: number | string;
  vendor_name?: string;
}

/** 盤費清冊：支付商盤費列 */
export interface ProfitSharePaymentFee {
  acf_fee?: number | string;
  acf_id?: number | string;
  /** 1=存款、否則提款 */
  cf_type?: number | string;
  cf_name?: string;
}

/** 佔成費用清冊 */
export async function getProfitShareFeeListApi() {
  return requestClient.post<
    BodyOf<{
      payment?: ProfitSharePaymentFee[];
      vendor?: ProfitShareVendorFee[];
    }>
  >('/api/profit_share/agent/fee_list', {}, { responseReturn: 'body' });
}

/** 佔成會員管理列 */
export interface ProfitShareMemberRow {
  account_type?: string;
  agent_username?: string;
  apply_type_text?: string;
  member_id?: number | string;
  /** 0=無進行中排程（可改類別）、否則處理中（可撤銷） */
  member_schedule_id?: number | string;
  /** 0/2 等帳號類別原值 */
  type?: number | string;
  username?: string;
}

/** 佔成會員管理列表 */
export async function getProfitShareMemberListApi(params: {
  page?: number;
  per_page?: number;
  type?: number | string;
  username?: string;
}) {
  const body: Record<string, any> = {
    page: params.page ?? 1,
    per_page: params.per_page ?? 30,
    type: params.type ?? 0,
  };
  if (params.username) body.username = params.username;
  return requestClient.post<PagedBody<ProfitShareMemberRow>>(
    '/api/profit_share/member/list',
    body,
    { responseReturn: 'body' },
  );
}

/** 申請切換會員類別 */
export async function modifyProfitShareMemberTypeApi(params: {
  member_id: number | string;
  remark: string;
  type: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/member_schedule/type',
    params,
    { responseReturn: 'body' },
  );
}

/** 撤銷會員類別切換排程 */
export async function cancelProfitShareMemberScheduleApi(params: {
  remark: string;
  schedule_id: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/profit_share/member_schedule/cancel',
    params,
    { responseReturn: 'body' },
  );
}

/** 股東業績查詢列 */
export interface ProfitSharePerformanceRow {
  agent_username?: string;
  cost?: number | string;
  game_type_id?: number | string;
  game_type_name?: string;
  shareholder_category_name?: string;
  total_bet?: number | string;
  type?: string;
  vendor?: string;
  vendor_id?: number | string;
  win_lose?: number | string;
}

/** 股東業績查詢 */
export async function getProfitSharePerformanceApi(params: {
  agent_username?: string;
  end_time: string;
  game_type?: number | string;
  member_type?: number | string;
  page?: number;
  per_page?: number;
  show_type?: number | string;
  start_time: string;
  vendor_id?: Array<number | string>;
}) {
  return requestClient.post<PagedBody<ProfitSharePerformanceRow>>(
    '/api/profit_share/report/performance',
    {
      agent_username: '',
      game_type: 0,
      member_type: 2,
      page: 1,
      per_page: 10,
      show_type: 1,
      vendor_id: 0,
      ...params,
    },
    { responseReturn: 'body' },
  );
}

/** 會員類別切換紀錄 */
export async function getProfitShareMemberScheduleListApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  status?: number | string;
  username?: string;
}) {
  const body: Record<string, any> = {
    end_time: params.end_time,
    page: params.page ?? 1,
    per_page: params.per_page ?? 10,
    start_time: params.start_time,
    status: params.status ?? -1,
  };
  if (params.username) body.username = params.username;
  return requestClient.post<PagedBody<ProfitShareMemberScheduleRow>>(
    '/api/profit_share/member_schedule/list',
    body,
    { responseReturn: 'body' },
  );
}
