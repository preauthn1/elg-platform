import type { z } from 'zod'

export type EndpointAuth = 'guest' | 'member' | 'optional'

/**
 * 一條 apis frontend/api/* 端點的契約。
 * - path：apis 路徑（去掉 frontend/api/ 前綴），通常與 registry 的 key 相同
 * - auth：member 必須有登入 session，BFF 才會轉發；guest/optional 有 token 就帶
 * - request / response：zod schema，request 在 BFF 驗證，response 在開發／測試環境驗證並記 warning
 * - withDomain：BFF 是否自動補 domain（Host）欄位，預設 true
 */
export interface EndpointDef<
  Req extends z.ZodTypeAny = z.ZodTypeAny,
  Res extends z.ZodTypeAny = z.ZodTypeAny,
> {
  path: string
  auth: EndpointAuth
  request: Req
  response: Res
  withDomain?: boolean
  description?: string
}

export type EndpointMap = Record<string, EndpointDef>

export function defineEndpoint<Req extends z.ZodTypeAny, Res extends z.ZodTypeAny>(
  def: EndpointDef<Req, Res>,
): EndpointDef<Req, Res> {
  return def
}
