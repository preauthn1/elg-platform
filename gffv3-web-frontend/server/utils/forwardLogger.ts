import { hostname } from 'node:os'

/**
 * BFF → apis 轉發紀錄，格式對齊 agent-backend 的 apps/gateway/utils/forward-logger.ts。
 *
 * 每一筆轉發拆成請求紀錄、響應紀錄兩行 JSON 印到 stdout，由 GKE log agent 收進
 * Cloud Logging 成 jsonPayload。欄位：time / application / host / remote-address /
 * severity / message / payload.context（request、response、info、耗時秒數 duration、
 * 連線失敗時的 handler_stats）。
 *
 * 本機 dev 只印一行響應摘要（`formatDevSummary`），完整 JSON 只在正式 build 輸出。
 */

export interface ForwardLogInput {
  /** 登入者 id；附在 message 尾端 `(id)`，未登入省略 */
  actorId?: number | string
  /** 真實 client IP，寫入 `remote-address` */
  clientIp: string
  /** GCLB trace id，用來跟 LB log、apis log 對同一筆請求 */
  gcpTrace?: string
  method: string
  /** 轉發路徑，如 `lobby/games` */
  path: string
  /** 加密前的業務 payload */
  payload: Record<string, unknown>
  /** 實際送出的 request headers */
  requestHeaders: Record<string, string>
  url: string
}

export interface ForwardResponseLogInput extends ForwardLogInput {
  /** 連線層錯誤（fetch throw、逾時、加密失敗）；有值代表沒拿到 HTTP 回應 */
  error?: unknown
  httpStatus?: number | null
  responseBody?: unknown
  responseHeaders?: Record<string, string>
  /** 送出前的 performance.now() */
  startedAt: number
}

/** 寫入 log 前要遮罩的鍵（不分大小寫） */
const MASK_KEYS = new Set([
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
])

const HOST = hostname()

const APP = 'web-frontend'

/** response body 序列化上限（字元數），超過只留 code／message 摘要 */
const MAX_BODY_BYTES = 16_384

/** `[site-app] `；SITE_NAME 沒設時只有 `[app] ` */
function messagePrefix(): string {
  const site = process.env.SITE_NAME || ''
  return site ? `[${site}-${APP}] ` : `[${APP}] `
}

/** RFC3339，小數補到 9 位 */
function logTimestamp(date: Date): string {
  return date.toISOString().replace(/\.(\d{3})Z$/, '.$1000000Z')
}

/** 遞迴遮罩敏感鍵，回傳新副本，不動到要回給瀏覽器的原物件 */
function maskSensitive(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(maskSensitive)
  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = MASK_KEYS.has(k.toLowerCase()) ? '***' : maskSensitive(v)
    }
    return out
  }
  return value
}

function truncateBody(body: unknown): unknown {
  if (body === null || body === undefined) return body
  if (typeof body === 'string') {
    return body.length > MAX_BODY_BYTES ? `${body.slice(0, MAX_BODY_BYTES)}…[truncated]` : body
  }
  let serialized: string | undefined
  try {
    serialized = JSON.stringify(body)
  }
  catch {
    return body
  }
  if (serialized === undefined || serialized.length <= MAX_BODY_BYTES) return body
  const b = body as Record<string, unknown>
  return { _bytes: serialized.length, _truncated: true, code: b.code, message: b.message }
}

/** 連線失敗的診斷；Node fetch 沒有 dns/connect/ssl/ttfb 分段耗時，固定 null */
function buildHandlerStats(totalTime: number, error: unknown): Record<string, unknown> {
  const err = (error ?? {}) as Record<string, unknown> & { cause?: unknown }
  const cause = (err.cause ?? {}) as Record<string, unknown>
  const message
    = (typeof cause.message === 'string' ? cause.message : null)
      ?? (typeof err.cause === 'string' ? err.cause : null)
      ?? (typeof err.message === 'string' ? err.message : null)
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
  }
}

function writeEntry(entry: Record<string, unknown>) {
  process.stdout.write(`${JSON.stringify(entry)}\n`)
}

function buildRequest(input: ForwardLogInput): Record<string, unknown> {
  return {
    header: maskSensitive(input.requestHeaders),
    method: input.method,
    params: maskSensitive(input.payload),
    url: input.url,
  }
}

function buildInfo(input: ForwardLogInput): Record<string, unknown> {
  return { apiType: 'apis', gcp_trace: input.gcpTrace ?? null }
}

function actorSuffix(actorId: ForwardLogInput['actorId']): string {
  return actorId === undefined ? '' : ` (${actorId})`
}

/** 本機 dev 的一行摘要：`[app] path 200 code=200 0.20698s (id)`；連線失敗印 `ERROR <原因>` */
export function formatDevSummary(input: ForwardResponseLogInput, totalTime: number): string {
  const status = input.error === undefined
    ? `${input.httpStatus ?? '-'} code=${(input.responseBody as { code?: unknown } | null | undefined)?.code ?? '-'}`
    : `ERROR ${input.error instanceof Error ? input.error.message : String(input.error)}`
  return `${messagePrefix()}${input.path} ${status} ${totalTime}s${actorSuffix(input.actorId)}`
}

/** 請求紀錄，severity 固定 INFO。要在 fetch 之前呼叫，掛住或 crash 時才有紀錄 */
export function logForwardRequest(input: ForwardLogInput) {
  if (import.meta.dev) return
  try {
    writeEntry({
      'application': APP,
      'host': HOST,
      'message': `${messagePrefix()}${input.path} 請求紀錄${actorSuffix(input.actorId)}`,
      'payload': { context: { info: buildInfo(input), request: buildRequest(input) } },
      'remote-address': input.clientIp,
      'severity': 'INFO',
      'time': logTimestamp(new Date()),
    })
  }
  catch {
    // logging 不可影響轉發
  }
}

/** 響應紀錄；拿到 HTTP 回應就 INFO，連線失敗才 ERROR，業務錯誤碼留在 body */
export function logForwardResponse(input: ForwardResponseLogInput) {
  try {
    const totalTime = Number(((performance.now() - input.startedAt) / 1000).toFixed(5))
    if (import.meta.dev) {
      process.stdout.write(`${formatDevSummary(input, totalTime)}\n`)
      return
    }
    const hasResponse = input.error === undefined

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
    }
    if (!hasResponse) context.handler_stats = buildHandlerStats(totalTime, input.error)

    writeEntry({
      'application': APP,
      'host': HOST,
      'message': `${messagePrefix()}${input.path} 響應紀錄, Time ${totalTime} sec${actorSuffix(input.actorId)}`,
      'payload': { context },
      'remote-address': input.clientIp,
      'severity': hasResponse ? 'INFO' : 'ERROR',
      'time': logTimestamp(new Date()),
    })
  }
  catch {
    // logging 不可影響轉發
  }
}
