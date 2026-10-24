import { defineEventHandler, readBody } from 'h3';
import { forwardToInternalApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** SR07 紅包明細表：內網服務路由僅接受 GET，需以查詢字串轉發。 */
export default defineEventHandler(async (event) => {
  const payload = (await readBody(event)) ?? {};
  const session = await getProxySession(event);

  const result = await forwardToInternalApi(
    event,
    'api/v1/report/sr07',
    payload as Record<string, any>,
    {
      actorId: session.data.id,
      method: 'GET',
      token: [REDACTED],
    },
  );

  // 內網服務回應沒有 code 欄位（只有 data/pagination），補上讓前端統一的
  // defaultResponseInterceptor（判斷 code===1000/200）能正確辨識成功。
  if (result.code === undefined && result.data !== undefined) {
    return { code: 1000, message: 'Success', ...result };
  }
  return result;
});
