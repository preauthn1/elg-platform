import { onMounted, ref } from 'vue'
import type { TelegramOAuthUser } from '#shared/api/endpoints/auth'
import { parseTelegramCallbackQuery } from '#shared/utils/auth'

/** /telegram-callback 的邏輯，所有皮共用，畫面在 components/<皮>/Page/TelegramCallback.vue。 */
export function useTelegramCallbackPage() {
  /**
   * Telegram OAuth 回呼（widget 的 return_to）：由 query 組 user 呼叫 tg-bot/oauth-login。
   * 成功：session cookie 已寫，postMessage({action:'redirect'}) 給開啟者後關閉彈窗；沒有開啟者就回首頁。
   * 333：顯示邀請碼輸入後重送。
   */
  const route = useRoute()
  const { oauthLogin } = useTelegramLogin()

  const user = ref<TelegramOAuthUser | null>(null)
  const botUsername = ref('')
  const loading = ref(false)
  const message = ref('')
  const needInviteCode = ref(false)
  const inviteCode = ref(typeof route.query.code === 'string' ? route.query.code : '')

  async function submit() {
    if (!user.value || !botUsername.value) {
      message.value = 'Telegram 回呼參數不完整。'
      return
    }
    loading.value = true
    message.value = ''
    const { res, status } = await oauthLogin({ user: user.value, bot_username: botUsername.value, code: inviteCode.value || undefined })
    loading.value = false
    if (status === 'inviteCode') {
      needInviteCode.value = true
      return
    }
    // 登入走 postRaw，useApi 不會自動彈窗
    if (status !== 'ok') {
      useAlert().showResponse(res)
      return
    }
    needInviteCode.value = false
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage({ action: 'redirect' }, window.location.origin)
      window.close()
      return
    }
    await navigateTo('/', { replace: true })
  }

  onMounted(() => {
    const parsed = parseTelegramCallbackQuery(route.query as Record<string, unknown>)
    user.value = parsed.user
    botUsername.value = parsed.bot_username
    void submit()
  })

  return {
    loading,
    message,
    needInviteCode,
    inviteCode,
    submit,
  }
}
