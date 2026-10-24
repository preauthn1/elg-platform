import { requestClient } from '#/api/request';

/** TGBot 寫入頁（brand_edit / telegram_broadcast_edit）API。 */

interface BodyOf<T> {
  code: number | string;
  data?: T;
  message: string;
}

/** 機器人詳細 */
export interface TgbotBrandInfo {
  agent_id?: number | string;
  agent_username?: string;
  bot_id?: string;
  bot_token?: string;
  code?: string;
  config?: string;
  id?: number | string;
  multi_language_content?: string;
  name?: string;
}

export async function getTgbotBrandInfoApi(id: number | string) {
  return requestClient.post<BodyOf<TgbotBrandInfo>>(
    '/api/tgbot/getBrandInfo',
    { id },
    { responseReturn: 'body' },
  );
}

export interface TgbotBrandPayload {
  agent_id: number | string;
  bot_id: string;
  bot_token: [REDACTED];
  code: string;
  config: Record<string, unknown>;
  id?: number | string;
  multi_language_content: Record<string, unknown>;
  name: string;
}

/** 建立機器人。 */
export async function createTgbotBrandApi(payload: TgbotBrandPayload) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/tgbot/createBrand', payload, { responseReturn: 'body' });
}

/** 更新機器人。 */
export async function updateTgbotBrandApi(payload: TgbotBrandPayload) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/tgbot/updateBrand', payload, { responseReturn: 'body' });
}

export interface TgbotBroadcastPayload {
  brand_id: number | string;
  message_type: string;
  messages: Array<{ content?: string; lang: string; media_url?: string }>;
}

/** 建立群發訊息（只存檔，狀態為未發送）。 */
export async function createTgbotBroadcastApi(payload: TgbotBroadcastPayload) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/tgbot/createBroadcast', payload, { responseReturn: 'body' });
}

/** 編輯群發訊息（僅限未發送）。 */
export async function updateTgbotBroadcastApi(
  payload: TgbotBroadcastPayload & { id: number | string },
) {
  return requestClient.post<{
    code: number | string;
    data?: unknown;
    message: string;
  }>('/api/tgbot/updateBroadcast', payload, { responseReturn: 'body' });
}

/** 媒體上傳 */
export async function uploadTgbotMediaApi(base64Media: string) {
  return requestClient.post<BodyOf<{ file_url?: string }>>(
    '/api/image/media/upload',
    { base64Media, directory: 'tgbot/broadcast' },
    { responseReturn: 'body' },
  );
}
