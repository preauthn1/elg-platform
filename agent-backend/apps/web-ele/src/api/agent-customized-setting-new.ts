import type { AgentSettingsBody } from './agent-settings';

import { requestClient } from '#/api/request';

/**
 * 代理群組客製化設定（agent/agent_customized_setting_new，從 group_list 的
 * type1 自訂群組帶 group_id 進入）。與個別代理版 agent_customized_setting
 * 共用同一組 customized-settings 端點，差別在 load/update 帶 group_id 而非 agent_id。 */

/** 群組版回應 data 另含 group_id / group_name */
export interface GroupCustomizedSettingsBody extends AgentSettingsBody {
  data?: AgentSettingsBody['data'] & {
    group_id?: number | string;
    group_name?: string;
  };
}

/** 取群組客製化設定 */
export async function getGroupCustomizedSettingsApi(groupId: number | string) {
  return requestClient.post<GroupCustomizedSettingsBody>(
    '/api/agent/customized-settings',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 儲存群組客製化設定 */
export async function updateGroupCustomizedSettingsApi(
  data: Record<string, any>,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/agent/customized-settings/update',
    data,
    { responseReturn: 'body' },
  );
}
