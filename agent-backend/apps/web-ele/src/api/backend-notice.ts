import { requestClient } from '#/api/request';

/**
 * 後台通知頁 API（最新消息 platform_notification / 系統通知 system_notification）
 * 注意：與 header 鈴鐺的儀表板提醒(api/notice/*)是不同來源。 */

interface NotifPagination {
  current?: number;
  pages?: number;
  total?: number;
}

/** 最新消息列 */
export interface PlatformNotificationRow {
  content?: string;
  image_url?: string;
  open_window?: number | string;
  start_time?: string;
  tag?: number | string;
  title?: string;
  type?: number | string;
}

export async function getPlatformNotificationListApi(params: {
  page: number;
  per_page: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: PlatformNotificationRow[];
    message?: string;
    pagination?: NotifPagination;
  }>('/api/platform_notification/list', params, { responseReturn: 'body' });
}

/** 最新消息未讀數（header 鈴鐺紅點用） */
export async function getPlatformNotificationUnreadApi() {
  return requestClient.post<{
    code: number | string;
    data?: { unread?: number | string };
  }>('/api/platform_notification/unread', {}, { responseReturn: 'body' });
}

export async function readAllPlatformNotificationApi() {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/api/platform_notification/readAll',
    {},
    { responseReturn: 'body' },
  );
}

/** 系統通知列 */
export interface SystemNotificationRow {
  amount?: number | string;
  content?: string;
  content_1?: string;
  content_2?: string;
  from?: string;
  status?: string;
  tag?: string;
  time?: string;
  to?: string;
  type?: number | string;
}

export async function getSystemNotificationListApi(params: {
  page: number;
  per_page: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: SystemNotificationRow[];
    message?: string;
    pagination?: NotifPagination;
  }>('/api/system_notification/list', params, { responseReturn: 'body' });
}

/** 系統通知未讀數（header 鈴鐺紅點用） */
export async function getSystemNotificationUnreadApi() {
  return requestClient.post<{
    code: number | string;
    data?: { unread?: number | string };
  }>('/api/system_notification/unread', {}, { responseReturn: 'body' });
}

export async function readAllSystemNotificationApi() {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/api/system_notification/readAll',
    {},
    { responseReturn: 'body' },
  );
}
