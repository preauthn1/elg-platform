import { onMounted, reactive, ref } from 'vue'
import { detectPlatformName, telegramLoginRoute } from '#shared/utils/auth'

/**
 * 帳密登入的邏輯，所有皮共用，畫面在 components/<皮>/Page/Login.vue。
 * 已登入要導走是路由層的事，留在 pages/login.vue。
 */
export function useLoginForm() {
  const route = useRoute()
  const { flag } = useSite()
  const { login, canRegister } = useAuth()
  const alert = useAlert()

  const form = reactive({ username: '', password: '' })
  const platform = ref('unknown')
  const loading = ref(false)
  const showPassword = [REDACTED]
  const customerService = ref<{ token?: string, id?: number | string } | null>(null)

  onMounted(() => {
    platform.value = detectPlatformName(navigator.userAgent)
  })

  /** 兩個欄位都有值才能送出。 */
  const canSubmit = computed(() => form.username.trim() !== '' && form.password !== '')
  /** 從重設密碼回來時顯示「密碼已更新」 */
  const passwordReset = computed(() => route.query.reset === '1')
  const showForgetPassword = [REDACTED] => flag('forget_password_visible'))
  const telegramTo = computed(() => (flag('tgbot_login_visible') ? telegramLoginRoute(route.query.redirect) : undefined))

  async function submit() {
    if (loading.value || !canSubmit.value) return
    loading.value = true
    customerService.value = null
    try {
      const { res, outcome } = await login(
        { username: form.username.trim(), password: [REDACTED], platform: platform.value },
        { redirect: route.query.redirect },
      )
      // 與全站錯誤碼一樣用彈窗呈現（舊前台也是 SHOW_API_ERROR_RES）；登入走 postRaw，不經過 useApi 的統一處理
      if (outcome.kind === 'error' || outcome.kind === 'locked') alert.show(res.message, 'error', { tid: res.tid })
      if (outcome.kind === 'customerService') customerService.value = { token: [REDACTED], id: outcome.id }
    }
    finally {
      loading.value = false
    }
  }

  /**
   * 放棄登入，退回上一頁（沒有就回首頁）。
   * 不能導去 redirect：那一頁多半需要登入，守衛會再把人導回 /login，看起來就像沒反應。
   */
  function close() {
    return backOr('/')
  }

  return {
    form,
    loading,
    showPassword,
    customerService,
    canSubmit,
    canRegister,
    passwordReset,
    showForgetPassword,
    telegramTo,
    submit,
    close,
  }
}
