/**
 * Nitro 會用 destr 解析環境變數，純數字字串（例如 16 位數的 AES IV）會被轉成 number，
 * 超過 2^53 還會失真。金鑰類設定一律走這裡：優先讀 process.env 原字串，沒有才退回 runtimeConfig。
 */
export function readSecretEnv(envName: string, fallback: unknown): string {
  const raw = process.env[envName]
  if (typeof raw === 'string' && raw.length > 0) return raw
  if (fallback === undefined || fallback === null) return ''
  return String(fallback)
}
