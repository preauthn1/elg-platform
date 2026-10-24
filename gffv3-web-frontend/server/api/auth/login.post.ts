import { getCookie } from 'h3'
import type { ApiEnvelope } from '#shared/types/api'
import { BFF_CODE } from '#shared/constants/codes'
import { authEndpoints, type LoginData } from '#shared/api/endpoints/auth'
import { isLoginOk, isMemberLoginData } from '#shared/utils/auth'
import { callApi } from '../../utils/apiClient'
import { setMemberSession } from '../../utils/memberSession'
import { ClientCryptoError, readClientBody } from '../../utils/clientCrypto'

const def = authEndpoints['guest/login']

/**
 * 登入專用 handler：成功（200/1000 且為會員資料）就寫密封 session，
 * 回給瀏覽器的 data 拿掉 token；318/319/322 等業務碼原樣回傳給 composable 分流。
 * hasDeviceId 客服登入回的是客服 token（無 username），不寫會員 session、原樣回傳。
 */
export default defineEventHandler(async (event): Promise<ApiEnvelope<LoginData | null>> => {
  let raw: Record<string, unknown>
  try {
    raw = await readClientBody(event)
  }
  catch (error) {
    return {
      code: BFF_CODE.INVALID_REQUEST,
      message: error instanceof ClientCryptoError ? error.message : 'invalid request',
      data: null,
    }
  }
  const parsed = def.request.safeParse(raw)
  if (!parsed.success) {
    return {
      code: BFF_CODE.INVALID_REQUEST,
      message: 'invalid request',
      data: { issues: parsed.error.issues.map(i => ({ path: i.path.join('.'), message: i.message })) } as unknown as null,
    }
  }

  const domain = example.invalid?.domain
  if (!domain) {
    return { code: BFF_CODE.INVALID_REQUEST, message: 'site domain unresolved', data: null }
  }

  const payload: Record<string, unknown> = { ...parsed.data, domain }
  const locale = getCookie(event, 'locale') || undefined
  const res = await callApi<LoginData>(event, def.path, payload, { locale })

  if (isLoginOk(res.code) && isMemberLoginData(res.data)) {
    await setMemberSession(event, {
      token: [REDACTED],
      user: { id: res.data.id, username: res.data.username, nickname: res.data.nickname ?? null },
    })
    const { token: [REDACTED], ...rest } = res.data
    return { ...res, data: rest as LoginData }
  }

  return res
})
