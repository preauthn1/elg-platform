import { getCookie } from 'h3'
import type { ApiEnvelope } from '#shared/types/api'
import { API_CODE, BFF_CODE } from '#shared/constants/codes'
import { authEndpoints } from '#shared/api/endpoints/auth'
import { isLoginOk } from '#shared/utils/auth'
import { callApi } from '../../utils/apiClient'
import { setMemberSession } from '../../utils/memberSession'
import { ClientCryptoError, readClientBody } from '../../utils/clientCrypto'

const def = authEndpoints['tg-bot/oauth-login']

/**
 * Telegram OAuth widget 登入：apis 只回 { token }，這裡再用該 token 打 member/detail
 * 取 id/username/nickname 寫 session；回給瀏覽器的 data 不含 token。333（需邀請碼）原樣回傳。
 */
export default defineEventHandler(async (event): Promise<ApiEnvelope<null>> => {
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

  const res = await callApi<{ token?: string }>(event, def.path, payload, { locale })
  if (!isLoginOk(res.code) || typeof res.data?.token !== 'string' || !res.data.token) {
    return { ...res, data: null }
  }

  const token = [REDACTED]
  const detail = await callApi<{ id?: number | string, username?: string, nickname?: string | null }>(event, 'member/detail', {}, { token, locale })
  if (detail.code !== API_CODE.OK || !detail.data || detail.data.id === undefined || !detail.data.username) {
    return { code: BFF_CODE.UPSTREAM_FAILED, message: detail.message || 'member/detail failed after telegram login', data: null }
  }

  await setMemberSession(event, {
    token,
    user: { id: detail.data.id, username: detail.data.username, nickname: detail.data.nickname ?? null },
  })
  return { code: API_CODE.OK, message: res.message, data: null, tid: res.tid }
})
