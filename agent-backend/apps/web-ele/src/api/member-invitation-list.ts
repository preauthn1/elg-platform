import type { PagedResult } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 會員邀請列表 API */

export interface MemberInvitationRow {
  created_at: string;
  first_name?: string;
  last_login_ip?: number;
  mobile?: string;
  register_ip?: number;
  username: string;
}

/** 會員邀請列表 */
export async function getMemberInvitationListApi(params: {
  page: number;
  per_page: number;
  username: string;
}) {
  return requestClient.post<PagedResult<MemberInvitationRow>>(
    '/api/member/invitation_list',
    params,
    { responseReturn: 'body' },
  );
}
