import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useAlert } from '../../app/composables/useAlert'

/**
 * 舊前台 Modal.hiddenModal：錯誤彈窗**關掉之後**才依 code 導頁（check-profile 的 324 → 綁手機…）。
 * 新前台用 useAlert 的 redirect 承接，這裡鎖住「關掉才導、沒帶就不導、reload 優先」。
 */
describe('useAlert：關掉後導頁', () => {
  const navigateTo = vi.fn()
  const reloadNuxtApp = vi.fn()

  beforeEach(() => {
    const state = ref(null)
    vi.stubGlobal('useState', () => state)
    vi.stubGlobal('navigateTo', navigateTo)
    vi.stubGlobal('reloadNuxtApp', reloadNuxtApp)
    navigateTo.mockReset()
    reloadNuxtApp.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('帶 redirect：顯示時不導，關掉才導', () => {
    const alert = useAlert()
    alert.show('請先綁定手機', 'error', { redirect: '/member/phone-verification' })
    expect(navigateTo).not.toHaveBeenCalled()
    alert.close()
    expect(navigateTo).toHaveBeenCalledWith('/member/phone-verification', { replace: false })
    expect(alert.state.value).toBeNull()
  })

  it('replace：成功提示關掉後用 replace 導（例如支付密碼設定完回會員中心）', () => {
    const alert = useAlert()
    alert.show('支付密碼更換成功', 'success', { redirect: '/member', replace: true })
    alert.close()
    expect(navigateTo).toHaveBeenCalledWith('/member', { replace: true })
  })

  it('沒帶 redirect：關掉不導頁', () => {
    const alert = useAlert()
    alert.show('一般錯誤')
    alert.close()
    expect(navigateTo).not.toHaveBeenCalled()
  })
})
