import { requestClient } from '#/api/request';

/** TGBot 管理模組 API。 */

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/* ───────────────────────── 機器人列表（brand_list） ───────────────────────── */

export interface TgbotBrandRow {
  agent_id?: number | string;
  agent_username?: string;
  bot_id?: string;
  code?: string;
  count?: number | string;
  id: number | string;
  name?: string;
  status?: number | string;
  updated_at?: string;
}

/** 機器人列表。 */
export async function getTgbotBrandListApi(params?: {
  agent_username?: string;
  bot_id?: string;
  code?: string;
}) {
  return requestClient.post<BodyOf<TgbotBrandRow[]>>(
    '/api/tgbot/brandList',
    { agent_username: '', bot_id: '', code: '', ...params },
    { responseReturn: 'body' },
  );
}

/** 切換機器人啟用狀態。 */
export async function updateTgbotBrandStatusApi(payload: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/tgbot/updateBrandStatus',
    payload,
    { responseReturn: 'body' },
  );
}

/** 刪除機器人。 */
export async function deleteTgbotBrandApi(id: number | string) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/tgbot/deleteBrand',
    { id },
    { responseReturn: 'body' },
  );
}

/* ───────────────────────── 群發消息（telegram_broadcast_list） ───────────────────────── */

export interface TgbotBroadcastRow {
  brand_id?: number | string;
  created_at?: string;
  fail_count?: number | string;
  id: number | string;
  /** JSON 字串：[{lang, content, media_url?}] */
  message?: string;
  operator?: string;
  /** 0 發送中、1 暫停、2 發送完畢、3 未發送 */
  status?: number | string;
  success_count?: number | string;
  total_count?: number | string;
  type?: string;
  waiting_count?: number | string;
}

export interface TgbotBroadcastListData {
  current_page?: number;
  data?: TgbotBroadcastRow[];
  last_page?: number;
  per_page?: number;
  total?: number;
}

/** 群發消息列表 */
export async function getTgbotBroadcastListApi(params: {
  brand_id?: number | string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<BodyOf<TgbotBroadcastListData>>(
    '/api/tgbot/broadcastList',
    { brand_id: '', page: 1, per_page: 10, status: '', ...params },
    { responseReturn: 'body' },
  );
}

/** 暫停群發。 */
export async function pauseTgbotBroadcastApi(id: number | string) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/tgbot/pauseBroadcast',
    { id },
    { responseReturn: 'body' },
  );
}

/** 發送/繼續群發（未發送、暫停都走這支）。 */
export async function resumeTgbotBroadcastApi(id: number | string) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/tgbot/resumeBroadcast',
    { id },
    { responseReturn: 'body' },
  );
}

/** 刪除群發（僅限未發送）。 */
export async function deleteTgbotBroadcastApi(id: number | string) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/api/tgbot/deleteBroadcast',
    { id },
    { responseReturn: 'body' },
  );
}
