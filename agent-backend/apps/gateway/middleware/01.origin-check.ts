import { createError, defineEventHandler, getRequestHeader } from 'h3';

/**
 * 反偽造（CSRF / 跨站）：對 /proxy 的「會改狀態」請求(非 GET)檢查 Origin。
 * 合法前端與 gateway 同源 → Origin host 等於請求 host 才放行；
 * 跨站(example.invalid)Origin 對不上 → 403。
 *
 * - 只擋有帶 Origin 的瀏覽器請求(跨站偽造一定帶 Origin)；無 Origin 的
 *   非瀏覽器請求交給後端 token 驗證把關(沒有有效 session 本來就會被拒)。
 * - localhost / 0.0.0.0 放行，讓 dev 的 vite proxy(前端 5777 → gateway 5320)正常。
 */
export default defineEventHandler((event) => {
  const path = event.path ?? '';
  if (!path.startsWith('/proxy')) return;

  const method = event.method;
  if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return;

  const origin = getRequestHeader(event, 'origin');
  if (!origin) return; // 非瀏覽器請求：交給 token 驗證

  let originHost: string;
  try {
    originHost = new URL(origin).host;
  } catch {
    originHost = '';
  }

  // dev：vite proxy 會讓 Origin(:5777) 與 gateway host(:5320) 不一致
  if (
    originHost.startsWith('localhost') ||
    originHost.startsWith('0.0.0.0')
  ) {
    return;
  }

  const host =
    getRequestHeader(event, 'x-forwarded-host') ??
    getRequestHeader(event, 'host') ??
    '';

  if (originHost && host && originHost === host) return;

  throw createError({
    statusCode: 403,
    statusMessage: 'Cross-origin request forbidden',
  });
});
