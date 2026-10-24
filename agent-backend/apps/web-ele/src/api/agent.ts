import type { PagedResult, StatusLogRow } from '#/api';

import { requestClient } from '#/api/request';

/**
 * 代理（營運商）管理 API */

export interface AgentRow {
  balance: number | string;
  bank_account_count?: number;
  code: string;
  created_at: string;
  created_by?: string;
  email?: string;
  first_ip?: number;
  /** 1=已開啟 Google 2FA */
  has_2fa?: number;
  host_id?: number;
  id: number;
  /** 1=套用 admin 角色（不能被關閉或重置 2FA） */
  is_admin_role?: number;
  is_balance_unlimited?: number;
  last_ip?: number;
  last_login?: string;
  mobile?: string;
  nickname?: string;
  operator?: string;
  parent?: number;
  parent_parent?: number;
  parent_username?: string;
  profit_share_balance?: number | string;
  profit_share_unlimited?: number;
  real_name?: string;
  realname_validate?: number;
  role_id?: number;
  status?: number;
  sys_lock?: number;
  username: string;
  [key: string]: any;
}

export interface AgentListParams {
  account_no?: string;
  account_type?: string;
  cash_flow_group_id?: number | string;
  chat_group_id?: number | string;
  code?: string;
  customized_setting_group_id?: number | string;
  email?: string;
  first_login_ip?: string;
  fuzzy_query?: number;
  fuzzy_query_real_name?: number;
  id?: number; // 查看下線
  include_self?: number;
  include_sub_account?: number;
  mobile?: string;
  page?: number;
  per_page?: number;
  real_name?: string;
  status?: number | string;
  sys_lock?: number | string;
  username?: string;
}

/** 代理列表 */
export async function getAgentListApi(params: AgentListParams) {
  const res = await requestClient.post<{
    code: number;
    data: AgentRow[];
    message: string;
    pagination: { current: number; pages: number; total: number };
  }>(
    '/api/agent/list',
    { page: 1, per_page: 50, ...params },
    { responseReturn: 'body' },
  );
  return {
    code: res.code,
    list: res.code === 1000 ? (res.data ?? []) : [],
    message: res.message,
    pagination: res.pagination ?? { current: 1, pages: 0, total: 0 },
  };
}

/** 啟用/停用代理 */
export async function editAgentStatusApi(data: {
  id: number;
  remark: string;
  status: number;
}) {
  return requestClient.post('/api/agent/edit_status', data, {
    responseReturn: 'body',
  });
}

/** 人工限制登入/解除限制（新 API，成功碼 200） */
export async function updateAgentSysLockApi(data: {
  agent_id: number;
  remark: string;
  sys_lock: number;
}) {
  return requestClient.post<{ code: number | string; message: string }>(
    '/agent/api/sys-lock/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 代理操作紀錄列：狀態與限制登入是兩個獨立維度 */
export interface AgentStatusLogRow extends StatusLogRow {
  after_sys_lock?: number;
  before_sys_lock?: number;
}

/** 代理操作紀錄 */
export async function getAgentStatusLogApi(params: {
  agent_id: number;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<AgentStatusLogRow>>(
    '/api/agent/status_log_list',
    { page: 1, per_page: 8, ...params },
    { responseReturn: 'body' },
  );
}

export interface AgentDomainRow {
  domain: string;
  domain_noted?: string;
  id: number;
  status?: number;
}

/** 代理網域列表 */
export async function getAgentDomainListApi(params: {
  agent_id: number;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<PagedResult<AgentDomainRow>>(
    '/api/agentDomain/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 代理分組下拉 */
export async function getAgentGroupDropdownApi(
  organizationId?: number | string,
) {
  return requestClient.post<
    Array<{ agent_group_type: number; id: number; name: string }>
  >(
    '/api/agent/group/dropdownList',
    organizationId === undefined ? {} : { organization_id: organizationId },
  );
}

/**
 * 代理存入/取出
 * type: 1=存入 2=取出 */
export async function agentTransactionApi(data: {
  agent_id: number;
  memo: string;
  point: number;
  type: number;
}) {
  return requestClient.post('/api/agentTransaction', data, {
    responseReturn: 'body',
  });
}

/** 代理本人/指定代理資料 */
export async function getAgentViewByIdApi(id: number) {
  return requestClient.post<AgentRow>('/api/agent/view', { id });
}

/**
 * Google 2FA 五支接口已自 API_Agent 搬到 apis（target/tickets#4518）。
 * apis 成功碼是 200（API_Agent 是 1000）；業務失敗以 HTTP 200 回 API_Agent 相容碼
 * （1098 驗證碼錯誤可重試、1100／1120／1128／1130／1131 見後端 TwoFactorResultCodeEnum），
 * 權限不足／查無帳號則是 HTTP 4xx，由 request 攔截器全域提示。
 */
export function is2faOk(code: unknown) {
  return Number(code) === 200 || Number(code) === 1000;
}

/** 本人的 2FA QRCode 與金鑰；只能查本人，已開啟 2FA 時兩者皆為空字串（target/tickets#4503） */
export async function get2faImageApi() {
  return requestClient.post<{ image: string; secret: [REDACTED] }>(
    '/agent/api/2fa/image',
    {},
  );
}

/** 驗證自己或範疇內代理的 2FA OTP */
export async function check2faSecretApi(data: {
  agent_id: number;
  secret: [REDACTED];
}) {
  return requestClient.post('/agent/api/2fa/auth', data, {
    responseReturn: 'body',
  });
}

/**
 * 關閉 Google 2FA（target/tickets#4503）：不帶 agent_id＝關閉自己；
 * secret 一律是操作者本人當下的驗證碼。錯誤碼 1098＝驗證碼錯誤，可重試。
 * 關閉自己時 data.relogin_required=true，由 request 攔截器統一跳「設定已更新，請重新登入」（#4518）。 */
export async function disable2faApi(data: {
  agent_id?: number;
  secret: [REDACTED];
}) {
  return requestClient.post<any>('/agent/api/2fa/disable', data, {
    responseReturn: 'body',
  });
}

/**
 * 本人開啟 Google 2FA（target/tickets#4521）：secret 為驗證器上的 6 位數驗證碼；1098＝驗證碼錯誤，可重試。
 * 成功後 data.relogin_required=true，由 request 攔截器統一處理重新登入（#4518）。 */
export async function enable2faApi(data: { secret: [REDACTED] }) {
  return requestClient.post<any>('/agent/api/2fa/enable', data, {
    responseReturn: 'body',
  });
}

/** 重置他人的 Google 2FA（關閉並作廢舊金鑰，target/tickets#4503）；secret 是操作者本人的驗證碼 */
export async function resetAgent2faApi(data: {
  agent_id: number;
  secret: [REDACTED];
}) {
  return requestClient.post<any>('/agent/api/2fa/reset-agent', data, {
    responseReturn: 'body',
  });
}

/**
 * 修改代理登入密碼／支付密碼（target/tickets#4506）。
 * 不帶 agent_id＝改自己（右上角個人選單）；改別人要帶 secret＝操作者本人的 Google 2FA 驗證碼，
 * 錯誤碼 1098＝驗證碼錯誤，可重試、不鎖定。成功後對方已登入的地方全部登出。 */
export async function modifyAgentPasswordApi(data: {
  agent_id?: number;
  confirm_password: [REDACTED];
  password: [REDACTED];
  remark: string;
  secret?: string;
  type: 'password' | 'payment_password';
}) {
  return requestClient.post<any>('/api/agent/modifyAgentPassword', data, {
    responseReturn: 'body',
  });
}

/**
 * 編輯代理欄位
 * 用於 frontend_register / has_2fa 等開關切換 */
export async function editAgentApi(data: { [key: string]: any; id: number }) {
  return requestClient.post('/api/agent/edit', data, {
    responseReturn: 'body',
  });
}

/** 設為客服 */
export async function setAgentRoleToCsApi(id: number) {
  return requestClient.post(
    '/api/agent/setRoleToCs',
    { id },
    { responseReturn: 'body' },
  );
}

export interface AgentBankAccountRow {
  account_no: string;
  bank_name: string;
  currency_code?: string;
}

/** 代理銀行卡清單 */
export async function getAgentBankAccountApi(id: number) {
  return requestClient.post<AgentBankAccountRow[]>('/api/agent/bankAccount', {
    id,
  });
}

/** 個人設置 */
export async function getPersonalSettingApi(agentId: number) {
  return requestClient.post<Array<{ data_value: any; setting_id: number }>>(
    '/api/agent/personal-setting',
    { agent_id: agentId },
  );
}

/**
 * 代理佔成錢包存入/取出
 * type: 1=存入 2=取出；wallet_type_id: 3=佔成錢包
 *
 * 🔴 備註欄位名是 `remark`，不是 `memo`（target/tickets#4435）：API_Agent
 * TransactionController@agentWallet 讀的是 `$request->remark`，舊後台也送 remark。
 * 一般開洗分 agentTransactionApi 讀的才是 `memo`，兩支不同，不要統一成同一個名字。
 * 送成 memo 時 remark 為 null，寫入直接噴「Column 'remark' cannot be null」。
 */
export async function agentWalletTransactionApi(data: {
  agent_id: number;
  point: number;
  remark: string;
  type: number;
  wallet_type_id: number;
}) {
  return requestClient.post('/api/agentWalletTransaction', data, {
    responseReturn: 'body',
  });
}

export interface AgentDetailCount {
  active_member_count?: number;
  agent_count?: number;
  member_count?: number;
  registered_member_count?: number;
}

/** 代理團隊人數統計 */
export async function getAgentDetailApi(id: number) {
  return requestClient.post<AgentDetailCount>('/api/agent/detail', { id });
}

/** 代理/會員人數 */
export async function getAgentNumberOfPeopleApi(agentId: number) {
  return requestClient.post<{ agent: number; member: number }>(
    '/api/report/numberOfPeople',
    { agent_id: agentId },
    { responseReturn: 'body' },
  );
}

/* ========== 代理模組剩餘頁 ========== */

export interface LoginIpLogRow {
  created_at?: string;
  ip_1?: number | string;
  ip_2?: number | string;
  ip_3?: number | string;
  ip_4?: number | string;
  username?: string;
}

/** 代理登入紀錄 */
export async function getLoginIpLogApi(params: {
  end_time: string;
  ip?: string;
  page?: number;
  per_page?: number;
  start_time: string;
  type: number | string;
  username?: string;
}) {
  return requestClient.post<{
    code: number;
    data?: LoginIpLogRow[];
    message: string;
    pagination?: { current: number; pages: number; total: number };
  }>(
    '/api/agent/getLoginIpLog',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 操作紀錄類型下拉 */
export async function getOperateLogTypesApi() {
  return requestClient.post<Array<{ text: string; value: string }>>(
    '/api/agent/operate-log-types/dropdownList',
    {},
  );
}

export interface OperateLogRow {
  created_at?: string;
  debug_info?: string;
  id: number;
  ip?: string;
  operator?: string;
  /** 訂單異動後的狀態文字，後端已依查看者語系翻好；沒有狀態資料時為空字串 */
  orderStatusAfter?: string;
  /** 訂單異動前的狀態文字，規則同 orderStatusAfter */
  orderStatusBefore?: string;
  /** 匯出紀錄指向的選單 code（末層） */
  targetMenuCode?: null | string;
  /** 由根到該選單的 code 陣列，翻譯後即為選單層級 */
  targetMenuCodePath?: string[];
  targetUserName?: string;
  type?: string;
  type_name?: string;
}

/** 代理操作紀錄 */
export async function getAgentOperateLogsApi(params: {
  end_time: string;
  operator_username?: string;
  page?: number;
  per_page?: number;
  search_ip?: string;
  start_time: string;
  target_username?: string;
  type?: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: OperateLogRow[];
    message: string;
    pagination?: { current: number; pages: number; total: number };
  }>(
    '/api/agent/operate/logs',
    { page: 1, per_page: 10, ...params },
    { responseReturn: 'body' },
  );
}

/**
 * 寫入後台 Excel 匯出的操作紀錄（type=export_data）。
 *
 * 指向的選單 id 由後端以 menu_url 反查，前端不送 id。
 * 回傳整包信封讓呼叫端自行判斷成功碼：稽核寫不進去就不得產檔。
 */
export async function writeExportOperateLogApi(payload: {
  action: string;
  conditions?: Record<string, unknown>;
  menu_name?: string;
  menu_url?: string;
  record_count: number;
}) {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/api/agent/operate/logs/export',
    payload,
    { responseReturn: 'body' },
  );
}

export interface AgentRealnameValidateRow {
  bank_account?: string;
  bank_name?: string;
  bsb_code?: string;
  created_at?: string;
  id: number;
  operator?: string;
  real_name?: string;
  remark?: string;
  status?: number;
  updated_at?: string;
  username?: string;
}

/** 代理實名審核列表 */
export async function getAgentRealnameValidateListApi(params: {
  agent_username?: string;
  bank_account?: string;
  end_time?: string;
  page?: number;
  per_page?: number;
  start_time?: string;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: AgentRealnameValidateRow[];
    message: string;
    pagination?: { current: number; pages: number; total: number };
  }>(
    '/api/agent/realname_validate/list',
    { page: 1, per_page: 30, ...params },
    { responseReturn: 'body' },
  );
}

/** 代理實名審核單筆 */
export async function getAgentRealnameValidateViewApi(requestId: number) {
  return requestClient.post<AgentRealnameValidateRow>(
    '/api/agent/realname_validate/view',
    { request_id: requestId },
  );
}

/** 代理實名審核 */
export async function reviewAgentRealnameValidateApi(data: {
  id: number;
  remark: string;
  status: number;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/realname_validate/review',
    data,
    { responseReturn: 'body' },
  );
}

/** 組織下拉 */
export async function getOrganizationDropdownApi() {
  return requestClient.post<Array<{ id: number; name: string }>>(
    '/api/organization/dropdownList',
    {},
  );
}

export interface AgentGroupRow {
  agent_group_type: number;
  created_at?: string;
  id: number;
  memo?: string;
  name?: string;
  organization_name?: string;
  total_agent_count?: number;
}

/** 代理組別列表 */
export async function getAgentGroupListApi(params: {
  agent_group_type?: number | string;
  agent_name?: string;
  org_id?: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number;
    data?: AgentGroupRow[];
    message: string;
    pagination?: { current: number; pages: number; total: number };
  }>(
    '/api/agent/group/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 建立代理組別 */
export async function createAgentGroupApi(data: {
  agent_group_type: number | string;
  memo: string;
  name: string;
  organization_id: number | string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/group/create',
    data,
    { responseReturn: 'body' },
  );
}

/** 編輯代理組別 */
export async function updateAgentGroupApi(data: {
  id: number;
  memo: string;
  name: string;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/group/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 複製建立新組別 */
export async function copyAgentGroupApi(targetGroupId: number) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/group/create-by-copy',
    { target_group_id: targetGroupId },
    { responseReturn: 'body' },
  );
}

/** 組別內代理清單 */
export async function getAgentsByGroupApi(params: Record<string, number>) {
  return requestClient.post<Array<{ id: number; username: string }>>(
    '/api/agent/filter-by-group',
    params,
  );
}

/** 代理下拉。帶 username 時後端模糊查詢並限量 50 筆（供遠端搜尋，target/tickets#4191）；
 *  不帶參數為全量回傳，大站（TPower 4.4 萬代理）勿直接灌進下拉渲染。 */
export async function getAgentDropdownListApi(params?: { username?: string }) {
  return requestClient.post<Array<{ id: number; username: string }>>(
    '/api/agent/dropdownList',
    params ?? {},
  );
}

export interface MigratePreviewAgent {
  agent_id?: number;
  hierarchy?: string;
  hierarchy_path?: string;
  username?: string;
}

export interface MigratePreview {
  hierachyChilds?: Array<MigratePreviewAgent & { expect_new_path?: string }>;
  new_parent_agent?: MigratePreviewAgent;
  original_agent?: MigratePreviewAgent;
  sqlRaw_1?: string;
  sqlRaw_2?: string;
  sqlRaw_3?: string;
  target_agent?: MigratePreviewAgent;
}

/** 代理移轉預覽 */
export async function migratePreviewApi(data: {
  new_parent_agent_id: number | string;
  target_agent_id: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: MigratePreview | string;
    message: string;
  }>('/api/agent/hierarchy-migrate/preview', data, { responseReturn: 'body' });
}

/** 執行代理移轉 */
export async function migrateAgentApi(data: {
  new_parent_agent_id: number | string;
  target_agent_id: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: {
      unexpected_agentIDs?: unknown[];
      unexpected_agentIDsText?: string;
    };
    message: string;
  }>('/api/agent/hierarchy-migrate', data, { responseReturn: 'body' });
}

/** 檢查全體代理樹狀異常 */
export async function checkHierarchyApi() {
  return requestClient.post<{
    code: number;
    data?: {
      unexpected_agentIDs?: unknown[];
      unexpected_agentIDsText?: string;
    };
    message: string;
  }>('/api/agent/hierarchy-check', { test: 123 }, { responseReturn: 'body' });
}

/** 子帳號清單（工單4422 起走 apis，成功碼 200） */
export async function getAgentChildAccountsApi() {
  return requestClient.post<{
    code: number;
    data?: {
      childCount?: number;
      childs?: Array<{ id: number; username: string }>;
      is_not_child_account?: boolean | number;
    };
    message: string;
  }>(
    '/agent/api/agent-permission/child-accounts',
    {},
    { responseReturn: 'body' },
  );
}

/** 同步子帳號權限（工單4422 起走 apis，成功碼 200） */
export async function syncChildPermissionApi() {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/agent/api/agent-permission/sync-children',
    {},
    { responseReturn: 'body' },
  );
}

export interface AgentMenuNode {
  code?: string;
  id: number;
  name?: string;
  parent?: number;
  submenu?: AgentMenuNode[];
}

/** 代理的生效選單樹（工單4422 起走 apis，每次即時算、不快取） */
export async function getAgentMenuTreeApi(agentId: number) {
  return requestClient.post<AgentMenuNode[]>(
    '/agent/api/agent-permission/menu-tree',
    {
      agent_id: agentId,
    },
  );
}

/** 代理階層樹 */
export async function getAgentHierarchyApi(username: string) {
  return requestClient.post<{
    code: number;
    data?: Array<{ id: number; name: string; pId: number }>;
    message: string;
  }>('/api/agent/getAgentHierarchy', { username }, { responseReturn: 'body' });
}

export interface InformationRow {
  content?: string;
  detail?: Array<{ image?: string; language?: number | string; name?: string }>;
  id: number;
  link_type?: number | string;
  sort?: number | string;
  status?: number;
  type?: number;
}

/** 資訊管理列表 */
export async function getInformationListApi() {
  return requestClient.post<InformationRow[]>('/api/information/list', {});
}

/** 更新資訊排序 */
export async function updateInformationSortApi(
  sortData: Record<string, number | string>,
) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/information/update_sort',
    { sort_data: sortData },
    { responseReturn: 'body' },
  );
}

/** 資訊管理 - 單筆明細列 */
export interface InformationDetail {
  id?: number | string;
  image?: string;
  image_pc?: string;
  language?: number | string;
}
/** 資訊管理 - view 回傳 */
export interface InformationView {
  agent_id?: number | string;
  content?: string;
  description?: string;
  detail?: InformationDetail[];
  open_window?: number | string;
  sort?: number | string;
  status?: number | string;
  type?: number | string;
}
/** 資訊管理單筆 */
export async function getInformationViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: InformationView;
    message?: string;
  }>('/api/information/view', { id }, { responseReturn: 'body' });
}
/** 儲存資訊（舊版 ajax save_information → information/add|edit） */
export async function saveInformationApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{ code: number | string; message?: string }>(
    isEdit ? '/api/information/edit' : '/api/information/add',
    data,
    { responseReturn: 'body' },
  );
}

/** 語系列表 */
export async function getLanguagesApi() {
  return requestClient.post<Array<{ code: string; id: number; name: string }>>(
    '/api/languages',
    {},
  );
}

/** 驗證自己的登入密碼 */
export async function confirmAgentPasswordApi(password: [REDACTED] {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/confirmPassword',
    { password },
    { responseReturn: 'body' },
  );
}

/** 代理帳號遠端搜尋單次回傳上限 */
export const AGENT_SEARCH_LIMIT = 100;

/**
 * 代理帳號下拉（含登入者的主帳號本身）。
 *
 * agent/list 對子帳號會排除主帳號，選不到總代；這支以 hierarchy 比對，
 * 主帳號自己也在結果內。後端沒有 LIMIT，由 gateway 依 limit 排序後截斷。
 */
export async function searchAgentDropdownApi(
  username: string,
  limit: number = AGENT_SEARCH_LIMIT,
) {
  return requestClient.post<{
    code: number | string;
    data?: Array<{ id: number; parent: number; username: string }>;
    message: string;
  }>(
    '/api/agent/newDropdownList',
    { limit, username },
    { responseReturn: 'body' },
  );
}

/** 代理帳號遠端搜尋；agent/list 有伺服器分頁，一次只取前 limit 筆 */
export async function searchAgentOptionsApi(
  username: string,
  limit: number = AGENT_SEARCH_LIMIT,
) {
  const res = await getAgentListApi({
    fuzzy_query: 1,
    include_sub_account: 0,
    page: 1,
    per_page: limit,
    ...(username ? { username } : {}),
  });
  const keyword = username.trim().toLowerCase();
  const rank = (name: string) => {
    if (!keyword) return 0;
    const at = name.toLowerCase().indexOf(keyword);
    if (at === -1) return 3;
    if (name.length === keyword.length) return 0;
    return at === 0 ? 1 : 2;
  };
  const data = res.list
    .map((row) => ({ id: row.id, username: row.username }))
    .toSorted((a, b) => {
      const byRank = rank(a.username) - rank(b.username);
      if (byRank !== 0) return byRank;
      const byLength = a.username.length - b.username.length;
      return byLength === 0 ? a.username.localeCompare(b.username) : byLength;
    });
  // 後端以 id 倒序截斷，完整帳號可能落在 limit 外，補一次精準查詢確保打全名找得到
  const kw = username.trim();
  if (
    kw &&
    data.length >= limit &&
    !data.some((o) => o.username.toLowerCase() === keyword)
  ) {
    const hit = await getAgentListApi({
      include_sub_account: 0,
      page: 1,
      per_page: 1,
      username: kw,
    });
    const row = hit.list[0];
    if (row) data.unshift({ id: row.id, username: row.username });
  }
  return { code: res.code, data, message: res.message };
}

/** 建立營運商 */
export async function addAgentApi(data: Record<string, unknown>) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/api/agent/add',
    data,
    { responseReturn: 'body' },
  );
}

/** 設定代理選單權限（工單4422 起走 apis，成功碼 200） */
export async function setAgentPermissionApi(data: {
  agent_id: number | string;
  menus: Record<string, string>;
}) {
  return requestClient.post<{ code: number; data?: unknown; message: string }>(
    '/agent/api/agent-permission/setting',
    data,
    { responseReturn: 'body' },
  );
}
