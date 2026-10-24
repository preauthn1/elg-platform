import { requestClient } from '#/api/request';

/** 代理設定值頁（personal_setting / agent_customized_setting）。 */

export interface AgentSettingLanguageValue {
  language_id: number | string;
  language_name?: string;
  language_value?: string;
}
export interface AgentSettingItem {
  data_type?: string;
  default_value?: any;
  language_text_code?: string;
  languageValues?: AgentSettingLanguageValue[];
  setting_id: number | string;
  text?: string;
  value?: any;
}
export interface AgentSettingGroup {
  setting_category_id: number | string;
  setting_category_name?: string;
  settings: AgentSettingItem[];
}
export interface AgentSettingsData {
  agent_id?: number | string;
  agent_name?: string;
  groups: AgentSettingGroup[];
}
export interface AgentSettingsBody {
  code: number;
  data?: AgentSettingsData;
  message: string;
}

/** 個人設定 */
export async function getAgentPersonalSettingsApi(agentId: number | string) {
  return requestClient.post<AgentSettingsBody>(
    '/api/agent/personal-settings',
    { agent_id: agentId },
    { responseReturn: 'body' },
  );
}
export async function updateAgentPersonalSettingsApi(
  data: Record<string, any>,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/agent/personal-settings/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 客製化設定 */
export async function getAgentCustomizedSettingsApi(agentId: number | string) {
  return requestClient.post<AgentSettingsBody>(
    '/api/agent/customized-settings',
    { agent_id: agentId },
    { responseReturn: 'body' },
  );
}
export async function updateAgentCustomizedSettingsApi(
  data: Record<string, any>,
) {
  return requestClient.post<{ code: number; message: string }>(
    '/api/agent/customized-settings/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 代理群組權限（工單4422 起走 apis，成功碼 200） */
export interface AgentGroupPermItem {
  group_id: number | string;
  group_name?: string;
  group_type?: number | string;
}
export interface AgentGroupPermBody {
  code: number;
  data?: {
    agent_id?: number | string;
    agent_name?: string;
    groups: AgentGroupPermItem[];
    has_permissions: Array<number | string>;
  };
  message: string;
}
export async function getAgentGroupPermissionsApi(agentId: number | string) {
  return requestClient.post<AgentGroupPermBody>(
    '/agent/api/agent-permission/group-permissions',
    { agent_id: agentId },
    { responseReturn: 'body' },
  );
}
export async function updateAgentGroupPermissionsApi(data: {
  agent_id: number | string;
  group_permission: Array<number | string>;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/agent/api/agent-permission/group-permissions/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 代理簡訊群組設定 */
export interface AgentSmsTextItem {
  country_id: number | string;
  country_name?: string;
  parameter?: string;
  remark?: string;
  text?: string;
  type_id: number | string;
}
export interface AgentSmsSettingBody {
  code: number;
  data?: {
    group_detail?: { id?: number | string; setting?: string };
    group_id?: number | string;
    group_name?: string;
    sms_text?: AgentSmsTextItem[];
  };
  message: string;
}
export async function getAgentSmsGroupSettingApi(groupId: number | string) {
  return requestClient.post<AgentSmsSettingBody>(
    '/api/agent/sms/agent_group_setting',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}
/** 儲存簡訊設定 */
export async function updateAgentSmsSettingApi(data: {
  group_id: number | string;
  setting?: string;
  sms_text?: Record<string, Record<string, string>>;
  type: string;
}) {
  const path =
    data.type === 'detail'
      ? '/api/sms/group_detail/save'
      : '/api/sms/text/save';
  return requestClient.post<{ code: number; message: string }>(path, data, {
    responseReturn: 'body',
  });
}
