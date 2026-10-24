import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 綁定獎勵 bind_reward API（名單管理 + 名單詳情） */

export interface BindRewardRow {
  agent_name: string;
  end_time: string;
  /** JSON 字串：{ flow_limit_multiple, vendor_id_restriction[], game_type_id_restriction[] } */
  flow_limits: string;
  id: number | string;
  point: number | string;
  reward_name: string;
  start_time: string;
  status: number | string;
  /** 1=平臺點數 2=流水 */
  type: number | string;
}

/** 名單管理列表（status 2=全部 1=啟用 0=停用 -1=過期） */
export async function getBindRewardListApi(params: {
  agent_name: string;
  page: number;
  per_page: number;
  status: number | string;
}) {
  return requestClient.post<{
    code: number;
    data: BindRewardRow[];
    message: string;
    pagination?: Pagination;
  }>('/api/bind_reward/list_management/list', params, {
    responseReturn: 'body',
  });
}

/** 名單管理單筆 */
export async function getBindRewardViewApi(id: number | string) {
  return requestClient.post<{
    agent_id: number | string;
    agent_name: string;
    end_time: string;
    flow_limits: string;
    point: number | string;
    reward_name: string;
    start_time: string;
    type: number | string;
  }>('/api/bind_reward/list_management/view', { id });
}

/** 名單管理新增/編輯 */
export async function saveBindRewardApi(payload: {
  agent_id: number | string;
  end_time: string;
  flow_limit_multiple: number | string;
  game_type_id_restriction: Array<number | string>;
  id?: number | string;
  reward_name: string;
  reward_point: number | string;
  reward_type: number | string;
  start_time: string;
  vendor_id_restriction: Array<number | string>;
}) {
  const url = payload.id
    ? '/api/bind_reward/list_management/edit'
    : '/api/bind_reward/list_management/create';
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    url,
    payload,
    { responseReturn: 'body' },
  );
}

/** 名單管理啟用/停用 */
export async function switchBindRewardStatusApi(
  id: number | string,
  status: number | string,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/bind_reward/list_management/switch_status',
    { id, status },
    { responseReturn: 'body' },
  );
}

/** 名單簡表（詳情頁下拉） */
export async function getBindRewardSimpleListApi() {
  return requestClient.post<
    Array<{
      agent_name: string;
      id: number | string;
      reward_name: string;
      status: number | string;
    }>
  >('/api/bind_reward/list_management/list_simple', {});
}

export interface BindRewardDetailRow {
  agent_name: string;
  bind_time: string;
  id: number | string;
  ip: string;
  member_name: string;
  member_real_name: string;
  phone: string;
  /** 1=已領取 0=未領取 */
  status: number | string;
}

/** 名單詳情列表（bind_status 2=全部 1=已領取 0=未領取） */
export async function getBindRewardDetailListApi(params: {
  bind_reward_id: number | string;
  bind_status: number | string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<{
    code: number;
    data: BindRewardDetailRow[];
    message: string;
    pagination?: Pagination;
  }>('/api/bind_reward/list_detail/list', params, { responseReturn: 'body' });
}

/** 名單詳情新增號碼（phone_numbers 為逗號分隔字串） */
export async function createBindRewardDetailApi(payload: {
  bind_reward_id: number | string;
  phone_numbers: string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/bind_reward/list_detail/create',
    payload,
    { responseReturn: 'body' },
  );
}

/** 名單詳情刪除（僅未領取可刪） */
export async function deleteBindRewardDetailApi(id: number | string) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/bind_reward/list_detail/delete',
    { id },
    { responseReturn: 'body' },
  );
}

/** 流水限制用遊戲類型下拉（不帶 status，對齊舊版） */
export async function getBindRewardGameTypesApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/gameType/list',
    {},
  );
}
