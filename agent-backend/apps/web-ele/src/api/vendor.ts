import { requestClient } from '#/api/request';

/** 遊戲商群 API。 */

export interface VendorApiBody<T = any> {
  code: number;
  data?: T;
  message: string;
  pagination?: { current: number; pages: number; total: number };
}

/** 代理已設定的遊戲商 */
export interface AgentVendorRow {
  backend_show?: number;
  id: number;
  image?: string;
  name?: string;
  self_status?: number;
  status?: number;
  vendor_id?: number;
  vendor_info_id?: number;
  vendor_status?: number;
  [key: string]: any;
}

/** 廠商管理列表列 */
export interface VendorManagementRow {
  app_icon?: string;
  id: number;
  image?: string;
  name?: string;
  percentage?: number | string;
  rate?: number | string;
  remark?: string;
  sort?: number;
  status?: number;
  valid_bet_rate?: number | string;
  [key: string]: any;
}

/** 商戶資料列表列 */
export interface VendorInfoRow {
  account_prefix?: string;
  agent_accounts?: string[];
  cost?: number | string;
  created_at?: string;
  currency?: string;
  id: number;
  info?: string;
  is_single_wallet?: number | string;
  name?: string;
  prepared_account_number?: number | string;
  rolling_chip_status?: number | string;
  status?: number;
  type?: number;
  vendor_id?: number;
  vendor_name?: string;
  [key: string]: any;
}

/** 遊戲商維護列表列 */
export interface VendorMaintenanceRow {
  agent_id?: number;
  agent_username?: string;
  created_at?: string;
  end_time?: string;
  exclude_agent_id?: number;
  exclude_agent_username?: null | string;
  id: number;
  memo?: string;
  start_time?: string;
  status?: number;
  type?: number;
  username?: string;
  vendor_id?: number;
  vendor_name?: string;
  [key: string]: any;
}

/** 營運商遊戲管理列表列 */
export interface AgentGameListRow {
  agent_id?: number;
  agent_username?: string;
  id: number;
  status?: number;
  total_count?: number;
  vendor_id?: number;
  vendor_info_name?: string;
  vendor_name?: string;
  [key: string]: any;
}

/** 遊戲清單列 */
export interface VendorGameRow {
  game_type_name?: string;
  id: number;
  name?: string;
  status?: null | number;
  type_id?: number;
}

/** 廠商可顯示代理列 */
export interface HasAgentVendorRow {
  agent_id: number;
  username?: string;
  vendor_status?: null | number;
}

/** 遊戲商名稱含補零 id：(0013) eBET */
export function vendorNameWithId(vendor: {
  id?: number | string;
  name?: string;
}): string {
  // 來源（如 api/vendor/dropdownList）可能已在 name 前帶 "(id) "，先去除避免 id 重複顯示
  const name = (vendor.name ?? '').replace(/^\s*\(\d+\)\s*/, '');
  return vendor.id ? `(${String(vendor.id).padStart(4, '0')}) ${name}` : name;
}

// ---------------------------------------------------------------------------
// 遊戲商管理 vendor/index
// ---------------------------------------------------------------------------

/** 代理已設定遊戲商列表 */
export async function getAgentVendorListApi(agentId: number) {
  return requestClient.post<AgentVendorRow[]>('/api/agentVendor/list', {
    agent_id: agentId,
  });
}

/** 切換遊戲商授權 */
export async function agentVendorSettingApi(data: {
  agent_id: number | string;
  backend_show?: number | string;
  data_switch?: number | string;
  id: number | string;
  self_status?: number | string;
  status?: number | string;
}) {
  const body: Record<string, any> = {
    agent_id: data.agent_id,
    vendor_id: data.id,
  };
  if (data.status !== undefined) body.status = data.status;
  if (data.self_status !== undefined) body.self_status = data.self_status;
  if (data.backend_show !== undefined) body.backend_show = data.backend_show;
  if (data.data_switch !== undefined) body.data_switch = data.data_switch;
  return requestClient.post<VendorApiBody>('/api/agentVendor/setting', body, {
    responseReturn: 'body',
  });
}

/** 強制設定可用的遊戲商樣板列表 */
export async function getAgentVendorTemplateApi(agentId: number) {
  return requestClient.post<VendorApiBody<Array<Record<string, any>>>>(
    '/api/agentVendor/template',
    { agent_id: agentId },
    { responseReturn: 'body' },
  );
}

/** 強制設定遊戲商 */
export async function agentVendorForceCreateApi(data: {
  agent_id: number | string;
  backend_show?: number | string;
  id: number | string;
  status?: number | string;
}) {
  const body: Record<string, any> = {
    agent_id: data.agent_id,
    vendor_id: data.id,
  };
  if (data.status !== undefined) body.status = data.status;
  if (data.backend_show !== undefined) body.backend_show = data.backend_show;
  return requestClient.post<VendorApiBody>(
    '/api/agentVendor/force_create',
    body,
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 遊戲商類別分類 vendor/vendor_category
// ---------------------------------------------------------------------------

/** 取得分類設定 */
export async function getVendorCategoryApi(groupId: number) {
  return requestClient.post<VendorApiBody<Record<string, number[][]>>>(
    '/api/vendor_category/getVendorCategory',
    { group_id: groupId },
    { responseReturn: 'body' },
  );
}

/** 更新分類設定 */
export async function updateVendorCategoryApi(data: {
  game_types: Array<number | string>;
  ids: Array<number | string>;
  type_id: number;
  vendors: Array<number | string>;
}) {
  return requestClient.post<VendorApiBody>(
    '/api/vendor_category/update',
    data,
    {
      responseReturn: 'body',
    },
  );
}

// ---------------------------------------------------------------------------
// 遊戲商維護 vendor_maintenances/index
// ---------------------------------------------------------------------------

/** 遊戲商下拉 */
export async function getVendorDropdownByTypeApi(type: number = 1) {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/vendor/dropdownList',
    { type },
  );
}

/** 遊戲商維護列表 */
export async function getVendorMaintenancesListApi(data: {
  page: number;
  per_page: number;
  status: number | string;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorApiBody<VendorMaintenanceRow[]>>(
    '/api/vendor_maintenances/list',
    data,
    { responseReturn: 'body' },
  );
}

/** 遊戲商維護單筆 */
export async function getVendorMaintenancesViewApi(id: number | string) {
  return requestClient.post<Record<string, any>>(
    '/api/vendor_maintenances/view',
    { id },
  );
}

/** 儲存遊戲商維護 */
export async function saveVendorMaintenancesApi(data: {
  agent_id: number | string;
  end_time: string;
  exclude_agent_id: number | string;
  memo: string;
  start_time: string;
  status: number | string;
  type: number | string;
  update_id: number | string;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorApiBody>(
    '/api/vendor_maintenances/update',
    data,
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 營運商遊戲管理 agent_game/index
// ---------------------------------------------------------------------------

/** 各代理對應遊戲商商戶列表 */
export async function getAgentVendorAgentListApi(data: {
  agent_username: string;
  page: number;
  per_page: number;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorApiBody<AgentGameListRow[]>>(
    '/api/agentVendor/agent_list',
    data,
    { responseReturn: 'body' },
  );
}

/** 代理對某商戶可玩遊戲 */
export async function getAgentGameApi(data: {
  agent_id: number | string;
  id: number | string;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorGameRow[]>(
    '/api/agentVendor/getAgentGame',
    data,
  );
}

/** 儲存代理可玩遊戲 */
export async function saveAgentGameApi(data: {
  agent_id: number | string;
  games: Array<number | string>;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorApiBody>(
    '/api/agentVendor/editAgentGame',
    data,
    { responseReturn: 'body' },
  );
}

/** 依代理帳號查詢對應商戶資料 */
export async function findVendorInfoByAgentApi(username: string) {
  return requestClient.post<VendorApiBody<Record<string, any>>>(
    '/api/vendor_info/find-by-agent',
    { username },
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 廠商管理 vendor_management/index
// ---------------------------------------------------------------------------

/** 廠商管理列表 */
export async function getVendorManagementListApi() {
  return requestClient.post<VendorManagementRow[]>('/api/vendor/getVendorList');
}

/** 切換廠商狀態 */
export async function vendorManagementToggleStatusApi(data: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<VendorApiBody>('/api/vendor/edit', data, {
    responseReturn: 'body',
  });
}

/** 廠商單筆 */
export async function getVendorViewApi(id: number | string) {
  return requestClient.post<Record<string, any>>('/api/vendor/view', { id });
}

/** 廠商可顯示之第一層代理 */
export async function getHasAgentVendorListApi(vendorId: number | string) {
  return requestClient.post<HasAgentVendorRow[]>(
    '/api/agentVendor/hasAgentVendorList',
    { vendor_id: vendorId },
  );
}

/** 儲存廠商 */
export async function saveVendorManagementApi(data: Record<string, any>) {
  return requestClient.post<VendorApiBody>('/api/vendor/edit', data, {
    responseReturn: 'body',
  });
}

/** 設定第一層代理是否顯示遊戲商 */
export async function agentVendorSettingBackendApi(data: {
  agent_id: number | string;
  status: number | string;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorApiBody>(
    '/api/agentVendor/settingBackend',
    data,
    { responseReturn: 'body' },
  );
}

// ---------------------------------------------------------------------------
// 商戶資料管理 vendor_info/index
// ---------------------------------------------------------------------------

/** 商戶資料列表 */
export async function getVendorInfoListApi(data: {
  agent_username?: string;
  is_single_wallet?: number | string;
  page: number;
  per_page: number;
  rolling_chip_status?: number | string;
  status: number | string;
  vendor_id: number | string;
  vendor_info_id: number | string;
}) {
  return requestClient.post<VendorApiBody<VendorInfoRow[]>>(
    '/api/vendor_info/list',
    data,
    { responseReturn: 'body' },
  );
}

/** 切換商戶狀態 */
export async function vendorInfoToggleStatusApi(data: {
  id: number | string;
  status: number | string;
}) {
  return requestClient.post<VendorApiBody>('/api/vendor_info/edit', data, {
    responseReturn: 'body',
  });
}

/** 商戶單筆 */
export async function getVendorInfoViewApi(id: number | string) {
  return requestClient.post<Record<string, any>>('/api/vendor_info/view', {
    id,
  });
}

/** 商戶可設定遊戲 */
export async function getVendorInfoGameApi(data: {
  id: number | string;
  vendor_id: number | string;
}) {
  return requestClient.post<VendorGameRow[]>(
    '/api/vendor_info/getVendorGame',
    data,
  );
}

/** 儲存商戶 */
export async function saveVendorInfoApi(data: Record<string, any>) {
  const path = data.id ? '/api/vendor_info/edit' : '/api/vendor_info/add';
  return requestClient.post<VendorApiBody>(path, data, {
    responseReturn: 'body',
  });
}
