import { useAuthStore } from '~/stores/auth'

/** /select-account 的邏輯，所有皮共用，畫面在 components/<皮>/Page/SelectAccount.vue。 */
export function useSelectAccountPage() {
  /** 同手機/信箱多帳號時選要重設密碼的帳號 → /set-password；沒有清單退回 /forget-password */
  const store = useAuthStore()

  async function choose(username: string) {
    store.setResetUsername(username)
    await navigateTo('/set-password')
  }

  return {
    store,
    choose,
  }
}
