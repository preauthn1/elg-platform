import { execFileSync } from 'node:child_process'
import { existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { aesEncrypt, signParams } from '../../server/utils/crypto'

/**
 * 黃金測試：Node 加密 → PHP 解密 → 原文一致。
 * 有 php 才跑：PATH 上的 ，或用 PHP_BIN 指定執行檔。
 */
const candidates = [process.env.PHP_BIN, 'php'].filter(Boolean) as string[]
const php = candidates.find((p) => {
  if (p === 'php') {
    try {
      execFileSync(p, ['-v'], { stdio: 'ignore' })
      return true
    }
    catch {
      return false
    }
  }
  return existsSync(p)
})

const script = fileURLToPath(new URL('../golden/decrypt.php', import.meta.url))

describe.skipIf(!php)('PHP 黃金測試', () => {
  it('PHP 能解開 Node 的密文並驗過簽章', () => {
    const plain = JSON.stringify({ domain: 'example.invalid', page: 1, text: '中文' })
    const aesKey = 'TEST_AES_KEY_16B'
    const iv = '1234567890123456'
    const md5Key = 'TEST_MD5_KEY_16B'
    const params = aesEncrypt(plain, aesKey, iv)
    const key = signParams(params, md5Key)
    const out = execFileSync(php!, [script, params, key, aesKey, iv, md5Key], { encoding: 'utf8' }).trim()
    const result = JSON.parse(out) as { key_ok: boolean, plain: string }
    expect(result.key_ok).toBe(true)
    expect(result.plain).toBe(plain)
  })
})
