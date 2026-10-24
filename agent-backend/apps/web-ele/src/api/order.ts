import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 儲值訂單 API */

export interface OrderCashFlow {
  id: number;
  name: string;
}

/** 金流商下拉 */
export async function getOrderCashFlowApi() {
  const res = await requestClient.post<{ cash_flow_list: OrderCashFlow[] }>(
    '/api/agent/onlineDeposit/getCashFlow',
    { type: [1, 2] },
  );
  return res?.cash_flow_list ?? [];
}

export interface OrderRow {
  agent_id?: number;
  agent_real_name?: string;
  agent_username?: string;
  amount?: number | string;
  cash_flow_id?: number;
  cf_name?: string;
  created_at?: string;
  from_account_name?: string;
  id: number | string;
  member_id?: number;
  member_tags?: Array<{
    background_color?: string;
    name?: string;
    text_color?: string;
  }>;
  member_username?: string;
  order_no?: string;
  order_type_id?: number;
  order_type_name?: string;
  point?: number | string;
  remark?: string;
  status?: number;
  status_name?: string;
  third_party_code?: string;
  third_party_order_no?: string;
  updated_at?: string;
}

export interface OrderListParams {
  agent_username?: string;
  cash_flow_id?: number | string;
  end_time?: string;
  id?: number | string;
  member_username?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  search_agent?: number;
  start_time?: string;
  status?: number | string;
  third_party_order_no?: string;
  /** 期間依哪個時間欄位篩：created 建立時間（預設）／paid 付款成功時間 */
  time_type?: 'created' | 'paid';
  type?: number | string;
  username?: string;
}

/** 儲值訂單列表 */
export async function getOrderListApi(params: OrderListParams) {
  return requestClient.post<{
    code: number;
    data: OrderRow[];
    message: string;
    pagination?: Pagination;
    total_summary?: {
      total_amount?: number | string;
      total_point?: number | string;
    };
  }>(
    '/api/order/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 查詢第三方訂單編號 */
export async function queryThirdPartyOrderApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: { third_party_order_no?: string };
    message: string;
  }>('/api/order/query-third-party-order', { id }, { responseReturn: 'body' });
}

/** 三方往返紀錄的一列。
 * APIs 刻意只回純量、不回任何往返內容——那張表存的是未遮罩原文、含明文商戶金鑰，
 * 靠白名單過濾等於留一個要靠人維護的控制面。客服要的是「為什麼」（result），
 * 要看「三方送了什麼」的是開發，直接查表。 */
export interface OrderRequestLogRow {
  action: string;
  created_at: null | string;
  http_status: null | number;
  result: null | string;
  source_ip: null | string;
  third_party_order_no: null | string;
}

/** 儲值訂單的三方往返紀錄 */
export async function getDepositRequestLogsApi(orderId: number | string) {
  return requestClient.post<{
    code: number;
    data: OrderRequestLogRow[];
    message: string;
  }>(
    '/agent/api/deposit/request-logs',
    { order_id: orderId },
    { responseReturn: 'body' },
  );
}

/** 手動上分 */
export async function saveManualDepositApi(data: {
  order_id: number | string;
  remark: string;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/order/manual-deposit',
    data,
    { responseReturn: 'body' },
  );
}

/** 重置訂單狀態 */
export async function resetOrderStatusApi(data: {
  order_id: number | string;
  remark: string;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/order/reset-status',
    data,
    { responseReturn: 'body' },
  );
}

/** 儲值訂單審核：列表列 */
export interface AutoDepositRow {
  account_name?: string;
  account_no?: string;
  amount?: number | string;
  bank_code?: string;
  bank_name?: string;
  bsb_code?: string;
  create_at?: string;
  id: number | string;
  order_id: number | string;
  order_no?: string;
  remark?: string;
  status?: number;
  username?: string;
}

export interface AutoDepositParams {
  account_no?: string;
  end_time?: string;
  id?: number | string;
  member_username?: string;
  order_no?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  status?: number | string;
}

/** 儲值訂單審核列表 */
export async function getAutoDepositApi(params: AutoDepositParams) {
  return requestClient.post<{
    code: number;
    data: AutoDepositRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/order/getAutoDeposit',
    { page: 1, per_page: 50, ...params },
    {
      responseReturn: 'body',
    },
  );
}

/** 儲值訂單審核：單筆詳細 */
export interface AutoDepositImage {
  account_name?: string;
  account_nickname?: string;
  account_no?: string;
  agent_username?: string;
  amount?: number | string;
  bank_name?: string;
  bsb_code?: string;
  create_at?: string;
  first_name?: string;
  id: number | string;
  image?: string;
  member_tags?: Array<{
    background_color?: string;
    name?: string;
    text_color?: string;
  }>;
  operator?: string;
  pay_time?: string;
  point?: number | string;
  rate?: number | string;
  remark?: string;
  status?: number;
  username?: string;
}

/** 取得儲值訂單詳細 */
export async function getAutoDepositImageApi(orderId: number | string) {
  return requestClient.post<{
    code: number;
    data: AutoDepositImage;
    message: string;
  }>('/api/order/getImage', { order_id: orderId }, { responseReturn: 'body' });
}

/** 審核儲值訂單 */
export async function reviewAutoDepositApi(data: {
  id: number | string;
  remark: string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: string;
    message: string;
  }>('/api/order/reviewOrder', data, { responseReturn: 'body' });
}
