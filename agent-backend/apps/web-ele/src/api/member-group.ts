import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 會員群組 API。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼為 200（與舊 API_Agent 的 1000 不同）；分頁資料在 data.items / data.pagination。
 */

// ---------- 群組列表 ----------
/**
 * 平台提款手續費階梯的一階。
 * type：0 = 固定金額、1 = 百分比；value 為百分比時是整數百分比（3 = 3%）。
 */
export interface MemberGroupFeeTier {
  from: number;
  type: 0 | 1;
  value: number | string;
}

export interface MemberGroupRow {
  id: number;
  agent_id?: number;
  agent_name?: string;
  bank_whitelist_enabled?: number;
  default_setting?: number;
  deposit_daily_amount?: number | string;
  deposit_daily_count?: number | string;
  deposit_max?: number | string;
  deposit_min?: number | string;
  member_count?: number;
  name?: string;
  remark?: string;
  sequence?: number | string;
  status?: number;
  transfer_daily_amount?: number | string;
  transfer_daily_count?: number | string;
  transfer_max?: number | string;
  transfer_min?: number | string;
  withdraw_daily_amount?: number | string;
  withdraw_daily_count?: number | string;
  /** 平台提款手續費階梯；null = 未設定（沿用銀行管理手續費） */
  withdraw_fee_tiers?: MemberGroupFeeTier[] | null;
  withdraw_max?: number | string;
  withdraw_min?: number | string;
  [key: string]: any;
}

export async function getMemberGroupListApi(params?: {
  agent_id?: number | string;
  keyword?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: MemberGroupRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/member-group/list',
    { page: 1, per_page: 100, ...params },
    {
      responseReturn: 'body',
    },
  );
}

// ---------- 群組下拉選單（會員編輯頁指派用） ----------
export interface MemberGroupOption {
  default_setting?: number;
  id: number;
  name?: string;
  [key: string]: any;
}

export async function getMemberGroupDropdownApi(params?: {
  member_id?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: MemberGroupOption[];
    message: string;
  }>('/agent/api/member-group/dropdown-list', params ?? {}, {
    responseReturn: 'body',
  });
}

// ---------- 群組詳情 ----------
export interface MemberGroupViewData extends MemberGroupRow {
  bank_account_ids?: number[];
}

export async function getMemberGroupViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: MemberGroupViewData;
    message: string;
  }>('/agent/api/member-group/view', { id }, { responseReturn: 'body' });
}

/** 新增/編輯會員群組；有 id 為編輯 */
export async function saveMemberGroupApi(
  data: Record<string, any>,
  isEdit: boolean,
) {
  return requestClient.post<{
    code: number | string;
    data?: { id?: number };
    message: string;
  }>(
    isEdit
      ? '/agent/api/member-group/update'
      : '/agent/api/member-group/create',
    data,
    { responseReturn: 'body' },
  );
}

// ---------- 銀行管理 tab 勾選清單（自助存款帳號） ----------
/** bank_id = bank_accounts.id，即白名單勾選值 */
export interface MemberGroupBankAccountRow {
  account_name?: string;
  account_no?: string;
  bank_id: number;
  bank_name?: string;
  [key: string]: any;
}

export async function getMemberGroupBankAccountsApi() {
  return requestClient.post<{
    code: number | string;
    data?: MemberGroupBankAccountRow[];
    message: string;
  }>('/agent/api/member-group/bank-accounts', {}, { responseReturn: 'body' });
}

// ---------- 指派會員到群組 ----------
/** member_group_id 傳 0/空 = 取消指派 */
export async function assignMemberGroupApi(params: {
  member_group_id?: null | number | string;
  member_id: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: any;
    message: string;
  }>('/agent/api/member-group/assign-member', params, {
    responseReturn: 'body',
  });
}
