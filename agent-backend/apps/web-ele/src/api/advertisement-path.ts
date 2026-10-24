import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 廣告版位（advertisementPath）API
 * 版位 index/edit（含儲存）。 */

export interface AdvertisementPathRow {
  column: number | string;
  height: number | string;
  id: number | string;
  name: string;
  status: number | string;
  width: number | string;
}

export interface AdvertisementPathBody {
  code: number;
  data: AdvertisementPathRow[];
  message: string;
  pagination?: Pagination;
}

/** 版位列表 */
export async function getAdvertisementPathListApi() {
  return requestClient.post<AdvertisementPathBody>(
    '/api/advertisementPath/list',
    { page: 1, per_page: 1000 },
    { responseReturn: 'body' },
  );
}

/** 版位詳細 */
export async function getAdvertisementPathViewApi(id: number | string) {
  return requestClient.post<AdvertisementPathRow>(
    '/api/advertisementPath/view',
    { id },
  );
}

export interface AdvertisementPathPayload {
  column: number | string;
  height: number | string;
  id?: number | string;
  name: string;
  status: number | string;
  width: number | string;
}

export interface AdvertisementPathActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 新增/編輯版位 */
export async function saveAdvertisementPathApi(
  payload: AdvertisementPathPayload,
) {
  const hasId = payload.id !== undefined && payload.id !== '';
  return requestClient.post<AdvertisementPathActionResult>(
    hasId ? '/api/advertisementPath/edit' : '/api/advertisementPath/add',
    payload,
    { responseReturn: 'body' },
  );
}
