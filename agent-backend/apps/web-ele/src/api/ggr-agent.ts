import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 推廣代理 GGR - 遊戲商設置（4123）與條件設置（4124）API。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼 200，分頁在 data.items / data.pagination。
 * 兩者皆無 delete：停用靠狀態關閉（4121 方案引用主表 id，不可斷鏈）。
 * 4123 每個 vendor_info × game_type 組合為多階層（計算金額逐階遞增）；
 * 4124 階梯列 user_count / percentage 逐階遞增，門檻為單一會員各自須達的值。
 */

interface ActionResult {
  code: number | string;
  data?: unknown;
  message: string;
}

/** 4124 條件階梯列（門檻 0 = 該項無要求） */
export interface GgrConditionTierRow {
  deposit_amount: number | string;
  game_revenue_amount: number | string;
  percentage: number | string;
  total_bet_amount: number | string;
  user_count: number | string;
  valid_bet_amount: number | string;
}

/** 4124 條件設置（view 含 tiers） */
export interface GgrConditionRow {
  /** 僅計算活躍用戶 1:是 0:否 */
  active_user_only?: number;
  created_at?: string;
  created_by?: null | string;
  id: number;
  /** 1:或者(OR) 2:和(AND) */
  logic_type?: number;
  name: string;
  /** 1:啟用 0:停用 */
  status?: number;
  tiers?: GgrConditionTierRow[];
  updated_at?: string;
  updated_by?: null | string;
}

/** 4123 明細階層（min_winlose / max_ggr 為 null = 無最低限制／無上限） */
export interface GgrVendorSettingTier {
  max_ggr: null | number | string;
  min_winlose: null | number | string;
  threshold_amount: number | string;
  weight_rate: number | string;
}

/** 4123 明細（每項＝vendor_info × game_type 組合，內含多階層） */
export interface GgrVendorSettingDetail {
  excluded_game_ids: number[];
  game_type_id: number;
  game_type_name?: string;
  /** 1:啟用 0:不列入結算 */
  status: number;
  tiers: GgrVendorSettingTier[];
  vendor_id: number;
  vendor_info_id: number;
  vendor_info_name?: string;
}

/** 4123 遊戲商設置列 */
export interface GgrVendorSettingRow {
  created_at?: string;
  created_by?: null | string;
  details?: GgrVendorSettingDetail[];
  id: number;
  name: string;
  /** 1:啟用 0:停用 */
  status?: number;
  updated_at?: string;
  updated_by?: null | string;
}

/** vendor-options 資料源（tabs 資料驅動、限總代支援範圍） */
export interface GgrVendorOptions {
  game_types: { id: number; name: string }[];
  items: {
    game_type_id: number;
    vendor_id: number;
    vendor_info_id: number;
    vendor_info_name: string;
  }[];
}

/** 遊戲商設置列表 */
export async function getGgrVendorSettingListApi(params?: {
  name?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: GgrVendorSettingRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/ggr/vendor-setting/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 遊戲商設置詳情（編輯/複製回填用，含巢狀明細） */
export async function getGgrVendorSettingViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: GgrVendorSettingRow;
    message: string;
  }>('/agent/api/ggr/vendor-setting/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯遊戲商設置；有 id 為編輯（明細全量替換） */
export async function saveGgrVendorSettingApi(
  data: Record<string, unknown>,
  isEdit: boolean,
) {
  return requestClient.post<ActionResult>(
    isEdit
      ? '/agent/api/ggr/vendor-setting/update'
      : '/agent/api/ggr/vendor-setting/create',
    data,
    { responseReturn: 'body' },
  );
}

/** 彈窗資料源：總代支援的 vendor_info × game_type 組合與類型 tabs */
export async function getGgrVendorOptionsApi() {
  return requestClient.post<{
    code: number | string;
    data?: GgrVendorOptions;
    message: string;
  }>(
    '/agent/api/ggr/vendor-setting/vendor-options',
    {},
    {
      responseReturn: 'body',
    },
  );
}

/** 排除遊戲下拉（明細列展開時逐列 lazy load） */
export async function getGgrGameOptionsApi(params: {
  game_type_id: number;
  vendor_id: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { id: number; name: string }[];
    message: string;
  }>('/agent/api/ggr/vendor-setting/game-options', params, {
    responseReturn: 'body',
  });
}

/** 條件設置列表 */
export async function getGgrConditionListApi(params?: {
  logic_type?: number | string;
  name?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: GgrConditionRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/ggr/condition-setting/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 條件設置詳情（編輯/複製回填用，含階梯列） */
export async function getGgrConditionViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: GgrConditionRow;
    message: string;
  }>(
    '/agent/api/ggr/condition-setting/view',
    { id },
    {
      responseReturn: 'body',
    },
  );
}

/** 新增/編輯條件設置；有 id 為編輯（階梯全量替換） */
export async function saveGgrConditionApi(
  data: Record<string, unknown>,
  isEdit: boolean,
) {
  return requestClient.post<ActionResult>(
    isEdit
      ? '/agent/api/ggr/condition-setting/update'
      : '/agent/api/ggr/condition-setting/create',
    data,
    { responseReturn: 'body' },
  );
}

/** 4121 方案的特殊用戶 override 列 */
export interface GgrPlanSpecialAgentRow {
  agent_id: number;
  agent_username?: string;
  condition_id: number;
  condition_name?: string;
  vendor_setting_id: number;
  vendor_setting_name?: string;
}

/** 4121 GGR 方案（view 含綁定名稱與特殊用戶列） */
export interface GgrPlanRow {
  agent_group_id?: number;
  /** 獎金百分比% */
  bonus_rate?: number | string;
  /** 发扬 1:負數GGR用原始數據結轉下期 */
  carry_negative?: number;
  created_at?: string;
  created_by?: null | string;
  downline_condition_id?: number;
  downline_condition_name?: string;
  downline_vendor_setting_id?: number;
  downline_vendor_setting_name?: string;
  /** GGR百分比% */
  ggr_rate?: number | string;
  /** 手續費(固定金額) */
  handling_fee?: number | string;
  id: number;
  kyc_unique_bank_account?: number;
  kyc_unique_contact_no?: number;
  kyc_unique_email?: number;
  kyc_unique_ip?: number;
  kyc_unique_name?: number;
  kyc_violate_count?: number;
  /** 管理費% */
  admin_fee_rate?: number | string;
  name: string;
  /** 結算週期 1:每日 2:每週 3:每月 */
  settlement_cycle?: number;
  special_agents?: GgrPlanSpecialAgentRow[];
  /** 特殊用戶數（list 載入） */
  special_agents_count?: number;
  start_date?: string;
  /** 1:啟用 0:停用 */
  status?: number;
  updated_at?: string;
  updated_by?: null | string;
}

/** GGR 方案列表 */
export async function getGgrPlanListApi(params?: {
  name?: string;
  page?: number;
  per_page?: number;
  settlement_cycle?: number | string;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: GgrPlanRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/ggr/plan/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** GGR 方案詳情（編輯/複製回填用，含綁定名稱與特殊用戶列） */
export async function getGgrPlanViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: GgrPlanRow;
    message: string;
  }>('/agent/api/ggr/plan/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯 GGR 方案；有 id 為編輯（特殊用戶列全量替換） */
export async function saveGgrPlanApi(
  data: Record<string, unknown>,
  isEdit: boolean,
) {
  return requestClient.post<ActionResult>(
    isEdit ? '/agent/api/ggr/plan/update' : '/agent/api/ggr/plan/create',
    data,
    { responseReturn: 'body' },
  );
}

/** 4121 表單下拉：本總代啟用中的遊戲商設定 */
export async function getGgrVendorSettingDropdownApi() {
  return requestClient.post<{
    code: number | string;
    data?: { id: number; name: string }[];
    message: string;
  }>(
    '/agent/api/ggr/plan/vendor-setting-dropdown',
    {},
    { responseReturn: 'body' },
  );
}

/** 4121 表單下拉：本總代啟用中的條件設定（max_percentage=最高階應得百分比，供有效分潤率試算） */
export async function getGgrConditionDropdownApi() {
  return requestClient.post<{
    code: number | string;
    data?: { id: number; max_percentage?: string; name: string }[];
    message: string;
  }>('/agent/api/ggr/plan/condition-dropdown', {}, { responseReturn: 'body' });
}

/** 4125 派發紀錄（結算結果，資料由結算引擎批次寫入） */
export interface GgrSettlementRow {
  /** 管理費%（行政費）快照 */
  admin_fee_rate?: string;
  agent_id: number;
  agent_username?: string;
  /** 管理者帳號（發放/備註操作者） */
  admin_username?: null | string;
  /** 獎金百分比%快照 */
  bonus_rate?: string;
  /** 計算明細快照（view 才回傳） */
  calc_snapshot?: null | Record<string, unknown>;
  /** 結轉狀態 1:有結轉 0:無 */
  carry?: number;
  /** 发扬:上期結轉帶入 */
  carry_in_amount?: string;
  /** 发扬:本期結轉帶出 */
  carry_out_amount?: string;
  /** 條件百分比%快照（4124 命中階應得%） */
  condition_rate?: string;
  created_at?: string;
  /** 最終金額 */
  final_amount?: string;
  /** 原始總GGR */
  ggr_amount?: string;
  /** GGR百分比%快照 */
  ggr_rate?: string;
  /** 手續費快照 */
  handling_fee?: string;
  id: number;
  /** 發放時間 */
  paid_at?: null | string;
  /** 發放狀態 0:未發放 1:已發放 */
  payout_status?: number;
  /** 結算區間迄 Y-m-d */
  period_end?: string;
  /** 結算區間起 Y-m-d */
  period_start?: string;
  plan_id?: number;
  plan_name?: string;
  remark?: null | string;
  /** 結算週期快照 1:每日 2:每週 3:每月 */
  settlement_cycle?: number;
  updated_at?: string;
}

/** 4125 派發紀錄列表 */
export async function getGgrSettlementListApi(params?: {
  agent_username?: string;
  carry?: number | string;
  end_date?: string;
  page?: number;
  payout_status?: number | string;
  per_page?: number;
  start_date?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: GgrSettlementRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/ggr/payout-record/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 4125 派發紀錄詳情（含計算明細快照） */
export async function getGgrSettlementViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: GgrSettlementRow;
    message: string;
  }>('/agent/api/ggr/payout-record/view', { id }, { responseReturn: 'body' });
}
