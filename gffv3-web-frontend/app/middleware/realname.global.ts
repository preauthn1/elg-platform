import { MEMBER_PATH, realnameRouteBlock, realnameRouteKind } from '#shared/utils/member'

/**
 * 實名認證守衛（舊前台 router/index.js 的 beforeEach）。進頁前打 realname-validation/check：
 *
 *   進實名認證頁   審核中 → 擋回原頁＋提示；已通過 → 回會員中心＋提示
 *   進提款／活動類 未申請 → 擋回原頁＋「前往實名認證」彈窗；審核中 → 擋回原頁＋提示
 *
 * **擋在這裡，不要在頁面裡各自檢查**：頁面裡擋就會先進頁、再顯示一段「請先實名」，
 * 舊版是根本不讓你進去、留在原頁彈窗。檔名排在 auth.global 之後，未登入先被導去登入頁。
 */
export default defineNuxtRouteMiddleware(async (to, from) => {
  const { loggedIn } = useUserSession()
  if (!loggedIn.value) return

  const { flag } = useSite()
  const kind = realnameRouteKind(to.path, {
    transferRequired: flag('transfer_withdraw_realname_required'),
    eventRequired: flag('event_reward_realname_validation'),
    applyVisible: flag('real_name_verification_apply_visible'),
  })
  if (!kind) return

  const status = await useRealnameValidation().fetchStatus()
  if (!status) return
  const block = realnameRouteBlock(kind, status)
  if (!block) return

  const { t } = useNuxtApp().$i18n
  const alert = useAlert()

  if (block === 'approved') {
    alert.show(t('member.realname.text.approved'), 'success')
    return navigateTo(MEMBER_PATH.home, { replace: true })
  }

  if (block === 'required') {
    alert.show(t('member.realname.text.required'), 'error', {
      confirm: { label: 'member.realname.action.verify', to: MEMBER_PATH.realNameVerification },
    })
  }
  else {
    alert.show(t('member.realname.msg.reviewing'))
  }

  // 直接開網址（SSR 首次載入）沒有原頁可以留，退回首頁
  const initial = import.meta.server || from.fullPath === to.fullPath
  return initial ? navigateTo('/', { replace: true }) : abortNavigation()
})
