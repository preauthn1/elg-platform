import { defineStore } from 'pinia'
import type { MemberDetail } from '#shared/api/endpoints/member'

/**
 * 會員資料（frontend/api/member/detail 的 data，鍵名照後端）。
 * 登入態本身在 nuxt-auth-utils 的密封 cookie，這裡只放登入後抓回來的 profile 與餘額；
 * 每次修改成功後用 patchProfile 寫回，不重打 detail。
 */
export const useMemberStore = defineStore('member', () => {
  const profile = ref<MemberDetail | null>(null)
  const loadedAt = ref<number | null>(null)

  function setProfile(next: MemberDetail | null) {
    profile.value = next
    loadedAt.value = next ? Date.now() : null
  }

  function patchProfile(patch: Partial<MemberDetail>) {
    if (!profile.value) return
    profile.value = { ...profile.value, ...patch }
  }

  function reset() {
    setProfile(null)
  }

  return { profile, loadedAt, setProfile, patchProfile, reset }
})
