import { defineEventHandler } from 'h3';
import { forwardToApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 登出：先通知真實 API 作廢 token，再清除伺服器端 session */
export default defineEventHandler(async (event) => {
  const session = await getProxySession(event);
  const token = [REDACTED];

  // 對應舊 API `Route::post('logout', 'Auth\LoginController@logout')`（需 token）。
  // best-effort：即使後端登出失敗（網路/token 已失效），仍要清掉本地 session。
  if (token) {
    try {
      await forwardToApi(
        event,
        'api/logout',
        {},
        {
          actorId: session.data.id,
          token,
        },
      );
    } catch {
      // 忽略後端登出錯誤
    }
  }

  await session.clear();
  return { code: 1000, data: null, message: 'ok' };
});
