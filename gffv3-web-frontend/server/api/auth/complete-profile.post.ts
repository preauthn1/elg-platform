import { getCookie } from 'h3'
import type { ApiEnvelope } from '#shared/types/api'
import { API_CODE, BFF_CODE } from '#shared/constants/codes'
import { authEndpoints } from '#shared/api/endpoints/auth'
import { callApi } from '../../utils/apiClient'
import { clearMemberSession, getMemberToken, setMemberSession } from '../../utils/memberSession'
import { ClientCryptoError, readClientBody } from '../../utils/clientCrypto'

const def = authEndpoints['tgbot/complete-profile']

/**
 * TG 會員補資料：成功時 apis 重新簽發 token，這裡用新 token 換掉 session 裡的舊 token，
 * 回給瀏覽器的 data 不含 token。
 */
export default defineEventHandler(async (event): Promise<ApiEnvelope<null>> => {
  const token = [REDACTED] getMemberToken(event)
  if (!token) {
    return { code: API_CODE.RELOGIN, message: 'login required', data: null }
  }

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

  const payload: Record<string, unknown> = { ...parsed.data }
  const domain = example.invalid?.domain
  if (domain) payload.domain = domain
  const locale = getCookie(event, 'locale') || undefined

  const res = await callApi<{ token?: string }>(event, def.path, payload, { token, locale })
  if (res.code === API_CODE.RELOGIN) {
    await clearMemberSession(event)
    return { ...res, data: null }
  }
  if (res.code !== API_CODE.OK || typeof res.data?.token !== 'string' || !res.data.token) {
    return { ...res, data: null }
  }

  const newToken = [REDACTED]
  const detail = await callApi<{ id?: number | string, username?: string, nickname?: string | null }>(event, 'member/detail', {}, { token: [REDACTED], locale })
  if (detail.code !== API_CODE.OK || !detail.data || detail.data.id === undefined || !detail.data.username) {
    return { code: BFF_CODE.UPSTREAM_FAILED, message: detail.message || 'member/detail failed after complete-profile', data: null }
  }

  await setMemberSession(event, {
    token: [REDACTED],
    user: { id: detail.data.id, username: detail.data.username, nickname: detail.data.nickname ?? null },
  })
  return { code: API_CODE.OK, message: res.message, data: null, tid: res.tid }
})
