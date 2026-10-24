import { requestClient } from '#/api/request';

/** 在線會員單列。member_id 即會員 id；last_login_ip 後端已轉為點分字串。 */
export interface MemberOnlineRow {
  first_name: null | string;
  group_name: null | string;
  last_activity_at: null | string;
  last_login: null | string;
  last_login_ip: null | string;
  member_id: number;
  online_seconds: null | number;
  username: string;
}

export interface MemberOnlineListParams {
  ip?: string;
  name?: string;
  page?: number;
  per_page?: number;
  username?: string;
}

/**
 * 在線會員列表（POST agent/api/member/online-list，apis 後端，成功碼 200）。
 * 在線定義＝持有未過期 access token。apis 分頁回應為巢狀
 * { code, data: { items, pagination } }，此處攤平成 useListQuery 慣例。
 */
export async function getMemberOnlineListApi(params: MemberOnlineListParams) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: MemberOnlineRow[];
      pagination?: { total?: number };
    };
    message?: string;
  }>('/agent/api/member/online-list', params, { responseReturn: 'body' });

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: { total: res.data?.pagination?.total ?? 0 },
  };
}

/** 強制登出在線會員（POST agent/api/member/kick）：作廢其全部有效 token。 */
export async function kickOnlineMemberApi(memberId: number) {
  return requestClient.post<{ code: number | string; message?: string }>(
    '/agent/api/member/kick',
    { member_id: memberId },
    { responseReturn: 'body' },
  );
}
