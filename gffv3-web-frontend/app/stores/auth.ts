import { defineStore } from 'pinia'
import type { LoginParams } from '#shared/api/endpoints/auth'
import type { TwoFactorOption } from '#shared/utils/auth'

export interface PendingLogin {
  /** 第一次登入送出的參數，二次驗證時加 do_2fa / secret 重送 */
  params: LoginParams
  options: TwoFactorOption[]
  memberId?: number
}

export interface RetrieveContext {
  member_id: number
  guest_validate_token: [REDACTED]
}

export interface ResetContext {
  /** 要重設密碼的帳號；忘記密碼多帳號時由 /select-account 補上 */
  username: string | null
  /** 已驗證通過的 6 碼，reset-password 會再用同一碼 */
  token: [REDACTED]
  mobile?: string
  email?: string
}

/**
 * 登入／註冊／找回密碼跨頁暫存。只活在記憶體，整頁重載即消失，各頁缺資料時退回入口頁。
 */
export const useAuthStore = defineStore('auth', () => {
  const pendingLogin = ref<PendingLogin | null>(null)
  const retrieve = ref<RetrieveContext | null>(null)
  const accountList = ref<string[]>([])
  const resetContext = ref<ResetContext | null>(null)
  const pendingRegistration = ref<{ username: string, password: [REDACTED] } | null>(null)
  /** 發碼倒數：key → 到期時間（epoch ms） */
  const codeTimers = ref<Record<string, number>>({})
  const isTgbot = ref(false)

  function setPendingLogin(next: PendingLogin | null) {
    pendingLogin.value = next
  }

  function setRetrieve(next: RetrieveContext | null) {
    retrieve.value = next
  }

  function setAccountList(list: string[]) {
    accountList.value = [...list]
  }

  function setResetContext(next: ResetContext | null) {
    resetContext.value = next
  }

  function setResetUsername(username: string) {
    if (resetContext.value) resetContext.value = { ...resetContext.value, username }
  }

  function setPendingRegistration(next: { username: string, password: [REDACTED] } | null) {
    pendingRegistration.value = next
  }

  function startCodeTimer(key: string, seconds: number, now = Date.now()) {
    codeTimers.value = { ...codeTimers.value, [key]: now + seconds * 1000 }
  }

  function clearCodeTimer(key: string) {
    const { [key]: _removed, ...rest } = codeTimers.value
    codeTimers.value = rest
  }

  /** 剩餘秒數（無計時或已到期為 0）。 */
  function codeTimerRemaining(key: string, now = Date.now()): number {
    const expiry = codeTimers.value[key]
    if (!expiry) return 0
    return Math.max(0, Math.ceil((expiry - now) / 1000))
  }

  function setTgbot(value: boolean) {
    isTgbot.value = value
  }

  function reset() {
    pendingLogin.value = null
    retrieve.value = null
    accountList.value = []
    resetContext.value = null
    pendingRegistration.value = null
    codeTimers.value = {}
  }

  return {
    pendingLogin,
    retrieve,
    accountList,
    resetContext,
    pendingRegistration,
    codeTimers,
    isTgbot,
    setPendingLogin,
    setRetrieve,
    setAccountList,
    setResetContext,
    setResetUsername,
    setPendingRegistration,
    startCodeTimer,
    clearCodeTimer,
    codeTimerRemaining,
    setTgbot,
    reset,
  }
})
