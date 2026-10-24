import type { ApiEnvelope } from '#shared/types/api'
import { isOn } from '#shared/utils/site'
import { AUTH_CODE } from '#shared/utils/auth'
import { useMemberStore } from '~/stores/member'

export type GoogleAuthToggleStatus = 'ok' | 'wrongCode' | 'locked' | 'error'

/**
 * 會員綁定／解除 Google Authenticator。
 * 開啟：先 google-auth/auth 驗碼，成功再 member/update-profile 把 has_2fa 設 '1'；關閉不驗碼直接設 '0'。
 */
export function useGoogleAuth() {
  const api = useApi()
  const session = useUserSession()
  const memberStore = useMemberStore()

  const has2fa = computed(() => isOn(memberStore.profile?.has_2fa))

  async function ensureProfile() {
    if (memberStore.profile) return
    const res = await api.post('member/detail', {})
    if (isApiOk(res)) memberStore.setProfile(res.data)
  }

  function fetchImage(): Promise<ApiEnvelope<{ image: string, secret: [REDACTED] }>> {
    return api.post('google-auth/image', {})
  }

  function verifyCode(secret: [REDACTED] Promise<ApiEnvelope<unknown>> {
    return api.post('google-auth/auth', { username: session.user.value?.username ?? '', secret }, { silent: true })
  }

  async function setHas2fa(enabled: boolean): Promise<ApiEnvelope<unknown>> {
    const res = await api.post('member/update-profile', { has_2fa: enabled ? '1' : '0' })
    if (isApiOk(res)) memberStore.patchProfile({ has_2fa: enabled ? 1 : 0 })
    return res
  }

  /** 開啟 2FA：驗證碑錯 401 → wrongCode；錯誤達上限 319 → locked（呼叫端要登出）。 */
  async function enable(secret: [REDACTED] Promise<{ res: ApiEnvelope<unknown>, status: GoogleAuthToggleStatus }> {
    const verified = await verifyCode(secret)
    if (!isApiOk(verified)) {
      if (verified.code === AUTH_CODE.LOGOUT) return { res: verified, status: 'locked' }
      if (verified.code === AUTH_CODE.UNAUTHORIZED) return { res: verified, status: 'wrongCode' }
      return { res: verified, status: 'error' }
    }
    const res = await setHas2fa(true)
    return { res, status: isApiOk(res) ? 'ok' : 'error' }
  }

  async function disable(): Promise<{ res: ApiEnvelope<unknown>, status: GoogleAuthToggleStatus }> {
    const res = await setHas2fa(false)
    return { res, status: isApiOk(res) ? 'ok' : 'error' }
  }

  return { has2fa, ensureProfile, fetchImage, verifyCode, enable, disable }
}
