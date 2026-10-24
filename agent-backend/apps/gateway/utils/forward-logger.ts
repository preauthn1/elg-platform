import { hostname } from 'node:os';

/**
 * Gateway → 真實 API 轉發紀錄。
 *
 * 每一筆轉發拆成請求紀錄、響應紀錄兩行結構化 JSON 印到 stdout，由 GKE log agent 收集進
 * Cloud Logging 成 jsonPayload（gateway 本身不需服務帳號金鑰）。JSON 欄位：
 * time / application / host / remote-address / severity / message / payload.context
 * （context 內含 request、response、info、耗時秒數 duration、連線失敗時的 handler_stats）。
 */

export type ApiType = 'api' | 'apis' | 'cs' | 'internal';

export interface RequestLogInput {
  /** 登入者 id；附在 message 尾端 `(id)`。未登入（pre-auth）路徑可省略。 */
  actorId?: number | string;
  apiType: ApiType;
  /** 真實 client IP，寫入 `remote-address` 欄。 */
  clientIp: string;
  /** GCLB trace id，用來跟 LB log、下游 API log 對同一筆請求。 */
  gcpTrace?: string;
  method: string;
  /** 轉發路徑，當作 log 的 action，如 `api/member/list`。 */
  path: string;
  /** 解密後的業務 payload（非送出時的加密 params/簽章 key）。 */
  payload: Record<string, any>;
  /** 實際送出的 request headers。 */
  requestHeaders: Record<string, string>;
  url: string;
}

export interface ResponseLogInput extends RequestLogInput {
  /** 連線層錯誤（fetch throw）；有值代表沒拿到 HTTP 回應。 */
  error?: unknown;
  /** HTTP 狀態碼（resp.status）。 */
  httpStatus?: null | number;
  responseBody?: unknown;
  responseHeaders?: Record<string, string>;
  /** 送出 fetch 前取得的 performance.now()，用來算耗時。 */
  startedAt: number;
}

/**
 * 寫入 log 前要遮罩的敏感鍵（不分大小寫）：密碼、支付密碼、簽章金鑰、
 * session token（Authorization header / 回應 token）等。
 */
const DEFAULT_MASK_KEYS = new Set([
  'authorization',
  'confirm_password',
  'key',
  'new_password',
  'old_password',
  'params',
  'password',
  'pay_password',
  'payment_password',
  'secret',
  'token',
]);

const HOST = hostname();

// 這個 log 來源的應用名稱（固定值，寫入 application 欄與 message 前綴）。
const APP = 'agent-gateway';

// response body 序列化上限（bytes）；超過就截斷成摘要，避免大列表/報表把 log 撐爆。
const MAX_BODY_BYTES = 16_384;

/** 組 message 前綴 `[site-app] `；site（SITE_NAME）為空時只顯示 `[app] `。 */
function messagePrefix(): string {
  const site = process.env.SITE_NAME || '';
  return site ? `[${site}-${APP}] ` : `[${APP}] `;
}

/** 將時間格式化成 RFC3339、小數補到 9 位（log 後端 time 欄要的格式）。 */
function logTimestamp(date: Date): string {
  return date.toISOString().replace(/\.(\d{3})Z$/, '.$1000000Z');
}

/**
 * 遞迴遮罩敏感鍵，回傳遮罩後的新副本（不變動傳入物件，避免影響實際回傳給瀏覽器的資料）。
 * 命中鍵一律換成 '***'。
 */
function maskSensitive(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map((v) => maskSensitive(v));
  }
  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = DEFAULT_MASK_KEYS.has(k.toLowerCase())
        ? '***'
        : maskSensitive(v);
    }
    return out;
  }
  return value;
}

/**
 * response body 過大時截斷成摘要（保留業務 code/message），避免列表/報表上千筆撐爆 log。
 */
function truncateBody(body: unknown): unknown {
  if (body === null || body === undefined) {
    return body;
  }
  if (typeof body === 'string') {
    return body.length > MAX_BODY_BYTES
      ? `${body.slice(0, MAX_BODY_BYTES)}…[truncated]`
      : body;
  }
  let serialized: string | undefined;
  try {
    serialized = JSON.stringify(body);
  } catch {
    return body;
  }
  if (serialized === undefined || serialized.length <= MAX_BODY_BYTES) {
    return body;
  }
  const b = body as Record<string, any>;
  return {
    _bytes: serialized.length,
    _truncated: true,
    code: b?.code,
    message: b?.message,
  };
}

/**
 * 連線失敗時的診斷：耗時 + 連線錯誤的代碼/訊息/位址。
 * Node fetch 不提供 dns/connect/ssl/ttfb 的分段耗時，那幾項固定為 null。
 */
function buildHandlerStats(
  totalTime: number,
  error: unknown,
): Record<string, unknown> {
  const err = (error ?? {}) as Record<string, any>;
  const cause = (err.cause ?? {}) as Record<string, any>;
  // 連線錯誤的底層原因通常在 error.cause；優先取 cause.message，再退回頂層 err.message。
  const message =
    (typeof cause.message === 'string' ? cause.message : null) ??
    (typeof err.cause === 'string' ? err.cause : null) ??
    (typeof err.message === 'string' ? err.message : null);
  return {
    connect: null,
    dns: null,
    error: {
      code: err.code ?? cause.code ?? null,
      errno: cause.errno ?? err.errno ?? null,
      hostname: cause.hostname ?? null,
      message,
      name: err.name ?? null,
      syscall: cause.syscall ?? err.syscall ?? null,
    },
    primary_ip: cause.address ?? null,
    primary_port: cause.port ?? null,
    ssl: null,
    total: totalTime,
    ttfb: null,
  };
}

/** 序列化成單行 JSON 印到 stdout。 */
function writeEntry(entry: Record<string, unknown>): void {
  process.stdout.write(`${JSON.stringify(entry)}\n`);
}

/** 請求區塊（兩種紀錄共用），敏感鍵已遮罩。 */
function buildRequest(input: RequestLogInput): Record<string, unknown> {
  return {
    header: maskSensitive(input.requestHeaders ?? {}),
    method: input.method,
    params: maskSensitive(input.payload ?? {}),
    url: input.url,
  };
}

/** info 區塊（兩種紀錄共用）。 */
function buildInfo(input: RequestLogInput): Record<string, unknown> {
  return { apiType: input.apiType, gcp_trace: input.gcpTrace ?? null };
}

export interface BlockedProxyLogInput {
  /** 真實 client IP，寫入 `remote-address` 欄與 payload。 */
  clientIp: string;
  /** 原請求是否帶 query；query 內容不記，避免把攻擊者塞的值寫進 log。 */
  hasQuery: boolean;
  method: string;
  /** 原始請求路徑（未解碼、不含 query 與 fragment），保留攻擊者實際送出的寫法。 */
  path: string;
  /** 擋下原因，如 `disabled-login-path`、`ambiguous-path`。 */
  reason: string;
}

function actorSuffix(actorId: RequestLogInput['actorId']): string {
  return actorId === undefined ? '' : ` (${actorId})`;
}

/**
 * 請求紀錄，只含 request、severity 永遠 INFO。
 * 務必在 fetch 之前呼叫，確保 fetch 掛住/沒回應/crash 時請求紀錄仍已落地。
 */
export function logForwardRequest(input: RequestLogInput): void {
  try {
    writeEntry({
      application: APP,
      host: HOST,
      message: `${messagePrefix()}${input.path} 請求紀錄${actorSuffix(input.actorId)}`,
      payload: {
        context: {
          info: buildInfo(input),
          request: buildRequest(input),
        },
      },
      'remote-address': input.clientIp,
      severity: 'INFO',
      time: logTimestamp(new Date()),
    });
  } catch {
    // logging 不可影響轉發；自身錯誤吞掉。
  }
}

/** 響應紀錄，含 request + response（連線失敗改放 handler_stats）。 */
export function logForwardResponse(input: ResponseLogInput): void {
  try {
    const totalTime = Number(
      ((performance.now() - input.startedAt) / 1000).toFixed(5),
    );
    // 有拿到 HTTP 回應就 INFO、連線失敗才 ERROR；業務錯誤碼留在 body 由查詢端自行判斷。
    const hasResponse = input.error === undefined;

    const context: Record<string, unknown> = {
      duration: totalTime,
      info: buildInfo(input),
      request: buildRequest(input),
      response: hasResponse
        ? {
            body: maskSensitive(truncateBody(input.responseBody)),
            header: input.responseHeaders ?? {},
            statusCode: input.httpStatus ?? null,
          }
        : null,
    };

    if (!hasResponse) {
      context.handler_stats = buildHandlerStats(totalTime, input.error);
    }

    writeEntry({
      application: APP,
      host: HOST,
      message: `${messagePrefix()}${input.path} 響應紀錄, Time ${totalTime} sec${actorSuffix(input.actorId)}`,
      payload: { context },
      'remote-address': input.clientIp,
      severity: hasResponse ? 'INFO' : 'ERROR',
      time: logTimestamp(new Date()),
    });
  } catch {
    // logging 不可影響轉發；自身錯誤吞掉。
  }
}

/**
 * proxy 守門擋下請求的紀錄，severity WARNING。
 *
 * target/tickets#4548 的 agent/api/login 曾可被利用來跳過二次驗證，有人試打這類
 * 路徑本身就是事故訊號；不留紀錄就追不到有沒有人在掃、從哪個 IP 來。
 */
export function logBlockedProxyRequest(input: BlockedProxyLogInput): void {
  try {
    writeEntry({
      application: APP,
      host: HOST,
      message: `${messagePrefix()}proxy 守門擋下請求: ${input.reason} ${input.path}`,
      payload: {
        clientIp: input.clientIp,
        hasQuery: input.hasQuery,
        method: input.method,
        path: input.path,
        reason: input.reason,
      },
      'remote-address': input.clientIp,
      severity: 'WARNING',
      time: logTimestamp(new Date()),
    });
  } catch {
    // logging 不可影響回應；自身錯誤吞掉。
  }
}
