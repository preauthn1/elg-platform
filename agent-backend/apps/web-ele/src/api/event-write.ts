import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 活動建立/編輯（event/new_edit、edit）所需的下拉、明細與儲存 API。
 *
 * 注意：建立/編輯活動為超級管理員（session id==1 或 host_id==1）限定，
 * 一般帳號進入為唯讀（欄位 disabled）。儲存端點為破壞性寫入。 */

// ---------- 活動明細（編輯帶入） ----------
export interface EventViewData {
  banner?: Array<Record<string, any>>;
  details?: Array<Record<string, any>>;
  exclude_agent?: Array<{ id: number | string; username: string }>;
  flow_limit?: number | string;
  image?: Array<Record<string, any>>;
  languages?: Array<Record<string, any>>;
  prize_draw_date?: string;
  prize_expire_date?: string;
  prizes?: Array<Record<string, any>>;
  type_id?: number | string;
  [key: string]: any;
}

export async function getEventViewDetailApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: EventViewData;
    message: string;
  }>('/api/event/view', { id }, { responseReturn: 'body' });
}

/** 通用 param/list */
export async function getEventParamListApi(key: string) {
  return requestClient.post<
    Array<{ id?: number | string; parameter?: string; value: number | string }>
  >('/api/param/list', { key });
}

// ---------- 廣告圖（圖庫挑選 + 編輯帶入） ----------
export interface AdvertiseImageRow {
  id: number | string;
  image_url: string;
  [key: string]: any;
}

export async function getAdvertiseImageListApi(params?: {
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: AdvertiseImageRow[];
    message: string;
    pagination?: Pagination;
  }>(
    '/api/advertise/image',
    { page: 1, per_page: 6, ...params },
    {
      responseReturn: 'body',
    },
  );
}

export async function getAdvertiseViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: Record<string, any>;
    message: string;
  }>('/api/advertise/view', { id }, { responseReturn: 'body' });
}

/** 總代理下拉 */
export async function getGeneralAgentDropdownApi() {
  return requestClient.post<Array<Record<string, any>>>(
    '/api/dropdown/general_agent',
    {},
  );
}

/**
 * 儲存活動（破壞性，超管限定）。
 * 無 id→api/event/new_add，有 id→api/event/new_edit。 */
export async function saveEventApi(data: Record<string, any>, isEdit: boolean) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(isEdit ? '/api/event/new_edit' : '/api/event/new_add', data, {
    responseReturn: 'body',
  });
}

/** 儲存彩池活動（type_id==3）。 */
export async function saveEventJackpotApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>(isEdit ? '/api/event/editJackpot' : '/api/event/createJackpot', data, {
    responseReturn: 'body',
  });
}
