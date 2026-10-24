import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 電子郵件提供商 API（成功碼 200，分頁在 data.items / data.pagination） */

export interface EmailGatewayRow {
  agent_group_id: number;
  agent_group_name?: string;
  email: string;
  from_name?: string;
  host: string;
  id: number;
  port: number;
  scheme: string;
  sort: number;
  status: number;
  updated_at?: string;
  updated_by?: string;
}

export interface EmailGatewayListBody {
  code: number | string;
  data?: { items?: EmailGatewayRow[]; pagination?: Pagination };
  message: string;
}

/** 提供商列表 */
export async function getEmailGatewayListApi(params?: {
  agent_group_id?: number | string;
  email?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<EmailGatewayListBody>(
    '/agent/api/email-gateway/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

export interface EmailGatewayRecordRow {
  agent_group_name?: string;
  agent_username?: string;
  created_at?: string;
  id: number;
  member_username?: string;
  message_content?: string;
  receiver: string;
  response_message?: string;
  sender?: string;
  status: number;
  subject?: string;
}

/** 發送紀錄 */
export async function getEmailGatewayRecordApi(params?: {
  agent_group_id?: number | string;
  agent_username?: string;
  end_time?: string;
  member_username?: string;
  page?: number;
  per_page?: number;
  receiver?: string;
  send_id?: number | string;
  start_time?: string;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: EmailGatewayRecordRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/email-gateway/record',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 查看發送紀錄內容（後端會寫入代理操作紀錄） */
export async function getEmailGatewayRecordContentApi(sendId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: { message_content?: string };
    message: string;
  }>(
    '/agent/api/email-gateway/record/content',
    { send_id: sendId },
    { responseReturn: 'body' },
  );
}

export interface EmailGatewayGroupOption {
  id: number;
  name: string;
}

/** 電子郵件設置組別下拉 */
export async function getEmailGatewayGroupDropdownApi() {
  return requestClient.post<{
    code: number | string;
    data?: EmailGatewayGroupOption[];
    message: string;
  }>('/agent/api/email-gateway/group/dropdown', {}, { responseReturn: 'body' });
}

/** 提供商詳細（不含密碼） */
export interface EmailGatewayDetail {
  agent_group_id: number;
  email: string;
  from_name?: string;
  host: string;
  id: number;
  port: number;
  scheme: string;
  sort: number;
  status: number;
}

export async function getEmailGatewayDetailApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: EmailGatewayDetail;
    message: string;
  }>('/agent/api/email-gateway/detail', { id }, { responseReturn: 'body' });
}

export interface EmailGatewayActionResult {
  code: number | string;
  data?: unknown;
  message: string;
}

/** 新增/編輯提供商；有 id 為編輯 */
export async function saveEmailGatewayApi(
  data: Record<string, unknown>,
  isEdit: boolean,
) {
  return requestClient.post<EmailGatewayActionResult>(
    isEdit
      ? '/agent/api/email-gateway/update'
      : '/agent/api/email-gateway/create',
    data,
    { responseReturn: 'body' },
  );
}

/** 啟用/停用切換 */
export async function toggleEmailGatewayStatusApi(
  id: number | string,
  status: number,
) {
  return requestClient.post<EmailGatewayActionResult>(
    '/agent/api/email-gateway/status',
    { id, status },
    { responseReturn: 'body' },
  );
}

/** 刪除提供商 */
export async function deleteEmailGatewayApi(id: number | string) {
  return requestClient.post<EmailGatewayActionResult>(
    '/agent/api/email-gateway/delete',
    { id },
    { responseReturn: 'body' },
  );
}

/** 電子郵件設置組別 › 高級設置（工單4286，apis 成功碼 200） */
export interface EmailGroupSettingLanguage {
  code: string;
  id: number;
  name: string;
}

export interface EmailGroupSettingText {
  content: string;
  id?: number;
  /** 0＝全語系（預設列，不可移除） */
  language_id: number;
  language_name?: null | string;
  /** 對應語系鍵 sms_text_type.{parameter} */
  parameter?: null | string;
  subject: string;
  type_id: number;
}

export interface EmailGroupSettingBody {
  code: number | string;
  data?: {
    email_text?: EmailGroupSettingText[];
    group_detail?: null | {
      id: number;
      setting: { max_send?: null | string; token_expired?: null | string };
    };
    group_id?: number;
    group_name?: string;
    languages?: EmailGroupSettingLanguage[];
    placeholders?: string[];
  };
  message: string;
}

/** 高級設置一次載入（群組設定、全部樣板、可選語系） */
export async function getEmailGroupSettingApi(groupId: number | string) {
  return requestClient.post<EmailGroupSettingBody>(
    '/agent/api/email-gateway/group-setting/view',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 儲存群組設定（驗證碼有效時間、有效時間內最大發送次數，皆為正整數） */
export async function saveEmailGroupDetailApi(data: {
  group_id: number | string;
  max_send: number;
  token_expired: number;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/email-gateway/group-setting/detail/save',
    data,
    { responseReturn: 'body' },
  );
}

/** 儲存全部樣板（整組覆寫；8 個類型每個都必須含 language_id=0 的全語系列） */
export async function saveEmailTextApi(data: {
  email_text: Array<
    Pick<
      EmailGroupSettingText,
      'content' | 'language_id' | 'subject' | 'type_id'
    >
  >;
  group_id: number | string;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/email-gateway/group-setting/text/save',
    data,
    { responseReturn: 'body' },
  );
}
