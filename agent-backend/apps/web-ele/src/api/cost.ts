import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 成本設定 cost API。 */

export interface CostRow {
  cost?: number | string;
  id: number | string;
  name?: string;
  oname?: string;
  /** 1/2=百分比、3=固定值 */
  type: number | string;
}

/** 成本列表 */
export async function getCostListApi(params: {
  page?: number;
  per_page?: number;
  type?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: CostRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/cost/list',
    { page: 1, per_page: 10, type: 0, ...params },
    { responseReturn: 'body' },
  );
}

/** 設定成本 */
export async function saveCostApi(payload: {
  cost: number | string;
  id: number | string;
  type: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/cost/setting',
    payload,
    { responseReturn: 'body' },
  );
}

/** 批次設定成本（佔成費用清冊編輯）：type 1=遊戲商 2=支付商，ids 與 costs 為對應陣列 */
export async function batchCostSettingApi(payload: {
  costs: Array<number | string>;
  ids: Array<number | string>;
  type: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/cost/batch_setting',
    payload,
    { responseReturn: 'body' },
  );
}
