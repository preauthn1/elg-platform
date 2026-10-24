import type { ApiEnvelope } from '#shared/types/api'
import type { RequestOf } from '#shared/api/registry'
import type { TelegramBrand } from '#shared/api/endpoints/auth'
import { isLoginOk, needsInviteCode } from '#shared/utils/auth'
import { useMemberStore } from '~/stores/member'

export type TelegramLoginStatus = 'ok' | 'inviteCode' | 'error'

export interface TelegramLoginResult {
  res: ApiEnvelope<unknown>
  status: TelegramLoginStatus
}

/**
 * Telegram 登入三條路：OAuth widget（/telegram-callback）、WebApp initData（/tg-login）、補資料。
 * 兩支登入都走 server/api/auth/* 專用 handler 寫 session；333 表示要輸入邀請碼後重送。
 */
export function useTelegramLogin() {
  const api = useApi()
  const session = useUserSession()
  const memberStore = useMemberStore()

  /** tg-bot/get-brand-by-domain 回 code 1000；取第一筆，沒有 bot 回 null。 */
  async function fetchBrand(): Promise<{ res: ApiEnvelope<unknown>, brand: TelegramBrand | null }> {
    const res = await api.post('tg-bot/get-brand-by-domain', {})
    const brand = isLoginOk(res.code) && Array.isArray(res.data) ? res.data[0] ?? null : null
    return { res, brand }
  }

  function statusOf(res: ApiEnvelope<unknown>): TelegramLoginStatus {
    if (isLoginOk(res.code)) return 'ok'
    if (needsInviteCode(res.code)) return 'inviteCode'
    return 'error'
  }

  async function oauthLogin(input: RequestOf<'tg-bot/oauth-login'>): Promise<TelegramLoginResult> {
    const res = await api.postRaw<ApiEnvelope<null>>('/api/auth/tg-oauth-login', input as unknown as Record<string, unknown>)
    const status = statusOf(res)
    if (status === 'ok') await session.fetch()
    return { res, status }
  }

  async function webAppLogin(input: RequestOf<'tg-bot/web-app-login'>): Promise<TelegramLoginResult> {
    const res = await api.postRaw<ApiEnvelope<null>>('/api/auth/tg-web-app-login', input as unknown as Record<string, unknown>)
    const status = statusOf(res)
    if (status === 'ok') await session.fetch()
    return { res, status }
  }

  /** 登入後重抓 agent/template（BFF 會帶 token），取 TG 會員還缺的必填欄位。 */
  async function fetchMissingRequiredFields(): Promise<string[]> {
    const res = await api.post('agent/template', {}, { silent: true })
    if (!isApiOk(res)) return []
    return (res.data.missing_required_fields ?? []).filter((f): f is string => typeof f === 'string')
  }

  /** 補資料成功：session 已換新 token，重抓會員資料。導頁交給呼叫端。 */
  async function completeProfile(payload: RequestOf<'tgbot/complete-profile'>): Promise<ApiEnvelope<null>> {
    const res = await api.postRaw<ApiEnvelope<null>>('/api/auth/complete-profile', payload as unknown as Record<string, unknown>)
    if (isApiOk(res)) {
      await session.fetch()
      const detail = await api.post('member/detail', {}, { silent: true })
      if (isApiOk(detail)) memberStore.setProfile(detail.data)
    }
    return res
  }

  return { fetchBrand, oauthLogin, webAppLogin, fetchMissingRequiredFields, completeProfile }
}
