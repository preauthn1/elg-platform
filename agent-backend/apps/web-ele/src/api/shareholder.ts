import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 股東（分桶）模組 API。 */

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

/* ───────────────────────── 匯率管理 ───────────────────────── */

export interface ShareholderExchangeRate {
  currencyCode?: string;
  organization?: string;
  rate?: number | string;
}

/** 當前匯率 */
export async function getShareholderExchangeRateApi() {
  return requestClient.post<BodyOf<ShareholderExchangeRate>>(
    '/api/shareholder/v2/exchange_rate',
    {},
    { responseReturn: 'body' },
  );
}

export interface ShareholderExchangeRateLog {
  admin?: string;
  after_change?: number | string;
  before_change?: number | string;
  created_at?: string;
}

/** 匯率操作紀錄 */
export async function getShareholderExchangeRateLogApi(params: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<ShareholderExchangeRateLog>>(
    '/api/shareholder/v2/exchange_rate_log',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 設定匯率。 */
export async function setShareholderExchangeRateApi(payload: {
  member_id: number | string;
  rate: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/shareholder/v2/set_exchange_rate',
    payload,
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 取消代理紀錄 ───────────────────────── */

export interface ShareholderCancelLogRow {
  bet?: number | string;
  created_at?: string;
  operator?: string;
  /** cancel_record_type enum：0=enable、1=disabled */
  type?: number | string;
  username?: string;
}

/** 取消代理紀錄 */
export async function getShareholderCancelLogApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  return requestClient.post<PagedBody<ShareholderCancelLogRow>>(
    '/api/shareholder/cancelLog',
    { page: 1, per_page: 20, username: '', ...params },
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 類別管理 ───────────────────────── */

export interface ShareholderCategoryRow {
  agent_group_id?: number;
  bet_ratio?: number | string;
  code?: string;
  commission_ratio?: number | string;
  id: number;
  name?: string;
  sort?: number;
  status?: number;
}

export interface ShareholderCategorySettingRow {
  category_id: number;
  game_type_id: number | string;
  game_type_name?: string;
  id: number | string;
  vendor_id: number | string;
  vendor_name?: string;
}

/** 遊戲商下拉 */
export async function getShareholderVendorListApi() {
  return requestClient.post<
    BodyOf<Array<{ id: number | string; name: string }>>
  >('/api/vendor/dropdownList', { type: 1 }, { responseReturn: 'body' });
}

/** 遊戲類型清單。 */
export async function getShareholderGameTypeListApi() {
  return requestClient.post<
    BodyOf<Array<{ id: number | string; name: string }>>
  >('/api/gameType/list', {}, { responseReturn: 'body' });
}

/** 股東類別清單 */
export async function getShareholderSettlementCategoryApi() {
  return requestClient.post<
    BodyOf<Array<{ id: number | string; name: string }>>
  >('/api/shareholder/category', { settlement: 1 }, { responseReturn: 'body' });
}

/** 股東類別清單。 */
export async function getShareholderCategoryListApi(shareholder_type?: number) {
  return requestClient.post<BodyOf<ShareholderCategoryRow[]>>(
    '/api/shareholder/category',
    shareholder_type === undefined ? {} : { shareholder_type },
    { responseReturn: 'body' },
  );
}

/** 股東類別設定 */
export async function getShareholderCategorySettingApi() {
  return requestClient.post<
    BodyOf<{ setting?: ShareholderCategorySettingRow[] }>
  >('/api/shareholder/categorySetting', {}, { responseReturn: 'body' });
}

/** 更新類別清單 */
export async function updateShareholderCategoryApi(payload: {
  bet_ratio: Array<number | string>;
  codes: string[];
  commission_ratio: Array<number | string>;
  ids: Array<number | string>;
  names: string[];
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/shareholder/setCategory',
    payload,
    { responseReturn: 'body' },
  );
}

/** 更新某類別的 vendor/game_type 設定 */
export async function updateShareholderCategorySettingApi(payload: {
  category_content_game_types: Array<number | string>;
  category_content_vendors: Array<number | string>;
  category_id: number | string;
  category_setting_ids: Array<number | string>;
  type: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/shareholder/setCategorySetting',
    payload,
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 輸入成本（cost_setting / V2） ───────────────────────── */

/** v2=true 時走 api/shareholder/v2/* 端點。 */
const sh = (path: string, v2: boolean) =>
  v2 ? `/api/shareholder/v2/${path}` : `/api/shareholder/${path}`;

export interface ShareholderCostRow {
  agent_id?: number | string;
  agent_username?: string;
  game_profit?: number | string;
  id: number | string;
  memo?: string;
  payout_record_id?: number | string;
  /** 0:未派發 1:已派發 2:待派發 3:已編輯 4:已結算 5/6 其他 7:子站輸入成本 */
  status?: number | string;
  /** >0 代表這筆屬於稱桶子站 */
  sub_site_id?: number | string;
  /** 1 代表子站已收到主站推下來的總數據，可進行子站編輯成本 */
  is_sub_site_received?: number | string;
  summary_time?: string;
}

/** 成本列表。 */
export async function getShareholderCostApi(params: {
  page?: number;
  per_page?: number;
  shareholder_type?: number | string;
}) {
  return requestClient.post<PagedBody<ShareholderCostRow>>(
    '/api/shareholder/cost',
    { page: 1, per_page: 10, shareholder_type: 0, ...params },
    { responseReturn: 'body' },
  );
}

export interface ShareholderRankRow {
  bet?: number | string;
  id: number | string;
  status?: number | string;
  summary_date?: string;
  username?: string;
}

export interface ShareholderRankListData {
  payout_record?: { id?: number | string };
  prohibit?: ShareholderRankRow[];
  rank?: ShareholderRankRow[];
  status?: number | string;
}

/** 投注排行 */
export async function getShareholderRankListApi(params: {
  id: number | string;
  payout_record_id: number | string;
  summary_date: string;
}) {
  return requestClient.post<BodyOf<ShareholderRankListData>>(
    '/api/shareholder/rankList',
    params,
    { responseReturn: 'body' },
  );
}

/** 排行禁止/允許計算。 */
export async function shareholderRankingCancelApi(payload: {
  bet: number | string;
  date: string;
  id: number | string;
  payout_record_id: number | string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/shareholder/ranking/cancel', payload, { responseReturn: 'body' });
}

export interface ShareholderTotalSalesSummary {
  bet_ratio?: number | string;
  commission_ratio?: number | string;
  game_profit?: number | string;
  game_spending?: number | string;
  id?: number | string;
  name?: string;
  net_win?: number | string;
  original_game_spending?: number | string;
  original_turnover?: number | string;
  shareholder_category_id?: number | string;
  turnover?: number | string;
}

export interface ShareholderTotalSalesData {
  status?: number | string;
  summary?: ShareholderTotalSalesSummary[];
}

/** 編輯報表 — 取總銷售 */
export async function getShareholderTotalSalesApi(
  params: {
    date: string;
    id: number | string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
  },
  v2 = false,
) {
  return requestClient.post<BodyOf<ShareholderTotalSalesData>>(
    sh('total_sales', v2),
    params,
    { responseReturn: 'body' },
  );
}

/** V2 外站類別 */
export async function getShareholderForeignCategoryApi() {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/shareholder/v2/foreign_category', {}, { responseReturn: 'body' });
}

/** V2 本站貨幣代碼 */
export async function getShareholderCurrencyCodeApi() {
  return requestClient.post<BodyOf<string>>(
    '/api/shareholder/v2/currency_code',
    {},
    { responseReturn: 'body' },
  );
}

export interface ShareholderForeignSiteSales {
  currency_code?: string;
  rate?: number | string;
  summary?: Array<{
    game_profit?: number | string;
    game_spending?: number | string;
    id?: number | string;
    name?: string;
    original_game_spending?: number | string;
    original_turnover?: number | string;
    turnover?: number | string;
  }>;
}

/** V2 外站總業績 */
export async function getShareholderForeignTotalSalesApi(date: string) {
  return requestClient.post<{
    code: number | string;
    data?: Record<string, ShareholderForeignSiteSales> | unknown[];
    message: string;
  }>(
    '/api/shareholder/v2/foreign_total_sales',
    { date },
    { responseReturn: 'body' },
  );
}

/** 編輯報表 — 更新總銷售。 */
export async function updateShareholderTotalSalesApi(
  payload: {
    payout_record_id: number | string;
    record: Array<{
      category_name?: number | string;
      game_spending: number | string;
      id: number | string;
      net_win?: number | string;
      turnover: number | string;
    }>;
    shareholder_type?: number | string;
    summary_date: string;
  },
  v2 = false,
) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>(sh('update_total_sales', v2), payload, { responseReturn: 'body' });
}

/** 設定成本。 */
export async function shareholderSetCostApi(
  payload: {
    cost: number | string;
    date: string;
    id: number | string;
    memo: string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
  },
  v2 = false,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    sh('set_cost', v2),
    payload,
    { responseReturn: 'body' },
  );
}

/** 結算。 */
export async function shareholderSettleApi(
  payload: {
    agent_id: number | string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
    summary_time: string;
  },
  v2 = false,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    sh('settle', v2),
    payload,
    { responseReturn: 'body' },
  );
}

/** 派發前試算金額。 */
export async function shareholderPreviewPayoutApi(payload: {
  agent_id: number | string;
  payout_record_id: number | string;
  shareholder_type?: number | string;
  summary_time: string;
}) {
  return requestClient.post<BodyOf<{ amount?: number | string }>>(
    '/api/shareholder/preview_payout',
    payload,
    { responseReturn: 'body' },
  );
}

/** 派發。 */
export async function shareholderPayoutApi(
  payload: {
    agent_id: number | string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
    summary_time: string;
  },
  v2 = false,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    sh('payout', v2),
    payload,
    { responseReturn: 'body' },
  );
}

/** 發布報表。 */
export async function shareholderReleaseApi(
  payload: {
    /** v2 需要，後端據此推算要發佈給外站的日期 */
    date?: string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
  },
  v2 = false,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    sh('release', v2),
    payload,
    { responseReturn: 'body' },
  );
}

/** 撤銷報表。 */
export async function shareholderRevokeReportApi(
  payload: {
    /** v2 需要，後端據此推算要撤回的外站日期 */
    date?: string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
  },
  v2 = false,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    sh('revoke_report', v2),
    payload,
    { responseReturn: 'body' },
  );
}

/** 份桶重置。 */
export async function shareholderResetApi(
  payload: {
    agent_id: number | string;
    payout_record_id: number | string;
    shareholder_type?: number | string;
    summary_time: string;
  },
  v2 = false,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    sh('reset', v2),
    payload,
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 業績查詢（performance_query / 2） ───────────────────────── */

export interface ShareholderPerfRow {
  agent_id?: number | string;
  agent_nickname?: string;
  agent_username?: string;
  bet_bonus?: number | string;
  bet_ratio?: number | string;
  commission_ratio?: number | string;
  direct_turnover?: number | string;
  game_profit?: number | string;
  game_spending?: number | string;
  name?: string;
  net_win?: number | string;
  nett_profit?: number | string;
  performance_bonus?: number | string;
  proportion?: number | string;
  share_point?: number | string;
  shareholder_category_id?: number | string;
  turnover?: number | string;
  upper_share_point?: number | string;
  win_profit?: number | string;
}

export interface ShareholderPerfData {
  cancel?: unknown;
  direct?: ShareholderPerfRow[];
  general_agent?: { [k: string]: any; nickname?: string };
  search_agent?: Array<{ id: number | string; username: string }>;
  share_point?: { general_agent?: number | string; self?: number | string };
  summary?: ShareholderPerfRow[];
  team?: ShareholderPerfRow[];
}

/** 業績查詢主資料 */
export async function getShareholderPerformanceApi(
  params: {
    agent_id?: number | string;
    category_type?: number | string;
    end_time: string;
    start_time: string;
  },
  variant2 = false,
) {
  return requestClient.post<BodyOf<ShareholderPerfData>>(
    variant2 ? '/api/shareholder/performance2' : '/api/shareholder/performance',
    { agent_id: 0, category_type: 0, ...params },
    { responseReturn: 'body' },
  );
}

export interface ShareholderPerfTotalSalesRow {
  game_profit?: number | string;
  game_spending?: number | string;
  id?: number | string;
  name?: string;
  shareholder_category_id?: number | string;
  turnover?: number | string;
}

/** 業績查詢的總業績 */
export async function getShareholderPerformanceTotalSalesApi(
  params: { end_time: string; start_time: string },
  variant2 = false,
) {
  return requestClient.post<BodyOf<ShareholderPerfTotalSalesRow[]>>(
    variant2
      ? '/api/shareholder/performance_total_sales2'
      : '/api/shareholder/performance_total_sales',
    params,
    { responseReturn: 'body' },
  );
}

export interface ShareholderPerfDetailRow {
  bet?: number | string;
  game_profit?: number | string;
  game_type_name?: string;
  turnover?: number | string;
  type_name?: string;
  username?: string;
  vendor_name?: string;
}

/** 業績明細 */
export async function getShareholderPerformanceDetailApi(
  params: {
    agent_id: number | string;
    end_time: string;
    shareholder_category_id: number | string;
    start_time: string;
  },
  variant2 = false,
) {
  return requestClient.post<
    BodyOf<{ summary_detail?: ShareholderPerfDetailRow[] }>
  >(
    variant2
      ? '/api/shareholder/performanceDetail2'
      : '/api/shareholder/performanceDetail',
    params,
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 股份調整（stock_adjustment） ───────────────────────── */

export interface ShareholderAgentDetail {
  agent_id?: number | string;
  id?: number | string;
  parent?: number | string;
  parent_share_point?: number | string;
  parent_username?: string;
  share_point?: number | string;
  share_point_id?: number | string;
  username?: string;
}

/** 依帳號查股東代理明細 */
export async function getShareholderAgentDetailApi(username: string) {
  return requestClient.post<BodyOf<{ data?: ShareholderAgentDetail }>>(
    '/api/shareholder/agent_detail',
    { username },
    { responseReturn: 'body' },
  );
}

/** 儲存暱稱與股份 */
export async function saveShareholderNicknameAndPointApi(payload: {
  id: number | string;
  lower_share_point: number | string;
  nickname?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/shareholder/setNicknameAndPoint', payload, {
    responseReturn: 'body',
  });
}

/** 代理股份 */
export async function getShareholderSharePointApi(agentId: number | string) {
  return requestClient.post<
    BodyOf<{
      parent_share_point?: number | string;
      self_share_point?: number | string;
    }>
  >(
    '/api/shareholder/sharePoint',
    { agent_id: agentId },
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 用戶列表（user_list） ───────────────────────── */

export interface ShareholderUserRow {
  agent_id?: number | string;
  email?: string;
  /** 代理列：1=已開啟 Google 2FA（會員列恆為 0） */
  has_2fa?: number;
  /** 代理列：1=套用 admin 角色 */
  is_admin_role?: number;
  last_login?: string;
  member_id?: number | string;
  mobile?: string;
  nickname?: string;
  real_name?: string;
  status?: number | string;
  /** 1=本人/已選帳號可明文 */
  sub?: number | string;
  /** 1=股東代理、2=現金會員 */
  type?: number | string;
  username?: string;
}

export interface ShareholderListData {
  search_path?: Array<{ id: number | string; username: string }>;
  shareholder?: ShareholderUserRow[];
}

/** 用戶列表 */
export async function getShareholderUserListApi(params: {
  agent_id?: number | string;
  member_id?: number | string;
  username?: string;
}) {
  return requestClient.post<BodyOf<ShareholderListData>>(
    '/api/shareholder/list',
    {
      agent_id: 0,
      member_id: 0,
      page: 1,
      per_page: 5000,
      username: '',
      ...params,
    },
    { responseReturn: 'body' },
  );
}

/** 重置用戶密碼 */
export async function resetShareholderUserPasswordApi(payload: {
  agent_id: number | string;
  confirm_new_password: [REDACTED];
  new_password: [REDACTED];
  user_name: string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/agent/modifyPassword',
    payload,
    { responseReturn: 'body' },
  );
}

/** 直屬業績明細 */
export async function getShareholderPerformanceDirectDetailApi(
  params: {
    agent_id: number | string;
    end_time: string;
    shareholder_category_id: number | string;
    start_time: string;
  },
  variant2 = false,
) {
  return requestClient.post<BodyOf<{ detail?: ShareholderPerfDetailRow[] }>>(
    variant2
      ? '/api/shareholder/performanceDirectDetail2'
      : '/api/shareholder/performanceDirectDetail',
    params,
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 股份設定（share_setting） ───────────────────────── */

/** 股份設定 - 列 */
export interface ShareholderAgentRow {
  agent_id: number | string;
  id?: number | string;
  lower_limit?: number | string;
  point_share?: number | string;
  upper_limit?: number | string;
  upper_username?: string;
  username?: string;
}
/** 股份設定 - 回傳 data（含中繼資訊） */
export interface ShareholderAgentData {
  data?: ShareholderAgentRow[];
  general_agent?: number | string;
  lower_limit?: number | string;
  modify?: number | string;
  my_shares?: number | string;
  my_superior?: string;
  upper_limit?: number | string;
}
/** 股份設定列表（api/shareholder/agent） */
export async function getShareholderAgentApi(params: {
  agent_username?: string;
  all_agent?: number | string;
  page: number;
  per_page: number;
  previous_agent_username?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: ShareholderAgentData;
    message?: string;
    pagination?: { total?: number };
  }>('/api/shareholder/agent', params, { responseReturn: 'body' });
}

/** 設定股份值（舊版 ajax shareholder_toggle_share → shareholder/point_share） */
export async function setShareholderPointShareApi(payload: {
  agent_id: number | string;
  id?: number | string;
  share: number | string;
}) {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/api/shareholder/point_share',
    payload,
    { responseReturn: 'body' },
  );
}

/** 股東組織樹節點（zTree simpleData） */
export interface ShareholderTreeNode {
  id: number;
  name: string;
  pId: number;
}
/** 股東組織樹（舊版 ajax get_shareholder_hierarchy → shareholder/getAgentHierarchy） */
export async function getShareholderHierarchyApi(username: string) {
  return requestClient.post<{
    code: number | string;
    data?: ShareholderTreeNode[];
    message?: string;
  }>(
    '/api/shareholder/getAgentHierarchy',
    { username },
    { responseReturn: 'body' },
  );
}

/** 投注排行 - 列 */
export interface ShareholderBetRankingRow {
  bet?: number | string;
  id: number | string;
  status?: number | string;
  summary_date?: string;
  username?: string;
}
export interface ShareholderBetRankingData {
  payout_record?: { id?: number | string };
  prohibit?: ShareholderBetRankingRow[];
  rank?: ShareholderBetRankingRow[];
}
/** 投注排行（api/shareholder/betRanking） */
export async function getShareholderBetRankingApi() {
  return requestClient.post<{
    code: number | string;
    data?: ShareholderBetRankingData;
    message?: string;
  }>('/api/shareholder/betRanking', {}, { responseReturn: 'body' });
}
