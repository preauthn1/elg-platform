import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 公告訊息（announcement/message_list）API
 * 公告訊息列表 */

export interface AnnouncementMessageRow {
  created_at?: string;
  end_time?: string;
  id: number | string;
  memo?: string;
  message?: string;
  op_username?: string;
  participants_type: number | string;
  start_time?: string;
  target_name?: string;
}

export interface AnnouncementMessageBody {
  code: number;
  data: AnnouncementMessageRow[];
  message: string;
  pagination?: Pagination;
}

export interface AnnouncementMessageParams {
  end_time: string;
  page: number;
  per_page: number;
  start_time: string;
  username?: string;
}

/** 公告訊息列表 */
export async function getAnnouncementMessageListApi(
  params: AnnouncementMessageParams,
) {
  return requestClient.post<AnnouncementMessageBody>(
    '/api/announcement-messages/list',
    params,
    { responseReturn: 'body' },
  );
}

export interface AnnouncementMessagePayload {
  end_time: string;
  hierarchy_agent_id?: number | string;
  memo?: string;
  message: string;
  organization_id?: number | string;
  start_time: string;
  type: number | string;
}

export interface AnnouncementMessageActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 新增公告訊息 */
export async function createAnnouncementMessageApi(
  payload: AnnouncementMessagePayload,
) {
  return requestClient.post<AnnouncementMessageActionResult>(
    '/api/announcement-messages/create',
    { title: '', ...payload },
    { responseReturn: 'body' },
  );
}

/** 刪除公告訊息 */
export async function deleteAnnouncementMessageApi(id: number | string) {
  return requestClient.post<AnnouncementMessageActionResult>(
    '/api/announcement-messages/delete',
    { announcement_message_id: id },
    { responseReturn: 'body' },
  );
}
