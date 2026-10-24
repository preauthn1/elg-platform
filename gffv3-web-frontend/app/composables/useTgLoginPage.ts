import { onMounted, ref } from 'vue'
import { resolveRedirectPath } from '#shared/utils/auth'
import { useAuthStore } from '~/stores/auth'

interface TelegramWebApp {
  initData: string
  initDataUnsafe?: { start_param?: string }
  ready?: () => void
}

/** /tg-login 的邏輯，所有皮共用，畫面在 components/<皮>/Page/TgLogin.vue。 */
export function useTgLoginPage() {
  /**
   * Telegram WebApp 登入：/tg-login?brand_code=&platform=tgbot&redirect=
   * 以 window.Telegram.WebApp.initData 呼叫 tg-bot/web-app-login；成功後若 agent/template 回
   * missing_required_fields 非空先補資料，否則整頁載入 redirect。?tg_redirect= 直接 location.replace。
   */
  const route = useRoute()
  const store = useAuthStore()
  const { webAppLogin, fetchMissingRequiredFields } = useTelegramLogin()

  const loading = ref(false)
  const message = ref('')
  const needInviteCode = ref(false)
  const inviteCode = ref('')
  const startParam = ref('')
  const initData = ref('')
  const missing = ref<string[]>([])

  const brandCode = typeof route.query.brand_code === 'string' ? route.query.brand_code : ''

  function goRedirect() {
    window.location.href = resolveRedirectPath(route.query.redirect)
  }

  async function submit() {
    if (!brandCode) {
      message.value = '缺少 brand_code。'
      return
    }
    if (!initData.value) {
      message.value = '不在 Telegram WebApp 環境內。'
      return
    }
    loading.value = true
    message.value = ''
    const { res, status } = await webAppLogin({
      init_data: initData.value,
      brand_code: brandCode,
      code: inviteCode.value || startParam.value || undefined,
    })
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
    missing.value = await fetchMissingRequiredFields()
    if (missing.value.length === 0) goRedirect()
  }

  onMounted(() => {
    const tgRedirect = route.query.tg_redirect
    if (typeof tgRedirect === 'string' && tgRedirect) {
      window.location.replace(resolveRedirectPath(tgRedirect))
      return
    }
    const webApp = (window as unknown as { Telegram?: { WebApp?: TelegramWebApp } }).Telegram?.WebApp
    initData.value = webApp?.initData ?? ''
    webApp?.ready?.()
    // 不在 TG WebView 內就不要把瀏覽器誤判成 TG
    store.setTgbot(route.query.platform === 'tgbot' && initData.value !== '')
    const fromQuery = route.query.tgWebAppStartParam
    startParam.value = (typeof fromQuery === 'string' && fromQuery) || webApp?.initDataUnsafe?.start_param || ''
    void submit()
  })

  return {
    loading,
    message,
    needInviteCode,
    inviteCode,
    missing,
    goRedirect,
    submit,
  }
}
