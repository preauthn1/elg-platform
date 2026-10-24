import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/** 會員追蹤 API（3963，成功碼 200，分頁在 data.items / data.pagination） */

/** 風控標記：命中的維度 */
export type MemberTraceRiskMark =
  | 'bank'
  | 'email'
  | 'ip'
  | 'mobile'
  | 'password'
  | 'realname';

export interface MemberTraceRow {
  agent_username: string;
  bank_accounts: string[];
  created_at: string;
  currency: string;
  email: null | string;
  last_login: string;
  last_login_ip: null | string;
  /** 顯示用 IP（4299）：與查詢條件撞到的置頂，其後為近期 IP，上限 20 */
  login_ips: string[];
  /** 登入紀錄總筆數（4299）：供「近 N 個（共 X 筆登入）」標示 */
  login_log_total?: number;
  /** 與查詢 IP 條件撞到的 IP（4299）：login_ips 的置頂子集，前端標色 */
  matched_login_ips?: string[];
  member_id: number;
  mobile: null | string;
  realname: string;
  risk_marks: MemberTraceRiskMark[];
  username: string;
}

export interface MemberTraceListBody {
  code: number | string;
  data?: { items?: MemberTraceRow[]; pagination?: Pagination };
  message: string;
}

export interface MemberTraceListParams {
  /** 下組：直接輸入值 */
  email?: string;
  ip?: string;
  mobile?: string;
  page?: number;
  password?: string;
  per_page?: number;
  /** 上組：取該用戶的哪些維度反查關聯帳號 */
  trace_fields?: string[];
  /** true:全部條件成立(AND) false:任一條件成立(OR) */
  use_and?: boolean;
  /** 上組：反查對象用戶名 */
  username?: string;
}

/** 會員追蹤列表 */
export async function getMemberTraceListApi(params: MemberTraceListParams) {
  return requestClient.post<MemberTraceListBody>(
    '/agent/api/member/trace',
    { page: 1, per_page: 25, ...params },
    { responseReturn: 'body' },
  );
}

export interface MemberTraceSuggestBody {
  code: number | string;
  data?: string[];
  message: string;
}

/** 帳號快搜建議（前綴比對，上限 10 筆） */
export async function getMemberTraceSuggestApi(keyword: string) {
  return requestClient.post<MemberTraceSuggestBody>(
    '/agent/api/member/trace/suggest',
    { keyword },
    { responseReturn: 'body' },
  );
}
