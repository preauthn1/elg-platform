import { requestClient } from '#/api/request';

/**
 * 代理群組（權限角色）API 工單4080
 *
 * 工單4422 起改走 apis（/agent/api/ 前綴，成功碼 200），列表分頁在 data.items／data.pagination。
 * 代理最終權限 = 個人權限 ∪ 角色權限，判斷在後端 AgentService::getEffectiveMenuIds()。
 */

export interface AgentPermissionGroupRow {
  /** 掛在此角色底下的代理數 */
  agents_count?: number;
  created_at: string;
  /** 是否預設群組 0:否 1:是（建立代理時下拉預選，非強制） */
  default_setting: number;
  id: number;
  name: string;
  /**
   * 所屬代理（持有者）agents.id，工單4543：null＝admin 持有（只有 admin 能套、可套給任何人，畫面顯示「admin」）；
   * 1＝admin預設角色（限 admin 子帳號，顯示「admin（子帳號專用）」）；其他＝建立者的主帳號
   */
  owner_agent_id: null | number;
  /** 持有者帳號，供列表顯示；admin 持有為 null、admin預設角色為 'admin' */
  owner_username?: null | string;
  /** 工單4543：false＝總代列表裡下線建的角色，只能檢視（不給編輯、不給狀態開關） */
  can_edit?: boolean;
  remark: null | string;
  /** 0:關閉 1:啟用 */
  status: number;
  updated_at: string;
}

export interface AgentPermissionGroupDetail extends AgentPermissionGroupRow {
  /** 該角色的 action 對照 { agent_menu_id: action } */
  menu_actions: Record<string, string>;
  /** 該角色勾選的選單 ID */
  menus: number[];
}

/**
 * 下拉用：操作者可套用的角色（工單4543 持有者規則：admin→admin 持有、總代→自己建的、一般代理→所屬總代預設＋自己建的，
 * 且權限足夠），含各自的選單 ID 供前端自動勾選。
 *
 * 帶 agent_id 查詢時會額外補回該代理已綁、但本次不可用的角色（停用／跨線／
 * 權限已不足），一律標記 status=0、不開放改選；menus 照實回（工單4543），
 * 編輯頁靠它知道哪些勾選來自角色、存檔時才扣得掉，否則角色權限會被整包寫進個人權限。
 */
export interface AgentPermissionGroupOption {
  default_setting: number;
  id: number;
  menus: number[];
  name: string;
  /** 持有者 agents.id，null＝admin 持有、1＝admin預設角色（工單4543） */
  owner_agent_id: null | number;
  /** 1=可套用；0=僅為顯示既有綁定而列出，不可改選 */
  status: number;
  /**
   * 工單4498：admin預設角色前提。false＝目標帳號未開 Google 2FA 或登入 IP 白名單為空（新增模式一律 false）。
   * 工單4543：代理已綁但操作者本次套不到的角色也回 false（停用／持有者不同／權限超出）。
   * 前端反灰並以 unassignable_reason 顯示「角色名（原因）」
   */
  assignable?: boolean;
  /** 不可選的原因（已翻譯），assignable=true 時為 null */
  unassignable_reason?: null | string;
}

/** 所屬代理下拉（建立／編輯角色時選持有者用；非 super admin 固定自己、不可選） */
export interface AgentPermissionGroupOwnerOption {
  id: number;
  /**
   * 工單4422：true＝admin 角色線（id 為 super admin 的 agents.id），限 super admin 子帳號套用。
   * 後端固定排第一項，前端加註並在其後畫分隔線
   */
  is_admin?: boolean;
  username: string;
}

/**
 * 所屬代理（持有者）選項與角色頁的操作開關（工單4543）。
 *
 * - can_select：建立／編輯時能不能選持有者，恆為 false（誰建的就記誰，admin 建的記 admin 持有）
 * - can_filter：列表能不能依所屬代理篩選，只有 super admin
 * - can_set_default：能不能把角色設為預設，只有總代（含子帳號）
 * - owners：super admin 拿到 admin預設角色＋全部總代，其餘只有自己的主帳號；用來顯示名稱與篩選
 */
export interface AgentPermissionGroupOwnerResult {
  can_filter: boolean;
  can_select: boolean;
  can_set_default: boolean;
  owners: AgentPermissionGroupOwnerOption[];
}

/** apis 分頁資訊（successWithPagination） */
export interface AgentPermissionGroupPagination {
  current: number;
  pages: number;
  per_page: number;
  total: number;
}

/** 代理群組列表 */
export async function getAgentPermissionGroupListApi(params: {
  name?: string;
  /** 依持有者篩選（僅 super admin 有效）；0 = 只看 admin 持有（owner 為空）的角色 */
  owner_agent_id?: number | string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number;
    data?: {
      items: AgentPermissionGroupRow[];
      pagination: AgentPermissionGroupPagination;
    };
    message: string;
  }>(
    '/agent/api/agent-permission-group/list',
    { page: 1, per_page: 25, ...params },
    { responseReturn: 'body' },
  );
}

/** 代理群組詳情（含已勾選的選單） */
export async function getAgentPermissionGroupApi(id: number | string) {
  return requestClient.post<{
    code: number;
    data?: AgentPermissionGroupDetail;
    message: string;
  }>(
    '/agent/api/agent-permission-group/view',
    { id },
    { responseReturn: 'body' },
  );
}

/**
 * 建立代理群組。
 *
 * default_setting=1 表示設為「該線的預設角色」（target/op_tickets#3168）：
 * 同一條線內唯一；該線已有預設時後端擋下，不自動頂替。
 */
export async function createAgentPermissionGroupApi(data: {
  /** 1=設為該線預設角色（同線唯一，已有預設時擋下）；未帶等同 0 */
  default_setting?: number;
  menus: number[];
  name: string;
  /** 所屬代理（持有者）；空字串或 null = admin 持有（僅 super admin 可建）。非 super admin 由後端固定寫自己（工單4543） */
  owner_agent_id?: null | number | string;
  remark?: string;
  status: number;
}) {
  return requestClient.post<{
    code: number;
    data?: { id: number };
    message: string;
  }>('/agent/api/agent-permission-group/create', data, {
    responseReturn: 'body',
  });
}

/**
 * 修改代理群組。
 *
 * default_setting 未帶時後端維持原值（舊前端不送，其預設身分不該因此被清掉）；
 * 帶 1 則設為該線預設；該線已有其他預設時後端擋下，不自動頂替。
 */
export async function updateAgentPermissionGroupApi(data: {
  /** 1=設為該線預設角色；未帶則維持原值 */
  default_setting?: number;
  id: number | string;
  menus: number[];
  name: string;
  /** 所屬代理（持有者）；未帶則後端維持原值，非 super admin 帶別人會回 9003 */
  owner_agent_id?: null | number | string;
  remark?: string;
  status: number;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/agent/api/agent-permission-group/update',
    data,
    { responseReturn: 'body' },
  );
}

/** 啟用／停用代理群組 */
export async function setAgentPermissionGroupStatusApi(data: {
  id: number | string;
  status: number;
}) {
  return requestClient.post<{ code: number; message: string }>(
    '/agent/api/agent-permission-group/status',
    data,
    { responseReturn: 'body' },
  );
}

/**
 * 可套用的角色下拉的「目標帳號」（工單4422）。後端依它決定是否給 admin 角色：
 * 目標是 super admin 子帳號只給 admin預設角色＋admin 持有的角色，其他目標不給 admin預設角色。
 * 編輯帶 agent_id；新增帶 is_sub_account＋parent（比照建立營運商的 host_id 規則）。
 */
export interface AgentPermissionGroupDropdownTarget {
  agent_id?: number;
  is_sub_account?: number;
  parent?: number | string;
}

/** 可套用的角色下拉（建立／編輯營運商選角色用，含選單 ID 供自動勾選） */
export async function getAgentPermissionGroupDropdownApi(
  target: AgentPermissionGroupDropdownTarget = {},
) {
  return requestClient.post<{
    code: number;
    data?: AgentPermissionGroupOption[];
    message: string;
  }>('/agent/api/agent-permission-group/dropdown', target, {
    responseReturn: 'body',
  });
}

/**
 * 所屬代理（持有者）下拉（建立／編輯角色時用）。
 *
 * super admin 取得 admin預設角色＋全部總代；其餘代理只會拿到自己的主帳號（工單4543，不可選）。
 */
export async function getAgentPermissionGroupOwnerDropdownApi() {
  return requestClient.post<{
    code: number;
    data?: AgentPermissionGroupOwnerResult;
    message: string;
  }>(
    '/agent/api/agent-permission-group/owner-dropdown',
    {},
    { responseReturn: 'body' },
  );
}

/** 掛在某角色底下的代理（列表「代理數」點擊查看） */
export interface AgentPermissionGroupAgentRow {
  id: number;
  /** 上層代理帳號 */
  parent_username: null | string;
  /** 0:鎖定 1:正常 */
  status: number;
  username: string;
}

/**
 * 列出掛在該角色底下的代理。
 *
 * 範疇與列表一致：非 super admin 只拿得到自己範疇內（主帳號＋整條下線）的代理——
 * 角色可見不代表底下的代理可見。
 */
export async function getAgentPermissionGroupAgentsApi(data: {
  id: number | string;
  page?: number;
  per_page?: number;
}) {
  return requestClient.post<{
    code: number;
    data?: {
      items: AgentPermissionGroupAgentRow[];
      pagination: AgentPermissionGroupPagination;
    };
    message: string;
  }>('/agent/api/agent-permission-group/agents', data, {
    responseReturn: 'body',
  });
}
