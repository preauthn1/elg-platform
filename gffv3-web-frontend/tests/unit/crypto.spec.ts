import { describe, expect, it } from 'vitest'
import { aesDecrypt, aesEncrypt, buildEncryptedForm, md5Hex, signParams } from '../../server/utils/crypto'

describe('apis 加密契約', () => {
  // 黃金值由 PHP 8.4 產生：
  // base64_encode(openssl_encrypt("hello", "aes-256-cbc", md5("k"), OPENSSL_RAW_DATA, "1234567890123456"))
  it('AES-256-CBC 結果與 PHP openssl_encrypt 一致', () => {
    expect(aesEncrypt('hello', 'k', '1234567890123456')).toBe('HT9O1x4Gab4eNymSsdaUGg==')
  })

  it('加密後可解回原文（含中文與 JSON）', () => {
    const plain = JSON.stringify({ domain: 'example.invalid', name: '測試', n: 1 })
    const cipher = aesEncrypt(plain, 'TEST_AES_KEY_16B', '1234567890123456')
    expect(aesDecrypt(cipher, 'TEST_AES_KEY_16B', '1234567890123456')).toBe(plain)
  })

  it('簽章 = md5(params + MD5_KEY)', () => {
    expect(md5Hex('abc')).toBe('900150983cd24fb0d6963f7d28e17f72')
    expect(signParams('p', 'k')).toBe(md5Hex('pk'))
  })

  it('buildEncryptedForm 產出 params 與 key 兩欄', () => {
    const form = buildEncryptedForm({ domain: 'x' }, { aesKey: 'a', aesIv: '1234567890123456', md5Key: 'm' })
    expect(form.params).toMatch(/^[A-Za-z0-9+/]+=*$/)
    expect(form.key).toBe(md5Hex(form.params + 'm'))
  })
})
