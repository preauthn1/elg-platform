import { requestClient } from '#/api/request';

/** 會員活動篩選器單列（3967）。member_id 即會員 id。 */
export interface MemberActivityFilterRow {
  deposit_amount: string;
  deposit_count: number;
  last_activity_at: null | string;
  last_bet_at: null | string;
  last_deposit_at: null | string;
  last_login: null | string;
  last_withdraw_at: null | string;
  member_id: number;
  mobile: string;
  username: string;
  withdraw_amount: string;
  withdraw_count: number;
}

/** 數值篩選:op = gt(>=) | between | lte(<=) */
export interface NumericFilter {
  op: 'between' | 'gt' | 'lte';
  value: number;
  value2?: number;
}

/** 日期篩選:起迄（YYYY-MM-DD） */
export interface DateFilter {
  end: string;
  start: string;
}

export interface MemberActivityFilterParams {
  filters?: Record<string, DateFilter | NumericFilter>;
  match?: 'and' | 'or';
  page?: number;
  per_page?: number;
}

/**
 * 會員活動篩選/列表（POST agent/api/member/activity-filter，apis 後端，成功碼 200）。
 * apis 分頁回應為巢狀 { code, data: { items, pagination } }，此處攤平成
 * useListQuery 慣例：{ code, data: rows[], pagination: { total } }。
 */
export async function getMemberActivityFilterApi(
  params: MemberActivityFilterParams,
) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: MemberActivityFilterRow[];
      pagination?: { total?: number };
    };
    message?: string;
  }>('/agent/api/member/activity-filter', params, { responseReturn: 'body' });

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: { total: res.data?.pagination?.total ?? 0 },
  };
}
