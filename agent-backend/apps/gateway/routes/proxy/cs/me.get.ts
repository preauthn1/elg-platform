import { defineEventHandler } from 'h3';
import { getCsGatewayEnv } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/**
 * 回傳目前客服(CS)登入狀態與身分（含 socket 連線資料）。
 */
export default defineEventHandler(async (event) => {
  const session = await getProxySession(event);
  const s = session.data;

  if (!s.csToken) {
    return { code: 1000, data: { loggedIn: false }, message: 'ok' };
  }

  const { csSocketUrl } = getCsGatewayEnv();

  return {
    code: 1000,
    data: {
      domain: s.csDomain,
      id: s.csId,
      loggedIn: true,
      member_id: s.csMemberId,
      member_username: s.csMemberUsername,
      newMessageChannel: s.csNewMessageChannel,
      role_id: s.csRoleId,
      socketUrl: csSocketUrl,
    },
    message: 'ok',
  };
});
