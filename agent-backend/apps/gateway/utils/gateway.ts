import type { EventHandlerRequest, H3Event } from 'h3';

import type { ApiType } from './forward-logger';

import { createCipheriv, createHash } from 'node:crypto';

import { getRequestHeader } from 'h3';

import { logForwardRequest, logForwardResponse } from './forward-logger';
import { resolveClientIp as resolveTrustedClientIp } from './trusted-proxies';

interface GatewayEnv {
  aesIv: string;
  aesKey: string;
  apiUrl: string;
  apiInternal: string;
  apisUrl: string;
  md5Key: string;
}

export function getGatewayEnv(): GatewayEnv {
  return {
    aesIv: process.env.AES_IV ?? '',
    aesKey: process.env.AES_KEY ?? '',
    apiUrl: process.env.API_URL ?? '',
    apiInternal: process.env.API_INTERNAL ?? '',
    apisUrl: process.env.APIS_URL ?? '',
    md5Key: process.env.MD5_KEY ?? '',
  };
}

function md5Hex(input: string): string {
  return createHash('md5').update(input, 'utf8').digest('hex');
}

/**
 * 對應 PHP encrypt():
 *   openssl_encrypt($data, 'aes-256-cbc', md5(AES_KEY), OPENSSL_RAW_DATA, AES_IV)
 *   然後 base64_encode
 * 注意：PHP md5() 回傳 32 字元的 hex 字串，當作 utf8 即 32 bytes => aes-256
 */
function encrypt(data: string, aesKey: string, aesIv: string): string {
  const key = Buffer.from(md5Hex(aesKey), 'utf8'); // 32 bytes
  const iv = Buffer.from(aesIv, 'utf8'); // 16 bytes
  const cipher = createCipheriv('aes-256-cbc', key, iv);
  return Buffer.concat([cipher.update(data, 'utf8'), cipher.final()]).toString(
    'base64',
  );
}

/**
 * 取得 GCLB 注入的 trace context（格式 `TRACE_ID/SPAN_ID;o=1`），轉發時原樣帶給下游 API，
 * 讓 LB log 與應用層 log 能用同一個 trace id 串起來；沒有就回空字串。
 */
function resolveTraceContext(event: H3Event<EventHandlerRequest>): string {
  return getRequestHeader(event, 'x-cloud-trace-context') ?? '';
}

/** 由 trace context 取出 trace id（`/` 之前那段）。 */
function traceIdOf(traceContext: string): string | undefined {
  return traceContext.split('/')[0] || undefined;
}

/**
 * 取得真實 client IP。對齊 gsrc/WEB_AgentBackend/_release/trusted_proxies.php
 * 的 resolveClientIp() 信任鏈規則（target/tickets#4187）：socket 的實際連線來源
 * 不在信任的三方 CDN/LB/WAF 清單內，就直接用它，完全不採信可被使用者自填的
 * X-Forwarded-For；只有連線來源本身已知可信，才進一步從 X-Forwarded-For
 * 右邊往左找出第一個不可信的節點。
 */
export function resolveClientIp(event: H3Event<EventHandlerRequest>): string {
  const remoteAddr =
    event.node.req.socket?.remoteAddress?.replace('::ffff:', '') ?? '';
  const xff = getRequestHeader(event, 'x-forwarded-for');
  const skipLastHop = process.env.TRUSTED_PROXY_SKIP_LAST_HOP === 'true';

  if (!remoteAddr) return '0.0.0.0';

  return resolveTrustedClientIp(remoteAddr, xff, skipLastHop);
}

export interface ForwardOptions {
  /** 登入者 id，寫入轉發 log 的 message 尾端 */
  actorId?: number | string;
  /** 內網目標路由若僅接受 GET，帶查詢字串呼叫（預設 POST 表單） */
  method?: 'GET' | 'POST';
  /** 伺服器端 session 取出的 token，會以 Bearer 注入 */
  token?: string;
}

export interface ApiResponse<T = any> {
  code?: number;
  data?: T;
  message?: string;
  [key: string]: any;
}

/**
 * 將請求加密後轉發到真實 API，回傳解析後的 JSON。
 * @param realPath API 路徑
 * @param payload  要送出的業務資料（會被加密成 params）
 */
export async function forwardToApi<T = any>(
  event: H3Event<EventHandlerRequest>,
  realPath: string,
  payload: Record<string, any>,
  options: ForwardOptions = {},
): Promise<ApiResponse<T>> {
  const { aesIv, aesKey, apiUrl, apisUrl, md5Key } = getGatewayEnv();

  const useNewApi = realPath.startsWith('agent/api/');
  const baseUrl = useNewApi ? apisUrl : apiUrl;
  if (!baseUrl) {
    return {
      code: -1,
      data: null as any,
      message: `Gateway not configured ${useNewApi ? 'APIS_URL' : 'API_URL'}`,
    };
  }

  const params = encrypt(JSON.stringify(payload), aesKey, aesIv);
  const sign = md5Hex(params + md5Key);

  const body = new URLSearchParams();
  body.set('params', params);
  body.set('key', sign);

  const headers: Record<string, string> = {
    'Accept-Language':
      getRequestHeader(event, 'accept-language') ??
      process.env.DEFAULT_LANGUAGE ??
      'en',
    'Content-Type': 'application/x-www-form-urlencoded',
    'V-HTTP-CLIENT-IP': resolveClientIp(event),
  };
  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }
  const traceContext = resolveTraceContext(event);
  if (traceContext) {
    headers['X-Cloud-Trace-Context'] = traceContext;
  }

  const url = `${baseUrl.replace(/\/+$/, '')}/${realPath.replace(/^\/+/, '')}`;
  const apiType: ApiType = useNewApi ? 'apis' : 'api';
  const startedAt = performance.now();

  // 請求紀錄先寫（fetch 之前），確保掛住/沒回應也有紀錄。
  const logBase = {
    actorId: options.actorId,
    apiType,
    clientIp: resolveClientIp(event),
    gcpTrace: traceIdOf(traceContext),
    method: 'POST',
    path: realPath,
    payload,
    requestHeaders: headers,
    url,
  };
  logForwardRequest(logBase);

  try {
    const resp = await fetch(url, {
      body: body.toString(),
      headers,
      method: 'POST',
    });
    const text = await resp.text();
    let parsed: ApiResponse<T>;
    try {
      parsed = JSON.parse(text) as ApiResponse<T>;
    } catch {
      parsed = { code: -1, data: text as any, message: 'response is not JSON' };
    }
    logForwardResponse({
      ...logBase,
      httpStatus: resp.status,
      responseBody: parsed,
      responseHeaders: Object.fromEntries(resp.headers),
      startedAt,
    });
    return parsed;
  } catch (error: any) {
    logForwardResponse({ ...logBase, error, startedAt });
    return {
      code: -1,
      data: null as any,
      message: `Gateway forwarding failed: ${error?.message ?? error}`,
    };
  }
}

/**
 * 主 API「不加密」轉發（對應 PHP curlPostWithoutEncrypt）：
 * 走 API_URL、資料直接以表單欄位送出，僅少數工具型端點使用（如 data-convert）。
 */
export async function forwardToApiPlain<T = any>(
  event: H3Event<EventHandlerRequest>,
  realPath: string,
  payload: Record<string, any>,
  options: ForwardOptions = {},
): Promise<ApiResponse<T>> {
  const { apiUrl } = getGatewayEnv();
  if (!apiUrl) {
    return {
      code: -1,
      data: null as any,
      message: 'Gateway not configured API_URL',
    };
  }

  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(payload ?? {})) {
    body.set(k, String(v ?? ''));
  }

  const headers: Record<string, string> = {
    'Accept-Language':
      getRequestHeader(event, 'accept-language') ??
      process.env.DEFAULT_LANGUAGE ??
      'en',
    'Content-Type': 'application/x-www-form-urlencoded',
    'V-HTTP-CLIENT-IP': resolveClientIp(event),
  };
  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }
  const traceContext = resolveTraceContext(event);
  if (traceContext) {
    headers['X-Cloud-Trace-Context'] = traceContext;
  }

  const url = `${apiUrl.replace(/\/+$/, '')}/${realPath.replace(/^\/+/, '')}`;
  const startedAt = performance.now();

  // 請求紀錄先寫（fetch 之前），確保掛住/沒回應也有紀錄。
  const logBase = {
    actorId: options.actorId,
    apiType: 'api' as const,
    clientIp: resolveClientIp(event),
    gcpTrace: traceIdOf(traceContext),
    method: 'POST',
    path: realPath,
    payload,
    requestHeaders: headers,
    url,
  };
  logForwardRequest(logBase);

  try {
    const resp = await fetch(url, {
      body: body.toString(),
      headers,
      method: 'POST',
      signal: AbortSignal.timeout(60_000),
    });
    const text = await resp.text();
    let parsed: ApiResponse<T>;
    try {
      parsed = JSON.parse(text) as ApiResponse<T>;
    } catch {
      parsed = { code: -1, data: text as any, message: 'response is not JSON' };
    }
    logForwardResponse({
      ...logBase,
      httpStatus: resp.status,
      responseBody: parsed,
      responseHeaders: Object.fromEntries(resp.headers),
      startedAt,
    });
    return parsed;
  } catch (error: any) {
    logForwardResponse({ ...logBase, error, startedAt });
    return {
      code: -1,
      data: null as any,
      message: `Gateway plain forwarding failed: ${error?.message ?? error}`,
    };
  }
}

/**
 * 內網 API 轉發：走 API_INTERNAL、資料「不加密」直接以表單欄位送出。
 */
export async function forwardToInternalApi<T = any>(
  event: H3Event<EventHandlerRequest>,
  realPath: string,
  payload: Record<string, any>,
  options: ForwardOptions = {},
): Promise<ApiResponse<T>> {
  const { apiInternal } = getGatewayEnv();
  if (!apiInternal) {
    return {
      code: -1,
      data: null as any,
      message: 'Gateway not configured API_INTERNAL',
    };
  }

  const method = options.method ?? 'POST';
  const query = new URLSearchParams();
  for (const [k, v] of Object.entries(payload ?? {})) {
    query.set(k, String(v ?? ''));
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language':
      getRequestHeader(event, 'accept-language') ??
      process.env.DEFAULT_LANGUAGE ??
      'en',
    'V-HTTP-CLIENT-IP': resolveClientIp(event),
  };
  if (method === 'POST') {
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
  }
  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }
  const traceContext = resolveTraceContext(event);
  if (traceContext) {
    headers['X-Cloud-Trace-Context'] = traceContext;
  }

  const baseUrl = `${apiInternal.replace(/\/+$/, '')}/${realPath.replace(/^\/+/, '')}`;
  const url =
    method === 'GET' && query.size > 0
      ? `${baseUrl}?${query.toString()}`
      : baseUrl;
  const startedAt = performance.now();

  // 請求紀錄先寫（fetch 之前），確保掛住/沒回應也有紀錄。
  const logBase = {
    actorId: options.actorId,
    apiType: 'internal' as const,
    clientIp: resolveClientIp(event),
    gcpTrace: traceIdOf(traceContext),
    method,
    path: realPath,
    payload,
    requestHeaders: headers,
    url,
  };
  logForwardRequest(logBase);

  try {
    const resp = await fetch(url, {
      body: method === 'POST' ? query.toString() : undefined,
      headers,
      method,
      // 內網 API 逾時 60 秒
      signal: AbortSignal.timeout(60_000),
    });
    const text = await resp.text();
    let parsed: ApiResponse<T>;
    try {
      parsed = JSON.parse(text) as ApiResponse<T>;
    } catch {
      parsed = { code: -1, data: text as any, message: 'response is not JSON' };
    }
    logForwardResponse({
      ...logBase,
      httpStatus: resp.status,
      responseBody: parsed,
      responseHeaders: Object.fromEntries(resp.headers),
      startedAt,
    });
    return parsed;
  } catch (error: any) {
    logForwardResponse({ ...logBase, error, startedAt });
    return {
      code: -1,
      data: null as any,
      message: `Gateway internal forwarding failed: ${error?.message ?? error}`,
    };
  }
}

interface CsGatewayEnv {
  csAesIv: string;
  csAesKey: string;
  csApiUrl: string;
  csMd5Key: string;
  csSocketUrl: string;
}

export function getCsGatewayEnv(): CsGatewayEnv {
  return {
    csAesIv: process.env.CS_IV ?? '',
    csAesKey: process.env.CS_AES_KEY ?? '',
    csApiUrl: process.env.CS_API_URL ?? '',
    csMd5Key: process.env.CS_MD5_KEY ?? '',
    csSocketUrl: process.env.CS_SOCKET_URL ?? '',
  };
}

/** 客服即時聊天「新訊息」私有頻道名稱：md5(member_id + CS_MD5_KEY + member_username)。 */
export function csNewMessageChannel(
  memberId: number | string,
  memberUsername: string,
): string {
  const { csMd5Key } = getCsGatewayEnv();
  return `newMessage.${md5Hex(`${memberId}${csMd5Key}${memberUsername}`)}`;
}

/**
 * CS 回應寫 log 用的摘要（取代整份 body）。
 * bytes 是序列化後的長度、rows 是 data 為陣列時的筆數——排查
 * 「回應是不是變得又大又慢」只需要這兩個數字，不需要內容本身。
 */
function csResponseSummary(parsed: ApiResponse<any>): Record<string, unknown> {
  let bytes: number | undefined;
  try {
    bytes = JSON.stringify(parsed)?.length;
  } catch {
    bytes = undefined;
  }
  return {
    bytes,
    code: parsed?.code,
    message: parsed?.message,
    rows: Array.isArray(parsed?.data) ? parsed.data.length : undefined,
  };
}

/** 將請求加密後轉發到客服(CS)後端，回傳解析後的 JSON。 */
export async function forwardToCsApi<T = any>(
  event: H3Event<EventHandlerRequest>,
  realPath: string,
  payload: Record<string, any>,
  options: ForwardOptions = {},
): Promise<ApiResponse<T>> {
  const { csAesIv, csAesKey, csApiUrl, csMd5Key } = getCsGatewayEnv();
  if (!csApiUrl) {
    return {
      code: -1,
      data: null as any,
      message: 'Gateway not configured CS_API_URL',
    };
  }

  const params = encrypt(JSON.stringify(payload), csAesKey, csAesIv);
  const sign = md5Hex(params + csMd5Key);

  const body = new URLSearchParams();
  body.set('params', params);
  body.set('key', sign);

  const headers: Record<string, string> = {
    'Accept-Language':
      getRequestHeader(event, 'accept-language') ??
      process.env.DEFAULT_LANGUAGE ??
      'en',
    'Content-Type': 'application/x-www-form-urlencoded',
    'V-HTTP-CLIENT-IP': resolveClientIp(event),
  };
  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }
  const traceContext = resolveTraceContext(event);
  if (traceContext) {
    headers['X-Cloud-Trace-Context'] = traceContext;
  }

  const url = `${csApiUrl.replace(/\/+$/, '')}/${realPath.replace(/^\/+/, '')}`;
  const startedAt = performance.now();

  // 請求紀錄先寫（fetch 之前），確保掛住/沒回應也有紀錄。
  const logBase = {
    actorId: options.actorId,
    apiType: 'cs' as const,
    clientIp: resolveClientIp(event),
    gcpTrace: traceIdOf(traceContext),
    method: 'POST',
    path: realPath,
    payload,
    requestHeaders: headers,
    url,
  };
  logForwardRequest(logBase);

  try {
    const resp = await fetch(url, {
      body: body.toString(),
      headers,
      method: 'POST',
      // 前面的 nginx 要等 60 秒才 504，那時 SPA 只拿到一張 HTML 錯誤頁；
      // 這裡 30 秒先斷，逾時會落到下方 catch 回 code:-1 的 JSON（HTTP 200），
      // 讓前端拿到可判斷的 body（target/op_tickets#3179）。
      signal: AbortSignal.timeout(30_000),
    });
    const text = await resp.text();
    // 部分 CS 端點（如 userMenu/list）會在 JSON 前多印一段 `[]`（PHP 殘留輸出），容錯去除後再解析
    const cleaned = text.replace(/^\s*\[\]\s*(?=[[{])/, '');
    let parsed: ApiResponse<T>;
    try {
      parsed = JSON.parse(cleaned) as ApiResponse<T>;
    } catch {
      parsed = { code: -1, data: text as any, message: 'response is not JSON' };
    }
    logForwardResponse({
      ...logBase,
      httpStatus: resp.status,
      // CS 轉送不寫整份 body：room/list 這類清單每 30 秒一次、單筆回應上百 KB，
      // 寫進 log 光序列化就吃掉大量 CPU 與記憶體（target/op_tickets#3179）。
      // 只留排查真正需要的：業務碼、訊息、原始大小、清單筆數。
      responseBody: csResponseSummary(parsed),
      responseHeaders: Object.fromEntries(resp.headers),
      startedAt,
    });
    return parsed;
  } catch (error: any) {
    logForwardResponse({ ...logBase, error, startedAt });
    return {
      code: -1,
      data: null as any,
      message: `Gateway CS forwarding failed: ${error?.message ?? error}`,
    };
  }
}
