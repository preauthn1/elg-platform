import { webcrypto } from 'node:crypto'
import { describe, expect, it, vi } from 'vitest'
import { encryptRequest } from '../../shared/utils/client-crypto'
import { aesEncrypt, buildEncryptedForm, signParams } from '../../server/utils/crypto'

vi.stubGlobal('defineEventHandler', (fn: unknown) => fn)
vi.stubGlobal('useRuntimeConfig', () => ({}))

const {
  ClientCryptoError,
  decryptClientBody,
  isEncryptedBody,
} = await import('../../server/utils/clientCrypto')

const subtle = webcrypto.subtle

/** 與部署的 API_AES_KEY / AES_IV / MD5_KEY 同形狀；IV 必須剛好 16 bytes。 */
const keys = {
  aesKey: 'TEST_AES_KEY_16B',
  aesIv: '1234567890123456',
  md5Key: 'TEST_MD5_KEY_16B',
}

describe('瀏覽器到 BFF 的請求加密（與舊前台同一套）', () => {
  it('加密後送出的是 { params, key }，內容看不出原始欄位', async () => {
    const body = await encryptRequest({ username: 'tim', password: [REDACTED]' }, keys, subtle)
    expect(Object.keys(body).sort()).toEqual(['key', 'params'])
    expect(body.params).not.toContain('tim')
    expect(body.params).not.toContain('password')
    expect(isEncryptedBody(body)).toBe(true)
  })

  it('伺服器解得回原本的物件', async () => {
    const payload = { vendor_id: 44, amount: '100.50', nested: { a: 1 } }
    const body = await encryptRequest(payload, keys, subtle)
    expect(decryptClientBody(body, keys)).toEqual(payload)
  })

  it('空物件也能來回', async () => {
    const body = await encryptRequest({}, keys, subtle)
    expect(decryptClientBody(body, keys)).toEqual({})
  })

  /**
   * 「沿用舊版」的重點：瀏覽器算出來的密文與簽章，必須與伺服器端
   * 送給 PHP 的那一套（server/utils/crypto.ts）逐字元相同。
   */
  it('與送給 PHP 的那一套逐字元相同', async () => {
    const payload = { username: 'tim', password: [REDACTED]' }
    const fromBrowser = await encryptRequest(payload, keys, subtle)
    const fromServer = buildEncryptedForm(payload, keys)
    expect(fromBrowser.params).toBe(fromServer.params)
    expect(fromBrowser.key).toBe(fromServer.key)
    expect(fromBrowser.params).toBe(aesEncrypt(JSON.stringify(payload), keys.aesKey, keys.aesIv))
    expect(fromBrowser.key).toBe(signParams(fromBrowser.params, keys.md5Key))
  })

  it('改過 params 會驗簽失敗', async () => {
    const body = await encryptRequest({ a: 1 }, keys, subtle)
    const tampered = { ...body, params: Buffer.from('nonsense').toString('base64') }
    expect(() => decryptClientBody(tampered, keys)).toThrow(ClientCryptoError)
  })

  it('換一把簽章金鑰解不開', async () => {
    const body = await encryptRequest({ a: 1 }, keys, subtle)
    const other = { ...keys, md5Key: 'different-md5-key' }
    expect(() => decryptClientBody(body, other)).toThrow(ClientCryptoError)
  })

  it('簽章亂填不會丟未預期的例外', async () => {
    const body = await encryptRequest({ a: 1 }, keys, subtle)
    expect(() => decryptClientBody({ ...body, key: '!!!' }, keys)).toThrow(ClientCryptoError)
  })

  it('明文物件不會被誤判成密文信封', () => {
    expect(isEncryptedBody({ username: 'tim' })).toBe(false)
    expect(isEncryptedBody({ params: 'a' })).toBe(false)
    expect(isEncryptedBody(null)).toBe(false)
    expect(isEncryptedBody({ params: 1, key: 2 })).toBe(false)
  })
})
