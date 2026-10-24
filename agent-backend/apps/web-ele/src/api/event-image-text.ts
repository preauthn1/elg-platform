import { requestClient } from '#/api/request';

/** 活動圖文 event_image_text API。 */

export interface EventApiBody<T = any> {
  code: number;
  data?: T;
  message: string;
  pagination?: { current: number; pages: number; total: number };
}

/** 合併後的活動列表列 */
export interface EventImageTextRow {
  agent_username?: string;
  created_at?: string;
  end_time?: string;
  id: number | string;
  sort?: number;
  source: 'v1' | 'v2';
  start_time?: string;
  status?: number;
  title?: string;
  type_id?: number;
  [key: string]: any;
}

/** V1 活動列表 */
export async function getEventListApi(data: {
  page: number;
  per_page: number;
  status?: number | string;
  title: string;
}) {
  return requestClient.post<EventApiBody<any[]>>('/api/event/list', data, {
    responseReturn: 'body',
  });
}

/** V2 活動列表 */
export async function getEventV2ListApi(data: {
  name: string;
  page: number;
  per_page: number;
  status?: number | string;
}) {
  return requestClient.post<EventApiBody<{ items: any[] }>>(
    '/agent/api/event-v2/list',
    data,
    { responseReturn: 'body' },
  );
}

/** 切換 V1 活動狀態（status 需送字串，後端用 `!= ''` 判空，數字 0 會被當成空值忽略） */
export async function eventToggleStatusApi(data: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<EventApiBody>(
    '/api/event/status',
    { id: data.id, status: String(data.status) },
    { responseReturn: 'body' },
  );
}

/** V2 更新資訊／切換狀態 */
export async function updateEventV2InfoApi(data: {
  detail_lang?: Array<{
    content: string;
    image?: string;
    language_id: number | string;
    name: string;
  }>;
  event_detail_id: number | string;
  sort?: number | string;
  status?: number | string;
}) {
  return requestClient.post<EventApiBody>(
    '/agent/api/event-v2/update-info',
    data,
    { responseReturn: 'body' },
  );
}

/** V1 活動詳細 */
export async function getEventViewApi(id: number | string) {
  return requestClient.post<EventApiBody<Record<string, any>>>(
    '/api/event/view',
    { id },
    { responseReturn: 'body' },
  );
}

/** V2 活動詳細 */
export async function getEventV2DetailApi(eventDetailId: number | string) {
  return requestClient.post<EventApiBody<Record<string, any>>>(
    '/agent/api/event-v2/detail',
    { event_detail_id: eventDetailId },
    { responseReturn: 'body' },
  );
}

/** 儲存 V1 活動圖文 */
export async function saveEventTitleApi(data: Record<string, any>) {
  return requestClient.post<EventApiBody>('/api/event/edit_title', data, {
    responseReturn: 'body',
  });
}

/** 上傳 base64 圖片到圖庫 */
export async function uploadAdvertiseImageApi(data: {
  agent_id: number | string;
  base64Image: string;
  type?: number | string;
}) {
  return requestClient.post<
    EventApiBody<{ advertise_image_id: number; path?: string }>
  >(
    '/api/image/base64/upload',
    { type: 1, ...data },
    { responseReturn: 'body' },
  );
}
