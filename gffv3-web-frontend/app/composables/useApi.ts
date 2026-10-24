import type { ApiEnvelope } from '#shared/types/api'
import type { EndpointKey, RequestOf, ResponseOf } from '#shared/api/registry'
import type { ClientCryptoKeys } from '#shared/utils/client-crypto'
import { API_CODE, isReloginCode } from '#shared/constants/codes'
import { encryptRequest } from '#shared/utils/client-crypto'
import { CONTENT_LOCALE_HEADER } from '#shared/constants/languages'
import { profileRedirectFor } from '#shared/utils/member'

export interface ApiPostOptions {
  /** 不做全域處理（9998 登出、317 維護、錯誤彈窗與依 code 導頁），呼叫端自己處理 */
  silent?: boolean
  /**
   * 這一次請求要的內容語言（例如活動詳情彈窗裡切的語言），不影響站台語系。
   * 沒給就照 locale cookie。
   */
  locale?: string
}

/** 取金鑰的 in-flight 請求，整個瀏覽器分頁共用一個。 */
let pendingKeys: Promise<ClientCryptoKeys | null> | null = null

/**
 * SSR 呼叫自己的 BFF 時要從原請求帶過去的標頭。
 *
 * **host／x-forwarded-host 一定要帶**：Nitro 的內部 fetch 沒有 host 時會補成 `localhost`，
 * site middleware 就會拿 localhost 去查 agent/template，BFF 補上的 domain 變成別的代理。
 * 登入時後端改用會員自己的代理所以看不出來，未登入時首頁活動、廣告全是別站的。
 */
export const SSR_FORWARDED_HEADERS = [
  'cookie',
  'user-agent',
  'accept-language',
  'x-forwarded-for',
  'x-orientation',
  'host',
  'x-forwarded-host',
] as const

/**
 * 瀏覽器與 SSR 都只跟同源 BFF 講話：POST /api/bff/<route>。
 * 型別由 shared/api/registry 的 zod schema 推導。
 */
export function useApi() {
  const requestHeaders = import.meta.server
    ? {
        ...useRequestHeaders([...SSR_FORWARDED_HEADERS]),
        // SSR 在同一個程序內呼叫自己的 BFF，用這個標頭表明身分才允許送明文。
        // 只存在伺服器記憶體，不會進 payload。
        'x-wf-internal': String(useRequestEvent()?.context.internalToken ?? ''),
      }
    : undefined
  const router = useRouter()
  const maintainMessage = useState<string>('maintain-message', () => '')
  const cryptoKeys = useState<ClientCryptoKeys | null>('client-crypto', () => null)

  /**
   * 取金鑰。正常情況 SSR 已經塞進 payload；但 nuxt.config 有幾組
   * `ssr: false` 的路由（/wallet、/record、/agent、/game/app），
   * 那些頁面沒有 SSR，payload 裡就沒有金鑰，**整個 session 之後送出的請求
   * 全部會是明文，BFF 一律回 422**。所以拿不到就跟伺服器要一次。
   *
   * 同時發生的多個請求收斂成同一個 in-flight promise，不要每支 API 各要一次。
   */
  async function ensureKeys(): Promise<ClientCryptoKeys | null> {
    if (cryptoKeys.value) return cryptoKeys.value
    pendingKeys ??= $fetch<ClientCryptoKeys>('/api/client-crypto')
      .then((keys) => {
        cryptoKeys.value = keys
        return keys
      })
      .catch(() => null)
      .finally(() => {
        pendingKeys = null
      })
    return pendingKeys
  }

  /**
   * 瀏覽器端把 body 加密成 `{ params, key }` 再送，F12 看到的就是密文。
   * SSR 不加密（同一個程序內，加了只是白繞一圈，而且有內部標頭可以送明文）。
   *
   * **瀏覽器端絕不退回明文**：明文送出去 BFF 一定回 422，等於請求本來就會失敗，
   * 卻讓帳號密碼這類內容曝在 F12 上。所以加密不成就直接丟錯，不發那個請求。
   */
  async function buildBody(body: Record<string, unknown>): Promise<Record<string, unknown>> {
    if (!import.meta.client) return body
    const subtle = globalThis.crypto?.subtle
    if (!subtle) {
      // crypto.subtle 只存在於安全環境（https 或 localhost）。
      // 用區網 IP 或自訂網域走 http 開發時會是 undefined。
      throw new Error('crypto.subtle 不存在（非安全環境），請求無法加密。請用 https 或 localhost 開啟。')
    }
    const keys = await ensureKeys()
    if (!keys) throw new Error('取不到請求加密金鑰，無法送出請求。')
    return { ...(await encryptRequest(body, keys, subtle)) }
  }

  /**
   * 這幾個碼下面自己有處理（導頁、清 session），**不要再彈一次錯誤視窗**——
   * 使用者會先看到彈窗、關掉之後才發現已經被導走，那是兩段互相打架的回饋。
   */
  function isHandled(code: number): boolean {
    return code === API_CODE.OK
      || code === API_CODE.MAINTENANCE
      || isReloginCode(code)
  }

  /**
   * token 失效：照舊版 Modal.hiddenModal，先彈出後端訊息（「Token錯誤」＋ ErrorCode），
   * **關掉彈窗後**才回登入頁，並帶上原本的路徑好讓登入後導回去。
   *
   * session cookie 由 BFF 在收到 319／9998 當下就清掉了，這裡**一定要等
   * `session.fetch()` 把 loggedIn 變回 false 再導**：login 頁看到還是登入狀態
   * 會把人彈回原頁，原頁再打一次 API 又拿到 319，兩邊無限來回。
   *
   * 同一時間好幾支請求一起失效時只跑一次，不要每支都導一次頁。
   */
  const relogging = useState('api-relogging', () => false)
  async function relogin(res: ApiEnvelope<unknown>) {
    if (relogging.value) return
    relogging.value = true
    try {
      await useUserSession().fetch()
      useMemberStore().reset()
      useWalletStore().reset()
      useAuthStore().reset()
      const route = router.currentRoute.value
      const redirect = route.path === '/login'
        ? undefined
        : router.resolve({ path: '/login', query: { redirect: route.fullPath } }).fullPath
      // useAlert 遇到空訊息不會開彈窗，那樣就永遠等不到關閉，直接導
      if (!String(res.message ?? '').trim()) {
        if (redirect) await navigateTo(redirect)
        return
      }
      useAlert().show(res.message, 'error', { tid: res.tid, ...(redirect ? { redirect } : {}) })
    }
    finally {
      relogging.value = false
    }
  }

  async function handleGlobalCodes(res: ApiEnvelope<unknown>) {
    if (res.code === API_CODE.MAINTENANCE) {
      maintainMessage.value = res.message
      await navigateTo('/maintain')
      return
    }
    // token 失效：319 與 9998 是同一件事，見 API_CODE.RELOGIN_LOGOUT 的說明
    if (isReloginCode(res.code)) return relogin(res)
    /*
     * 423 要整頁重載，但**等使用者關掉提示再做**（舊前台也是關閉彈窗才 reload）。
     * 收到就立刻 reload 的話，重載後那支 API 再回一次 423 就是無限重載。
     */
    if (res.code === API_CODE.RELOAD) return useAlert().show(res.message, 'error', { action: 'reload', tid: res.tid })
    if (isHandled(res.code)) return
    /*
     * 其餘錯誤碼統一用彈窗呈現，訊息就是後端給的，不自己改寫。
     * 照舊版 Modal.hiddenModal：碼在 profileRedirectFor 的表裡（324 綁手機、323 支付密碼、327 綁卡…）
     * 就**關掉彈窗後**導過去。呼叫端不要再自己導，要自己處理的請求請帶 silent。
     */
    const redirect = profileRedirectFor(res.code, router.currentRoute.value.path)
    useAlert().show(res.message, 'error', { tid: res.tid, ...(redirect ? { redirect } : {}) })
  }

  async function post<K extends EndpointKey>(
    route: K,
    body: RequestOf<K>,
    opts: ApiPostOptions = {},
  ): Promise<ApiEnvelope<ResponseOf<K>>> {
    const res = await $fetch<ApiEnvelope<ResponseOf<K>>>(`/api/bff/${route}`, {
      method: 'POST',
      body: await buildBody(body as Record<string, unknown>),
      headers: opts.locale ? { ...requestHeaders, [CONTENT_LOCALE_HEADER]: opts.locale } : requestHeaders,
    })
    if (!opts.silent) await handleGlobalCodes(res)
    return res
  }

  /**
   * 送到 BFF 以外的同源端點（server/api/auth/* 那幾支登入 handler）。
   * 走同一條加密路徑，F12 看到的一樣是 `{ params, key }`——
   * 登入的帳號密碼原本是明文送的，就是漏在這裡。
   */
  async function postRaw<T>(url: string, body: Record<string, unknown>): Promise<T> {
    // $fetch 對自家 server routes 會去推導回應型別，這裡由呼叫端指定，所以收斂一次
    return await $fetch(url, {
      method: 'POST',
      body: await buildBody(body),
      headers: requestHeaders,
    }) as T
  }

  return { post, postRaw }
}

/** 成功判斷統一走這裡。 */
export function isApiOk<T>(res: ApiEnvelope<T>): res is ApiEnvelope<T> & { code: typeof API_CODE.OK } {
  return res.code === API_CODE.OK
}
