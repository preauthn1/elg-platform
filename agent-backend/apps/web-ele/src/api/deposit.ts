import type { PagedResult } from '#/api/member';

import { requestClient } from '#/api/request';

// ---------- deposit/index 支付商戶（cash flow）管理 ----------

export interface DepositCashFlowRow {
  id: number | string;
  name?: string;
  status?: number | string;
  type?: number | string;
}

export interface CashFlowOrderTypeRow {
  cash_flow_id?: number | string;
  cash_flow_name?: string;
  id: number | string;
  key?: string;
  name?: string;
  sorts?: number | string;
  status?: number | string;
}

/** 支付商戶 + 語系清單 */
export async function getCashFlowApi() {
  return requestClient.post<{
    cash_flow_list: DepositCashFlowRow[];
    language_list: Array<{ id: number | string; name: string }>;
  }>('/api/agent/onlineDeposit/getCashFlow', {});
}

/** 編輯支付商戶 */
export async function updateCashFlowApi(params: {
  id: number | string;
  name: string;
  type: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/onlineDeposit/updateCashFlow',
    params,
    { responseReturn: 'body' },
  );
}

/** 切換支付商戶狀態 */
export async function cashFlowToggleStatusApi(params: {
  cf_id: number | string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: { status?: number | string };
    message: string;
  }>('/api/agent/onlineDeposit/cashFlowToggleStatus', params, {
    responseReturn: 'body',
  });
}

/** 取得某支付商戶的支付方式清單 */
export async function getDepositOrderTypeListApi(cfId: number | string) {
  return requestClient.post<{
    code: number;
    data?: CashFlowOrderTypeRow[];
    message: string;
  }>(
    '/api/agent/onlineDeposit/getOrderTypeList',
    { cf_id: cfId },
    { responseReturn: 'body' },
  );
}

/** 編輯支付方式 */
export async function updateCashFlowOrderTypeApi(params: {
  cash_flow_id: number | string;
  id: number | string;
  key: string;
  name: string;
  sorts: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: { cash_flow_order_type_list?: CashFlowOrderTypeRow[] };
    message: string;
  }>('/api/agent/onlineDeposit/updateCashFlowOrderType', params, {
    responseReturn: 'body',
  });
}

/** 切換支付方式狀態 */
export async function cashFlowOrderTypeToggleStatusApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: { status?: number | string };
    message: string;
  }>('/api/agent/onlineDeposit/cashFlowOrderTypeToggleStatus', params, {
    responseReturn: 'body',
  });
}

/** 取得支付方式的多語系名稱 */
export async function getCashFlowOrderTypeNameApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: {
      order_type?: { id: number | string; name?: string };
      order_type_name_list?: Array<{
        language_id: number | string;
        name?: string;
      }>;
    };
    message: string;
  }>(
    '/api/agent/onlineDeposit/getCashFlowOrderTypeName',
    { id },
    { responseReturn: 'body' },
  );
}

/** 更新支付方式的多語系名稱 */
export async function updateCashFlowOrderTypeNameApi(params: {
  id: number | string;
  name: Record<string, string>;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/onlineDeposit/updateCashFlowOrderTypeName',
    params,
    { responseReturn: 'body' },
  );
}

// ---------- deposit/order_type_list 支付方式列表 ----------

export interface OrderTypeRow {
  cash_flow_id?: number | string;
  cash_flow_name?: string;
  created_at?: string;
  currency_id?: number | string;
  currency_name?: string;
  id: number | string;
  image_path?: string;
  key?: string;
  name?: string;
  sorts?: number | string;
  status?: number | string;
  type?: number | string;
}

/** 支付方式分頁列表 */
export async function getOrderTypePagingApi(params: {
  name?: string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<OrderTypeRow>>(
    '/api/orderType/paging-list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 編輯支付方式 */
export async function updateOrderTypeApi(params: {
  currency: number | string;
  id: number | string;
  image_path?: string;
  key: string;
  name: string;
  sorts: number | string;
  status: number | string;
  type: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/orderType/update',
    params,
    { responseReturn: 'body' },
  );
}

/** 取得支付方式多語系名稱 */
export async function getOrderTypeNamesApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: Array<{ language_id: number | string; name?: string }>;
    message: string;
  }>('/api/orderType/names', { id }, { responseReturn: 'body' });
}

/** 更新支付方式多語系名稱 */
export async function updateOrderTypeNamesApi(params: {
  id: number | string;
  order_type_names: Record<string, string>;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/orderType/names/update',
    params,
    { responseReturn: 'body' },
  );
}

/** base64 圖片上傳，回 path */
export async function uploadDepositImageApi(base64Image: string) {
  return requestClient.post<{
    code: number;
    data?: { path?: string };
    message: string;
  }>('/api/image/base64/upload', { base64Image }, { responseReturn: 'body' });
}
