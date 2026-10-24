import { randomBytes } from 'node:crypto'

/**
 * 分辨「SSR 在同一個程序內打自己的 BFF」與「外面送進來的請求」。
 *
 * 程序啟動時產生一次，只存在伺服器記憶體，不會進 runtimeConfig.public、
 * 不會進 SSR payload、也不會寫進任何 cookie，所以瀏覽器與外部都拿不到。
 * SSR 的 useApi 會帶上這個標頭，BFF 看到才允許明文 body；
 * 其餘來源一律要送加密信封。
 */
const TOKEN = [REDACTED]'base64url')

export const INTERNAL_HEADER = 'x-wf-internal'

export function internalToken(): string {
  return TOKEN
}

export function isInternalRequest(headerValue: string | undefined): boolean {
  return headerValue === TOKEN
}
