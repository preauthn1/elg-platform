import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 代理限額 transfer_limit API。 */

export interface TransferLimitRow {
  id: number | string;
  limit?: number | string;
  name?: string;
}

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

/** 限額列表 */
export async function getTransferLimitListApi(params: {
  agent_group_name?: string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedBody<TransferLimitRow>>(
    '/api/transfer_limit/list',
    { agent_group_name: '', page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 代理組別下拉 */
export async function getTransferLimitDropdownApi() {
  return requestClient.post<Array<{ id: number | string; name: string }>>(
    '/api/transfer_limit/getDropDownList',
    {},
  );
}

/** 單筆限額資訊 */
export async function getTransferLimitInfoApi(limitId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: TransferLimitRow;
    message: string;
  }>(
    '/api/transfer_limit/getLimitInfo',
    { limit_id: limitId },
    { responseReturn: 'body' },
  );
}

export interface TransferLimitRecordRow {
  after_limit?: number | string;
  agent_group_name?: string;
  amount?: number | string;
  before_limit?: number | string;
  created_at?: string;
  id?: number | string;
  memo?: string;
}

/**
 * 上下分紀錄
 * agent_group_id 一定要送字串：後端 TransferLimitController::getRecord() 用
 * `$request->agent_group_id !== '0'` 嚴格比較，送數字 0（代表全部組別）會被
 * 誤判成「有指定組別」，改用 ag.id = 0 查詢，永遠查不到資料。
 */
export async function getTransferLimitRecordApi(params: {
  agent_group_id?: number | string;
  agent_name?: string;
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  type?: number | string;
}) {
  const { agent_group_id, ...rest } = params;
  return requestClient.post<PagedBody<TransferLimitRecordRow>>(
    '/api/transfer_limit/getRecord',
    {
      agent_group_id: String(agent_group_id ?? ''),
      agent_name: '',
      page: 1,
      per_page: 20,
      type: '',
      ...rest,
    },
    { responseReturn: 'body' },
  );
}

/** 新增限額 */
export async function createTransferLimitApi(payload: {
  id: number | string;
  limit: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/transfer_limit/createLimit',
    payload,
    { responseReturn: 'body' },
  );
}

/** 調整限額 */
export async function updateTransferLimitApi(payload: {
  amount: number | string;
  id: number | string;
  memo?: string;
  type: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/transfer_limit/updateLimit',
    payload,
    { responseReturn: 'body' },
  );
}
