import { requestClient } from '#/api/request';

/**
 * 貨幣管理 currency API。 */

export interface CurrencyManageRow {
  code?: string;
  id: number | string;
  name?: string;
  rate?: number | string;
  sort?: number | string;
  status?: number;
}

export interface CurrencyManageDetail extends CurrencyManageRow {
  /** 多語名稱 */
  names?: Array<{ language_id: number | string; name: string }>;
}

/** 貨幣列表 */
export async function getCurrencyManageListApi() {
  return requestClient.post<CurrencyManageRow[]>('/api/currency/list', {});
}

/** 單筆貨幣 */
export async function getCurrencyViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: CurrencyManageDetail;
    message: string;
  }>('/api/currency/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯貨幣 */
export async function saveCurrencyApi(payload: {
  code: string;
  id: number | string;
  name: string;
  names?: Record<string, string>;
  rate: number | string;
  sort: number | string;
  status: number;
}) {
  const isAdd = !payload.id || Number(payload.id) === 0;
  return requestClient.post<{ code: number | string; message: string }>(
    isAdd ? '/api/currency/add' : '/api/currency/edit',
    payload,
    { responseReturn: 'body' },
  );
}

/** 切換啟用狀態 */
export async function toggleCurrencyStatusApi(
  id: number | string,
  status: number,
) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/currency/edit',
    { id, status },
    { responseReturn: 'body' },
  );
}
