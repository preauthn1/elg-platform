import { requestClient } from '#/api/request';

/**
 * 任務系統 API */

export interface MissionAgent {
  id: number | string;
  username: string;
}

/** 任務列表列 */
export interface MissionRow {
  action_type?: string;
  available_agents?: MissionAgent[];
  end_time?: string;
  id: number | string;
  name?: string;
  reset_type?: string;
  start_time?: string;
  status?: number | string;
}

/** 任務列表（action_type/status：-1 為全部） */
export async function getMissionsListApi(params: {
  action_type?: number | string;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: MissionRow[];
    message?: string;
  }>('/api/missions/list', params, { responseReturn: 'body' });
}

/** 可用類型字典（key → 顯示名稱） */
export interface MissionAvailableTypes {
  action_types?: Record<string, string>;
  condition_types?: Record<string, string>;
  reset_types?: Record<string, string>;
  reward_types?: Record<string, string>;
}

export async function getMissionAvailableTypesApi() {
  return requestClient.post<{
    code: number | string;
    data?: MissionAvailableTypes;
    message?: string;
  }>('/api/missions/get_available_types', {}, { responseReturn: 'body' });
}

/** 任務明細 */
export interface MissionDetail {
  action_type?: string;
  available_agents?: MissionAgent[];
  condition_type?: string;
  condition_value?: number | string;
  description?: string;
  end_time?: string;
  id?: number | string;
  info?: {
    action_config?: any;
    condition_config?: any;
    reward_config?: any;
  };
  mission_names?: Record<string, { description?: string; name?: string }>;
  name?: string;
  reset_type?: string;
  start_time?: string;
  status?: number | string;
}

export async function getMissionViewApi(missionId: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: MissionDetail;
    message?: string;
  }>(
    '/api/missions/view',
    { mission_id: missionId },
    { responseReturn: 'body' },
  );
}

/** 儲存任務（含 mission_id 走 update，否則 create） */
export interface MissionSavePayload {
  action: {
    game?: {
      game_type: Array<number | string>;
      vendor_id: Array<number | string>;
    };
    type: string;
  };
  agent_ids: Array<number | string>;
  condition: {
    fix_number?: number | string;
    type: string;
    value: number | string;
  };
  description?: string;
  end_time: string;
  lang_datas: Record<string, { description?: string; name?: string }>;
  mission_id?: number | string;
  name: string;
  reset_type: string;
  reward: {
    flow_limit_multiple?: number | string;
    type: string;
    value: number | string;
  };
  start_time: string;
}

export async function saveMissionApi(payload: MissionSavePayload) {
  const endpoint = payload.mission_id
    ? '/api/missions/update'
    : '/api/missions/create';
  return requestClient.post<{ code: number | string; message?: string }>(
    endpoint,
    payload,
    { responseReturn: 'body' },
  );
}

export async function updateMissionStatusApi(params: {
  mission_id: number | string;
  status: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: string;
    message?: string;
  }>('/api/missions/update_status', params, { responseReturn: 'body' });
}

/** 任務領取記錄列 */
export interface MissionClaimRecordRow {
  agent_username?: string;
  claim_time?: string;
  finish_time?: string;
  is_claimed?: number | string;
  member_username?: string;
  mission_name?: string;
  reward_type?: string;
  reward_value?: number | string;
}

/** 領取記錄（成功 code 為 200） */
export async function getMissionClaimRecordApi(params: {
  end_time: string;
  is_claimed?: number | string;
  member_username?: string;
  page: number;
  per_page: number;
  reward_type?: string;
  start_time: string;
  time_column?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: {
      items?: MissionClaimRecordRow[];
      pagination?: { total?: number };
    };
    message?: string;
  }>('/agent/api/mission/claim-record', params, { responseReturn: 'body' });
}
