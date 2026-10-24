import type { H3Event } from 'h3'
import { getRequestHeader, readBody } from 'h3'
import { timingSafeEqual } from 'node:crypto'
import { aesDecrypt, signParams } from './crypto'
import { readSecretEnv } from './env'
import { INTERNAL_HEADER, isInternalRequest } from './internalToken'
// 型別與瀏覽器端同一份，重複宣告會讓 Nuxt 自動匯入二選一
import type { ClientCryptoKeys, EncryptedBody } from '#shared/utils/client-crypto'

/**
 * 瀏覽器到 BFF 這一段的請求加密，與舊前台同一套式子（AES-256-CBC + md5 簽章），
 * 所以 server/utils/crypto.ts 那幾支直接共用。
 *
 * 金鑰會出現在瀏覽器（SSR payload 或 /api/client-crypto）——前端要加密就必須拿得到，
 * 所以**這層是混淆不是保密**。真正的防護是端點白名單、zod、密封 cookie 裡的登入態與限流。
 *
 * 這一段與 BFF 到 PHP 那一段用同一組金鑰，但仍是兩次獨立的加解密：
 * 伺服器要先解開才能驗端點、驗參數、補 domain、掛 token。
 */

/** 取這個部署的金鑰；三把沒設齊就回 null，呼叫端據此判斷不可用。 */
export function getClientCryptoKeys(event: H3Event): ClientCryptoKeys | null {
  const cfg = useRuntimeConfig(event)
  const keys: ClientCryptoKeys = {
    aesKey: readSecretEnv('NUXT_API_AES_KEY', cfg.apiAesKey),
    aesIv: readSecretEnv('NUXT_API_AES_IV', cfg.apiAesIv),
    md5Key: readSecretEnv('NUXT_API_MD5_KEY', cfg.apiMd5Key),
  }
  if (!keys.aesKey || !keys.aesIv || !keys.md5Key) return null
  return keys
}

/** 請求 body 是不是加密信封。 */
export function isEncryptedBody(body: unknown): body is EncryptedBody {
  if (typeof body !== 'object' || body === null) return false
  const b = body as Record<string, unknown>
  return typeof b.params === 'string' && typeof b.key === 'string'
}

export class ClientCryptoError extends Error {}

/** 驗簽後解密。簽章式子與 PHP 相同：md5(params + MD5_KEY)。 */
export function decryptClientBody(body: EncryptedBody, keys: ClientCryptoKeys): Record<string, unknown> {
  const expected = Buffer.from(signParams(body.params, keys.md5Key), 'utf8')
  const given = Buffer.from(body.key, 'utf8')
  if (given.length !== expected.length || !timingSafeEqual(given, expected)) {
    throw new ClientCryptoError('signature mismatch')
  }

  let plain: string
  try {
    plain = aesDecrypt(body.params, keys.aesKey, keys.aesIv)
  }
  catch {
    throw new ClientCryptoError('decrypt failed')
  }

  let parsed: unknown
  try {
    parsed = JSON.parse(plain)
  }
  catch {
    throw new ClientCryptoError('payload is not JSON')
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    throw new ClientCryptoError('payload is not an object')
  }
  return parsed as Record<string, unknown>
}

/**
 * 讀取請求 body，瀏覽器送來的一律要是加密信封。
 *
 * BFF 與 server/api/auth/* 共用這一支，確保 F12 看到的每一筆 POST 都是密文。
 * 只有 SSR 在同一個程序內帶著內部標頭呼叫時才收明文（加密只是白繞一圈）。
 */
export async function readClientBody(event: H3Event): Promise<Record<string, unknown>> {
  const body = (await readBody(event).catch(() => null)) ?? {}
  if (isEncryptedBody(body)) {
    const keys = getClientCryptoKeys(event)
    if (!keys) throw new ClientCryptoError('API keys not configured (NUXT_API_*)')
    return decryptClientBody(body, keys)
  }
  if (isInternalRequest(getRequestHeader(event, INTERNAL_HEADER))) {
    return (body ?? {}) as Record<string, unknown>
  }
  throw new ClientCryptoError('encrypted body required')
}
