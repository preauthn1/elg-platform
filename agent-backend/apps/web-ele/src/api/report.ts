import type { PagedResult, Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 統計報表 API */

export interface CashFlowRow {
  cash_flow_id: number;
  name: string;
  /** 1/2=存款類(來源代碼3)，其他=取款類(來源代碼4) */
  type: number;
}

/** 金流清單 */
export async function getCashFlowsApi() {
  return requestClient.post<CashFlowRow[]>('/api/agent/cashFlows', {});
}

export interface TransactionFilterParams {
  agent_username?: string;
  cash_flow_id?: number | string;
  deposit_to?: number;
  end_date: string;
  execution_username?: string;
  filter?: number;
  is_created_period?: number;
  member_nickname?: string;
  member_username?: string;
  source?: number | string;
  start_date: string;
  transaction_no?: string;
}

/** 開洗分合計 */
export async function getTransactionTotalApi(params: TransactionFilterParams) {
  return requestClient.post<
    Array<{
      agent_username: null | string;
      total_in: null | number;
      total_out: null | number;
    }>
  >('/api/report/transactionTotal', params);
}

export interface TransactionRow {
  agent_username?: string;
  balance?: number | string;
  created_at?: string;
  id: number;
  member_first_name?: string;
  member_username?: string;
  operator_username?: string;
  platform?: number;
  point?: number | string;
  source?: string;
  status?: string;
  third_party_order_no?: string;
  to_agent_username?: string;
  transaction_no?: string;
  // 細分後的操作類型可能是字串鍵（third_party_deposit / cs_payout ...）
  type?: number | string;
}

/** 開洗分明細 */
export async function getTransactionListApi(
  params: TransactionFilterParams & { page?: number; per_page?: number },
) {
  return requestClient.post<PagedResult<TransactionRow>>(
    '/api/report/transactionList',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

export interface TransactionLog {
  agent_username?: string;
  balance?: number | string;
  bank_nickname?: string;
  cf_name?: string;
  created_at?: string;
  from_account_name?: string;
  id?: number | string;
  member_username?: string;
  operator_username?: string;
  order_type_name?: string;
  point?: number | string;
  remark?: string;
  source?: string;
  status?: string;
  third_party_order_no?: string;
  to_agent_username?: string;
  transaction_no?: string;
  type?: number;
}

/** 開洗分單筆明細 */
export async function getTransactionLogApi(data: {
  id: number | string;
  type: number;
}) {
  return requestClient.post<{
    code: number;
    data?: TransactionLog;
    message: string;
  }>('/api/report/transactionLog', data, { responseReturn: 'body' });
}

/* ---- SR02N 開洗分明細表（apis） ---- */

/** SR02N 篩選配對（操作者 → 對象）；admin 只在 Admin 線登入時可選 */
export type Sr02nPair =
  | 'admin'
  | 'agent_agent'
  | 'agent_member'
  | 'cs_agent'
  | 'cs_member'
  | 'ga_agent'
  | 'ga_cs'
  | 'ga_member';

export interface Sr02nFilterParams {
  agent_cash_flow_id?: number | string;
  agent_username?: string;
  cash_flow_id?: number | string;
  end_date: string;
  execution_username?: string;
  is_created_period?: number;
  member_nickname?: string;
  member_username?: string;
  order_type_id?: number | string;
  /** 全不選送空陣列 */
  pairs: Sr02nPair[];
  /** 0=全部、1=客服系統、3=存款金流、4=取款金流 */
  source: number;
  start_date: string;
  transaction_no?: string;
}

/** 來源種類：cash_flow 顯示 source_name，其餘由前端翻譯 */
export type Sr02nSourceKind =
  | 'cash_flow'
  | 'customer_service'
  | 'ota'
  | 'system';

export interface Sr02nRow extends TransactionRow {
  /** 實際金額（變更點數 − 手續費）；非提款列為 null */
  actual_point: null | string;
  member_nickname?: string;
  remark?: string;
  segment?: string;
  source_kind: Sr02nSourceKind;
  source_name?: null | string;
  /** 手續費（已換算成平台點數）；非提款列為 null */
  tax: null | string;
  wallet_type?: number;
}

export interface Sr02nTotal {
  total_actual: string;
  total_in: string;
  total_out: string;
  total_tax: string;
}

export interface Sr02nPagination {
  current: number;
  next?: null | number;
  pages: number;
  per_page: number;
  previous?: null | number;
  total: number;
}

export interface Sr02nOrderTypeNode {
  name: string;
  order_type_id: number;
}

export interface Sr02nMerchantNode {
  agent_cash_flow_id: number;
  children: Sr02nOrderTypeNode[];
  name: string;
}

export interface Sr02nCashFlowTreeNode {
  cash_flow_id: number;
  children: Sr02nMerchantNode[];
  name: string;
  /** 1/2=存款類(來源代碼3)，其他=取款類(來源代碼4) */
  type: number;
}

/** SR02N 開洗分明細 */
export async function getSr02nListApi(
  params: Sr02nFilterParams & { page: number; per_page: number },
) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: Sr02nRow[]; pagination?: Sr02nPagination };
    message: string;
  }>('/agent/api/report/sr02n/list', params, { responseReturn: 'body' });
}

/** SR02N 開洗分合計（四欄皆 4 位小數字串） */
export async function getSr02nTotalApi(params: Sr02nFilterParams) {
  return requestClient.post<{
    code: number | string;
    data?: Sr02nTotal;
    message: string;
  }>('/agent/api/report/sr02n/total', params, { responseReturn: 'body' });
}

/** SR02N 來源三層：支付商 → 支付商戶 → 支付通道 */
export async function getSr02nCashFlowTreeApi() {
  return requestClient.post<{
    code: number | string;
    data?: Sr02nCashFlowTreeNode[];
    message: string;
  }>('/agent/api/report/sr02n/cash-flow-tree', {}, { responseReturn: 'body' });
}

export interface Sr03Row {
  agent_username?: string;
  member_username?: string;
  total_in: number | string;
  total_out: number | string;
}

export interface Sr03Params {
  agent_username?: string;
  end_date: string;
  source?: number;
  start_date: string;
}

export interface Sr14Row {
  agent_username?: string;
  balance?: number | string;
  before_balance?: number | string;
  created_at?: string;
  is_success_status?: boolean | number;
  member_username?: string;
  point?: number | string;
  remark?: string;
  status_text?: string;
  transaction_id?: number | string;
  type?: number;
  vendor_name?: string;
}

/** SR14 現金紀錄表 */
export async function getSr14Api(params: {
  end_time: string;
  member_username: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<PagedResult<Sr14Row>>(
    '/api/report/sr14',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface BetDetailRow {
  bet?: number | string;
  bet_record_id?: number | string;
  bet_time?: string;
  bonus?: number | string;
  disburse_award?: number | string;
  point?: number | string;
  point_win_lose?: number | string;
  rolling_chip?: number | string;
  rolling_chip_win_lose?: number | string;
  valid_bet?: number | string;
  win_lose?: number | string;
}

export interface BetDetailTotals {
  total_bonus?: number | string;
  total_disburse_award?: number | string;
  total_point?: number | string;
  total_point_win_lose?: number | string;
  total_rolling_chip?: number | string;
  total_rolling_chip_win_lose?: number | string;
  total_valid_bet?: number | string;
  total_win_lose?: number | string;
}

/** SR14 稽核流水明細（sr24 用 wallet_type=2 查遊戲錢包） */
export async function getBetDetailApi(params: {
  end_time?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  transaction_id: number | string;
  wallet_type?: number;
}) {
  return requestClient.post<
    PagedResult<BetDetailRow> & {
      statistics?: BetDetailTotals;
      subtotal?: BetDetailTotals;
    }
  >(
    '/api/report/bet_detail',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

export type WinLoseRankingPath = 'agent' | 'member' | 'team';

export interface WinLoseRankingRow {
  agent_id?: number | string;
  agent_username?: string;
  bet_amount?: number | string;
  member_id?: number | string;
  username?: string;
  win_lose_amount?: number | string;
}

/** 輸贏排行報表（type 1=贏分 2=輸分） */
export async function getWinLoseRankingApi(
  path: WinLoseRankingPath,
  params: {
    end_time: string;
    start_time: string;
    type: number | string;
    vendor_id: number | string;
  },
) {
  return requestClient.post<{
    code: number;
    data?: WinLoseRankingRow[];
    message: string;
  }>(`/api/report/${path}WinLoseRanking`, params, { responseReturn: 'body' });
}

export interface WinLoseDetailRow {
  bet_amount?: number | string;
  vendor_name?: string;
  win_lose_amount?: number | string;
}

/** 輸贏排行明細（各平台加總） */
export async function getWinLoseDetailApi(
  path: WinLoseRankingPath,
  params: {
    agent_id?: number | string;
    end_time: string;
    member_id?: number | string;
    start_time: string;
    type: number | string;
    vendor_id: number | string;
  },
) {
  return requestClient.post<{
    code: number;
    data?: WinLoseDetailRow[];
    message: string;
  }>(`/api/report/${path}WinLoseDetail`, params, { responseReturn: 'body' });
}

/** SR23 代理多錢包紀錄 */
export async function getReportSr23Api(params: {
  agent_username: string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<PagedResult<WalletLogRow>>(
    '/api/report/sr23',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface Sr24Row {
  agent_username?: string;
  balance?: number | string;
  before_balance?: number | string;
  created_at?: string;
  is_success_status?: boolean | number;
  member_username?: string;
  operator?: string;
  point?: number | string;
  remark?: string;
  status?: number;
  transaction_id?: number | string;
  type?: number;
  vendor_name?: string;
}

/** SR24 會員多錢包紀錄 */
export async function getReportSr24Api(params: {
  end_time: string;
  member_username: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<PagedResult<Sr24Row>>(
    '/api/report/sr24',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface AdjustmentType {
  id: number;
  name: string;
}

/** 調帳類別下拉 */
export async function getAdjustmentTypeApi() {
  return requestClient.post<AdjustmentType[]>(
    '/api/dropdown/adjustment_type',
    {},
  );
}

export interface AdjustmentReportRow {
  agent_username?: string;
  created_at?: string;
  member_nickname?: string;
  member_username?: string;
  operator?: string;
  point?: number | string;
  remark?: string;
  type?: string;
}

/** 調帳報表 */
export async function getAdjustmentReportApi(params: Record<string, unknown>) {
  return requestClient.post<PagedResult<AdjustmentReportRow>>(
    '/api/report/adjustment_report',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** SR03 開洗分統計：總公司 / 代理 / 會員 三組 */
export async function getSr03UserApi(params: Sr03Params) {
  return requestClient.post<Sr03Row[]>('/api/report/sr03User', params);
}
export async function getSr03AgentApi(params: Sr03Params) {
  return requestClient.post<Sr03Row[]>('/api/report/sr03Agent', params);
}
export async function getSr03MemberApi(params: Sr03Params) {
  return requestClient.post<Sr03Row[]>('/api/report/sr03Member', params);
}

/* ========== 報表批次（明細表類，2026-06-10） ========== */

/** 幣別下拉 */
export async function getCurrencyListApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/currency/list',
    {},
  );
}

export interface ReportSr06Row {
  agent_username?: string;
  balance?: number | string;
  created_at?: string;
  id: number | string;
  member_nickname?: string;
  member_username?: string;
  point?: number | string;
  status?: number;
  transaction_no?: string;
  type?: number;
  vendor?: string;
  vendor_id?: number;
}

/** SR06 平台轉入轉出明細表
 *  type 1/2/3/4 → agent_username / member_username / member_nickname / transaction_no */
export async function getReportSr06Api(
  params: Record<string, unknown> & {
    end_date: string;
    page?: number;
    per_page?: number;
    start_date: string;
    status?: number;
    vendor_id?: Array<number | string> | number;
  },
) {
  return requestClient.post<PagedResult<ReportSr06Row>>(
    '/api/report/sr06',
    { page: 1, per_page: 50, status: 0, vendor_id: 0, ...params },
    { responseReturn: 'body' },
  );
}

export interface Sr08Row {
  agent_username?: string;
  balance?: number | string;
  created_at?: string;
  id: number | string;
  member_username?: string;
  operator?: string;
  point?: number | string;
  remark?: string;
  status?: number;
}

/** SR08 補點紀錄 */
export async function getReportSr08Api(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  return requestClient.post<PagedResult<Sr08Row>>(
    '/api/report/sr08',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** sr07 紅包/轉帳記錄 列 */
export interface Sr07Row {
  amount?: number | string;
  created_at?: string;
  id?: number | string;
  other_username?: string;
  self_username?: string;
  status?: number | string;
  tax?: number | string;
}
export async function getReportSr07Api(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  return requestClient.post<PagedResult<Sr07Row>>(
    '/internal/api/v1/report/sr07',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface Sr97Row {
  account_name?: string;
  account_no?: string;
  amount?: number | string;
  bank_name?: string;
  created_at?: string;
  currency_name?: string;
  pay_time?: string;
  payment_type?: number;
  point?: number | string;
  rate?: number | string;
  real_name?: string;
  status?: number | string;
  username?: string;
}

/** SR97 代理個人開洗分紀錄表
 *  time_type 1=創建/2=更新；start_time/end_time 帶到分需補 :00/:59 */
export async function getReportSr97Api(params: {
  currency_id?: number | string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  time_type: number | string;
}) {
  return requestClient.post<
    PagedResult<Sr97Row> & {
      summary?: {
        deposit_amount?: number | string;
        deposit_point?: number | string;
        withdraw_amount?: number | string;
        withdraw_point?: number | string;
      };
    }
  >(
    '/api/report/sr97',
    {
      cash_flow_id: 0,
      currency_id: 0,
      order_type_id: 0,
      page: 1,
      per_page: 20,
      source: 0,
      status: 0,
      ...params,
    },
    { responseReturn: 'body' },
  );
}

export interface ReportSummary {
  deposit_amount?: number | string;
  deposit_point?: number | string;
  withdraw_amount?: number | string;
  withdraw_point?: number | string;
}

export interface SrChargeRow {
  account_name?: string;
  account_no?: string;
  amount?: number | string;
  bank_name?: string;
  created_at?: string;
  currency_name?: string;
  order_no?: string;
  pay_time?: string;
  /** 支付商手續費（成本） */
  payment_cost?: number | string;
  payment_type?: number;
  point?: number | string;
  rate?: number | string;
  real_name?: string;
  source?: string;
  status?: number | string;
  tax?: number | string;
  third_party_order_no?: string;
  user_type?: number;
  username?: string;
}

/** SR98 代理開洗分明細表 */
export async function getReportSr98Api(params: Record<string, unknown>) {
  return requestClient.post<
    PagedResult<SrChargeRow> & { summary?: ReportSummary }
  >(
    '/api/report/sr98',
    {
      agent_cash_flow_id: 0,
      cash_flow_id: 0,
      currency_id: 0,
      order_type_id: 0,
      page: 1,
      per_page: 20,
      source: 0,
      status: 1,
      ...params,
    },
    { responseReturn: 'body' },
  );
}

/** SR99 充提明細表 */
export async function getReportSr99Api(params: Record<string, unknown>) {
  return requestClient.post<
    PagedResult<SrChargeRow> & { summary?: ReportSummary }
  >(
    '/api/report/sr99',
    {
      agent_cash_flow_id: 0,
      cash_flow_id: 0,
      currency_id: 0,
      order_type_id: 0,
      page: 1,
      per_page: 20,
      source: 0,
      status: 1,
      user_type: 0,
      ...params,
    },
    { responseReturn: 'body' },
  );
}

/** 存款金流的訂單類型 */
export async function getAgentOrderTypesApi(
  cashFlowId: number | string,
  extra?: { agent_cash_flow_id?: number | string; parent_id?: number | string },
) {
  return requestClient.post<{
    code: number;
    data?: Array<{ name: string; order_type_id: number }>;
    message: string;
  }>(
    '/api/agent/orderTypes',
    { cash_flow_id: cashFlowId, ...extra },
    { responseReturn: 'body' },
  );
}

/** 提款金流的子類型 */
export async function getWithdrawalSubListApi(cashFlowId: number | string) {
  return requestClient.post<{
    code: number;
    data?: Array<{ name: string; order_type_id: number }>;
    message: string;
  }>(
    '/api/withdrawal/subList',
    { cash_flow_id: cashFlowId },
    { responseReturn: 'body' },
  );
}

/* ---- 各類錢包明細（sr21/sr22/sr25/sr26） ---- */

/** 禮碼活動獎勵類型下拉 */
export async function getEventRewardTypesApi() {
  return requestClient.post<Array<{ event_reward_id: number }>>(
    '/api/rolling_chip/event_reward',
    { type: 2 },
  );
}

/** 優惠錢包交易類型下拉 */
export async function getPromotionWalletTypesApi() {
  return requestClient.post<Array<{ type_id: number }>>(
    '/api/promotion_wallet/transaction_type',
    { type: 2 },
  );
}

export interface WalletLogRow {
  agent_username?: string;
  balance?: number | string;
  before_balance?: number | string;
  created_at?: string;
  from_username?: string;
  member_username?: string;
  operate_agent_username?: string;
  point?: number | string;
  remark?: string;
  status?: number;
  to_username?: string;
  type?: number;
  type_text?: string;
  user_join_status?: number;
  vendor_name?: string;
}

/** SR21 禮碼紀錄明細表
 *  username_type 1=代理/2=會員 → agent_username/member_username；type 0=全部或 event_reward_id */
export async function getReportSr21Api(
  params: Record<string, unknown> & {
    end_time: string;
    page?: number;
    per_page?: number;
    start_time: string;
    type?: number | string;
  },
) {
  return requestClient.post<PagedResult<WalletLogRow>>(
    '/api/report/sr21',
    { page: 1, per_page: 20, type: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** SR22 優惠錢包紀錄明細表 */
export async function getReportSr22Api(params: {
  end_time: string;
  member_username: string;
  page?: number;
  per_page?: number;
  start_time: string;
  type?: number | string;
}) {
  return requestClient.post<PagedResult<WalletLogRow>>(
    '/api/report/sr22',
    { page: 1, per_page: 20, type: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** SR26 佔成錢包紀錄明細表 */
export async function getReportSr26Api(params: {
  agent_username?: string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  type?: number | string;
}) {
  return requestClient.post<
    PagedResult<WalletLogRow> & { sum_change_point?: number | string }
  >(
    '/api/report/sr26',
    { page: 1, per_page: 20, type: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** SR25 活動錢包紀錄明細表 */
export async function getReportSr25Api(params: {
  end_time: string;
  member_username: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<{
    code: number;
    data?: { items?: WalletLogRow[] };
    message: string;
  }>(
    '/agent/api/report/sr25',
    { page: 1, per_page: 1000, ...params },
    { responseReturn: 'body' },
  );
}

/** SR05 會員帳變明細表 */
export async function getReportSr05Api(params: {
  end_time: string;
  member_username: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<PagedResult<WalletLogRow>>(
    '/api/report/sr05',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface Sr18Row {
  audit_cnt?: number;
  audit_second?: number;
  cash_flow_id?: number;
  pay_cnt?: number;
  pay_second?: number;
  success_cnt?: number;
  success_second?: number;
  username?: string;
}

/** SR18 客服效率統計 */
export async function getReportSr18Api(params: {
  agent_username?: string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<PagedResult<Sr18Row>>(
    '/api/report/sr18',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface AgentTxLogRow {
  amount?: number | string;
  created_at?: string;
  custom_type?: number;
  display_from?: string;
  display_to?: string;
}

/** 代理交易紀錄
 *  時間需減 8 小時 + 補 :00/:59 送出（API 為 UTC，前端 +8 顯示） */
export async function getAgentTransactionReportApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  type?: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: AgentTxLogRow[];
    general_agent_name?: null | string;
    message: string;
  }>(
    '/api/getTransactionReport',
    { page: 1, per_page: 5000, type: 0, ...params },
    { responseReturn: 'body' },
  );
}

export interface CompanyWinLoseData {
  events?: Array<{
    point?: number | string;
    role?: number;
    type?: number | string;
  }>;
  pays?: Array<{
    name?: string;
    total_cost?: number | string;
    total_point?: number | string;
    type?: number;
  }>;
  sms?: Array<{
    name?: string;
    total_cost?: number | string;
    total_send?: number | string;
    type?: number;
  }>;
  vendors?: Array<{
    total_bet?: number | string;
    total_cost?: number | string;
    total_payout?: number | string;
    total_rolling_chip?: number | string;
    total_win_lose?: number | string;
    vendor_name?: string;
  }>;
}

/** 公司輸贏報表 */
export async function getCompanyWinLoseApi(params: {
  end_date: string;
  page?: number;
  per_page?: number;
  start_date: string;
}) {
  return requestClient.post<{
    code: number;
    data?: CompanyWinLoseData;
    message: string;
  }>(
    '/api/report/companyWinLose',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 遊戲類型下拉 */
export async function getGameTypeListApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/gameType/list',
    { status: 1 },
  );
}

export interface Sr12Row {
  agent_username?: string;
  bet_amount?: number | string;
  bet_time?: string;
  commission?: number | string;
  game_name?: string;
  game_no?: string;
  member_username?: string;
  payout?: number | string;
  rolling_chip?: number | string;
  settlement_time?: string;
  valid_bet_amount?: number | string;
  vendor_name?: string;
  win_lose?: number | string;
}

export interface Sr12Summary {
  bet_amount?: number | string;
  commission?: number | string;
  payout?: number | string;
  rolling_chip?: number | string;
  valid_bet_amount?: number | string;
  win_lose?: number | string;
}

/**
 * SR12 遊戲下注紀錄：
 * vendor_id 單選（預設 1）、game_type 0=全部、sort_by 1=下注時間/2=下注金額、
 * whitch_time 1=下注時間/2=結算時間；時間帶秒（:00/:59）；回應多 summary_data 合計。 */
export async function getReportSr12Api(params: {
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
    PagedResult<Sr12Row> & { summary_data?: Sr12Summary }
  >(
    '/api/report/sr12',
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

export interface Sr13Row {
  after_balance?: number | string;
  amount?: number | string;
  before_balance?: number | string;
  created_at?: string;
  from_username?: string;
  member_username?: string;
  remark?: string;
  /** 1=代理對代理（顯示 to_username/status_X）、2=餘額顯示 '--'；其他=玩家（chat_transaction_status_X） */
  source?: number | string;
  status?: number | string;
  to_username?: string;
  transaction_no?: string;
  type_name?: string;
}

/** SR13 代理分數紀錄 */
export async function getReportSr13Api(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username: string;
}) {
  return requestClient.post<PagedResult<Sr13Row>>(
    '/api/newreport/sr13',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 翻譯標籤包 */
export async function getTranslateApi() {
  return requestClient.post<Record<string, string>>('/api/translate', {});
}

/** 報表用平台下拉 */
export async function getVendorReportDropdownApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/vendor/reportDropdown',
    {},
  );
}

export interface Sr15Row {
  agent_id?: number;
  commission?: number | string;
  cost?: number | string;
  jackpot_event?: number | string;
  parent_username?: string;
  recycle_amount?: number | string;
  total_bet?: number | string;
  total_score?: number | string;
  username?: string;
  v_jackpot?: number | string;
  win_lose?: number | string;
}

/** SR15 代理對帳報表
 *  agent_username 必填；parent_username 下鑽查下線；vendor_id 多選(含特殊負值)；hidden_zero_data 0/1 */
export async function getReportSr15Api(params: {
  cost_visible?: number | string;
  end_time: string;
  hidden_zero_data?: number | string;
  page?: number;
  parent_username?: string;
  per_page?: number;
  start_time: string;
  username?: string;
  vendor_id?: Array<number | string> | string;
}) {
  return requestClient.post<PagedResult<Sr15Row>>(
    '/api/report/sr15',
    { hidden_zero_data: 1, page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}
export interface Sr19Row {
  achievements?: number | string;
  agent_id?: number;
  commission?: number | string;
  cost?: number | string;
  jackpot_event?: number | string;
  parent_username?: string;
  proportion?: number | string;
  total_bet?: number | string;
  total_score?: number | string;
  username?: string;
  v_jackpot?: number | string;
  win_lose?: number | string;
}

/** SR19 代理結帳報表 */
export async function getReportSr19Api(params: {
  cost_visible?: number | string;
  end_time: string;
  hidden_zero_data?: number | string;
  page?: number;
  parent_username?: string;
  per_page?: number;
  start_time: string;
  username?: string;
  vendor_id?: Array<number | string> | string;
}) {
  return requestClient.post<PagedResult<Sr19Row>>(
    '/api/report/sr19',
    { hidden_zero_data: 1, page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 更新代理佔成 */
export async function updateAgentProportionApi(
  id: number | string,
  percent: number,
) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/updateProportion',
    { id, val: percent / 100 },
    { responseReturn: 'body' },
  );
}

export interface Sr19DetailRow {
  agent_nickname?: string;
  agent_username?: string;
  commission?: number | string;
  cost?: number | string;
  game_type?: string;
  game_type_id?: number | string;
  lottery?: number | string;
  proportion?: number | string;
  score?: number | string;
  total_bet?: number | string;
  v_jackpot?: number | string;
  vendor?: string;
  vendor_id?: number;
  win_lose?: number | string;
}

/** SR19 結帳明細 */
export async function getReportSr19DetailApi(params: {
  agent_id: number | string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
}) {
  return requestClient.post<{
    code: number;
    data?: { '4D'?: Sr19DetailRow[]; Other?: Sr19DetailRow[] };
    message: string;
  }>(
    '/api/report/sr19Detail',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
}
export interface UserInstantBetRow {
  id?: number;
  parent?: number;
  total_bet?: number | string;
  username?: string;
}

/** 股東即時報表
 *  非權限者(role 非 1/11/12 且非本人上下層)帳號需遮罩，由前端依 role_id/host_id 判斷 */
export async function getReportUserInstantBetsApi(params: {
  page?: number;
  per_page?: number;
  username?: string;
}) {
  return requestClient.post<PagedResult<UserInstantBetRow>>(
    '/api/report/user_instant_bets',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}
export interface StatisticalRow {
  /** 投注人數：COUNT(DISTINCT member_id)，同一人下注多次仍算 1 */
  bet_count?: number | string;
  /** 投注次數：SUM(record_count)，同一人下注多次會累加 */
  bet_record_count?: number | string;
  bet_user_30_day_number?: number | string;
  /** 充值手續費（待後端實作） */
  deposit_fee?: number | string;
  /** 首存人數：COUNT(*)，當天 first_deposit 落在這天的會員數 */
  first_deposit_count?: number | string;
  /** 首存金額：SUM(m.first_deposit_point)，這些會員的首存金額加總 */
  first_deposit_point?: number | string;
  login_count?: number | string;
  next_day_bet_user_number?: number | string;
  reg_count?: number | string;
  sdate?: string;
  today_register_deposit_user_number?: number | string;
  total_adjustment?: number | string;
  total_adjustment_count?: number | string;
  total_adjustment_in?: number | string;
  total_adjustment_in_count?: number | string;
  total_adjustment_out?: number | string;
  total_adjustment_out_count?: number | string;
  /** 代理彩金與獎勵（待後端實作） */
  total_agent_prize_reward?: number | string;
  total_bet?: number | string;
  total_deposit?: number | string;
  total_deposit_count?: number | string;
  total_deposit_users?: number | string;
  /** 彩金與獎勵（會員） */
  total_prize_reward?: number | string;
  total_win_lose?: number | string;
  total_withdraw?: number | string;
  total_withdraw_count?: number | string;
  total_withdraw_users?: number | string;
  /** 取款手續費（待後端實作） */
  withdraw_fee?: number | string;
}

/** 數據分析（tz：時區偏移 -12~12，後端依此換算日界；不傳視同 8） */
export async function getReportStatisticalApi(params: {
  end_date: string;
  only_direct_member?: number | string;
  page?: number;
  per_page?: number;
  start_date: string;
  /** 1：彙總整段區間為單列；不傳/''：逐日列出 */
  summary?: number | string;
  tz?: number;
  username?: string;
}) {
  return requestClient.post<
    {
      /**
       * 投注統計未計入的遊戲商（target/tickets#4418）：固定排除 HAOLONG 系列 4／104／181，
       * 加上所有免盤費商戶（vendors.is_free = 1）。與後端查詢實際排除的名單同一份，
       * 「投注」表頭的問號直接顯示它，前端不自行維護名單。
       */
      excluded_vendors?: Array<{ id: number; name: string }>;
      /** 總摘要：整段查詢區間的合計（不受分頁影響） */
      summary?: StatisticalRow;
    } & PagedResult<StatisticalRow>
  >(
    '/api/report/statistical',
    { page: 1, per_page: 100, ...params },
    { responseReturn: 'body' },
  );
}

/* ========== SSR02 / SR02T（transaction*New 系列）與 SSR07 / 返水 ========== */

/** 活動獎勵下拉 */
export async function getEventRewardsApi() {
  return requestClient.post<Array<{ event_reward_id: number | string }>>(
    '/api/agent/eventReward',
    {},
  );
}

/** TP 金流下拉 */
export interface CashFlowForTpRow {
  agent_cash_flow_id: number | string;
  cash_flow_id: number | string;
  name: string;
  need_order_type_selection?: boolean;
  order_type_id?: number | string;
  type: number;
}
export async function getCashFlowsForTpApi() {
  return requestClient.post<CashFlowForTpRow[]>(
    '/api/agent/cashFlowsForTp',
    {},
  );
}

export interface TransactionNewFilterParams extends TransactionFilterParams {
  agent_cash_flow_id?: number | string;
  order_type_id?: number | string;
  /** sr02t 變體旗標 */
  sr02t?: number;
  /** sr02y 變體旗標：後端據此把佔成錢包（代理互轉）UNION 進來 */
  sr02y?: number;
}

/** 開洗分合計 New */
export async function getTransactionTotalNewApi(
  params: TransactionNewFilterParams,
) {
  return requestClient.post<
    Array<{
      total_in: null | number;
      total_out: null | number;
      total_payout: null | number;
      /** 佔成加分（SR02Y 專屬；SR02T 走到的分支恆為 0） */
      total_profit_share_in?: null | number;
      /** 佔成洗分（SR02Y 專屬；SR02T 走到的分支恆為 0） */
      total_profit_share_out?: null | number;
      total_reward: null | number;
    }>
  >('/api/report/transactionTotalNew', params);
}

/** 開洗分明細 New */
export async function getTransactionListNewApi(
  params: TransactionNewFilterParams & { page?: number; per_page?: number },
) {
  return requestClient.post<PagedResult<TransactionRow>>(
    '/api/report/transactionListNew',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

export interface Ssr07Row {
  amount?: number | string;
  created_at?: string;
  id: number | string;
  operator_username?: string;
  receiver_username?: string;
  status?: number | string;
  tax?: number | string;
}

/** SSR07 會員互轉紀錄 */
export async function getReportSsr07Api(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  return requestClient.post<PagedResult<Ssr07Row>>(
    '/api/report/ssr07',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/* ========== SR11/SR11Y 遊戲平臺對帳表（api/newreport/platformReconciliation*） ========== */

export interface ReconciliationRow {
  agent_nickname?: string;
  agent_username?: string;
  commission?: number | string;
  cost?: number | string;
  game_type?: string;
  game_type_id?: number | string;
  lottery?: number | string;
  /** agent / member / self */
  lower_type?: string;
  member_nickname?: string;
  member_username?: string;
  recycle_amount?: number | string;
  total_bet?: number | string;
  v_jackpot?: number | string;
  vendor?: string;
  vendor_id?: number | string;
  win_lose?: number | string;
}

export interface ReconciliationParams {
  agent_username?: string;
  end_time: string;
  game_type?: number | string;
  /** sr11y：1=份桶會員 2=佔成會員 */
  member_type?: number;
  member_username?: string;
  page?: number;
  per_page?: number;
  show_type?: number;
  start_time: string;
  /** 逗號串（含 -1~-9 特殊項） */
  vendor_id: string;
}

interface ReconciliationBody {
  code: number;
  data?: ReconciliationRow[];
  message: string;
  pagination?: Pagination;
  show_v_jackpot?: boolean;
}

/** 對帳表上方統計 */
export async function getPlatformReconciliationTotalApi(
  params: ReconciliationParams,
) {
  return requestClient.post<ReconciliationBody>(
    '/api/newreport/platformReconciliationTotal',
    params,
    { responseReturn: 'body' },
  );
}

/** 對帳表下方明細 */
export async function getPlatformReconciliationDetailApi(
  params: ReconciliationParams,
) {
  return requestClient.post<ReconciliationBody>(
    '/api/newreport/platformReconciliationDetail',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
}

/** 對帳表會員明細 */
export async function getPlatformReconciliationMemberApi(
  params: ReconciliationParams,
) {
  return requestClient.post<ReconciliationBody>(
    '/api/newreport/platformReconciliationMember',
    params,
    { responseReturn: 'body' },
  );
}

export interface RebateRow {
  bet_amount?: number | string;
  game_type_name?: string;
  rebate?: number | string;
  vendor_name?: string;
}

/** 返水報表 */
export async function getRebateReportApi(params: {
  end_date: string;
  page?: number;
  per_page?: number;
  start_date: string;
  vendor_id?: number | string;
}) {
  return requestClient.post<PagedResult<RebateRow>>(
    '/api/three_hierarchy/rebate',
    { page: 1, per_page: 5000, vendor_id: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** 會員輸贏報表每列下的平台明細 */
export interface PerformanceVendorRow {
  bet?: number | string;
  name?: string;
  win_lose?: number | string;
}

export interface PerformanceReportRow {
  bet?: number | string;
  member_username?: string;
  vendors?: PerformanceVendorRow[];
  win_lose?: number | string;
}

export interface PerformanceReportBody {
  code: number | string;
  data?: {
    items?: PerformanceReportRow[];
    total_bet?: number | string;
    total_win_lose?: number | string;
  };
  message: string;
  pagination?: Pagination;
}

/** 會員輸贏報表。
 *  start_time/end_time 傳日期（Y-m-d），補上 00:00:00／23:59:59。 */
export async function getPerformanceReportApi(params: {
  end_time: string;
  member_username?: string;
  page?: number;
  per_page?: number;
  sort_direction?: 'asc' | 'desc';
  sort_field?: 'bet' | 'member_username' | 'win_lose';
  start_time: string;
}) {
  const { end_time, start_time, ...rest } = params;
  return requestClient.post<PerformanceReportBody>(
    '/api/report/performance_report',
    {
      page: 1,
      per_page: 10,
      sort_direction: 'desc',
      sort_field: 'bet',
      ...rest,
      end_time: `${end_time} 23:59:59`,
      start_time: `${start_time} 00:00:00`,
    },
    { responseReturn: 'body' },
  );
}
