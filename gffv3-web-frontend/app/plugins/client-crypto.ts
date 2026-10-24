import type { ClientCryptoKeys } from '#shared/utils/client-crypto'

/**
 * 伺服器端把這個 session 的加密金鑰放進 useState，水合後 useApi 用它加密請求，
 * 讓 F12 看到的是密文而不是明文 JSON。
 *
 * 金鑰本來就必須讓瀏覽器讀得到，不然前端無法加密，所以這層是混淆不是保密。
 */
export default defineNuxtPlugin(() => {
  const keys = useState<ClientCryptoKeys | null>('client-crypto', () => null)

  if (import.meta.server) {
    const event = useRequestEvent()
    keys.value = (event?.context.clientCrypto as ClientCryptoKeys | null) ?? null
  }
})
