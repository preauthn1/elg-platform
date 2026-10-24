import { getCookie } from 'h3'
import type { ApiEnvelope } from '#shared/types/api'
import { API_CODE } from '#shared/constants/codes'
import { callApi } from '../../utils/apiClient'
import { clearMemberSession, getMemberId, getMemberToken } from '../../utils/memberSession'

/** 有 token 就通知 apis 撤銷；不論結果一律清 session。 */
export default defineEventHandler(async (event): Promise<ApiEnvelope<null>> => {
  const token = [REDACTED] getMemberToken(event)
  if (token) {
    const locale = getCookie(event, 'locale') || undefined
    await callApi(event, 'member/logout', {}, { token, locale, actorId: await getMemberId(event) })
  }
  await clearMemberSession(event)
  return { code: API_CODE.OK, message: 'ok', data: null }
})
