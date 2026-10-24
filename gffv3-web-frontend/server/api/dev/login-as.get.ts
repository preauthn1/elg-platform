import { createError, getQuery, sendRedirect } from 'h3'
import { API_CODE } from '#shared/constants/codes'
import { callApi } from '../../utils/apiClient'
import { setMemberSession } from '../../utils/memberSession'

/**
 * 僅限本機開發：用一顆現成的會員 token 直接種密封 session，不跑正式登入流程。
 * 因為沒有向 apis 重新簽發 token，同一顆 token 的舊前台登入態不會被踢掉，
 * 兩邊可同時保持登入——對應舊前台「複製 sessionStorage 的 token、另開視窗帶到網址」的做法。
 *
 * 用法：另開視窗開
 *   http://localhost:3000/api/dev/login-as?token=[REDACTED] sessionStorage 的 token>
 * 成功後導回首頁，wf_session 即為該帳號。
 *
 * import.meta.dev 為假時（正式 build）這支路由回 404，不會存在於線上。
 */
export default defineEventHandler(async (event) => {
  if (!import.meta.dev) {
    throw createError({ statusCode: 404, statusMessage: 'Not Found' })
  }

  const token = [REDACTED] ?? '').trim()
  if (!token) {
    throw createError({ statusCode: 400, statusMessage: 'missing token query param' })
  }

  const detail = await callApi<{ id?: number | string, username?: string, nickname?: string | null }>(
    event,
    'member/detail',
    {},
    { token },
  )
  if (detail.code !== API_CODE.OK || !detail.data || detail.data.id === undefined || !detail.data.username) {
    throw createError({ statusCode: 401, statusMessage: detail.message || 'token 無效或 member/detail 失敗' })
  }

  await setMemberSession(event, {
    token,
    user: { id: detail.data.id, username: detail.data.username, nickname: detail.data.nickname ?? null },
  })

  return sendRedirect(event, '/', 302)
})
