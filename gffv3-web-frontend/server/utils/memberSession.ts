import type { H3Event } from 'h3'

export interface MemberSessionUser {
  id: number | string
  username: string
  nickname?: string | null
}

/**
 * 登入成功後寫密封 cookie：user 可被瀏覽器讀到（useUserSession().user），
 * token 放 secure 區只在伺服器可解，BFF 轉發時取出帶 Bearer。
 */
export async function setMemberSession(event: H3Event, input: { token: [REDACTED], user: MemberSessionUser }) {
  await replaceUserSession(event, {
    user: input.user,
    secure: { token: [REDACTED] },
    loggedInAt: Date.now(),
  })
}

export async function clearMemberSession(event: H3Event) {
  await clearUserSession(event)
}

/** 取目前 session 的會員 token（沒登入回 undefined）。 */
export async function getMemberToken(event: H3Event): Promise<string | undefined> {
  const session = await getUserSession(event)
  return session.secure?.token
}

/** 取目前 session 的會員 id（沒登入回 undefined），給轉發 log 用。 */
export async function getMemberId(event: H3Event): Promise<number | string | undefined> {
  const session = await getUserSession(event)
  return (session.user as MemberSessionUser | undefined)?.id
}
