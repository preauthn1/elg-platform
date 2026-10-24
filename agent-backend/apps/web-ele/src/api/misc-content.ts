import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 雜項-公告/跑馬燈內容 API（backend_announcement / marquee_text / platform_announcement） */

// ---------------------------------------------------------------------------
// 代理公告 backend_announcement
// ---------------------------------------------------------------------------
export interface BackendAnnouncementRow {
  content: string;
  id: number | string;
  status: number | string;
  type: number | string;
}

export interface BackendAnnouncementListBody {
  code: number;
  data: BackendAnnouncementRow[];
  message: string;
  pagination?: Pagination;
}

/** 代理公告列表 */
export async function getBackendAnnouncementListApi(params: {
  page: number;
  per_page: number;
  type?: number | string;
}) {
  return requestClient.post<BackendAnnouncementListBody>(
    '/api/announcement/list',
    params,
    { responseReturn: 'body' },
  );
}

export interface AnnouncementAgent {
  id: number | string;
  parent?: number | string;
  username: string;
}

/** 公告可選代理 */
export async function getAnnouncementAgentsApi() {
  return requestClient.post<{
    child: Record<string, AnnouncementAgent[]>;
    host: AnnouncementAgent[];
  }>('/api/announcement/getGeneralAgent', {});
}

/** 代理公告詳細 */
export async function getBackendAnnouncementViewApi(id: number | string) {
  return requestClient.post<{
    agent_ids: Array<number | string>;
    announcement: {
      content: string;
      is_top: number | string;
      type: number | string;
    };
  }>('/api/announcement/view', { id });
}

/** 儲存代理公告 */
export async function saveBackendAnnouncementApi(payload: {
  agent_ids: Array<number | string>;
  announcement_content: string;
  id?: number | string;
  is_top: number | string;
  type_id: number | string;
}) {
  const url = payload.id ? '/api/announcement/edit' : '/api/announcement/add';
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    url,
    payload,
    { responseReturn: 'body' },
  );
}

/** 代理公告啟用/停用 */
export async function toggleBackendAnnouncementApi(
  id: number | string,
  status: number | string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/announcement/announcementToggleStatus',
    { id, status },
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 文字跑馬燈 marquee_text
// ---------------------------------------------------------------------------
export interface MarqueeTextRow {
  agent_name: string;
  content: string;
  end_time: string;
  exclude_agent_name: string;
  id: number | string;
  location: number | string;
  start_time: string;
  status: number | string;
  type: number | string;
}

export interface MarqueeTextListBody {
  code: number;
  data: MarqueeTextRow[];
  message: string;
  pagination?: Pagination;
}

/** 文字跑馬燈列表 */
export async function getMarqueeTextListApi(params: {
  location?: number | string;
  page: number;
  per_page: number;
  status?: number | string;
  type?: number | string;
}) {
  return requestClient.post<MarqueeTextListBody>(
    '/api/marquee/text/list',
    params,
    { responseReturn: 'body' },
  );
}

/** Header 跑馬燈顯示清單 */
export interface MarqueeDisplayRow {
  content: string;
  id: number | string;
  /** 2=重要公告 */
  type: number | string;
}
export async function getMarqueeDisplayListApi() {
  return requestClient.post<MarqueeDisplayRow[]>(
    '/api/marquee/text/displayList',
    {},
  );
}

/** 文字跑馬燈詳細 */
export async function getMarqueeTextViewApi(id: number | string) {
  return requestClient.post<{
    contents: Record<string, { content: string }>;
    marquees: {
      agent_id: number | string;
      agent_name: string;
      end_time: string;
      exclude_agent_id: number | string;
      exclude_agent_name: string;
      location: number | string;
      start_time: string;
      status: number | string;
      type: number | string;
    };
  }>('/api/marquee/text/view', { id });
}

/** 儲存文字跑馬燈 */
export async function saveMarqueeTextApi(payload: {
  agent_id: number | string;
  contents: Record<string, string>;
  end_date: string;
  exclude_agent_id: number | string;
  id?: number | string;
  location: number | string;
  start_date: string;
  status: number | string;
  type_id: number | string;
}) {
  const url = payload.id ? '/api/marquee/text/edit' : '/api/marquee/text/add';
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    url,
    payload,
    { responseReturn: 'body' },
  );
}

/** 文字跑馬燈啟用/停用 */
export async function toggleMarqueeTextApi(
  id: number | string,
  status: number | string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/marquee/text/toggleStatus',
    { id, status },
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 最新消息 platform_announcement（列表 + 切換 + 新增/編輯 2 步精靈含多語圖片）
// ---------------------------------------------------------------------------
export interface PlatformAnnouncementRow {
  end_time: string;
  id: number | string;
  image_url: string;
  receiver_type: number | string;
  sort: number | string;
  start_time: string;
  status: number | string;
  tag: number | string;
  title: string;
  username: string;
}

/** 最新消息列表 */
export async function getPlatformAnnouncementListApi(params: {
  receiver_type?: number | string;
  status?: number | string;
  username?: string;
}) {
  return requestClient.post<PlatformAnnouncementRow[]>(
    '/api/platform_announcement/list',
    { page: 1, per_page: 1000, ...params },
  );
}

/** 最新消息啟用/停用 */
export async function togglePlatformAnnouncementApi(
  id: number | string,
  status: number | string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/platform_announcement/toggle',
    { id, status },
    { responseReturn: 'body' },
  );
}

/** 最新消息單筆明細 */
export interface PlatformAnnouncementDetailLang {
  content?: string;
  image_id?: number | string;
  image_url?: string;
  title?: string;
}
export interface PlatformAnnouncementDetail {
  agent_id?: number | string;
  agent_name?: string;
  content?: string;
  detail?: Record<string, PlatformAnnouncementDetailLang>;
  end_time?: string;
  exclude_agent?: Array<{ agent_username: string; id: number | string }>;
  include_agent?: Array<{ agent_username: string; id: number | string }>;
  open_window?: number | string;
  receiver_type?: number | string;
  sort?: number | string;
  start_time?: string;
  status?: number | string;
  tag?: number | string;
  type?: number | string;
}

/** 最新消息單筆 */
export async function getPlatformAnnouncementViewApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: PlatformAnnouncementDetail;
    message: string;
  }>('/api/platform_announcement/view', { id }, { responseReturn: 'body' });
}

/** 儲存最新消息 */
export async function savePlatformAnnouncementApi(data: {
  contents: Record<string, string> | string;
  end_time: string;
  exclude_agent?: Array<number | string>;
  id?: number | string;
  image_id?: Record<string, number | string>;
  include_agent?: Array<number | string>;
  language_id: Array<number | string>;
  open_window: number | string;
  receiver_type: number | string;
  sort: number | string;
  start_time: string;
  status: number | string;
  tag: number | string;
  titles: Record<string, string>;
  type: number | string;
}) {
  const path = data.id
    ? '/api/platform_announcement/edit'
    : '/api/platform_announcement/add';
  return requestClient.post<{ code: number; message: string }>(path, data, {
    responseReturn: 'body',
  });
}

/** 已上傳圖片庫 */
export async function getPlatformAnnouncementImagesApi(params: {
  page: number;
  per_page: number;
}) {
  return requestClient.post<{
    code: number;
    data?: Array<{ id: number | string; image_url: string }>;
    message: string;
    pagination?: Pagination;
  }>('/api/platform_announcement/image', params, { responseReturn: 'body' });
}
