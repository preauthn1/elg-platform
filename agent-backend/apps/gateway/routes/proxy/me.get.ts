import { defineEventHandler, setResponseStatus } from 'h3';
import { isLoggedIn } from '~/utils/agent-api-guard';
import { forwardToApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 前端攔截到這個碼會清狀態並導回登入頁 */
const TOKEN_ERROR_CODE = 9998;

/** apis 的「這個 token 不得再使用」碼 */
const APIS_LOGOUT_CODE = 319;

/** 驗 token 專用；apis 的 AuthAgent 每請求直查 DB，只有 319 代表 token 死了 */
const TOKEN_VERIFY_PATH = 'agent/api/domain-manage/type-list';

/** 回傳目前登入者資訊（從伺服器端 session 取，不含 token），並驗一次 token 還在不在 */
export default defineEventHandler(async (event) => {
  const session = await getProxySession(event);
  const s = session.data;

  if (!isLoggedIn(s)) {
    setResponseStatus(event, 401);
    return { code: 401, data: null, message: 'Unauthorized' };
  }

  // 只認 319，轉發失敗或後端暫時掛掉一律當成沒事
  const verify = await forwardToApi(
    event,
    TOKEN_VERIFY_PATH,
    {},
    { actorId: s.id, token: [REDACTED] },
  ).catch(() => null);

  if (verify && Number(verify.code) === APIS_LOGOUT_CODE) {
    await session.clear();
    return {
      code: TOKEN_ERROR_CODE,
      data: null,
      message: verify.message ?? 'token error',
    };
  }

  return {
    code: 1000,
    data: {
      balance: s.balance,
      code: s.code,
      domain: s.domain,
      hierarchy: s.hierarchy,
      host_id: s.host_id,
      id: s.id,
      is_balance_unlimited: s.is_balance_unlimited,
      mobile: s.mobile,
      parent: s.parent,
      role_id: s.role_id,
      username: s.username,
    },
    message: 'ok',
  };
});
