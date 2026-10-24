import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 稱桶（分桶 3.0）模組 API。 */

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

interface PlainBody {
  code: number | string;
  data?: unknown;
  message: string;
}

/**
 * 稱桶的結算與跨站呼叫比一般端點慢很多，全域 60 秒逾時會在跨站來回時中斷。
 * 這裡的秒數對齊舊版 ajax 的 curl timeout。 */
const SLOW = 120_000;
const VERY_SLOW = 180_000;

/* ───────────────────────── 子站管理 ───────────────────────── */

export interface ShareholderV3SiteRow {
  api_url?: string;
  currency_id?: number | string;
  currency_name?: string;
  exchange_rate_to_usd?: number | string;
  foreign_agent_id?: number | string;
  id: number | string;
  memo?: string;
  site_name?: string;
  /** 0=內部系統、1=外部系統 */
  source?: number | string;
  /** 1=啟用、0=停用 */
  status?: number | string;
  whitelist?: string;
}

/** 子站列表 */
export async function getShareholderV3SiteListApi(params: {
  agent_id?: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<ShareholderV3SiteRow>>(
    '/api/shareholder/v3/site/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface ShareholderV3SitePayload {
  agent_id: number | string;
  api_url: string;
  currency_id: number | string;
  foreign_agent_id?: number | string;
  id?: number | string;
  memo: string;
  site_name: string;
  source?: number | string;
  status: number | string;
  /** update 端點會把沒送的值覆寫成空字串，需帶原值 */
  whitelist?: string;
}

/** 新增子站 */
export async function createShareholderV3SiteApi(
  payload: ShareholderV3SitePayload,
) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/site/create',
    payload,
    { responseReturn: 'body' },
  );
}

/** 編輯子站 */
export async function updateShareholderV3SiteApi(
  payload: ShareholderV3SitePayload,
) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/site/update',
    payload,
    { responseReturn: 'body' },
  );
}

/** 子站明細（含 api_key） */
export async function getShareholderV3SiteDetailApi(id: number | string) {
  return requestClient.post<BodyOf<{ api_key?: string; site_name?: string }>>(
    '/api/shareholder/v3/site/detail',
    { id },
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 匯率管理 ───────────────────────── */

export interface ShareholderV3CurrencyRateRow {
  currency?: string;
  currency_id: number | string;
  currency_name?: string;
  exchange_rate_to_usd?: number | string;
  updated_at?: string;
}

/** 幣別對美金匯率列表 */
export async function getShareholderV3CurrencyRateListApi(params: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<ShareholderV3CurrencyRateRow>>(
    '/api/shareholder/v3/currency_rate/list',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
}

/** 設定幣別對美金匯率（新增與編輯同一支） */
export async function setShareholderV3CurrencyRateApi(payload: {
  currency_id: number | string;
  exchange_rate_to_usd: number | string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/currency_rate/set',
    payload,
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 子站操作紀錄 ───────────────────────── */

export interface ShareholderV3OperationLogRow {
  action?: string;
  created_at?: string;
  http_status?: number | string;
  request?: unknown;
  response?: unknown;
  site_name?: string;
  time?: string;
  url?: string;
  username?: string;
}

/** 稱桶結算子站操作紀錄 */
export async function getShareholderV3OperationLogsApi(params: {
  action?: string;
  end_time: string;
  page?: number;
  per_page?: number;
  site_id?: number | string;
  start_time: string;
}) {
  return requestClient.post<PagedBody<ShareholderV3OperationLogRow>>(
    '/api/shareholder/v3/foreign_operation_logs',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 資料同步 / 推送 ───────────────────────── */

export interface ShareholderV3SyncStatusRow {
  id: number | string;
  push_req?: unknown;
  push_res?: unknown;
  /** 0=未推送、1=推送成功、2=失敗 */
  push_status?: number | string;
  push_time?: string;
  push_url?: string;
  site_name?: string;
  /** 0=未同步、1=已同步、2=失敗、3=排除 */
  sync_status?: number | string;
  sync_time?: string;
}

/** 各子站同步 / 推送狀態 */
export async function getShareholderV3SyncStatusApi(params: {
  agent_id: number | string;
  date: string;
  payout_record_id: number | string;
}) {
  return requestClient.post<PagedBody<ShareholderV3SyncStatusRow>>(
    '/api/shareholder/v3/sync_status',
    params,
    { responseReturn: 'body' },
  );
}

export interface ShareholderV3SyncResultRow {
  end_time?: string;
  site_id: number | string;
  /** 1=成功 */
  status?: number | string;
}

/** 同步子站業績（site_id=0 為一鍵同步） */
export async function syncShareholderV3ForeignPerformanceApi(payload: {
  date: string;
  site_id: number | string;
}) {
  return requestClient.post<PagedBody<ShareholderV3SyncResultRow>>(
    '/api/shareholder/v3/sync_foreign_performance',
    payload,
    { responseReturn: 'body', timeout: VERY_SLOW },
  );
}

export interface ShareholderV3DailyDataRow {
  category_name?: string;
  currency?: string;
  turnover?: number | string;
  turnover_usd?: number | string;
  win?: number | string;
  win_lose?: number | string;
  win_lose_usd?: number | string;
  win_usd?: number | string;
}

/** 已同步的子站當日數據 */
export async function getShareholderV3ForeignDailyDataApi(params: {
  date: string;
  site_id: number | string;
}) {
  return requestClient.post<PagedBody<ShareholderV3DailyDataRow>>(
    '/api/shareholder/v3/foreign_daily_data',
    params,
    { responseReturn: 'body' },
  );
}

/** 排除 / 取消排除某子站當日數據 */
export async function toggleShareholderV3SiteExclusionApi(payload: {
  date: string;
  exclude: number;
  remark: string;
  site_id: number | string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/toggle_site_exclusion',
    payload,
    { responseReturn: 'body' },
  );
}

export interface ShareholderV3PushResultRow {
  end_time?: string;
  message?: string;
  request_body?: unknown;
  request_url?: string;
  response?: unknown;
  site_id: number | string;
  /** 1=成功 */
  status?: number | string;
}

/** 推送總業績給子站（site_id=0 為一鍵推送） */
export async function pushShareholderV3ForeignPayoutApi(payload: {
  site_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<PagedBody<ShareholderV3PushResultRow>>(
    '/api/shareholder/v3/push_foreign_payout',
    payload,
    { responseReturn: 'body', timeout: VERY_SLOW },
  );
}

/* ───────────────────────── 稱桶結算流程 ───────────────────────── */

/** 設定成本 */
export async function shareholderV3SetCostApi(payload: {
  cost: number | string;
  date: string;
  id: number | string;
  memo: string;
  payout_record_id: number | string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/set_cost',
    payload,
    { responseReturn: 'body', timeout: SLOW },
  );
}

/** 結算 */
export async function shareholderV3SettleApi(payload: {
  agent_id: number | string;
  payout_record_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<PlainBody>('/api/shareholder/v3/settle', payload, {
    responseReturn: 'body',
    timeout: SLOW,
  });
}

/** 派彩 */
export async function shareholderV3PayoutApi(payload: {
  agent_id: number | string;
  payout_record_id: number | string;
  summary_time: string;
}) {
  return requestClient.post<PlainBody>('/api/shareholder/v3/payout', payload, {
    responseReturn: 'body',
    timeout: SLOW,
  });
}

/** 發佈報表 */
export async function shareholderV3ReleaseApi(payload: {
  date: string;
  payout_record_id: number | string;
}) {
  return requestClient.post<PlainBody>('/api/shareholder/v3/release', payload, {
    responseReturn: 'body',
    timeout: SLOW,
  });
}

/** 撤回報表 */
export async function shareholderV3RevokeReportApi(payload: {
  date: string;
  payout_record_id: number | string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/revoke_report',
    payload,
    { responseReturn: 'body', timeout: SLOW },
  );
}

export interface ShareholderV3ForeignTotalSalesRow {
  category_name?: string;
  currency?: string;
  rate_to_usd?: number | string;
  site_name?: string;
  /** 3=排除 */
  sync_status?: number | string;
  turnover?: number | string;
  win?: number | string;
  win_lose?: number | string;
}

/** 各子站總業績（扁平列，依 site_name + category_name 分組） */
export async function getShareholderV3ForeignTotalSalesApi(date: string) {
  return requestClient.post<PagedBody<ShareholderV3ForeignTotalSalesRow>>(
    '/api/shareholder/v3/foreign_total_sales',
    { date },
    { responseReturn: 'body' },
  );
}

/** 更新總業績 */
export async function updateShareholderV3TotalSalesApi(payload: {
  payout_record_id: number | string;
  record: Array<{
    category_name?: number | string;
    game_spending: number | string;
    id: number | string;
    turnover: number | string;
  }>;
  summary_date: string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/update_total_sales',
    payload,
    { responseReturn: 'body', timeout: SLOW },
  );
}

/* ─────────────── 子站（分桶 1.0 頁面用到的稱桶端點） ─────────────── */

/** 子站輸入成本（狀態 7：主站成本 + 子站成本） */
export async function shareholderV3SetSubSiteCostApi(payload: {
  date: string;
  id: number | string;
  memo: string;
  payout_record_id: number | string;
  site_cost: number | string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/set_sub_site_cost',
    payload,
    { responseReturn: 'body', timeout: SLOW },
  );
}

/** 撤回子站報表（sub_site_id > 0 時取代 revoke_report） */
export async function shareholderV3RevokeSubSiteReportApi(payload: {
  payout_record_id: number | string;
  shareholder_type: number | string;
}) {
  return requestClient.post<PlainBody>(
    '/api/shareholder/v3/revoke_sub_site_report',
    payload,
    { responseReturn: 'body', timeout: SLOW },
  );
}
