import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { TelegramBrand, TelegramOAuthUser } from '#shared/api/endpoints/auth'
import {
  buildTelegramBotUrl,
  buildTelegramOAuthUrl,
  parseTelegramAuthMessage,
  resolveRedirectPath,
} from '#shared/utils/auth'

/** /telegram-login-options 的邏輯，所有皮共用，畫面在 components/<皮>/Page/TelegramLoginOptions.vue。 */
export function useTelegramLoginOptionsPage() {
  /**
   * Telegram 登入選項：Bot 登入（example.invalid 連結）或 OAuth widget 彈窗。
   * 支援 ?redirect=&code=；widget 結果由 message 事件（auth_result）或 /telegram-callback 的 postMessage 回來。
   */
  const route = useRoute()
  const session = useUserSession()
  const { fetchBrand, oauthLogin } = useTelegramLogin()

  const brand = ref<TelegramBrand | null>(null)
  const noBot = ref(false)
  const loading = ref(false)
  const needInviteCode = ref(false)
  const inviteCode = ref(typeof route.query.code === 'string' ? route.query.code : '')
  const pendingUser = ref<TelegramOAuthUser | null>(null)
  const origin = ref('')

  const redirectTo = () => resolveRedirectPath(route.query.redirect)

  async function finish() {
    await session.fetch()
    await navigateTo(redirectTo(), { replace: true })
  }

  async function loginWithUser(user: TelegramOAuthUser) {
    if (!brand.value) return
    loading.value = true
    const { res, status } = await oauthLogin({ user, bot_username: brand.value.bot_username, code: inviteCode.value || undefined })
    loading.value = false
    if (status === 'ok') {
      needInviteCode.value = false
      await finish()
      return
    }
    if (status === 'inviteCode') {
      pendingUser.value = user
      needInviteCode.value = true
      return
    }
    // 登入走 postRaw，useApi 不會自動彈窗
    useAlert().showResponse(res)
  }

  async function onMessage(event: MessageEvent) {
    const data = event.data as unknown
    // /telegram-callback 彈窗登入成功後通知開啟者
    if (data && typeof data === 'object' && (data as { action?: string }).action === 'redirect' && event.origin === origin.value) {
      await finish()
      return
    }
    const result = parseTelegramAuthMessage(data)
    if (result) await loginWithUser(result as TelegramOAuthUser)
  }

  function openWidget() {
    if (!brand.value) return
    window.open(
      buildTelegramOAuthUrl({ botId: brand.value.bot_id, botUsername: brand.value.bot_username, origin: origin.value }),
      '_blank',
      'width=550,height=450',
    )
  }

  function openBot() {
    if (!brand.value) return
    window.location.href = buildTelegramBotUrl(brand.value.bot_username, inviteCode.value || null)
  }

  onMounted(async () => {
    origin.value = window.location.origin
    window.addEventListener('message', onMessage)
    const { brand: found } = await fetchBrand()
    brand.value = found
    noBot.value = !found
  })

  onBeforeUnmount(() => {
    window.removeEventListener('message', onMessage)
  })

  return {
    brand,
    noBot,
    loading,
    needInviteCode,
    inviteCode,
    pendingUser,
    loginWithUser,
    openWidget,
    openBot,
  }
}
