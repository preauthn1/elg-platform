import { defineEventHandler } from 'h3';
import { csNewMessageChannel, forwardToCsApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 客服自動登入：用目前 agent session token 登入。 */
export default defineEventHandler(async (event) => {
  const session = await getProxySession(event);
  const { token, username } = session.data;

  if (!token || !username) {
    return { code: 401, data: null, message: 'agent not logged in' };
  }

  const result = await forwardToCsApi(event, 'api/login', {
    agent_token: [REDACTED],
    agent_username: username,
    auth_done: '-',
    password: '-',
    username,
  });

  if (result.code === 1000 && result.data) {
    const d = result.data;
    await session.update({
      csDomain: d.domain,
      csId: d.id,
      csMemberId: d.member_id,
      csMemberUsername: d.member_username,
      csNewMessageChannel: csNewMessageChannel(d.member_id, d.member_username),
      csRoleId: d.role_id,
      csToken: [REDACTED],
    });
    // 不把 CS token 回傳瀏覽器
    delete result.data.token;
  }

  return result;
});
