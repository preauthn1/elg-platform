import { defineEventHandler, setResponseStatus } from 'h3';
import {
  decideProxyGuard,
  isLoggedIn,
  stripQueryAndFragment,
} from '~/utils/agent-api-guard';
import { logBlockedProxyRequest } from '~/utils/forward-logger';
import { resolveClientIp } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/**
 * `agent/api/*`（apis）轉發守門，target/tickets#4548（排查細節見
 * target/tickets#4535 留言 154153）。
 *
 * 為什麼 gateway 要再擋一次：`routes/proxy/[...].ts` 是通用轉發，不看 session，
 * 會把 session 裡的 token（可能是空的）直接帶給 apis。apis 的
 * `POST agent/api/login` 可以跳過二次驗證直接發 token，apis 端已拿掉該路由回 404；
 * 但 gateway 是外部唯一入口，這裡再擋一層做縱深防禦，不賴 apis 單邊。
 *
 * 為什麼不能單純比對字串：h3 的 router param 不解碼，原始路徑會原樣送到 apis；
 * apis 前的 nginx 會解碼 `%6C`、合併 `//`，Node fetch 會把 `\` 轉成 `/`、自己
 * 消 `..`。編碼、多斜線、大小寫、反斜線、點段（`./`、`x/../`）都能繞過字串比對。
 *
 * 為什麼改成「拒絕歧義」而不是繼續模擬下游：guard 與 fetch／nginx／Laravel 的
 * 解碼與點段處理順序永遠對不齊。反例 `agent/api/login/%2F/../../login`：這裡先
 * 解碼 `%2F` 再消點段算出 `proxy/agent/login`，fetch 卻把未解碼的 `%2F` 當成一段
 * 來消、實際送出 `agent/api/login`。所以打 apis 的請求只接受乾淨的字面路徑
 * （每段只含英數、底線、連字號），任何編碼、反斜線、多斜線、點段、尾斜線一律
 * 403。前端所有 agent/api 路徑都只用小寫字母、數字、斜線、連字號、底線，拒絕
 * 其他字元不會誤擋。normalizeProxyPath 仍保留，作為已停用登入路徑的第二道網。
 *
 * 為什麼判斷「是否打 apis」同時看原始前綴：gateway.ts 依未解碼的 router param
 * 是否以 `agent/api/` 開頭決定轉給 apis。多層編碼的 `..`（如 `%252e%252e`）能讓
 * 正規化結果跳出前綴，但原始路徑仍會被轉給 apis，兩邊任一命中都要守。
 *
 * 兩種回應：
 * - 命中已停用的登入路徑或歧義路徑：不論登入與否回 HTTP 403 JSON，並寫一行
 *   WARNING log（有人試打就是事故訊號）；log 不含 query，避免把攻擊者塞的值
 *   寫進 log，只以 hasQuery 標示原請求帶過 query。不用 createError，因為 error.ts 會把
 *   error.stack 原文印給客戶端。
 * - 未登入打其他 `agent/api/*`：回 HTTP 200 `{ code: 9998 }`，與 apis `AuthAgent`
 *   現行的未登入回應一致，前端攔截器（web-ele/src/api/request.ts）照舊導回登入頁。
 *   前端登入完成前不打任何 `agent/api/*`，所以不會打壞現有登入流程。
 *
 * 不碰 `proxy/api/*`（API_Agent，登入前要用 `api/login`、`api/domain/info`、
 * `api/languages`）、`proxy/cs/*`、`proxy/internal/*`。不分 HTTP method，
 * HEAD／OPTIONS 一樣擋。
 */
export default defineEventHandler(async (event) => {
  const rawPath = event.path ?? '';
  const decision = decideProxyGuard(rawPath);
  if (decision.kind === 'pass') return;

  if (decision.kind === 'block') {
    logBlockedProxyRequest({
      clientIp: resolveClientIp(event),
      hasQuery: rawPath.includes('?'),
      method: event.method,
      path: stripQueryAndFragment(rawPath),
      reason: decision.reason,
    });
    setResponseStatus(event, 403);
    return { code: 403, data: null, message: 'Forbidden' };
  }

  const session = await getProxySession(event);
  if (!isLoggedIn(session.data)) {
    return { code: 9998, data: null, message: 'Unauthorized' };
  }
});
