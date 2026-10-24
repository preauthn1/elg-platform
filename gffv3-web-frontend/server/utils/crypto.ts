import { createCipheriv, createDecipheriv, createHash } from 'node:crypto'

export interface ApiKeys {
  aesKey: string
  aesIv: string
  md5Key: string
}

export function md5Hex(input: string): string {
  return createHash('md5').update(input, 'utf8').digest('hex')
}

/**
 * 對應 PHP：base64_encode(openssl_encrypt($json, 'aes-256-cbc', md5(AES_KEY), OPENSSL_RAW_DATA, AES_IV))
 * PHP md5() 回 32 字元 hex 字串，直接當 utf8 bytes 就是 32 bytes 的 AES-256 key。
 */
export function aesEncrypt(plain: string, aesKey: string, aesIv: string): string {
  const key = Buffer.from(md5Hex(aesKey), 'utf8')
  const iv = Buffer.from(aesIv, 'utf8')
  const cipher = createCipheriv('aes-256-cbc', key, iv)
  return Buffer.concat([cipher.update(plain, 'utf8'), cipher.final()]).toString('base64')
}

export function aesDecrypt(cipherBase64: string, aesKey: string, aesIv: string): string {
  const key = Buffer.from(md5Hex(aesKey), 'utf8')
  const iv = Buffer.from(aesIv, 'utf8')
  const decipher = createDecipheriv('aes-256-cbc', key, iv)
  return Buffer.concat([
    decipher.update(Buffer.from(cipherBase64, 'base64')),
    decipher.final(),
  ]).toString('utf8')
}

/** 簽章：md5(params + MD5_KEY)，apis DecryptRequest 用同一式子比對。 */
export function signParams(params: string, md5Key: string): string {
  return md5Hex(params + md5Key)
}

/** 組出 apis 要的表單欄位 { params, key }。 */
export function buildEncryptedForm(payload: Record<string, unknown>, keys: ApiKeys): { params: string, key: string } {
  const params = aesEncrypt(JSON.stringify(payload), keys.aesKey, keys.aesIv)
  return { params, key: signParams(params, keys.md5Key) }
}
