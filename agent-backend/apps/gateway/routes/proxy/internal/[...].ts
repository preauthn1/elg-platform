import { createError, defineEventHandler, getRouterParam, readBody } from 'h3';
import { forwardToInternalApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/**
 * 內網 API 白名單。API_INTERNAL（gsrc/api-internal）的 Authenticate middleware
 * 只驗證 token 有效、帳號啟用，沒有逐端點授權（`hasPermission` 從一開始就是
 * 沒實作的 TODO），部分端點（如 transaction/agents、cs/addTransactions）也完全
 * 沒比對呼叫者是不是操作自己名下的代理/會員。這支 catch-all 本身如果不設限，
 * 等於讓任何已登入使用者都能直接繞過前端、打到那些端點。
 *
 * 這裡只放行前端實際會用到的路徑；sr07 已有專屬檔案
 * （internal/api/v1/report/sr07.ts）覆寫掉這支 catch-all，列在這裡純粹是
 * 文件用途——如果那支專屬檔案未來被誤刪，這裡要記得補回來。
 *
 * target/tickets#4302：getAgentMoneyChangeLogs（SR13 舊版資料源）已隨 sr13.vue
 * 改走 NewReportController@sr13 而淘汰，該內網服務本身連不上，故一併從白名單
 * 移除，不再放行任何呼叫路徑打到這支已知壞掉的端點。
 */
const ALLOWED_INTERNAL_PATHS = new Set([
  'api/v1/report/sr07',
  'api/vendor/transferIn',
]);

/** 內網 API 轉發。 */
export default defineEventHandler(async (event) => {
  const realPath = getRouterParam(event, '_') ?? '';

  if (!ALLOWED_INTERNAL_PATHS.has(realPath)) {
    throw createError({
      statusCode: 403,
      statusMessage: 'Forbidden',
    });
  }

  const payload = event.method === 'GET' ? {} : ((await readBody(event)) ?? {});
  const session = await getProxySession(event);

  return forwardToInternalApi(event, realPath, payload as Record<string, any>, {
    actorId: session.data.id,
    token: [REDACTED],
  });
});
