import { md5 } from 'js-md5'

/**
 * 瀏覽器端的請求加密，與 server/utils/clientCrypto.ts 同一套協定，式子與 PHP 對齊：
 *   params = base64(AES-256-CBC(JSON))，AES 金鑰取 md5(AES_KEY) 的 32 字元 hex 當 utf8 bytes
 *   key    = md5(params + MD5_KEY)
 *
 * 這層是混淆不是保密（金鑰必須讓瀏覽器讀得到），細節見 CLAUDE.md 鐵則 2。
 * md5 用 js-md5：Web Crypto 只有 SHA 系列，而簽章式子要與 PHP 一致。
 */

export interface ClientCryptoKeys {
  /** 對應 API_AES_KEY；實際 AES 金鑰是 md5(aesKey) 的 hex 字串 */
  aesKey: string
  /** 對應 API_AES_IV；直接當 utf8 bytes，必須是 16 bytes */
  aesIv: string
  /** 對應 API_MD5_KEY；簽章用 */
  md5Key: string
}

export interface EncryptedBody {
  params: string
  key: string
}

function utf8(value: string): Uint8Array {
  return new TextEncoder().encode(value)
}

function bytesToBase64(bytes: ArrayBuffer): string {
  const view = new Uint8Array(bytes)
  let binary = ''
  for (const byte of view) binary += String.fromCharCode(byte)
  return btoa(binary)
}

/** 簽章：md5(params + MD5_KEY)，apis 的 DecryptRequest 用同一式比對。 */
export function signParams(params: string, md5Key: string): string {
  return md5(params + md5Key)
}

/** 加密一次請求。keys 來自 SSR payload（或 /api/client-crypto）。 */
export async function encryptRequest(
  payload: Record<string, unknown>,
  keys: ClientCryptoKeys,
  subtle: SubtleCrypto,
): Promise<EncryptedBody> {
  // PHP：openssl_encrypt($json, 'aes-256-cbc', md5(AES_KEY), OPENSSL_RAW_DATA, AES_IV)
  // md5() 回 32 字元 hex，直接當 utf8 bytes 就是 32 bytes 的 AES-256 金鑰
  const aesKey = await subtle.importKey(
    'raw',
    utf8(md5(keys.aesKey)) as BufferSource,
    { name: 'AES-CBC' },
    false,
    ['encrypt'],
  )
  // Web Crypto 的 AES-CBC 預設就是 PKCS#7 padding，與 PHP 的 OPENSSL_RAW_DATA 一致
  const cipher = await subtle.encrypt(
    { name: 'AES-CBC', iv: utf8(keys.aesIv) as BufferSource },
    aesKey,
    utf8(JSON.stringify(payload)) as BufferSource,
  )
  const params = bytesToBase64(cipher)

  return { params, key: signParams(params, keys.md5Key) }
}
