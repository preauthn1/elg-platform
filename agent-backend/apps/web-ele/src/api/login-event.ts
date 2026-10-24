import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 登入活動模組 API（api/loginEvent/*）。 */

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

interface PagedBody<T> {
  code: number | string;
  data?: T[];
  message: string;
  pagination?: Pagination;
}

/** 登入活動列 */
export interface LoginEventRow {
  agent_id?: number | string;
  agent_username?: string;
  end_time?: string;
  how_days?: number | string;
  id: number | string;
  number_of_participants?: number | string;
  number_of_people?: number | string;
  point?: number | string;
  /** 1 重複參加 */
  repeat_hour?: number | string;
  start_time?: string;
  /** 1=啟用、否則停用 */
  status?: number | string;
  times?: number | string;
  title?: string;
  /** 1/2 活動對象類型 */
  type?: number | string;
  /** 逗號串的試玩遊戲商 id */
  vendor_id?: string;
}

/** 登入活動列表 */
export async function getLoginEventListApi(params?: {
  page?: number;
  per_page?: number;
  status?: number | string;
  title?: string;
}) {
  return requestClient.post<PagedBody<LoginEventRow>>(
    '/api/loginEvent/list',
    {
      page: params?.page ?? 1,
      per_page: params?.per_page ?? 1000,
      status: params?.status ?? 'all',
      title: params?.title ?? '',
    },
    { responseReturn: 'body' },
  );
}

/** 切換登入活動狀態（status 需送字串，後端用 `!= ''` 判空，數字 0 會被當成空值忽略） */
export async function toggleLoginEventStatusApi(params: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/loginEvent/status',
    { id: params.id, status: String(params.status) },
    { responseReturn: 'body' },
  );
}

/** 登入活動詳細 */
export interface LoginEventDetail extends LoginEventRow {
  [key: string]: unknown;
}

export async function getLoginEventViewApi(id: number | string) {
  return requestClient.post<BodyOf<LoginEventDetail>>(
    '/api/loginEvent/view',
    { id },
    { responseReturn: 'body' },
  );
}

/** 新增/編輯登入活動 */
export async function saveLoginEventApi(payload: Record<string, any>) {
  const isEdit = payload.id !== undefined && payload.id !== '';
  return requestClient.post<BodyOf<unknown>>(
    isEdit ? '/api/loginEvent/edit' : '/api/loginEvent/add',
    payload,
    { responseReturn: 'body' },
  );
}
