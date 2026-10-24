import { requestClient } from '#/api/request';

/**
 * 下線會員管理 - 銀行卡管理 API */

export interface MemberBankCardRow {
  account_name?: string;
  account_no?: string;
  agent_username?: string;
  bank_id?: number | string;
  bank_name?: string;
  binding_time?: string;
  id: number | string;
  member_username?: string;
}

/** 銀行卡列表 */
export async function getMemberBankAccountListApi(data: {
  account_no?: string;
  agent_username?: string;
  binding_end_time?: string;
  binding_start_time?: string;
  member_username?: string;
  page: number;
  per_page: number;
}) {
  return requestClient.post<{
    code: number | string;
    data?: MemberBankCardRow[];
    message?: string;
    pagination?: { total?: number };
  }>('/api/member/bank_account/list', data, { responseReturn: 'body' });
}

/** 銀行卡單筆明細 */
export async function getMemberBankAccountViewApi(id: number | string) {
  return requestClient.post<{
    code: number | string;
    data?: MemberBankCardRow;
    message?: string;
  }>('/api/member/bank_account/view', { id }, { responseReturn: 'body' });
}

/** 編輯銀行卡 */
export async function saveMemberBankAccountApi(data: {
  account_name: string;
  account_no: string;
  bank_id: number | string;
  id: number | string;
}) {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/api/member/bank_account/edit',
    data,
    { responseReturn: 'body' },
  );
}

/** 刪除銀行卡 */
export async function deleteMemberBankAccountApi(id: number | string) {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/api/member/bank_account/delete',
    { id },
    { responseReturn: 'body' },
  );
}
