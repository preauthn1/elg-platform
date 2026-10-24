import type { PagedResult } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 會員標籤 API */

export interface TagRow {
  background_color?: string;
  id: number;
  name: string;
  status?: number;
  text_color?: string;
}

/** 標籤清單 */
export async function getTagListApi(params: { status?: number } = {}) {
  return requestClient.post<TagRow[]>('/api/tag/list', params);
}

export interface TagMemberRow {
  id: number;
  tag_ids?: string;
  username?: string;
}

/** 會員標籤列表 */
export async function getTagMemberListApi(params: {
  page?: number;
  per_page?: number;
  tag_id?: Array<number | string>;
  username?: string;
}) {
  return requestClient.post<PagedResult<TagMemberRow>>(
    '/api/tag/member/list',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/** 更新會員標籤 */
export async function saveMemberTagsApi(data: {
  member_id: number;
  tags: Array<number | string>;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/tag/member/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 新增/編輯/切換標籤 */
export async function saveTagApi(data: {
  background_color?: string;
  id?: number | string;
  name?: string;
  status?: number;
  text_color?: string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/tag/save',
    data,
    { responseReturn: 'body' },
  );
}
