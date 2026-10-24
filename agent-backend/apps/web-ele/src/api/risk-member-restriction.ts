import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 風控管理 - 會員限制 API（4118）。
 *
 * 後端在 apis repo 的 Agent 面向（gateway 依 `agent/api/` 前綴轉發到 APIS_URL）。
 * 成功碼 200，分頁在 data.items / data.pagination。
 * 函式名帶 Risk 前綴避免與 api/member.ts 既有的舊「會員限制」（getMemberRestrictionListApi 等）撞名。
 */

export interface RiskMemberRestrictionRow {
  agent_username?: string;
  id: number;
  is_disabled?: number;
  remark?: string;
  status?: number;
  target_name?: string;
  /** 限制類型 1:会员管理 2:会员群组 */
  type?: number;
  updated_at?: string;
  updated_by?: string;
  withdrawal_locked?: number;
}

/** 會員限制列表 */
export async function getRiskMemberRestrictionListApi(params?: {
  page?: number;
  per_page?: number;
  remark?: string;
  status?: number | string;
  type?: number | string;
  username?: string;
}) {
  return requestClient.post<{
    code: number | string;
    data?: { items?: RiskMemberRestrictionRow[]; pagination?: Pagination };
    message: string;
  }>(
    '/agent/api/member-restriction/list',
    { page: 1, per_page: 20, ...params },
    { responseReturn: 'body' },
  );
}

/** 會員限制詳情（編輯回填用） */
export async function getRiskMemberRestrictionViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: RiskMemberRestrictionRow;
    message: string;
  }>('/agent/api/member-restriction/view', { id }, { responseReturn: 'body' });
}

export interface RiskMemberRestrictionActionResult {
  code: number | string;
  data?: unknown;
  message: string;
}

/** 新增/編輯會員限制；有 id 為編輯（編輯時限制類型與目標不可變） */
export async function saveRiskMemberRestrictionApi(
  data: Record<string, unknown>,
  isEdit: boolean,
) {
  return requestClient.post<RiskMemberRestrictionActionResult>(
    isEdit
      ? '/agent/api/member-restriction/update'
      : '/agent/api/member-restriction/create',
    data,
    { responseReturn: 'body' },
  );
}

/** 刪除會員限制 */
export async function deleteRiskMemberRestrictionApi(id: number | string) {
  return requestClient.post<RiskMemberRestrictionActionResult>(
    '/agent/api/member-restriction/delete',
    { id },
    { responseReturn: 'body' },
  );
}
