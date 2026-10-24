import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 簡訊管理 API */

export interface SmsDropdownRow {
  id: number | string;
  name: string;
}

/** 簡訊商下拉 */
export async function getSmsDropdownApi() {
  return requestClient.post<SmsDropdownRow[]>('/api/sms/dropdown', {});
}

/** 簡訊群組下拉 */
export async function getSmsGroupDropdownApi() {
  return requestClient.post<SmsDropdownRow[]>('/api/sms/group/dropdown', {});
}

export interface SmsGatewayRow {
  code: string;
  country_code: string;
  country_name: string;
  id: number | string;
  memo: string;
  name: string;
  status: number | string;
  url: string;
}

export interface SmsGatewayListBody {
  code: number;
  data: SmsGatewayRow[];
  message: string;
  pagination?: Pagination;
}

/** 簡訊商列表 */
export async function getSmsGatewayListApi(params: {
  page: number;
  per_page: number;
  status?: number | string;
}) {
  return requestClient.post<SmsGatewayListBody>(
    '/api/sms/gateway/list',
    params,
    { responseReturn: 'body' },
  );
}

export interface SmsInfoRow {
  agent_group_name: string;
  cost: number | string;
  countries: string;
  id: number | string;
  info: string;
  sms_gateway_info: string;
  sms_gateway_name: string;
  sort: number | string;
  status: number | string;
}

export interface SmsInfoListBody {
  code: number;
  data: SmsInfoRow[];
  message: string;
  pagination?: Pagination;
}

/** 簡訊商戶資訊列表 */
export async function getSmsInfoListApi(params: {
  agent_group_id?: number | string;
  agent_username?: string;
  page: number;
  per_page: number;
  sms_gateway_id?: number | string;
  status?: number | string;
}) {
  return requestClient.post<SmsInfoListBody>('/api/sms/info/list', params, {
    responseReturn: 'body',
  });
}

export interface SmsRecordRow {
  agent_username: string;
  created_at: string;
  id: number | string;
  message_content: string;
  mobile: string;
  sms_gateway_name: string;
  status: number | string;
  transaction_id: string;
  username: string;
}

export interface SmsRecordListBody {
  code: number;
  data: SmsRecordRow[];
  message: string;
  pagination?: Pagination;
}

/** 簡訊發送紀錄 */
export async function getSmsRecordApi(params: {
  agent_username?: string;
  end_time: string;
  member_username?: string;
  mobile?: string;
  page: number;
  per_page: number;
  send_id?: number | string;
  sms_gateway_id?: number | string;
  start_time: string;
  status?: number | string;
}) {
  return requestClient.post<SmsRecordListBody>('/api/sms/record', params, {
    responseReturn: 'body',
  });
}

export interface SmsGatewayDetail {
  code: string;
  country_id: string;
  id: number | string;
  info: string;
  memo: string;
  name: string;
  status: number | string;
  url: string;
}

/** 簡訊商詳細 */
export async function getSmsGatewayShowApi(id: number | string) {
  return requestClient.post<SmsGatewayDetail>('/api/sms/gateway/show', { id });
}

export interface SmsInfoDetail {
  cost: number | string;
  country_id: string;
  gateway_id: number | string;
  gateway_info: string;
  gateway_name: string;
  id: number | string;
  info: string;
  sort: number | string;
  status: number | string;
}

/** 簡訊商戶資訊詳細 */
export async function getSmsInfoShowApi(id: number | string) {
  return requestClient.post<SmsInfoDetail>('/api/sms/info/show', { id });
}

/** 簡訊商支援國家手機清單 */
export async function getCountryMobileListApi(gatewayId: number | string) {
  return requestClient.post<Array<{ id: number | string; name: string }>>(
    '/api/country/mobile_list',
    { id: gatewayId },
  );
}

/** 查看簡訊內容 */
export async function getSmsContentApi(sendId: number | string) {
  return requestClient.post<{ message_content: string }>(
    '/api/sms/record/view-content',
    { send_id: sendId },
  );
}

export interface SmsActionResult {
  code: number;
  data?: unknown;
  message: string;
}

/** 儲存簡訊商 */
export async function saveSmsGatewayApi(payload: Record<string, unknown>) {
  return requestClient.post<SmsActionResult>('/api/sms/gateway/save', payload, {
    responseReturn: 'body',
  });
}

/** 儲存簡訊商戶資訊 */
export async function saveSmsInfoApi(payload: Record<string, unknown>) {
  return requestClient.post<SmsActionResult>('/api/sms/info/save', payload, {
    responseReturn: 'body',
  });
}

/** 簡訊商啟用/停用切換 */
export async function toggleSmsGatewayStatusApi(
  id: number | string,
  status: boolean,
) {
  return requestClient.post<SmsActionResult>(
    '/api/sms/gateway/status',
    { id, status },
    { responseReturn: 'body' },
  );
}

/** 簡訊商戶資訊啟用/停用切換 */
export async function toggleSmsInfoStatusApi(
  id: number | string,
  status: boolean,
) {
  return requestClient.post<SmsActionResult>(
    '/api/sms/info/status',
    { id, status },
    { responseReturn: 'body' },
  );
}
