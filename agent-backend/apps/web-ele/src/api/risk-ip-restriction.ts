import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 風控管理 - IP限制 API（4096）。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼 200，分頁在 data.items / data.pagination。
 * 無 delete：同 IP 僅一筆，打錯靠編輯修正、停用靠狀態關閉。
 * repeat_count 為系統累計（被擋嘗試 +1，不分項目），後台唯讀。
 */

/** 阻擋項目（多選）；值與後端 IpRestrictionBlockItemEnum 一致 */
export type RiskIpRestrictionBlockItem = 'login' | 'register' | 'sms';

/** 顯示順序固定，建立/編輯與列表共用同一份，避免兩處排序不一致 */
export const RISK_IP_RESTRICTION_BLOCK_ITEMS: RiskIpRestrictionBlockItem[] = [
  'register',
  'login',
  'sms',
];

export interface RiskIpRestrictionRow {
  /** 阻擋項目（多選，至少一項） */
  block_items?: RiskIpRestrictionBlockItem[];
  id: number;
  ip: string;
  remark?: string;
  /** 被擋嘗試累計次數（系統累加，唯讀） */
  repeat_count?: number;
  /** 1:啟用 0:關閉 */
  status?: number;
  updated_at?: string;
  /** 更新者帳號；未經人工編輯為 null（顯示 -） */
  updated_by?: null | string;
}

/** IP限制列表 */
export async function getRiskIpRestrictionListApi(params?: {
  ip?: string;
  page?: number;
  per_page?: number;
  status?: number | string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: RiskIpRestrictionRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/ip-restriction/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** IP限制詳情（編輯回填用） */
export async function getRiskIpRestrictionViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: RiskIpRestrictionRow;
    message: string;
  }>('/agent/api/ip-restriction/view', { id }, { responseReturn: 'body' });
}

export interface RiskIpRestrictionActionResult {
  code: number | string;
  data?: unknown;
  message: string;
}

/** 新增/編輯IP限制；有 id 為編輯（IP 與阻擋項目皆可修正，repeat_count 不可入參） */
export async function saveRiskIpRestrictionApi(
  data: Record<string, unknown>,
  isEdit: boolean,
) {
  return requestClient.post<RiskIpRestrictionActionResult>(
    isEdit
      ? '/agent/api/ip-restriction/update'
      : '/agent/api/ip-restriction/create',
    data,
    { responseReturn: 'body' },
  );
}
