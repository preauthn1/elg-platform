import { requestClient } from '#/api/request';

/** 直屬會員紀錄：每列＝一名會員，統計欄位為其直屬下線在期間內的數據。 */

export interface MemberReferralInfoRow {
  agent_username: string;
  bet_count: number;
  deposit_amount: string;
  deposit_count: number;
  direct_count: number;
  first_deposit_count: number;
  member_id: number;
  referral_reward_amount: string;
  reward_amount: string;
  /** 帳號狀態（0:未認證, 1:正常, 2:鎖定） */
  status: number;
  username: string;
  valid_bet: string;
  withdrawal_amount: string;
  withdrawal_count: number;
}

export interface MemberReferralInfoParams {
  agent_username?: string;
  end_time: string;
  page: number;
  per_page: number;
  start_time: string;
  username?: string;
}

/** apis 分頁回應為巢狀 { code, data: { items, pagination } }，此處攤平成 useListQuery 慣例。 */
export async function getMemberReferralInfoListApi(
  params: MemberReferralInfoParams,
) {
  const res = await requestClient.post<{
    code: number | string;
    data?: {
      items?: MemberReferralInfoRow[];
      pagination?: { total?: number };
    };
    message?: string;
  }>('/agent/api/member/referral-info', params, { responseReturn: 'body' });

  return {
    code: res.code,
    data: res.data?.items ?? [],
    message: res.message,
    pagination: { total: res.data?.pagination?.total ?? 0 },
  };
}
